<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Domain\ValueObject;

/**
 * Every response on the way from a requested URL to the final one.
 *
 * The first step is the requested URL itself, the last step the response that
 * ended the chain — a non-redirect status, or the redirect that could not be
 * followed any further.
 */
final readonly class RedirectChain
{
    public const string ABORT_NONE = '';
    public const string ABORT_LOOP = 'loop';
    public const string ABORT_HOP_LIMIT = 'hopLimit';
    public const string ABORT_MISSING_LOCATION = 'missingLocation';
    public const string ABORT_CONNECTION_ERROR = 'connectionError';
    public const string ABORT_TIMEOUT = 'timeout';

    /**
     * @param list<array{url: string, status: int}> $steps
     * @param TransferFailure|null $transferFailure set when a request of the chain got no response.
     */
    public function __construct(
        public array $steps,
        public string $finalBody,
        public string $abortReason = self::ABORT_NONE,
        public ?TransferFailure $transferFailure = null,
    ) {
    }

    /**
     * @param list<array{url: string, status: int}> $steps
     */
    public static function abortedByTransferFailure(array $steps, TransferFailure $transferFailure): self
    {
        return new self(
            $steps,
            '',
            $transferFailure === TransferFailure::Timeout ? self::ABORT_TIMEOUT : self::ABORT_CONNECTION_ERROR,
            $transferFailure,
        );
    }

    public function isRetryable(): bool
    {
        return $this->transferFailure?->isRetryable() ?? false;
    }

    public function getRequestedUrl(): string
    {
        return $this->steps[0]['url'] ?? '';
    }

    public function getFirstStatus(): int
    {
        return $this->steps[0]['status'] ?? 0;
    }

    public function getFinalUrl(): string
    {
        return $this->getLastStep()['url'] ?? '';
    }

    /**
     * 0 when the chain was aborted, so an aborted chain never passes as a
     * working page.
     */
    public function getFinalStatus(): int
    {
        if ($this->abortReason !== self::ABORT_NONE) {
            return 0;
        }
        return $this->getLastStep()['status'] ?? 0;
    }

    public function getHopCount(): int
    {
        return max(0, count($this->steps) - 1);
    }

    /**
     * @return array{url: string, status: int}|null
     */
    private function getLastStep(): ?array
    {
        return $this->steps === [] ? null : $this->steps[count($this->steps) - 1];
    }
}
