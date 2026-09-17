<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Domain\ValueObject;

/**
 * The kind of environment a sitemap snapshot was taken from. A fixed set, so
 * the module can suggest which snapshots to compare — the state on live as
 * reference, the one on staging as target.
 */
enum SnapshotEnvironment: string
{
    case Live = 'live';
    case Staging = 'staging';
    case Development = 'development';
    case Local = 'local';

    /**
     * Preference as a migration check target: the environment closest to the
     * next go-live first.
     */
    public const array TARGET_ORDER = [self::Staging, self::Development, self::Local];

    /**
     * The environment a TYPO3 application context stands for, e.g.
     * "Production/Staging" or "Development/Local".
     */
    public static function fromApplicationContext(string $applicationContext): self
    {
        $parts = array_map('strtolower', explode('/', $applicationContext));
        if ($parts[0] === 'production') {
            return array_intersect($parts, ['staging', 'stage']) !== [] ? self::Staging : self::Live;
        }

        return array_intersect($parts, ['local', 'ddev']) !== [] ? self::Local : self::Development;
    }

    /**
     * The environment a site base condition names, e.g.
     * `applicationContext == "Development/Local"`. Other conditions name none.
     */
    public static function fromBaseCondition(string $condition): ?self
    {
        if (preg_match('/^\s*applicationContext\s*==\s*["\']([A-Za-z]+(?:\/[A-Za-z0-9]+)*)["\']\s*$/', $condition, $matches) !== 1) {
            return null;
        }
        $context = $matches[1];
        if (!in_array(strtolower(explode('/', $context)[0]), ['production', 'development'], true)) {
            return null;
        }

        return self::fromApplicationContext($context);
    }
}
