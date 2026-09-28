<?php

declare(strict_types=1);

namespace Drupal\lettermint;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\lettermint\Exception\LettermintApiException;
use Psr\Log\LoggerInterface;

/**
 * Queues outgoing mail and sends it using the Lettermint batch endpoint.
 *
 * Each queue item holds a ready to send API payload, so a batch of queued
 * messages can be sent with a single /send/batch request.
 */
class QueueProcessor {

  public const QUEUE_NAME = 'lettermint_send';

  /**
   * How long claimed items stay reserved, in seconds.
   */
  protected const LEASE_TIME = 300;

  /**
   * The maximum size of a batch request, with a margin for JSON overhead.
   */
  protected const MAX_CHUNK_BYTES = LettermintClient::MAX_PAYLOAD_BYTES - 1024 * 1024;

  public function __construct(
    protected LettermintClient $client,
    protected QueueFactory $queueFactory,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
    protected TimeInterface $time,
    protected UuidInterface $uuid,
  ) {}

  /**
   * Adds a message to the queue.
   *
   * @param array $payload
   *   The message, as expected by the /send endpoint.
   */
  public function enqueue(array $payload): void {
    $this->getQueue()->createItem([
      'uuid' => $this->uuid->generate(),
      'payload' => $payload,
    ]);
  }

  /**
   * Returns the number of messages waiting in the queue.
   */
  public function count(): int {
    return $this->getQueue()->numberOfItems();
  }

  /**
   * Sends queued messages until the queue is empty or time runs out.
   *
   * @param int $timeLimit
   *   The number of seconds after which no new batch is started, or 0 to keep
   *   going until the queue is empty.
   *
   * @return array
   *   The result, see ::processBatch().
   */
  public function process(int $timeLimit = 0): array {
    $end = $this->time->getCurrentTime() + $timeLimit;
    $total = ['sent' => 0, 'failed' => 0, 'deferred' => FALSE];

    do {
      $result = $this->processBatch();
      $total['sent'] += $result['sent'];
      $total['failed'] += $result['failed'];
      $total['deferred'] = $result['deferred'];
      $processed = $result['sent'] + $result['failed'];
    } while ($processed > 0 && !$result['deferred'] && ($timeLimit === 0 || $this->time->getCurrentTime() < $end));

    return $total;
  }

  /**
   * Sends the next batch of queued messages with a single request.
   *
   * @return array
   *   An array with the keys:
   *   - sent: The number of messages accepted by Lettermint.
   *   - failed: The number of messages that were rejected and discarded.
   *   - deferred: TRUE when sending stopped on an error that may resolve
   *     itself, like a network failure or rate limit. The affected messages
   *     remain in the queue.
   */
  public function processBatch(): array {
    $result = ['sent' => 0, 'failed' => 0, 'deferred' => FALSE];
    if (!$this->client->isConfigured()) {
      if ($this->count() > 0) {
        $this->logger->warning('Queued mail cannot be sent, because the Lettermint API token is not configured.');
        $result['deferred'] = TRUE;
      }
      return $result;
    }

    $items = $this->claimBatch();
    if ($items === []) {
      return $result;
    }

    if (count($items) === 1) {
      $this->sendSingle($items[0], $result);
      return $result;
    }

    $uuids = array_map(static fn(object $item): string => $item->data['uuid'], $items);
    try {
      $this->client->sendBatch(
        array_map(static fn(object $item): array => $item->data['payload'], $items),
        'batch-' . hash('sha256', implode(',', $uuids)),
      );
      foreach ($items as $item) {
        $this->getQueue()->deleteItem($item);
      }
      $result['sent'] = count($items);
      return $result;
    }
    catch (LettermintApiException $e) {
      if ($e->isRetryable()) {
        $this->release($items, $e, $result);
        return $result;
      }
      // The batch was rejected as a whole, usually because one of its
      // messages is invalid. Send the messages one by one, so only the
      // invalid ones are discarded.
      $this->logger->notice('Lettermint rejected a batch of @count messages (@message), sending them individually.', [
        '@count' => count($items),
        '@message' => $e->getMessage(),
      ]);
    }

    foreach ($items as $index => $item) {
      $this->sendSingle($item, $result);
      if ($result['deferred']) {
        foreach (array_slice($items, $index + 1) as $remaining) {
          $this->getQueue()->releaseItem($remaining);
        }
        break;
      }
    }
    return $result;
  }

  /**
   * Claims the next batch of queue items, within the API size limits.
   *
   * @return object[]
   *   The claimed queue items.
   */
  protected function claimBatch(): array {
    $queue = $this->getQueue();
    $limit = max(1, min(LettermintClient::MAX_BATCH_SIZE, (int) $this->configFactory->get('lettermint.settings')->get('batch_size')));

    $items = [];
    $bytes = 0;
    while (count($items) < $limit) {
      $item = $queue->claimItem(self::LEASE_TIME);
      if (!is_object($item)) {
        break;
      }
      if (!is_array($item->data) || !isset($item->data['uuid'], $item->data['payload'])) {
        $this->logger->error('Discarded malformed Lettermint queue item @id.', ['@id' => $item->item_id]);
        $queue->deleteItem($item);
        continue;
      }
      $size = strlen((string) json_encode($item->data['payload']));
      if ($items !== [] && $bytes + $size > self::MAX_CHUNK_BYTES) {
        // Leave it for the next batch.
        $queue->releaseItem($item);
        break;
      }
      $items[] = $item;
      $bytes += $size;
    }
    return $items;
  }

  /**
   * Sends a single queued message.
   */
  protected function sendSingle(object $item, array &$result): void {
    try {
      $this->client->send($item->data['payload'], $item->data['uuid']);
      $this->getQueue()->deleteItem($item);
      $result['sent']++;
    }
    catch (LettermintApiException $e) {
      if ($e->isRetryable()) {
        $this->release([$item], $e, $result);
        return;
      }
      $this->logger->error('Lettermint rejected the message "@subject" to @to, it will not be retried: @message @errors', [
        '@subject' => $item->data['payload']['subject'] ?? '',
        '@to' => implode(', ', (array) ($item->data['payload']['to'] ?? [])),
        '@message' => $e->getMessage(),
        '@errors' => $e->getErrors() ? json_encode($e->getErrors()) : '',
      ]);
      $this->getQueue()->deleteItem($item);
      $result['failed']++;
    }
  }

  /**
   * Returns items to the queue after an error that may resolve itself.
   */
  protected function release(array $items, LettermintApiException $e, array &$result): void {
    foreach ($items as $item) {
      $this->getQueue()->releaseItem($item);
    }
    $this->logger->warning('Sending @count queued messages through Lettermint failed, they will be retried later: @message', [
      '@count' => count($items),
      '@message' => $e->getMessage(),
    ]);
    $result['deferred'] = TRUE;
  }

  /**
   * Returns the mail queue.
   */
  protected function getQueue(): QueueInterface {
    return $this->queueFactory->get(self::QUEUE_NAME, TRUE);
  }

}
