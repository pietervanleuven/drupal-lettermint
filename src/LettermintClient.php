<?php

declare(strict_types=1);

namespace Drupal\lettermint;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\lettermint\Exception\LettermintApiException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Client for the Lettermint Sending API.
 *
 * @see https://lettermint.co/docs/api-reference/sending/send
 */
class LettermintClient {

  public const BASE_URL = 'https://api.lettermint.co/v1';

  /**
   * The maximum number of messages accepted by a single batch request.
   */
  public const MAX_BATCH_SIZE = 500;

  /**
   * The maximum payload size of a request, in bytes.
   */
  public const MAX_PAYLOAD_BYTES = 25 * 1024 * 1024;

  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Whether an API token has been configured.
   */
  public function isConfigured(): bool {
    return $this->getToken() !== '';
  }

  /**
   * Sends a single email.
   *
   * @param array $payload
   *   The message, as expected by the /send endpoint.
   * @param string|null $idempotencyKey
   *   An optional key that prevents the message from being sent twice when
   *   the request is retried.
   *
   * @return array
   *   The response, containing 'message_id' and 'status'.
   *
   * @throws \Drupal\lettermint\Exception\LettermintApiException
   */
  public function send(array $payload, ?string $idempotencyKey = NULL): array {
    return $this->request('POST', 'send', $payload, $idempotencyKey);
  }

  /**
   * Sends multiple emails in a single request.
   *
   * @param array[] $payloads
   *   Up to 500 messages, each as expected by the /send endpoint.
   * @param string|null $idempotencyKey
   *   An optional key that prevents the batch from being sent twice when the
   *   request is retried.
   *
   * @return array[]
   *   A response per message, in the same order as the messages, each
   *   containing 'message_id' and 'status'.
   *
   * @throws \Drupal\lettermint\Exception\LettermintApiException
   */
  public function sendBatch(array $payloads, ?string $idempotencyKey = NULL): array {
    if ($payloads === []) {
      return [];
    }
    if (count($payloads) > self::MAX_BATCH_SIZE) {
      throw new \InvalidArgumentException(sprintf('A batch may contain at most %d messages.', self::MAX_BATCH_SIZE));
    }
    return $this->request('POST', 'send/batch', array_values($payloads), $idempotencyKey);
  }

  /**
   * Checks that the API is reachable and the token is accepted.
   *
   * @throws \Drupal\lettermint\Exception\LettermintApiException
   */
  public function ping(): void {
    $this->request('GET', 'ping');
  }

  /**
   * Performs an API request.
   *
   * @throws \Drupal\lettermint\Exception\LettermintApiException
   */
  protected function request(string $method, string $path, ?array $json = NULL, ?string $idempotencyKey = NULL): array {
    $token = $this->getToken();
    if ($token === '') {
      throw new LettermintApiException('The Lettermint API token is not configured.', 401);
    }

    $options = [
      'headers' => [
        'Accept' => 'application/json',
        'x-lettermint-token' => $token,
      ],
      'timeout' => 30,
    ];
    if ($json !== NULL) {
      $options['json'] = $json;
    }
    if ($idempotencyKey !== NULL) {
      $options['headers']['Idempotency-Key'] = $idempotencyKey;
    }

    try {
      $response = $this->httpClient->request($method, self::BASE_URL . '/' . $path, $options);
    }
    catch (RequestException $e) {
      $response = $e->getResponse();
      if ($response === NULL) {
        throw new LettermintApiException('Lettermint API request failed: ' . $e->getMessage(), 0, [], $e);
      }
      $body = json_decode((string) $response->getBody(), TRUE);
      $message = is_array($body) && isset($body['message']) ? (string) $body['message'] : $response->getReasonPhrase();
      $errors = is_array($body) && isset($body['errors']) && is_array($body['errors']) ? $body['errors'] : [];
      throw new LettermintApiException(
        sprintf('Lettermint API returned %d: %s', $response->getStatusCode(), $message),
        $response->getStatusCode(),
        $errors,
        $e,
      );
    }
    catch (GuzzleException $e) {
      throw new LettermintApiException('Lettermint API request failed: ' . $e->getMessage(), 0, [], $e);
    }

    $body = (string) $response->getBody();
    if ($body === '') {
      return [];
    }
    $data = json_decode($body, TRUE);
    // The ping endpoint answers with plain text.
    return is_array($data) ? $data : ['response' => $body];
  }

  /**
   * Returns the configured API token.
   */
  protected function getToken(): string {
    return trim((string) $this->configFactory->get('lettermint.settings')->get('api_token'));
  }

}
