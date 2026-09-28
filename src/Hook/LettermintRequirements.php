<?php

declare(strict_types=1);

namespace Drupal\lettermint\Hook;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\lettermint\LettermintClient;
use Drupal\lettermint\QueueProcessor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reports the Lettermint configuration and queue status.
 *
 * @phpstan-consistent-constructor
 */
class LettermintRequirements implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * The queue size above which a warning is shown.
   */
  protected const QUEUE_WARNING_THRESHOLD = 1000;

  public function __construct(
    protected LettermintClient $client,
    protected QueueProcessor $queueProcessor,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('lettermint.client'),
      $container->get('lettermint.queue_processor'),
    );
  }

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    $requirements = [];

    if (!$this->client->isConfigured()) {
      $requirements['lettermint_token'] = [
        'title' => $this->t('Lettermint'),
        'value' => $this->t('Not configured'),
        'description' => $this->t('Configure the Lettermint API token on the <a href=":url">settings page</a>.', [
          ':url' => Url::fromRoute('lettermint.settings')->toString(),
        ]),
        'severity' => $this->severity('error'),
      ];
    }

    $queued = $this->queueProcessor->count();
    $requirements['lettermint_queue'] = [
      'title' => $this->t('Lettermint mail queue'),
      'value' => $this->formatPlural($queued, '1 message queued', '@count messages queued'),
      'severity' => $this->severity($queued > self::QUEUE_WARNING_THRESHOLD ? 'warning' : 'ok'),
    ];
    if ($queued > self::QUEUE_WARNING_THRESHOLD) {
      $requirements['lettermint_queue']['description'] = $this->t('Queued mail is sent on cron. Make sure cron runs frequently, or run <code>drush lettermint:send-queue</code>.');
    }

    return $requirements;
  }

  /**
   * Returns a requirement severity that works on all supported core versions.
   *
   * The RequirementSeverity enum was added in Drupal 11.2, and the
   * REQUIREMENT_* constants are removed in Drupal 12.
   *
   * @param string $level
   *   One of 'ok', 'warning' or 'error'.
   */
  protected function severity(string $level): mixed {
    if (class_exists(RequirementSeverity::class)) {
      return match ($level) {
        'error' => RequirementSeverity::Error,
        'warning' => RequirementSeverity::Warning,
        default => RequirementSeverity::OK,
      };
    }
    return constant('REQUIREMENT_' . strtoupper($level));
  }

}
