<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Unit\Service;

use OliverThiele\OtWebsitecheck\Domain\Model\Observation;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\PageIdentity;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\RedirectChain;
use OliverThiele\OtWebsitecheck\Service\MigrationAnalyzer;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The verdicts are what an editor acts on before a relaunch: a wrong "ok" hides
 * a lost ranking, a wrong "missing" sends someone chasing a redirect that works.
 */
final class MigrationAnalyzerTest extends UnitTestCase
{
    private MigrationAnalyzer $subject;

    private int $nextUid = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new MigrationAnalyzer();
    }

    #[Test]
    public function samePathSamePageIsOk(): void
    {
        $reference = $this->reference('/about/', new PageIdentity(10, 'en'));
        $target = $this->target('/about/', [['url' => 'https://target.example.com/about/', 'status' => 200]], new PageIdentity(10, 'en'));

        self::assertSame(MigrationAnalyzer::VERDICT_OK, $this->verdictOf($target, [$reference, $target]));
    }

    #[Test]
    public function permanentRedirectToSamePageIsMovedWithRedirect(): void
    {
        $reference = $this->reference('/old/', new PageIdentity(10, 'en'));
        $target = $this->target('/old/', [
            ['url' => 'https://target.example.com/old/', 'status' => 301],
            ['url' => 'https://target.example.com/new/', 'status' => 200],
        ], new PageIdentity(10, 'en'));

        $result = $this->subject->analyze([$reference, $target])[$target->uid];

        self::assertSame(MigrationAnalyzer::VERDICT_MOVED_WITH_REDIRECT, $result['verdict']);
        self::assertSame([], $result['warnings']);
    }

    #[Test]
    public function notFoundWithoutRedirectIsMissing(): void
    {
        $reference = $this->reference('/gone/', new PageIdentity(10, 'en'));
        $target = $this->target('/gone/', [['url' => 'https://target.example.com/gone/', 'status' => 404]]);

        self::assertSame(MigrationAnalyzer::VERDICT_MISSING, $this->verdictOf($target, [$reference, $target]));
    }

    #[Test]
    public function redirectEndingInAnErrorIsRedirectBroken(): void
    {
        $reference = $this->reference('/old/', new PageIdentity(10, 'en'));
        $target = $this->target('/old/', [
            ['url' => 'https://target.example.com/old/', 'status' => 301],
            ['url' => 'https://target.example.com/new/', 'status' => 404],
        ]);

        self::assertSame(MigrationAnalyzer::VERDICT_REDIRECT_BROKEN, $this->verdictOf($target, [$reference, $target]));
    }

    #[Test]
    public function redirectLoopIsRedirectBroken(): void
    {
        $reference = $this->reference('/old/', new PageIdentity(10, 'en'));
        $target = $this->target('/old/', [
            ['url' => 'https://target.example.com/old/', 'status' => 301],
            ['url' => 'https://target.example.com/other/', 'status' => 301],
        ], new PageIdentity(), RedirectChain::ABORT_LOOP);

        self::assertSame(MigrationAnalyzer::VERDICT_REDIRECT_BROKEN, $this->verdictOf($target, [$reference, $target]));
    }

    #[Test]
    public function redirectToADifferentRecordIsOtherContent(): void
    {
        $reference = $this->reference('/product/a', new PageIdentity(20, 'en', 'tx_myextension_domain_model_item', 1));
        $target = $this->target('/product/a', [
            ['url' => 'https://target.example.com/product/a', 'status' => 307],
            ['url' => 'https://target.example.com/product/b', 'status' => 200],
        ], new PageIdentity(20, 'en', 'tx_myextension_domain_model_item', 2));

        $result = $this->subject->analyze([$reference, $target])[$target->uid];

        self::assertSame(MigrationAnalyzer::VERDICT_OTHER_CONTENT, $result['verdict']);
        self::assertContains(MigrationAnalyzer::WARNING_TEMPORARY_REDIRECT, $result['warnings']);
    }

    #[Test]
    public function languageChangeIsOtherContentWithWarning(): void
    {
        $reference = $this->reference('/de/about/', new PageIdentity(10, 'de'));
        $target = $this->target('/de/about/', [['url' => 'https://target.example.com/de/about/', 'status' => 200]], new PageIdentity(10, 'en'));

        $result = $this->subject->analyze([$reference, $target])[$target->uid];

        self::assertSame(MigrationAnalyzer::VERDICT_OTHER_CONTENT, $result['verdict']);
        self::assertContains(MigrationAnalyzer::WARNING_LANGUAGE_CHANGED, $result['warnings']);
    }

    #[Test]
    public function missingMarkersOnTargetGiveIdentityUnknown(): void
    {
        $reference = $this->reference('/about/', new PageIdentity(10, 'en'));
        $target = $this->target('/about/', [['url' => 'https://target.example.com/about/', 'status' => 200]], new PageIdentity());

        self::assertSame(MigrationAnalyzer::VERDICT_IDENTITY_UNKNOWN, $this->verdictOf($target, [$reference, $target]));
    }

    #[Test]
    public function brokenReferenceIsNotCountedAsProblem(): void
    {
        $reference = $this->reference('/broken/', new PageIdentity(), 500);
        $target = $this->target('/broken/', [['url' => 'https://target.example.com/broken/', 'status' => 500]]);

        self::assertSame(MigrationAnalyzer::VERDICT_REFERENCE_NOT_OK, $this->verdictOf($target, [$reference, $target]));
    }

    #[Test]
    public function timedOutTargetIsNotReportedAsMissing(): void
    {
        $reference = $this->reference('/slow/', new PageIdentity(10, 'en'));
        $target = $this->target('/slow/', [['url' => 'https://target.example.com/slow/', 'status' => 0]], new PageIdentity(), RedirectChain::ABORT_TIMEOUT);

        self::assertSame(MigrationAnalyzer::VERDICT_TIMEOUT, $this->verdictOf($target, [$reference, $target]));
    }

    #[Test]
    public function deepUrlRedirectingToStartPageIsWarned(): void
    {
        $reference = $this->reference('/products/', new PageIdentity(10, 'en'));
        $target = $this->target('/products/', [
            ['url' => 'https://target.example.com/products/', 'status' => 307],
            ['url' => 'https://target.example.com/', 'status' => 200],
        ], new PageIdentity(1, 'en'));

        $warnings = $this->subject->analyze([$reference, $target])[$target->uid]['warnings'];

        self::assertContains(MigrationAnalyzer::WARNING_REDIRECT_TO_ROOT_PAGE, $warnings);
        self::assertContains(MigrationAnalyzer::WARNING_TEMPORARY_REDIRECT, $warnings);
    }

    #[Test]
    public function missingUrlGetsTheUnambiguousPathOfTheSamePageAsSuggestion(): void
    {
        $reference = $this->reference('/old/', new PageIdentity(10, 'en'));
        $target = $this->target('/old/', [['url' => 'https://target.example.com/old/', 'status' => 404]]);
        $listedOnTarget = $this->observation(Observation::ROLE_TARGET_SITEMAP, 'target-sitemap', '/new/', [['url' => 'https://target.example.com/new/', 'status' => 200]], new PageIdentity(10, 'en'));

        $result = $this->subject->analyze([$reference, $target, $listedOnTarget])[$target->uid];

        self::assertSame(MigrationAnalyzer::VERDICT_MISSING, $result['verdict']);
        self::assertSame('/new/', $result['suggestedTarget']);
    }

    #[Test]
    public function noSuggestionWhenSeveralPathsRenderTheSamePage(): void
    {
        $reference = $this->reference('/detail/a', new PageIdentity(20, 'en'));
        $target = $this->target('/detail/a', [['url' => 'https://target.example.com/detail/a', 'status' => 404]]);
        $first = $this->observation(Observation::ROLE_TARGET_SITEMAP, 'target-sitemap', '/detail/b', [['url' => 'https://target.example.com/detail/b', 'status' => 200]], new PageIdentity(20, 'en'));
        $second = $this->observation(Observation::ROLE_TARGET_SITEMAP, 'target-sitemap', '/detail/c', [['url' => 'https://target.example.com/detail/c', 'status' => 200]], new PageIdentity(20, 'en'));

        $result = $this->subject->analyze([$reference, $target, $first, $second])[$target->uid];

        self::assertSame('', $result['suggestedTarget']);
    }

    #[Test]
    public function sameRecordOnTwoPagesIsDuplicateDetailPage(): void
    {
        $record = ['tx_myextension_domain_model_item', 5];
        $first = $this->observation(Observation::ROLE_TARGET_SITEMAP, 'target-sitemap', '/one/item', [['url' => 'https://target.example.com/one/item', 'status' => 200]], new PageIdentity(30, 'en', ...$record));
        $second = $this->observation(Observation::ROLE_TARGET_SITEMAP, 'target-sitemap', '/two/item', [['url' => 'https://target.example.com/two/item', 'status' => 200]], new PageIdentity(31, 'en', ...$record));

        $results = $this->subject->analyze([$first, $second]);

        self::assertContains(MigrationAnalyzer::WARNING_DUPLICATE_DETAIL_PAGE, $results[$first->uid]['warnings']);
        self::assertContains(MigrationAnalyzer::WARNING_DUPLICATE_DETAIL_PAGE, $results[$second->uid]['warnings']);
    }

    /**
     * @param list<Observation> $observations
     */
    private function verdictOf(Observation $observation, array $observations): string
    {
        return $this->subject->analyze($observations)[$observation->uid]['verdict'];
    }

    private function reference(string $path, PageIdentity $identity, int $status = 200): Observation
    {
        return $this->observation(Observation::ROLE_REFERENCE, 'reference', $path, [['url' => 'https://www.example.com' . $path, 'status' => $status]], $identity);
    }

    /**
     * @param list<array{url: string, status: int}> $chain
     */
    private function target(string $path, array $chain, PageIdentity $identity = new PageIdentity(), string $abortReason = RedirectChain::ABORT_NONE): Observation
    {
        return $this->observation(Observation::ROLE_TARGET, 'target', $path, $chain, $identity, $abortReason);
    }

    /**
     * Built through RedirectChain, so final status, final path and hop count
     * follow the same rules as in a real run.
     *
     * @param list<array{url: string, status: int}> $chain
     */
    private function observation(string $role, string $environment, string $path, array $chain, PageIdentity $identity, string $abortReason = RedirectChain::ABORT_NONE): Observation
    {
        $redirectChain = new RedirectChain($chain, '', $abortReason);
        $finalPath = parse_url($redirectChain->getFinalUrl(), PHP_URL_PATH);

        return new Observation(
            uid: $this->nextUid++,
            runLabel: 'test',
            environment: $environment,
            role: $role,
            sitemapGroup: 'pages',
            requestedUrl: $redirectChain->getRequestedUrl(),
            requestedPath: $path,
            firstStatus: $redirectChain->getFirstStatus(),
            finalUrl: $redirectChain->getFinalUrl(),
            finalPath: is_string($finalPath) ? $finalPath : '',
            finalStatus: $redirectChain->getFinalStatus(),
            hopCount: $redirectChain->getHopCount(),
            redirectChain: $chain,
            abortReason: $abortReason,
            identity: $identity,
        );
    }
}
