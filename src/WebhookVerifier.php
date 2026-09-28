<?php

declare(strict_types=1);

namespace Drupal\lettermint;

use Drupal\Component\Datetime\TimeInterface;

/**
 * Verifies the signature of Lettermint webhook deliveries.
 *
 * The X-Lettermint-Signature header has the format 't={timestamp},v1={hash}',
 * where the hash is the HMAC-SHA256 of '{timestamp}.{body}' using the webhook
 * signing secret.
 *
 * @see https://lettermint.co/docs/platform/webhooks/introduction
 */
class WebhookVerifier {

  /**
   * The maximum age of a delivery, in seconds, to prevent replay attacks.
   */
  public const TOLERANCE = 300;

  public function __construct(
    protected TimeInterface $time,
  ) {}

  /**
   * Checks whether a webhook delivery is authentic.
   *
   * @param string $payload
   *   The raw request body.
   * @param string $signature
   *   The X-Lettermint-Signature header.
   * @param string $secret
   *   The webhook signing secret.
   * @param string|null $deliveryTimestamp
   *   The X-Lettermint-Delivery header, if present. It must match the
   *   timestamp in the signature.
   */
  public function verify(string $payload, string $signature, string $secret, ?string $deliveryTimestamp = NULL): bool {
    if ($secret === '') {
      return FALSE;
    }

    $parts = [];
    foreach (explode(',', $signature) as $part) {
      [$key, $value] = array_pad(explode('=', trim($part), 2), 2, NULL);
      if ($value !== NULL) {
        $parts[$key] = $value;
      }
    }
    if (!isset($parts['t'], $parts['v1']) || !ctype_digit($parts['t'])) {
      return FALSE;
    }

    $timestamp = (int) $parts['t'];
    if ($deliveryTimestamp !== NULL && (int) $deliveryTimestamp !== $timestamp) {
      return FALSE;
    }
    if (abs($this->time->getCurrentTime() - $timestamp) > self::TOLERANCE) {
      return FALSE;
    }

    return hash_equals(hash_hmac('sha256', $timestamp . '.' . $payload, $secret), $parts['v1']);
  }

}
