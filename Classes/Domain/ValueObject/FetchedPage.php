<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Domain\ValueObject;

final readonly class FetchedPage
{
    /**
     * @param int $httpStatus 0 when no response arrived at all.
     * @param TransferFailure|null $transferFailure why no response arrived; null when one did.
     */
    public function __construct(
        public int $httpStatus,
        public string $body,
        public ?TransferFailure $transferFailure = null,
    ) {
    }

    public function isConnectionError(): bool
    {
        return $this->httpStatus === 0;
    }

    public function isTimeout(): bool
    {
        return $this->transferFailure === TransferFailure::Timeout;
    }

    public function isRetryable(): bool
    {
        return $this->transferFailure?->isRetryable() ?? false;
    }

    public function isOk(): bool
    {
        return $this->httpStatus === 200;
    }
}
