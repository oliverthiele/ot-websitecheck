<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Domain\ValueObject;

use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\ResponseTimeoutException;

/**
 * Why a request ended without a usable HTTP answer.
 *
 * Only failures of the transport are worth another attempt. An HTTP answer is
 * never classified here: with "http_errors" off, a 500 arrives as a response,
 * and it is a result that has to stay visible.
 */
enum TransferFailure: string
{
    /**
     * Connected, but the answer did not arrive in time — typically a page that
     * renders slowly, e.g. right after a cache flush.
     */
    case Timeout = 'timeout';

    /**
     * No answer at all: refused, unresolvable, TLS failure, connection reset.
     * A connect timeout belongs here too — a server that does not even accept
     * the connection is unreachable, not slow.
     */
    case Network = 'network';

    /**
     * Anything else, e.g. an invalid URL or too many redirects. Another
     * attempt would fail the same way.
     */
    case Request = 'request';

    public static function fromThrowable(\Throwable $throwable): self
    {
        if ($throwable instanceof NetworkTimeoutException || $throwable instanceof ResponseTimeoutException) {
            return self::Timeout;
        }
        // Includes ConnectException and its ConnectTimeoutException.
        if ($throwable instanceof NetworkException) {
            return self::Network;
        }

        return self::Request;
    }

    public function isRetryable(): bool
    {
        return $this !== self::Request;
    }
}
