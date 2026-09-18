<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Domain\ValueObject;

/**
 * An archive file in the configured archive directory.
 */
final readonly class ArchiveFile
{
    public function __construct(
        public string $name,
        public string $path,
        public int $size,
        public int $modifiedAt,
    ) {
    }
}
