<?php

declare(strict_types=1);

namespace Drupal\Tests\lettermint\Unit;

use Drupal\lettermint\MessageBuilder;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the conversion of Drupal mail into API payloads.
 *
 * @group lettermint
 */
#[CoversClass(MessageBuilder::class)]
#[Group('lettermint')]
class MessageBuilderTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // MailFormatHelper::htmlToText() expects the request globals to be set.
    $GLOBALS['base_url'] = 'http://example.com';
    $GLOBALS['base_path'] = '/';
  }

  /**
   * Creates a message builder with the given settings.
   */
  protected function createBuilder(array $settings = []): MessageBuilder {
    return new MessageBuilder($this->getConfigFactoryStub([
      'lettermint.settings' => $settings + [
        'sender_name' => '',
        'sender_email' => '',
        'route' => '',
        'tag_with_mail_id' => TRUE,
      ],
    ]));
  }

  /**
   * Returns a formatted message as passed to MailInterface::mail().
   */
  protected function createMessage(array $overrides = []): array {
    return $overrides + [
      'id' => 'user_password_reset',
      'to' => 'jane@example.com',
      'from' => 'site@example.com',
      'subject' => 'Reset your password',
      'body' => 'Hello',
      'headers' => [
        'From' => 'site@example.com',
        'Content-Type' => 'text/plain; charset=UTF-8; format=flowed; delsp=yes',
        'MIME-Version' => '1.0',
        'X-Mailer' => 'Drupal',
      ],
      'params' => [],
    ];
  }

  /**
   * Tests building a plain text message.
   */
  public function testBuildPlainText(): void {
    $payload = $this->createBuilder()->build($this->createMessage());

    $this->assertSame([
      'from' => 'site@example.com',
      'to' => ['jane@example.com'],
      'subject' => 'Reset your password',
      'text' => 'Hello',
      'tag' => 'user-password-reset',
    ], $payload);
  }

  /**
   * Tests that HTML messages get a text alternative.
   */
  public function testBuildHtmlAddsTextAlternative(): void {
    $message = $this->createMessage(['body' => '<p>Hello <strong>Jane</strong></p>']);
    $message['headers']['Content-Type'] = 'TEXT/HTML; charset=UTF-8';

    $payload = $this->createBuilder()->build($message);

    $this->assertSame('<p>Hello <strong>Jane</strong></p>', $payload['html']);
    $this->assertStringContainsString('Hello *Jane*', $payload['text']);
  }

  /**
   * Tests the configured sender, route and tag settings.
   */
  public function testConfiguredSenderMovesOriginalToReplyTo(): void {
    $builder = $this->createBuilder([
      'sender_name' => 'Acme, Inc.',
      'sender_email' => 'noreply@acme.test',
      'route' => 'outgoing',
      'tag_with_mail_id' => FALSE,
    ]);

    $payload = $builder->build($this->createMessage());

    $this->assertSame('"Acme, Inc." <noreply@acme.test>', $payload['from']);
    $this->assertSame(['site@example.com'], $payload['reply_to']);
    $this->assertSame('outgoing', $payload['route']);
    $this->assertArrayNotHasKey('tag', $payload);
  }

  /**
   * Tests that an explicit Reply-To header is kept.
   */
  public function testExplicitReplyToWins(): void {
    $message = $this->createMessage();
    $message['headers']['Reply-to'] = 'support@example.com';

    $payload = $this->createBuilder(['sender_email' => 'noreply@acme.test'])->build($message);

    $this->assertSame(['support@example.com'], $payload['reply_to']);
  }

  /**
   * Tests recipients, headers, attachments and options.
   */
  public function testRecipientsHeadersAndOptions(): void {
    $message = $this->createMessage([
      'to' => '"Doe, Jane" <jane@example.com>, john@example.com',
      'params' => [
        'lettermint' => [
          'metadata' => ['order' => '42'],
          'scheduled_at' => '2026-10-01T09:00:00Z',
          'from' => 'ignored@example.com',
        ],
        'attachments' => [
          ['filename' => 'hello.txt', 'filecontent' => 'Hi', 'filemime' => 'text/plain'],
          ['filename' => 'logo.png', 'filecontent' => 'PNG', 'cid' => 'logo'],
        ],
      ],
    ]);
    $message['headers'] += [
      'Cc' => 'cc1@example.com, cc2@example.com',
      'Bcc' => 'bcc@example.com',
      'List-Unsubscribe' => '<https://example.com/unsubscribe>',
    ];

    $payload = $this->createBuilder()->build($message);

    $this->assertSame(['"Doe, Jane" <jane@example.com>', 'john@example.com'], $payload['to']);
    $this->assertSame(['cc1@example.com', 'cc2@example.com'], $payload['cc']);
    $this->assertSame(['bcc@example.com'], $payload['bcc']);
    $this->assertSame(['List-Unsubscribe' => '<https://example.com/unsubscribe>'], $payload['headers']);
    $this->assertSame(['order' => '42'], $payload['metadata']);
    $this->assertSame('2026-10-01T09:00:00Z', $payload['scheduled_at']);
    $this->assertSame('site@example.com', $payload['from']);
    $this->assertSame([
      ['filename' => 'hello.txt', 'content' => base64_encode('Hi'), 'content_type' => 'text/plain'],
      ['filename' => 'logo.png', 'content' => base64_encode('PNG'), 'content_id' => 'logo'],
    ], $payload['attachments']);
  }

}
