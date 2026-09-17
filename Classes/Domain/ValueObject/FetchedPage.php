<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Domain\ValueObject;

final readonly class FetchedPage
{
    /**
     * @param int $httpStatus 0 when no response arrived at all.
     */
    public function __construct(
        public int $httpStatus,
        public string $body,
    ) {
    }

    public function isConnectionError(): bool
    {
        return $this->httpStatus === 0;
    }

    public function isOk(): bool
    {
        return $this->httpStatus === 200;
    }
}
