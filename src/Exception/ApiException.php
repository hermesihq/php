<?php

declare(strict_types=1);

namespace Hermesi\Exception;

/**
 * Hermesi answered, and the answer was a refusal.
 *
 * Branch on the class or on `errorCode`, never on the message: the message is prose that the API rewrites without notice.
 * `errorCode` is stable. `requestId` is what to quote to whoever runs Hermesi. (`getCode()` is PHP's own integer and
 * stays 0; the API's code is a string, hence the other name.)
 */
class ApiException extends HermesiException
{
    /**
     * @param list<ErrorDetail> $details
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorType,
        public readonly string $errorCode,
        string $message,
        public readonly string $requestId = '',
        public readonly array $details = [],
        public readonly string $docUrl = '',
        /** Seconds the server asked to wait, from `Retry-After`; only on a 429. */
        public readonly ?float $retryAfter = null,
    ) {
        parent::__construct(\sprintf('%s: %s (HTTP %d, request %s)', $errorCode, $message, $status, '' === $requestId ? 'unknown' : $requestId));
    }

    /** True for a failure that sending the same request again later can fix. */
    public function isRetryable(): bool
    {
        return 429 === $this->status || $this->status >= 500;
    }

    /**
     * The exception for a non-2xx answer. Anything that is not the API's error envelope is reported as such, with `errorType`
     * `sdk_error` (the API never sends it), so a caller can tell a refusal from a response the SDK could not read.
     */
    public static function fromResponse(int $status, mixed $body, ?float $retryAfter): self
    {
        $envelope = \is_array($body) && isset($body['error']) && \is_array($body['error']) ? $body['error'] : [];
        $text = static fn (string $key): string => isset($envelope[$key]) && \is_string($envelope[$key]) ? $envelope[$key] : '';

        $details = [];
        if (isset($envelope['detail']) && \is_array($envelope['detail'])) {
            foreach ($envelope['detail'] as $item) {
                if (!\is_array($item)) {
                    continue;
                }
                $field = isset($item['field']) && \is_string($item['field']) && '' !== $item['field'] ? $item['field'] : null;
                $issue = isset($item['issue']) && \is_string($item['issue']) && '' !== $item['issue'] ? $item['issue'] : null;
                $details[] = new ErrorDetail($field, $issue);
            }
        }

        $class = match (true) {
            400 === $status, 422 === $status => ValidationException::class,
            401 === $status => AuthenticationException::class,
            403 === $status => ForbiddenException::class,
            404 === $status => NotFoundException::class,
            429 === $status => RateLimitException::class,
            $status >= 500 => ServerException::class,
            default => self::class,
        };

        return new $class(
            $status,
            '' !== $text('type') ? $text('type') : 'sdk_error',
            '' !== $text('code') ? $text('code') : 'unexpected_response',
            '' !== $text('message') ? $text('message') : \sprintf('Hermesi answered HTTP %d.', $status),
            $text('request_id'),
            $details,
            $text('doc_url'),
            $retryAfter,
        );
    }
}
