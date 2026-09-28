<?php

declare(strict_types=1);

namespace Drupal\lettermint\Drush\Commands;

use Drupal\lettermint\QueueProcessor;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for the Lettermint module.
 */
final class LettermintCommands extends DrushCommands {

  public function __construct(
    protected QueueProcessor $queueProcessor,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('lettermint.queue_processor'));
  }

  /**
   * Sends queued mail using the Lettermint batch API.
   */
  #[CLI\Command(name: 'lettermint:send-queue', aliases: ['lm-send'])]
  #[CLI\Option(name: 'time-limit', description: 'Stop starting new batches after this many seconds. 0 sends until the queue is empty.')]
  #[CLI\Usage(name: 'drush lettermint:send-queue --time-limit=60', description: 'Send queued mail for at most a minute.')]
  public function sendQueue(array $options = ['time-limit' => 0]): int {
    $result = $this->queueProcessor->process((int) $options['time-limit']);

    $this->logger()->success(dt('Sent @sent messages, @failed rejected, @remaining remaining.', [
      '@sent' => $result['sent'],
      '@failed' => $result['failed'],
      '@remaining' => $this->queueProcessor->count(),
    ]));
    if ($result['deferred']) {
      $this->logger()->warning(dt('Sending stopped on a temporary error, check the logs. The remaining messages stay queued.'));
      return self::EXIT_FAILURE;
    }
    return self::EXIT_SUCCESS;
  }

}
