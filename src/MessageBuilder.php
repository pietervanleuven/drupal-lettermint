<?php

declare(strict_types=1);

namespace Drupal\lettermint;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Mail\MailFormatHelper;

/**
 * Converts Drupal mail messages into Lettermint API payloads.
 *
 * Callers can pass Lettermint specific options through
 * $params['lettermint'] when calling the mail manager, or set them in
 * hook_mail_alter(). Supported keys: 'tag', 'tags', 'metadata', 'route',
 * 'scheduled_at' and 'settings'. These are passed to the API as is.
 *
 * Attachments are read from $params['attachments'], using the structure
 * shared with the Mime Mail and Symfony Mailer modules: each attachment has a
 * 'filename' and either a 'filepath' or 'filecontent', and optionally a
 * 'filemime' and a 'cid' for inline images.
 */
class MessageBuilder {

  /**
   * Headers that are covered by payload fields, or set by Lettermint itself.
   */
  protected const RESERVED_HEADERS = [
    'bcc',
    'cc',
    'content-transfer-encoding',
    'content-type',
    'from',
    'mime-version',
    'reply-to',
    'return-path',
    'sender',
    'subject',
    'to',
    'x-mailer',
  ];

  /**
   * Options that callers may pass through $params['lettermint'].
   */
  protected const PASSTHROUGH_OPTIONS = [
    'tag',
    'tags',
    'metadata',
    'route',
    'scheduled_at',
    'settings',
  ];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Formats a message body the way MailInterface::format() expects.
   *
   * HTML messages keep their markup. Everything else is converted to plain
   * text, like core's PhpMail does.
   */
  public function format(array $message): array {
    $body = implode("\n\n", array_map('strval', (array) $message['body']));
    if (!$this->isHtml($message)) {
      $body = MailFormatHelper::wrapMail(MailFormatHelper::htmlToText($body));
    }
    $message['body'] = $body;
    return $message;
  }

  /**
   * Builds the payload for the /send endpoint from a formatted message.
   *
   * @param array $message
   *   A message as passed to MailInterface::mail().
   *
   * @return array
   *   The API payload.
   */
  public function build(array $message): array {
    $config = $this->configFactory->get('lettermint.settings');
    $headers = array_change_key_case($message['headers'] ?? [], CASE_LOWER);

    // The configured sender wins over the message 'from': Lettermint only
    // accepts senders on a verified domain, and Drupal unconditionally sets
    // 'from' to the site email address, which is not necessarily on one.
    $sender_email = (string) $config->get('sender_email');
    $sender_name = (string) $config->get('sender_name');
    $original_from = (string) ($headers['from'] ?? $message['from'] ?? '');
    if ($sender_email !== '') {
      $from = $sender_name !== '' ? $this->formatAddress($sender_name, $sender_email) : $sender_email;
    }
    else {
      $from = $original_from;
    }

    $payload = [
      'from' => $from,
      'to' => $this->parseAddressList((string) $message['to']),
      'subject' => (string) $message['subject'],
    ];

    $body = (string) $message['body'];
    if ($this->isHtml($message)) {
      $payload['html'] = $body;
      $payload['text'] = MailFormatHelper::htmlToText($body);
    }
    else {
      $payload['text'] = $body;
    }

    foreach (['cc', 'bcc'] as $field) {
      if (!empty($headers[$field])) {
        $payload[$field] = $this->parseAddressList((string) $headers[$field]);
      }
    }

    // Keep replies flowing to the address the message was composed with, since
    // the sender may have been replaced by the configured one above.
    $reply_to = (string) ($headers['reply-to'] ?? '');
    if ($reply_to === '' && $original_from !== '' && $original_from !== $from) {
      $reply_to = $original_from;
    }
    if ($reply_to !== '') {
      $payload['reply_to'] = $this->parseAddressList($reply_to);
    }

    $custom_headers = [];
    foreach ($message['headers'] ?? [] as $name => $value) {
      if (!in_array(strtolower((string) $name), self::RESERVED_HEADERS, TRUE) && is_scalar($value) && (string) $value !== '') {
        $custom_headers[(string) $name] = (string) $value;
      }
    }
    if ($custom_headers) {
      $payload['headers'] = $custom_headers;
    }

    $route = (string) $config->get('route');
    if ($route !== '') {
      $payload['route'] = $route;
    }

    if ($config->get('tag_with_mail_id') && !empty($message['id'])) {
      $tag = $this->slugify((string) $message['id']);
      if ($tag !== '') {
        $payload['tag'] = $tag;
      }
    }

    $attachments = $this->buildAttachments($message['params']['attachments'] ?? []);
    if ($attachments) {
      $payload['attachments'] = $attachments;
    }

    $options = $message['params']['lettermint'] ?? [];
    if (is_array($options)) {
      $payload = array_merge($payload, array_intersect_key($options, array_flip(self::PASSTHROUGH_OPTIONS)));
    }

    return $payload;
  }

  /**
   * Whether the message has an HTML body, based on its Content-Type header.
   */
  public function isHtml(array $message): bool {
    $headers = array_change_key_case($message['headers'] ?? [], CASE_LOWER);
    return stripos((string) ($headers['content-type'] ?? ''), 'text/html') !== FALSE;
  }

  /**
   * Splits a mail header value into a list of individual addresses.
   *
   * @param string $addresses
   *   One or more addresses, separated by commas. Addresses may contain a
   *   display name, for example: 'Jane Doe <jane@example.com>'.
   *
   * @return string[]
   *   The individual addresses.
   */
  public function parseAddressList(string $addresses): array {
    // Only split on commas that are not part of a quoted display name.
    $parts = preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $addresses) ?: [];
    return array_values(array_filter(array_map('trim', $parts), static fn(string $part): bool => $part !== ''));
  }

  /**
   * Formats an address with a display name, quoting the name when needed.
   */
  protected function formatAddress(string $name, string $email): string {
    if (preg_match('/[^\w \-.]/u', $name)) {
      $name = '"' . addcslashes($name, '"\\') . '"';
    }
    return "$name <$email>";
  }

  /**
   * Converts Drupal attachments into Lettermint attachments.
   */
  protected function buildAttachments(mixed $attachments): array {
    if (!is_array($attachments)) {
      return [];
    }

    $result = [];
    foreach ($attachments as $attachment) {
      if (!is_array($attachment)) {
        continue;
      }
      if (isset($attachment['filecontent'])) {
        $content = (string) $attachment['filecontent'];
      }
      elseif (!empty($attachment['filepath']) && is_readable($attachment['filepath'])) {
        $content = (string) file_get_contents($attachment['filepath']);
      }
      else {
        continue;
      }

      $item = [
        'filename' => (string) ($attachment['filename'] ?? basename((string) ($attachment['filepath'] ?? 'attachment'))),
        'content' => base64_encode($content),
      ];
      if (!empty($attachment['filemime'])) {
        $item['content_type'] = (string) $attachment['filemime'];
      }
      if (!empty($attachment['cid'])) {
        $item['content_id'] = (string) $attachment['cid'];
      }
      $result[] = $item;
    }
    return $result;
  }

  /**
   * Converts a mail ID like 'user_password_reset' into a tag slug.
   */
  protected function slugify(string $value): string {
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($value)) ?? '';
    return substr(trim($slug, '-'), 0, 255);
  }

}
