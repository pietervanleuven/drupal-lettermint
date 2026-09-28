<?php

declare(strict_types=1);

namespace Drupal\lettermint\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Dispatched for every verified Lettermint webhook delivery.
 *
 * Subscribe to this event to act on delivery events, for example to block
 * users whose address hard bounced, or to record opens and clicks.
 *
 * @see https://lettermint.co/docs/platform/webhooks/events
 */
class LettermintWebhookEvent extends Event {

  /**
   * The event name.
   */
  public const NAME = 'lettermint.webhook';

  /**
   * Constructs a LettermintWebhookEvent.
   *
   * @param array $payload
   *   The decoded webhook payload, with the keys 'id', 'event', 'timestamp',
   *   'context' and 'data'.
   */
  public function __construct(
    protected array $payload,
  ) {}

  /**
   * Returns the event type, for example 'message.hard_bounced'.
   */
  public function getType(): string {
    return (string) ($this->payload['event'] ?? '');
  }

  /**
   * Returns the event specific data, like 'message_id' and 'recipient'.
   */
  public function getData(): array {
    return (array) ($this->payload['data'] ?? []);
  }

  /**
   * Returns the full decoded webhook payload.
   */
  public function getPayload(): array {
    return $this->payload;
  }

}
