<?php

declare(strict_types=1);

namespace Drupal\lettermint\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\lettermint\Batch\QueueBatch;
use Drupal\lettermint\Exception\LettermintApiException;
use Drupal\lettermint\LettermintClient;
use Drupal\lettermint\MessageBuilder;
use Drupal\lettermint\QueueProcessor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Lettermint settings.
 */
class LettermintSettingsForm extends ConfigFormBase {

  /**
   * The Lettermint API client.
   */
  protected LettermintClient $client;

  /**
   * The message builder.
   */
  protected MessageBuilder $messageBuilder;

  /**
   * The queue processor.
   */
  protected QueueProcessor $queueProcessor;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->client = $container->get('lettermint.client');
    $instance->messageBuilder = $container->get('lettermint.message_builder');
    $instance->queueProcessor = $container->get('lettermint.queue_processor');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['lettermint.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lettermint_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    if ($this->config('system.mail')->get('interface.default') !== 'lettermint_mail') {
      $form['mail_system'] = [
        '#type' => 'container',
        '#markup' => $this->t('Lettermint is not the default mail system. Set <code>$config[\'system.mail\'][\'interface\'][\'default\'] = \'lettermint_mail\';</code> in settings.php, or use the <a href="https://www.drupal.org/project/mailsystem">Mail System</a> module.'),
        '#attributes' => ['class' => ['messages', 'messages--warning']],
      ];
    }

