<?php

/**
 * HTTP response value object for the plugin's transport layer.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugin\MusicBrainz;

/**
 * The outcome of one transport GET: status code, body, and the few response
 * headers the API layer needs (ETag, Retry-After).
 *
 * The transport previously returned only `?string` — the HTTP status was
 * destroyed at the boundary, so a 404 (genuinely "not found", cacheable), a
 * 429/503 (rate limited — the caller must back off), and a curl failure
 * (transient — must not be cached as "not found") were indistinguishable
 * upstream, and MusicBrainz's ETag/Retry-After signals were simply dropped.
 *
 * `status === null` means the request never completed at the transport level;
 * a completed exchange always carries its code, including error codes.
 *
 * @package Phlix\Plugin\MusicBrainz
 */
final class HttpResponse
{
    /**
     * @param array<string, string> $headers Response headers with lowercase keys
     */
    public function __construct(
        public readonly ?int $status,
        public readonly ?string $body,
        public readonly array $headers = []
    ) {
    }

    /**
     * Bridge for body-only producers (legacy mocks, cached bodies): a string
     * reads as a successful 200, null as a transport failure.
     */
    public static function fromBody(?string $body): self
    {
        return $body === null ? self::failure() : new self(200, $body);
    }

    /**
     * The request never completed (DNS/connect/timeout/curl error).
     */
    public static function failure(): self
    {
        return new self(null, null);
    }

    /**
     * The transport aborted the exchange because the response body exceeded
     * the caller's declared `maxResponseBytes` budget. No body is delivered;
     * `isTooLarge()` reads this back. (A genuine upstream 413, if one ever
     * arrives, shares the reading — both shapes mean "refused for size".)
     */
    public static function contentTooLarge(): self
    {
        return new self(413, null);
    }

    public function isTooLarge(): bool
    {
        return $this->status === 413;
    }

    public function ok(): bool
    {
        return $this->status !== null && $this->status >= 200 && $this->status < 300;
    }

    public function notFound(): bool
    {
        return $this->status === 404;
    }

    public function notModified(): bool
    {
        return $this->status === 304;
    }

    /**
     * Rate-limit signals: 429 Too Many Requests and 503 Service Unavailable
     * (MusicBrainz uses 503 with Retry-After to shed load).
     */
    public function isRateLimited(): bool
    {
        return $this->status === 429 || $this->status === 503;
    }

    public function etag(): ?string
    {
        $etag = $this->headers['etag'] ?? null;

        return $etag !== null && $etag !== '' ? $etag : null;
    }

    /**
     * The Retry-After hint in whole seconds, accepting both forms (delay in
     * seconds and an HTTP date), or null when absent/unparseable.
     */
    public function retryAfterSeconds(): ?int
    {
        $value = trim($this->headers['retry-after'] ?? '');
        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int)$value;
        }

        $when = strtotime($value);
        if ($when === false) {
            return null;
        }

        return max(0, $when - time());
    }
}
