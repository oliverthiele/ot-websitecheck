<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Exception;

/**
 * Thrown from the Guzzle progress callback to abort a download that exceeds
 * ResponseSizeLimit::MAXIMUM_BYTES. Guzzle hands it on as the previous
 * exception of the RequestException it raises.
 */
final class ResponseTooLargeException extends \RuntimeException
{
}
