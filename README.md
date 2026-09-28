# Lettermint

Send emails from Drupal using the [Lettermint](https://lettermint.co/) API.

Mail is sent over the Lettermint REST API instead of SMTP. Messages can be sent
immediately, or queued and sent in batches of up to 500 messages per request
using the Lettermint batch endpoint, which keeps page requests fast on sites
that send a lot of mail.

## Features

- A mail plugin for plain text and HTML mail. HTML mail also gets a plain text
  alternative.
- Two delivery modes:
  - **Send immediately.** Each message is sent with its own request. When
    Lettermint can't be reached or rate limits the site, the message is queued
    for a retry.
  - **Queue.** Messages are sent in batches through `/v1/send/batch` on cron,
    with `drush lettermint:send-queue`, or with the *Send queued mail now*
    button on the settings page, which uses the Batch API.
- Idempotency keys on every request, so retries never send a message twice.
- Temporary errors (network failures, rate limits, server errors, invalid
  tokens) keep the mail queued. When a batch is rejected, its messages are sent
  one by one, so only the invalid ones are dropped.
- Cc, Bcc, Reply-To, custom headers, attachments and inline images.
- Messages are tagged with their Drupal mail ID, like `user-password-reset`, so
  you can filter them in the Lettermint dashboard.
- Signed webhooks. Deliveries are verified and dispatched as a Symfony event.
  Bounces and spam complaints are logged.
- Status report entries for the configuration and the size of the queue.

## Requirements

- Drupal 10.3+ or 11
- A Lettermint project with a verified sending domain, and its API token

## Installation

Install the module as you would any Drupal module. For example using Composer:

```bash
composer require drupal/lettermint
drush en lettermint
```

## Configuration

1. Navigate to **Administration > Configuration > System > Lettermint Settings**
   (`/admin/config/system/lettermint`).
2. Enter the **Project API token**. To keep it out of the database and config
   exports, set it in `settings.php` instead:

   ```php
   $config['lettermint.settings']['api_token'] = getenv('LETTERMINT_TOKEN');
   ```

3. Optionally set a **Sender email** on a verified domain. It replaces the
   sender of all outgoing mail, and the original sender becomes the Reply-To
   address.
4. Choose a **Delivery mode**. In queue mode, make sure cron runs frequently.
5. Set Lettermint as the default mail system in `settings.php`:

   ```php
   $config['system.mail']['interface']['default'] = 'lettermint_mail';
   ```

   Alternatively, use the
   [Mail System](https://www.drupal.org/project/mailsystem) module to configure
   the mail plugin per module or mail key.

## Lettermint options per message

Pass options for the Lettermint API in `$params['lettermint']` when sending
mail, or set them in `hook_mail_alter()`. The supported options are `tag`,
`tags`, `metadata`, `route`, `scheduled_at` and `settings`:

```php
\Drupal::service('plugin.manager.mail')->mail('mymodule', 'order_shipped', $to, $langcode, [
  'order' => $order,
  'lettermint' => [
    'metadata' => ['order_id' => (string) $order->id()],
    'scheduled_at' => '2026-10-01T09:00:00+02:00',
  ],
]);
```

Attachments are read from `$params['attachments']`, using the same structure as
the Mime Mail and Symfony Mailer modules: each attachment has a `filename`, and
either a `filepath` or `filecontent`. It can also have a `filemime`, and a `cid`
for inline images.

## Sending batches from code

To send many different messages at once, for example a newsletter, you can call
the API client directly. It accepts up to 500 messages per call:

```php
$responses = \Drupal::service('lettermint.client')->sendBatch([
  ['from' => 'news@example.com', 'to' => ['a@example.com'], 'subject' => 'Hi A', 'html' => '<p>Hi</p>'],
  ['from' => 'news@example.com', 'to' => ['b@example.com'], 'subject' => 'Hi B', 'html' => '<p>Hi</p>'],
]);
```

You can also pass payloads to `\Drupal::service('lettermint.queue_processor')->enqueue()`.
Queued payloads are sent in batches on cron.

## Webhooks

1. In the Lettermint dashboard, add a webhook pointing to
   `https://your-site.example/lettermint/webhook`.
2. Copy its signing secret into the **Webhooks** section of the settings page.
   The endpoint stays disabled until a secret is set.

Every verified delivery is dispatched as a `LettermintWebhookEvent`. You can
subscribe to it, for example to block users whose address hard bounced:

```php
use Drupal\lettermint\Event\LettermintWebhookEvent;

public static function getSubscribedEvents(): array {
  return [LettermintWebhookEvent::NAME => 'onWebhook'];
}

public function onWebhook(LettermintWebhookEvent $event): void {
  if ($event->getType() === 'message.hard_bounced') {
    $recipient = $event->getData()['recipient'];
    // ...
  }
}
```

## Drush

| Command | Description |
|---|---|
| `drush lettermint:send-queue [--time-limit=60]` | Send queued mail in batches. Exits with an error when sending stops on a temporary failure. |

## Permissions

| Permission | Description |
|---|---|
| Administer Lettermint settings | Access the Lettermint configuration form. Restricted by default. |
