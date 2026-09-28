<?php

declare(strict_types=1);

namespace Drupal\Tests\lettermint\Unit;

use Drupal\lettermint\Exception\LettermintApiException;
use Drupal\lettermint\LettermintClient;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Lettermint API client.
 *
 * @group lettermint
 */
#[CoversClass(LettermintClient::class)]
#[Group('lettermint')]
class LettermintClientTest extends UnitTestCase {

  /**
   * The requests sent by the client.
   */
  protected array $history = [];

  /**
   * Creates a client that answers with the given responses.
   */
  protected function createClient(array $responses, string $token = 'secret-token'): LettermintClient {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->history));
    return new LettermintClient(
      new Client(['handler' => $stack]),
      $this->getConfigFactoryStub(['lettermint.settings' => ['api_token' => $token]]),
    );
  }

  /**
   * Tests sending a single message.
   */
  public function testSend(): void {
    $client = $this->createClient([new Response(202, [], '{"message_id":"abc","status":"pending"}')]);

    $result = $client->send(['to' => ['jane@example.com']], 'key-1');

    $this->assertSame(['message_id' => 'abc', 'status' => 'pending'], $result);
    $request = $this->history[0]['request'];
    $this->assertSame('POST', $request->getMethod());
    $this->assertSame('https://api.lettermint.co/v1/send', (string) $request->getUri());
    $this->assertSame('secret-token', $request->getHeaderLine('x-lettermint-token'));
    $this->assertSame('key-1', $request->getHeaderLine('Idempotency-Key'));
    $this->assertSame('{"to":["jane@example.com"]}', (string) $request->getBody());
  }

  /**
   * Tests that a batch is sent as a JSON list.
   */
  public function testSendBatchPostsList(): void {
    $client = $this->createClient([new Response(202, [], '[{"message_id":"a","status":"pending"},{"message_id":"b","status":"pending"}]')]);

    $result = $client->sendBatch(['x' => ['subject' => 'A'], 'y' => ['subject' => 'B']]);

    $this->assertCount(2, $result);
    $request = $this->history[0]['request'];
    $this->assertSame('https://api.lettermint.co/v1/send/batch', (string) $request->getUri());
    $this->assertSame('[{"subject":"A"},{"subject":"B"}]', (string) $request->getBody());
    $this->assertFalse($request->hasHeader('Idempotency-Key'));
  }

  /**
   * Tests the batch size limit.
   */
  public function testSendBatchRejectsTooManyMessages(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->createClient([])->sendBatch(array_fill(0, 501, []));
  }

  /**
   * Tests that validation errors are exposed.
   */
  public function testValidationError(): void {
    $client = $this->createClient([new Response(422, [], '{"message":"The from field is invalid.","errors":{"from":["The from field is invalid."]}}')]);

    try {
      $client->send([]);
      $this->fail('Expected an exception.');
    }
    catch (LettermintApiException $e) {
      $this->assertSame(422, $e->getStatusCode());
      $this->assertSame(['from' => ['The from field is invalid.']], $e->getErrors());
      $this->assertStringContainsString('The from field is invalid.', $e->getMessage());
      $this->assertFalse($e->isRetryable());
    }
  }

  /**
   * Tests errors that may resolve themselves.
   *
   * @dataProvider providerRetryableErrors
   */
  #[DataProvider('providerRetryableErrors')]
  public function testRetryableErrors(Response|\Closure $response, int $status): void {
    if ($response instanceof \Closure) {
      $response = $response();
    }
    $client = $this->createClient([$response]);

    try {
      $client->send([]);
      $this->fail('Expected an exception.');
    }
    catch (LettermintApiException $e) {
      $this->assertSame($status, $e->getStatusCode());
      $this->assertTrue($e->isRetryable());
    }
  }

  /**
   * Data provider for ::testRetryableErrors().
   */
  public static function providerRetryableErrors(): array {
    return [
      'rate limited' => [new Response(429), 429],
      'server error' => [new Response(503, [], 'Service Unavailable'), 503],
      'invalid token' => [new Response(401, [], '{"message":"Unauthenticated."}'), 401],
      'network' => [fn() => new ConnectException('Could not resolve host', new Request('POST', 'send')), 0],
    ];
  }

  /**
   * Tests sending without an API token.
   */
  public function testMissingToken(): void {
    $client = $this->createClient([], '');

    $this->assertFalse($client->isConfigured());
    $this->expectException(LettermintApiException::class);
    $client->send([]);
  }

}
