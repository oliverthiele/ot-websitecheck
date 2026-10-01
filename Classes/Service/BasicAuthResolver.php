<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

/**
 * Resolves HTTP Basic Auth credentials from a "user:password" option value,
 * falling back to the environment variables <prefix>_USER and <prefix>_PASS.
 *
 * Each variable is read from $_ENV first, then with getenv(). Both are needed:
 * vlucas/phpdotenv without the putenv adapter only populates $_ENV, while the
 * real environment of the process — export, a cron entry, `op run` — only
 * reaches $_ENV when variables_order contains "E", which php.ini-production
 * leaves out.
 */
class BasicAuthResolver
{
    /**
     * @return array<string, mixed> Guzzle request options: an "auth" entry, or nothing if no credentials are configured.
     */
    public function buildRequestOptions(string $credentials, string $environmentPrefix): array
    {
        if ($credentials !== '' && str_contains($credentials, ':')) {
            [$user, $password] = explode(':', $credentials, 2);
            return ['auth' => [$user, $password]];
        }
        if ($environmentPrefix === '') {
            return [];
        }

        $user = $this->readEnvironment($environmentPrefix . '_USER');
        $password = $this->readEnvironment($environmentPrefix . '_PASS');
        if ($user !== '' && $password !== '') {
            return ['auth' => [$user, $password]];
        }

        return [];
    }

    private function readEnvironment(string $name): string
    {
        $value = $_ENV[$name] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }
        $value = getenv($name);

        return is_string($value) ? $value : '';
    }
}
