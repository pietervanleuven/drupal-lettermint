<?php

declare(strict_types=1);

namespace Drupal\Tests\lettermint\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\lettermint\WebhookVerifier;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the webhook signature verification.
 *
 * @group lettermint
 */
#[CoversClass(WebhookVerifier::class)]
#[Group('lettermint')]
class WebhookVerifierTest extends UnitTestCase {

  protected const NOW = 1790000000;

  protected const SECRET = 'whsec_test';

  /**
   * The verifier under test.
   */
  protected WebhookVerifier $verifier;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(self::NOW);
    $this->verifier = new WebhookVerifier($time);
  }

  /**
   * Signs a payload the way Lettermint does.
   */
  protected function sign(string $payload, int $timestamp, string $secret = self::SECRET): string {
    return sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp . '.' . $payload, $secret));
  }

  /**
   * Tests signature verification.
   */
  public function testVerify(): void {
    $payload = '{"event":"webhook.test"}';

    $this->assertTrue($this->verifier->verify($payload, $this->sign($payload, self::NOW), self::SECRET, (string) self::NOW));
    $this->assertTrue($this->verifier->verify($payload, $this->sign($payload, self::NOW - 60), self::SECRET));

    // Tampered payload, wrong secret, and no secret.
    $this->assertFalse($this->verifier->verify('{"event":"other"}', $this->sign($payload, self::NOW), self::SECRET));
    $this->assertFalse($this->verifier->verify($payload, $this->sign($payload, self::NOW, 'other'), self::SECRET));
    $this->assertFalse($this->verifier->verify($payload, $this->sign($payload, self::NOW, ''), ''));
    // Replayed or mismatched timestamps.
    $this->assertFalse($this->verifier->verify($payload, $this->sign($payload, self::NOW - 301), self::SECRET));
    $this->assertFalse($this->verifier->verify($payload, $this->sign($payload, self::NOW), self::SECRET, (string) (self::NOW - 1)));
    // Malformed headers.
    $this->assertFalse($this->verifier->verify($payload, '', self::SECRET));
    $this->assertFalse($this->verifier->verify($payload, 'v1=abc', self::SECRET));
    $this->assertFalse($this->verifier->verify($payload, 't=abc,v1=abc', self::SECRET));
  }

}
