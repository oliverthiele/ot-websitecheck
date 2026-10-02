<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Unit\Service;

use OliverThiele\OtWebsitecheck\Service\ErrorMarkerDetector;
use OliverThiele\OtWebsitecheck\Service\FindingGuide;
use OliverThiele\OtWebsitecheck\Service\MigrationAnalyzer;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Who acts on a finding decides whether an editor wastes time on something
 * only the integrator can change.
 */
final class FindingGuideTest extends UnitTestCase
{
    #[Test]
    public function everyProblemVerdictNamesSomeoneWhoActs(): void
    {
        $subject = new FindingGuide();
        foreach (MigrationAnalyzer::PROBLEM_VERDICTS as $verdict) {
            $guide = $subject->forVerdict($verdict);
            self::assertNotSame(FindingGuide::ACTOR_NONE, $guide['actor'], $verdict);
            self::assertNotSame('', $guide['entry'], $verdict);
        }
    }

    #[Test]
    public function workingVerdictsNeedNobody(): void
    {
        $subject = new FindingGuide();

        self::assertSame(['actor' => FindingGuide::ACTOR_NONE, 'entry' => ''], $subject->forVerdict(MigrationAnalyzer::VERDICT_OK));
        self::assertSame(FindingGuide::ACTOR_NONE, $subject->forVerdict(MigrationAnalyzer::VERDICT_MOVED_WITH_REDIRECT)['actor']);
    }

    #[Test]
    public function redirectsAreFixedByTheEditorAndSitemapsByTheIntegrator(): void
    {
        $subject = new FindingGuide();

        self::assertSame(FindingGuide::ACTOR_EDITOR, $subject->forVerdict(MigrationAnalyzer::VERDICT_MISSING)['actor']);
        self::assertSame(FindingGuide::ACTOR_EDITOR, $subject->forWarning(MigrationAnalyzer::WARNING_CANONICAL_DIFFERS)['actor']);
        self::assertSame(FindingGuide::ACTOR_INTEGRATOR, $subject->forWarning(MigrationAnalyzer::WARNING_LISTED_URL_NOT_CANONICAL)['actor']);
        self::assertSame(FindingGuide::ACTOR_INTEGRATOR, $subject->forVerdict(MigrationAnalyzer::VERDICT_DETAIL_PAGE_WITHOUT_RECORD)['actor']);
    }

    #[Test]
    public function statusResultWithoutMarkerIsJudgedByItsStatus(): void
    {
        $subject = new FindingGuide();

        self::assertSame(FindingGuide::ACTOR_NONE, $subject->forStatusResult('', 200)['actor']);
        self::assertSame(FindingGuide::ACTOR_EDITOR, $subject->forStatusResult('', 404)['actor']);
        self::assertSame(FindingGuide::ACTOR_INTEGRATOR, $subject->forStatusResult('', 503)['actor']);
    }

    #[Test]
    public function exceptionClassIsAMarkerForTheIntegrator(): void
    {
        $guide = (new FindingGuide())->forStatusResult('TYPO3\\CMS\\Extbase\\Mvc\\Controller\\Exception\\RequiredArgumentMissingException', 500);

        self::assertSame(['actor' => FindingGuide::ACTOR_INTEGRATOR, 'entry' => 'errorPage'], $guide);
    }

    #[Test]
    public function markersAreFilteredByActor(): void
    {
        $markers = ['pageNotFound', ErrorMarkerDetector::MARKER_TIMEOUT, 'My\\Extension\\SomeException'];

        self::assertSame(['pageNotFound'], (new FindingGuide())->filterMarkers($markers, FindingGuide::ACTOR_EDITOR));
        self::assertSame([ErrorMarkerDetector::MARKER_TIMEOUT, 'My\\Extension\\SomeException'], (new FindingGuide())->filterMarkers($markers, FindingGuide::ACTOR_INTEGRATOR));
    }
}