    $form['api_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Project API token'),
      '#description' => $this->t('The API token of your Lettermint project, found under <em>Projects &gt; your project &gt; API</em> in the <a href="https://dash.lettermint.co">Lettermint dashboard</a>. To keep it out of the database and config exports, set <code>$config[\'lettermint.settings\'][\'api_token\']</code> in settings.php instead.'),
      '#config_target' => 'lettermint.settings:api_token',
      '#required' => TRUE,
      '#maxlength' => 256,
    ];

    $form['sender'] = [
      '#type' => 'details',
      '#title' => $this->t('Sender'),
      '#open' => TRUE,
    ];
    $form['sender']['sender_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sender name'),
      '#config_target' => 'lettermint.settings:sender_name',
    ];
    $form['sender']['sender_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Sender email'),
      '#description' => $this->t('Replaces the sender of all outgoing mail, and must be on a domain verified in Lettermint. The original sender is used as Reply-To address. Leave empty to keep the sender of each message.'),
      '#config_target' => 'lettermint.settings:sender_email',
    ];
    $form['sender']['route'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Route'),
      '#description' => $this->t('The Lettermint route to send through, for example <em>outgoing</em> or <em>broadcast</em>. Leave empty to use the default route of the project.'),
      '#config_target' => 'lettermint.settings:route',
    ];
    $form['sender']['tag_with_mail_id'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Tag messages with their Drupal mail ID'),
      '#description' => $this->t('For example <em>user-password-reset</em>, to filter messages in the Lettermint dashboard.'),
      '#config_target' => 'lettermint.settings:tag_with_mail_id',
    ];

    $queued = $this->queueProcessor->count();
    $form['delivery'] = [
      '#type' => 'details',
      '#title' => $this->t('Delivery'),
      '#open' => TRUE,
    ];
    $form['delivery']['delivery_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Delivery mode'),
      '#options' => [
        'direct' => $this->t('Send immediately'),
        'queue' => $this->t('Queue, and send in batches on cron'),
      ],
      'direct' => [
        '#description' => $this->t('Every message is sent with its own API request while the page is handled.'),
      ],
      'queue' => [
        '#description' => $this->t('Messages are sent in batches using the Lettermint batch API when cron runs, or with <code>drush lettermint:send-queue</code>. This keeps page requests fast and is best for sites that send many messages.'),
      ],
      '#config_target' => 'lettermint.settings:delivery_mode',
    ];
    $form['delivery']['queue_on_failure'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Queue messages for a retry when sending fails temporarily'),
      '#description' => $this->t('For example when Lettermint cannot be reached, or rate limits the site.'),
      '#config_target' => 'lettermint.settings:queue_on_failure',
      '#states' => [
        'visible' => [':input[name="delivery_mode"]' => ['value' => 'direct']],
      ],
    ];
    $form['delivery']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Messages per batch request'),
      '#min' => 1,
      '#max' => LettermintClient::MAX_BATCH_SIZE,
      '#config_target' => 'lettermint.settings:batch_size',
    ];
    $form['delivery']['cron_time_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Time limit per cron run'),
      '#field_suffix' => $this->t('seconds'),
      '#min' => 1,
      '#config_target' => 'lettermint.settings:cron_time_limit',
    ];
    $form['delivery']['queue_status'] = [
      '#type' => 'item',
      '#title' => $this->t('Queued messages'),
      '#markup' => (string) $queued,
    ];

    $form['webhook'] = [
      '#type' => 'details',
      '#title' => $this->t('Webhooks'),
      '#open' => (bool) $this->config('lettermint.settings')->get('webhook_secret'),
    ];
    $form['webhook']['webhook_url'] = [
      '#type' => 'item',
      '#title' => $this->t('Webhook URL'),
      '#markup' => '<code>' . Url::fromRoute('lettermint.webhook', [], ['absolute' => TRUE])->toString() . '</code>',
      '#description' => $this->t('Add this URL as a webhook in the Lettermint dashboard. Verified deliveries are dispatched as the <code>lettermint.webhook</code> event, and bounces and spam complaints are logged.'),
    ];
    $form['webhook']['webhook_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Signing secret'),
      '#description' => $this->t('The signing secret shown when creating the webhook. The webhook URL is disabled while this is empty.'),
      '#config_target' => 'lettermint.settings:webhook_secret',
      '#maxlength' => 256,
    ];

    $form['test'] = [
      '#type' => 'details',
      '#title' => $this->t('Test'),
      '#open' => TRUE,
    ];
    $form['test']['test_recipient'] = [
      '#type' => 'email',
      '#title' => $this->t('Test recipient'),
      '#default_value' => $this->currentUser()->getEmail() ?: $this->config('system.site')->get('mail'),
    ];

    $form = parent::buildForm($form, $form_state);

    $form['actions']['send_test'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save and send test mail'),
      '#submit' => ['::submitForm', '::sendTestMail'],
      '#weight' => 10,
    ];
    if ($queued > 0) {
      $form['actions']['send_queue'] = [
        '#type' => 'submit',
        '#value' => $this->t('Send queued mail now'),
        '#submit' => ['::sendQueue'],
        '#limit_validation_errors' => [],
        '#weight' => 20,
      ];
    }

    return $form;
  }

  /**
   * Submit handler for the test mail button.
   *
   * The test mail is always sent immediately, also in queue mode, so the
   * result can be reported right away.
   */
  public function sendTestMail(array &$form, FormStateInterface $form_state): void {
    $recipient = (string) $form_state->getValue('test_recipient');
    if ($recipient === '') {
      $this->messenger()->addError($this->t('Enter a test recipient.'));
      return;
    }

    $message = $this->messageBuilder->format([
      'id' => 'lettermint_test',
      'module' => 'lettermint',
      'key' => 'test',
      'to' => $recipient,
      'from' => $this->config('system.site')->get('mail'),
      'subject' => (string) $this->t('Lettermint Drupal test mail'),
      'body' => [
        (string) $this->t('This is a test email sent from your Drupal site using the Lettermint module.'),
      ],
      'headers' => [
        'Content-Type' => 'text/plain; charset=UTF-8',
      ],
      'params' => [],
    ]);

    try {
      $response = $this->client->send($this->messageBuilder->build($message));
      $this->messenger()->addStatus($this->t('Test mail sent to @recipient (message ID @id, status @status).', [
        '@recipient' => $recipient,
        '@id' => $response['message_id'] ?? '-',
        '@status' => $response['status'] ?? '-',
      ]));
    }
    catch (LettermintApiException $e) {
      $this->messenger()->addError($this->t('Sending the test mail failed: @message', ['@message' => $e->getMessage()]));
      foreach ($e->getErrors() as $field => $errors) {
        $this->messenger()->addError($field . ': ' . implode(' ', (array) $errors));
      }
    }
  }

  /**
   * Submit handler for the send queue button.
   */
  public function sendQueue(array &$form, FormStateInterface $form_state): void {
    batch_set(QueueBatch::build());
  }

}
