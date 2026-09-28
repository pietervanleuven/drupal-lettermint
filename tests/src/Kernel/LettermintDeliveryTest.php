<?php

declare(strict_types=1);

namespace Drupal\Tests\lettermint\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\lettermint\Event\LettermintWebhookEvent;
use Drupal\lettermint\QueueProcessor;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests sending mail directly, through the queue and receiving webhooks.
 *
 * @group lettermint
 */
#[Group('lettermint')]
#[RunTestsInSeparateProcesses]
class LettermintDeliveryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'lettermint'];

  /**
   * The mocked API responses.
   */
  protected MockHandler $mockHandler;

  /**
   * The requests sent to the API.
   */
  protected array $history = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['lettermint']);
    $this->config('lettermint.settings')->set('api_token', 'secret-token')->save();

    $this->mockHandler = new MockHandler();
    $stack = HandlerStack::create($this->mockHandler);
    $stack->push(Middleware::history($this->history));
    $this->container->set('http_client', new Client(['handler' => $stack]));
  }

  /**
   * Sends a message through the Lettermint mail plugin.
   */
  protected function sendMail(string $to, string $subject = 'Hello'): bool {
    /** @var \Drupal\Core\Mail\MailInterface $plugin */
    $plugin = $this->container->get('plugin.manager.mail')->createInstance('lettermint_mail');
    return $plugin->mail($plugin->format([
      'id' => 'lettermint_test',
      'to' => $to,
      'from' => 'site@example.com',
      'subject' => $subject,
      'body' => ['Hello'],
      'headers' => ['Content-Type' => 'text/plain; charset=UTF-8'],
      'params' => [],
    ]));
  }

  /**
   * Returns the decoded body and path of a sent request.
   */
  protected function getRequest(int $index): array {
    $request = $this->history[$index]['request'];
    return [$request->getUri()->getPath(), json_decode((string) $request->getBody(), TRUE)];
  }

  /**
   * Returns the queue processor.
   */
  protected function queue(): QueueProcessor {
    return $this->container->get('lettermint.queue_processor');
  }

  /**
   * Tests that queued mail is sent in batches.
   */
  public function testQueuedBatchDelivery(): void {
    $this->config('lettermint.settings')->set('delivery_mode', 'queue')->set('batch_size', 2)->save();

    foreach (['a', 'b', 'c'] as $name) {
      $this->assertTrue($this->sendMail("$name@example.com"));
    }
    $this->assertCount(0, $this->history);
    $this->assertSame(3, $this->queue()->count());

    $this->mockHandler->append(
      new Response(202, [], '[{"message_id":"1","status":"pending"},{"message_id":"2","status":"pending"}]'),
      new Response(202, [], '{"message_id":"3","status":"pending"}'),
    );
    $this->container->get('cron')->run();

    $this->assertSame(0, $this->queue()->count());
    $this->assertCount(2, $this->history);
    [$path, $body] = $this->getRequest(0);
    $this->assertSame('/v1/send/batch', $path);
    $this->assertSame([['a@example.com'], ['b@example.com']], array_column($body, 'to'));
    $this->assertStringStartsWith('batch-', $this->history[0]['request']->getHeaderLine('Idempotency-Key'));
    // The last message is sent on its own.
    [$path, $body] = $this->getRequest(1);
    $this->assertSame('/v1/send', $path);
    $this->assertSame(['c@example.com'], $body['to']);
  }

  /**
   * Tests that a rejected batch is retried message by message.
   */
  public function testRejectedBatchFallsBackToSingleMessages(): void {
    $this->config('lettermint.settings')->set('delivery_mode', 'queue')->save();
    foreach (['a', 'b', 'c'] as $name) {
      $this->sendMail("$name@example.com");
    }

    $this->mockHandler->append(
      new Response(422, [], '{"message":"Invalid recipient."}'),
      new Response(202, [], '{"message_id":"1","status":"pending"}'),
      new Response(422, [], '{"message":"Invalid recipient."}'),
      new Response(202, [], '{"message_id":"3","status":"pending"}'),
    );
    $result = $this->queue()->process();

    $this->assertSame(['sent' => 2, 'failed' => 1, 'deferred' => FALSE], $result);
    $this->assertSame(0, $this->queue()->count());
    $this->assertCount(4, $this->history);
  }

  /**
   * Tests that temporary errors keep the mail queued.
   */
  public function testTemporaryErrorKeepsMailQueued(): void {
    $this->config('lettermint.settings')->set('delivery_mode', 'queue')->save();
    $this->sendMail('a@example.com');
    $this->sendMail('b@example.com');

    $this->mockHandler->append(new Response(503));
    $result = $this->queue()->process();
    $this->assertSame(['sent' => 0, 'failed' => 0, 'deferred' => TRUE], $result);
    $this->assertSame(2, $this->queue()->count());

    // The released messages are sent on the next run, with the same
    // idempotency key.
    $this->mockHandler->append(new Response(202, [], '[{"message_id":"1","status":"pending"},{"message_id":"2","status":"pending"}]'));
    $result = $this->queue()->process();
    $this->assertSame(['sent' => 2, 'failed' => 0, 'deferred' => FALSE], $result);
    $this->assertSame(0, $this->queue()->count());
    $this->assertSame(
      $this->history[0]['request']->getHeaderLine('Idempotency-Key'),
      $this->history[1]['request']->getHeaderLine('Idempotency-Key'),
    );
  }

  /**
   * Tests sending directly, and queueing on temporary failures.
   */
  public function testDirectDelivery(): void {
    $this->mockHandler->append(
      new Response(202, [], '{"message_id":"1","status":"pending"}'),
      new Response(503),
      new Response(422, [], '{"message":"Invalid recipient."}'),
    );

    $this->assertTrue($this->sendMail('a@example.com'));
    [$path, $body] = $this->getRequest(0);
    $this->assertSame('/v1/send', $path);
    $this->assertSame('Hello', $body['subject']);
    $this->assertSame(0, $this->queue()->count());

    // A temporary failure queues the message for a retry.
    $this->assertTrue($this->sendMail('b@example.com'));
    $this->assertSame(1, $this->queue()->count());

    // A permanent failure is reported to the caller.
    $this->assertFalse($this->sendMail('c@example.com'));
    $this->assertSame(1, $this->queue()->count());

    $this->config('lettermint.settings')->set('queue_on_failure', FALSE)->save();
    $this->mockHandler->append(new Response(503));
    $this->assertFalse($this->sendMail('d@example.com'));
    $this->assertSame(1, $this->queue()->count());
  }

  /**
   * Tests the status report requirements.
   */
  public function testRequirements(): void {
    $this->config('lettermint.settings')->set('delivery_mode', 'queue')->save();
    $this->sendMail('a@example.com');

    $this->container->get('module_handler')->loadInclude('lettermint', 'install');
    $requirements = lettermint_requirements('runtime');
    $this->assertArrayNotHasKey('lettermint_token', $requirements);
    $this->assertSame('1 message queued', (string) $requirements['lettermint_queue']['value']);

    $this->config('lettermint.settings')->set('api_token', '')->save();
    $requirements = lettermint_requirements('runtime');
    $this->assertArrayHasKey('lettermint_token', $requirements);
  }

  /**
   * Tests the webhook endpoint.
   */
  public function testWebhook(): void {
    $received = [];
    $this->container->get('event_dispatcher')->addListener(
      LettermintWebhookEvent::NAME,
      function (LettermintWebhookEvent $event) use (&$received): void {
        $received[] = $event;
      },
    );

    $payload = json_encode([
      'id' => 'evt_1',
      'event' => 'message.hard_bounced',
      'timestamp' => '2026-09-27T12:00:00Z',
      'data' => ['message_id' => 'abc', 'recipient' => 'jane@example.com'],
    ]);
    $post = function (string $signature = '', ?string $delivery = NULL) use ($payload): int {
      $request = Request::create('/lettermint/webhook', 'POST', [], [], [], [], $payload);
      $request->headers->set('X-Lettermint-Signature', $signature);
      if ($delivery !== NULL) {
        $request->headers->set('X-Lettermint-Delivery', $delivery);
      }
      return $this->container->get('http_kernel')->handle($request)->getStatusCode();
    };

    // Disabled until a secret is configured.
    $this->assertSame(404, $post());

    $this->config('lettermint.settings')->set('webhook_secret', 'whsec_test')->save();
    $this->assertSame(401, $post('t=1,v1=invalid'));
    $this->assertSame([], $received);

    $timestamp = $this->container->get('datetime.time')->getCurrentTime();
    $signature = sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp . '.' . $payload, 'whsec_test'));
    $this->assertSame(200, $post($signature, (string) $timestamp));
    $this->assertCount(1, $received);
    $this->assertSame('message.hard_bounced', $received[0]->getType());
    $this->assertSame('jane@example.com', $received[0]->getData()['recipient']);
  }

}
