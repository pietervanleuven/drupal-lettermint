<?php

declare(strict_types=1);

namespace Drupal\lettermint\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\lettermint\Event\LettermintWebhookEvent;
use Drupal\lettermint\WebhookVerifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Receives Lettermint webhook deliveries.
 *
 * @phpstan-consistent-constructor
 */
class WebhookController implements ContainerInjectionInterface {

  /**
   * Events that indicate a problem with a recipient address.
   */
  protected const WARNING_EVENTS = [
    'message.hard_bounced',
    'message.spam_complaint',
    'message.policy_rejected',
    'message.failed',
  ];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected WebhookVerifier $verifier,
    protected EventDispatcherInterface $eventDispatcher,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('lettermint.webhook_verifier'),
      $container->get('event_dispatcher'),
      $container->get('logger.channel.lettermint'),
    );
  }

  /**
   * Verifies a webhook delivery and dispatches it as an event.
   */
  public function handle(Request $request): JsonResponse {
    $secret = (string) $this->configFactory->get('lettermint.settings')->get('webhook_secret');
    if ($secret === '') {
      return new JsonResponse(['error' => 'Webhooks are not enabled.'], 404);
    }

    $body = $request->getContent();
    $signature = (string) $request->headers->get('X-Lettermint-Signature', '');
    $delivery = $request->headers->get('X-Lettermint-Delivery');
    if (!$this->verifier->verify($body, $signature, $secret, $delivery)) {
      $this->logger->warning('Rejected a Lettermint webhook delivery with an invalid signature.');
      return new JsonResponse(['error' => 'Invalid signature.'], 401);
    }

    $payload = json_decode($body, TRUE);
    if (!is_array($payload)) {
      return new JsonResponse(['error' => 'Invalid payload.'], 400);
    }

    $event = new LettermintWebhookEvent($payload);
    if (in_array($event->getType(), self::WARNING_EVENTS, TRUE)) {
      $data = $event->getData();
      $this->logger->warning('Lettermint reported @event for @recipient (message @id): @response', [
        '@event' => $event->getType(),
        '@recipient' => $data['recipient'] ?? '-',
        '@id' => $data['message_id'] ?? '-',
        '@response' => $data['response']['content'] ?? '',
      ]);
    }

    $this->eventDispatcher->dispatch($event, LettermintWebhookEvent::NAME);

    return new JsonResponse(['status' => 'ok']);
  }

}
