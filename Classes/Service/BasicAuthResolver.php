<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

/**
 * Resolves HTTP Basic Auth credentials from a "user:password" option value,
 * falling back to the environment variables <prefix>_USER and <prefix>_PASS.
 *
 * The fallback reads $_ENV, not getenv(): projects loading .env files via
 * vlucas/phpdotenv without the putenv adapter only ever populate
 * $_ENV/$_SERVER, so getenv() would stay empty.
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

        $user = $_ENV[$environmentPrefix . '_USER'] ?? null;
        $password = $_ENV[$environmentPrefix . '_PASS'] ?? null;
        if (is_string($user) && $user !== '' && is_string($password) && $password !== '') {
            return ['auth' => [$user, $password]];
        }

        return [];
    }
}
