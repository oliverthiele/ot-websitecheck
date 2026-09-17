<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Exception;

/**
 * An export or import of sitemap snapshots that cannot be carried out; the
 * message says why and is meant for the person running the command.
 */
final class SnapshotArchiveException extends \RuntimeException
{
}
