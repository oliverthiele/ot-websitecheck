<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use Doctrine\DBAL\ParameterType;
use OliverThiele\OtWebsitecheck\Domain\Repository\ObservationRepository;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\SnapshotEnvironment;
use OliverThiele\OtWebsitecheck\Exception\SnapshotArchiveException;

/**
 * The file format that carries sitemap snapshots and migration check runs
 * out of one database and into another: gzip-compressed JSON with a format
 * version.
 *
 * Records are identified by their uuid, never by uid — uids are only valid in
 * the database they came from. A run names its snapshots by uuid for the same
 * reason.
 *
 * decode() checks the whole structure, so an importer can rely on every value
 * having the documented type.
 *
 * @phpstan-type ArchivedDocument array{language: string, url: string, parentUrl: string, sitemapGroup: string, type: string, httpStatus: int, body: string, urls: list<array{url: string, lastmod: string}>}
 * @phpstan-type ArchivedSnapshot array{uuid: string, label: string, environment: string, startUrl: string, note: string, locked: bool, fetchedAt: int, documents: list<ArchivedDocument>}
 * @phpstan-type ArchivedRun array{uuid: string, label: string, referenceSnapshot: string, targetSnapshot: string, targetHost: string, startedAt: int, observations: list<array<string, int|string>>}
 * @phpstan-type Archive array{createdAt: int, snapshots: list<ArchivedSnapshot>, runs: list<ArchivedRun>}
 */
class SnapshotArchive
{
    public const string FORMAT = 'ot-websitecheck-archive';
    public const int VERSION = 1;

    /**
     * @param Archive $archive
     */
    public function encode(array $archive): string
    {
        $json = json_encode(
            ['format' => self::FORMAT, 'version' => self::VERSION] + $archive,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $compressed = gzencode($json, 9);
        if ($compressed === false) {
            throw new SnapshotArchiveException('The archive could not be compressed.', 1789490001);
        }

        return $compressed;
    }

    /**
     * @return Archive
     * @throws SnapshotArchiveException when the content is no archive of a supported version
     */
    public function decode(string $content): array
    {
        $json = @gzdecode($content);
        if ($json === false) {
            throw new SnapshotArchiveException('The file is not gzip-compressed.', 1789490002);
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new SnapshotArchiveException('The file contains no valid JSON: ' . $exception->getMessage(), 1789490003, $exception);
        }
        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT) {
            throw new SnapshotArchiveException('The file is no Website Check archive.', 1789490004);
        }
        if (($data['version'] ?? null) !== self::VERSION) {
            throw new SnapshotArchiveException(sprintf(
                'The archive has format version %s; this version of the extension reads version %d.',
                is_scalar($data['version'] ?? null) ? (string)$data['version'] : 'unknown',
                self::VERSION,
            ), 1789490005);
        }

        return [
            'createdAt' => $this->int($data, 'createdAt', 'archive'),
            'snapshots' => array_map($this->decodeSnapshot(...), $this->list($data, 'snapshots', 'archive')),
            'runs' => array_map($this->decodeRun(...), $this->list($data, 'runs', 'archive')),
        ];
    }

    /**
     * @return ArchivedSnapshot
     */
    private function decodeSnapshot(mixed $snapshot): array
    {
        $snapshot = $this->record($snapshot, 'snapshot');
        $context = 'snapshot "' . $this->string($snapshot, 'label', 'snapshot') . '"';

        return [
            'uuid' => $this->uuid($snapshot, 'uuid', $context),
            'label' => $this->string($snapshot, 'label', $context),
            // Added after the first archives were written; they have none.
            'environment' => $this->environment($snapshot, $context),
            'startUrl' => $this->string($snapshot, 'startUrl', $context),
            'note' => $this->string($snapshot, 'note', $context),
            'locked' => $this->bool($snapshot, 'locked', $context),
            'fetchedAt' => $this->int($snapshot, 'fetchedAt', $context),
            'documents' => array_map(
                fn(mixed $document): array => $this->decodeDocument($document, $context),
                $this->list($snapshot, 'documents', $context),
            ),
        ];
    }

