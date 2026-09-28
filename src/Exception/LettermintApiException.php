<?php

declare(strict_types=1);

namespace Drupal\lettermint\Exception;

/**
 * Thrown when a Lettermint API request fails.
 */
class LettermintApiException extends \RuntimeException {

  /**
   * Constructs a LettermintApiException.
   *
   * @param string $message
   *   The error message.
   * @param int $statusCode
   *   The HTTP status code, or 0 when no response was received.
   * @param array $errors
   *   Validation errors keyed by field, as reported by the API.
   * @param \Throwable|null $previous
   *   The previous exception.
   */
  public function __construct(
    string $message,
    protected int $statusCode = 0,
    protected array $errors = [],
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, $statusCode, $previous);
  }

  /**
   * Returns the HTTP status code, or 0 when no response was received.
   */
  public function getStatusCode(): int {
    return $this->statusCode;
  }

  /**
   * Returns the validation errors keyed by field, as reported by the API.
   */
  public function getErrors(): array {
    return $this->errors;
  }

  /**
   * Whether the same request may succeed when it is retried later.
   *
   * This covers network failures, rate limiting and server errors, but also
   * authentication failures: those are configuration problems, and the mail
   * should be kept until the token is fixed rather than being discarded.
   */
  public function isRetryable(): bool {
    return $this->statusCode === 0
      || in_array($this->statusCode, [401, 403, 408, 409, 429], TRUE)
      || $this->statusCode >= 500;
  }

}
