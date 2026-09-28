<?php

declare(strict_types=1);

namespace Drupal\lettermint\Batch;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Sends all queued mail using the Batch API.
 *
 * Every batch operation step sends one Lettermint batch request.
 */
class QueueBatch {

  /**
   * Creates the batch definition.
   */
  public static function build(): array {
    return (new BatchBuilder())
      ->setTitle(new TranslatableMarkup('Sending queued mail through Lettermint'))
      ->setInitMessage(new TranslatableMarkup('Preparing to send queued mail.'))
      ->addOperation([static::class, 'process'])
      ->setFinishCallback([static::class, 'finished'])
      ->toArray();
  }

  /**
   * Batch operation: sends the next batch of queued messages.
   */
  public static function process(array &$context): void {
    /** @var \Drupal\lettermint\QueueProcessor $processor */
    $processor = \Drupal::service('lettermint.queue_processor');

    if (!isset($context['sandbox']['total'])) {
      $context['sandbox']['total'] = max(1, $processor->count());
      $context['results'] = ['sent' => 0, 'failed' => 0, 'deferred' => FALSE];
    }

    $result = $processor->processBatch();
    $context['results']['sent'] += $result['sent'];
    $context['results']['failed'] += $result['failed'];
    $context['results']['deferred'] = $result['deferred'];

    $remaining = $processor->count();
    if ($result['deferred'] || $result['sent'] + $result['failed'] === 0 || $remaining === 0) {
      $context['finished'] = 1;
    }
    else {
      // New mail may be queued while the batch runs, so never report done.
      $context['finished'] = min(0.99, 1 - $remaining / max($remaining, $context['sandbox']['total']));
    }
    $context['message'] = new TranslatableMarkup('Sent @sent messages, @remaining remaining.', [
      '@sent' => $context['results']['sent'],
      '@remaining' => $remaining,
    ]);
  }

  /**
   * Batch finish callback.
   */
  public static function finished(bool $success, array $results, array $operations): void {
    $messenger = \Drupal::messenger();
    if (!$success) {
      $messenger->addError(new TranslatableMarkup('Sending queued mail was interrupted. Check the logs for details.'));
      return;
    }
    $messenger->addStatus(new TranslatableMarkup('Sent @count queued messages through Lettermint.', ['@count' => $results['sent'] ?? 0]));
    if (!empty($results['failed'])) {
      $messenger->addError(new TranslatableMarkup('@count messages were rejected by Lettermint. Check the logs for details.', ['@count' => $results['failed']]));
    }
    if (!empty($results['deferred'])) {
      $messenger->addWarning(new TranslatableMarkup('Sending stopped on a temporary error. The remaining messages stay queued and will be retried on cron.'));
    }
  }

}