    /**
     * @return ArchivedDocument
     */
    private function decodeDocument(mixed $document, string $context): array
    {
        $document = $this->record($document, $context);
        $context .= ', sitemap ' . $this->string($document, 'url', $context);

        $urls = [];
        foreach ($this->list($document, 'urls', $context) as $entry) {
            $entry = $this->record($entry, $context);
            $urls[] = ['url' => $this->string($entry, 'url', $context), 'lastmod' => $this->string($entry, 'lastmod', $context)];
        }

        return [
            'language' => $this->string($document, 'language', $context),
            'url' => $this->string($document, 'url', $context),
            'parentUrl' => $this->string($document, 'parentUrl', $context),
            'sitemapGroup' => $this->string($document, 'sitemapGroup', $context),
            'type' => $this->string($document, 'type', $context),
            'httpStatus' => $this->int($document, 'httpStatus', $context),
            'body' => $this->string($document, 'body', $context),
            'urls' => $urls,
        ];
    }

    /**
     * @return ArchivedRun
     */
    private function decodeRun(mixed $run): array
    {
        $run = $this->record($run, 'run');
        $context = 'run "' . $this->string($run, 'label', 'run') . '"';

        $observations = [];
        foreach ($this->list($run, 'observations', $context) as $observation) {
            $observation = $this->record($observation, $context);
            $values = [];
            foreach (ObservationRepository::ROW_FIELDS as $field => $type) {
                $values[$field] = $type === ParameterType::INTEGER
                    ? $this->int($observation, $field, $context)
                    : $this->string($observation, $field, $context);
            }
            $observations[] = $values;
        }

        return [
            'uuid' => $this->uuid($run, 'uuid', $context),
            'label' => $this->string($run, 'label', $context),
            'referenceSnapshot' => $this->uuid($run, 'referenceSnapshot', $context),
            'targetSnapshot' => $this->optionalUuid($run, 'targetSnapshot', $context),
            'targetHost' => $this->string($run, 'targetHost', $context),
            'startedAt' => $this->int($run, 'startedAt', $context),
            'observations' => $observations,
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function record(mixed $value, string $context): array
    {
        if (!is_array($value)) {
            throw $this->invalid($context, 'an entry is not an object');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<mixed>
     */
    private function list(array $data, string $key, string $context): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->invalid($context, sprintf('"%s" is not a list', $key));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function string(array $data, string $key, string $context): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw $this->invalid($context, sprintf('"%s" is not a string', $key));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function int(array $data, string $key, string $context): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw $this->invalid($context, sprintf('"%s" is not an integer', $key));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function bool(array $data, string $key, string $context): bool
    {
        $value = $data[$key] ?? null;
        if (!is_bool($value)) {
            throw $this->invalid($context, sprintf('"%s" is not a boolean', $key));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function environment(array $data, string $context): string
    {
        if (!array_key_exists('environment', $data)) {
            return '';
        }
        $value = $this->string($data, 'environment', $context);
        if ($value !== '' && SnapshotEnvironment::tryFrom($value) === null) {
            throw $this->invalid($context, sprintf('"%s" is no known environment', $value));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function uuid(array $data, string $key, string $context): string
    {
        $value = $this->optionalUuid($data, $key, $context);
        if ($value === '') {
            throw $this->invalid($context, sprintf('"%s" is empty', $key));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function optionalUuid(array $data, string $key, string $context): string
    {
        $value = $this->string($data, $key, $context);
        if ($value !== '' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) !== 1) {
            throw $this->invalid($context, sprintf('"%s" is not a uuid', $key));
        }

        return $value;
    }

    private function invalid(string $context, string $problem): SnapshotArchiveException
    {
        return new SnapshotArchiveException(sprintf('The archive is damaged: %s in %s.', $problem, $context), 1789490006);
    }
}
