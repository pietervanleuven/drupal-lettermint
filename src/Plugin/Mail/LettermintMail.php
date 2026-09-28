<?php

declare(strict_types=1);

namespace Drupal\lettermint\Plugin\Mail;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Mail\Attribute\Mail;
use Drupal\Core\Mail\MailInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lettermint\Exception\LettermintApiException;
use Drupal\lettermint\LettermintClient;
use Drupal\lettermint\MessageBuilder;
use Drupal\lettermint\QueueProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a mail plugin that sends email using the Lettermint API.
 *
 * @phpstan-consistent-constructor
 */
#[Mail(
  id: 'lettermint_mail',
  label: new TranslatableMarkup('Lettermint Mail'),
  description: new TranslatableMarkup('Sends emails using the Lettermint API.'),
)]
class LettermintMail implements MailInterface, ContainerFactoryPluginInterface {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected LettermintClient $client,
    protected MessageBuilder $messageBuilder,
    protected QueueProcessor $queueProcessor,
    protected UuidInterface $uuid,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $container->get('config.factory'),
      $container->get('lettermint.client'),
      $container->get('lettermint.message_builder'),
      $container->get('lettermint.queue_processor'),
      $container->get('uuid'),
      $container->get('logger.channel.lettermint'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function format(array $message): array {
    return $this->messageBuilder->format($message);
  }

  /**
   * {@inheritdoc}
   */
  public function mail(array $message): bool {
    $config = $this->configFactory->get('lettermint.settings');
    $payload = $this->messageBuilder->build($message);

    if ($config->get('delivery_mode') === 'queue') {
      $this->queueProcessor->enqueue($payload);
      return TRUE;
    }

    if (!$this->client->isConfigured()) {
      $this->logger->error('The Lettermint API token is not configured.');
      return FALSE;
    }

    try {
      $this->client->send($payload, $this->uuid->generate());
      return TRUE;
    }
    catch (LettermintApiException $e) {
      if ($e->isRetryable() && $config->get('queue_on_failure')) {
        $this->queueProcessor->enqueue($payload);
        $this->logger->warning('Sending "@subject" to @to through Lettermint failed, it was queued for a retry: @message', [
          '@subject' => $payload['subject'],
          '@to' => implode(', ', $payload['to']),
          '@message' => $e->getMessage(),
        ]);
        return TRUE;
      }
      $this->logger->error('Sending "@subject" to @to through Lettermint failed: @message @errors', [
        '@subject' => $payload['subject'],
        '@to' => implode(', ', $payload['to']),
        '@message' => $e->getMessage(),
        '@errors' => $e->getErrors() ? json_encode($e->getErrors()) : '',
      ]);
      return FALSE;
    }
  }

}
