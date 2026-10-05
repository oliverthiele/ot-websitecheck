<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Functional\Service;

use OliverThiele\OtWebsitecheck\Domain\ValueObject\PageIdentity;
use OliverThiele\OtWebsitecheck\Service\RequiredParameterPages;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A detail page called without a record is reported as such instead of as a
 * missing page — but a detail URL with its record must never be.
 */
final class RequiredParameterPagesTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'oliverthiele/ot-websitecheck',
        __DIR__ . '/../Fixtures/Extensions/websitecheck_fixture',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'ot_websitecheck' => [
                'requiresParameterField' => 'tx_websitecheckfixture_requires_parameter',
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Pages.csv');
    }

    #[Test]
    public function markedPagesAndTheirTranslationsAreRead(): void
    {
        $markedPageUids = $this->createSubject()->getMarkedPageUids();
        ksort($markedPageUids);

        self::assertSame([2 => true, 3 => true], $markedPageUids);
    }

    #[Test]
    public function markedPageWithoutArgumentsIsCalledWithoutParameter(): void
    {
        $subject = $this->createSubject();

        self::assertTrue($subject->isCalledWithoutParameter(new PageArguments(2, '0', []), new PageIdentity()));
        self::assertTrue($subject->isCalledWithoutParameter(new PageArguments(2, '0', [], [], ['cHash' => 'abc']), new PageIdentity()));
    }

    #[Test]
    public function markedPageWithARecordIsNotCalledWithoutParameter(): void
    {
        $subject = $this->createSubject();

        self::assertFalse($subject->isCalledWithoutParameter(new PageArguments(2, '0', ['tx_myextension_show' => ['item' => '5']]), new PageIdentity()));
        self::assertFalse($subject->isCalledWithoutParameter(new PageArguments(2, '0', [], [], ['tx_myextension_show' => ['item' => '5']]), new PageIdentity()));
    }

    #[Test]
    public function unmarkedPageIsNeverReported(): void
    {
        self::assertFalse($this->createSubject()->isCalledWithoutParameter(new PageArguments(4, '0', []), new PageIdentity(4)));
    }

    #[Test]
    public function pathUnknownToTheLocalRoutingFallsBackToTheRenderedPage(): void
    {
        $subject = $this->createSubject();

        self::assertTrue($subject->isCalledWithoutParameter(null, new PageIdentity(2, 'en')));
        self::assertFalse($subject->isCalledWithoutParameter(null, new PageIdentity(2, 'en', 'tx_myextension_domain_model_item', 5)));
        self::assertFalse($subject->isCalledWithoutParameter(null, new PageIdentity()));
    }

    #[Test]
    public function fieldThePagesDoNotHaveSwitchesTheCheckOff(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ot_websitecheck']['requiresParameterField'] = 'no_such_field';

        $subject = $this->createSubject();

        self::assertSame([], $subject->getMarkedPageUids());
        self::assertFalse($subject->isCalledWithoutParameter(new PageArguments(2, '0', []), new PageIdentity()));
    }

    private function createSubject(): RequiredParameterPages
    {
        return new RequiredParameterPages(
            $this->get(ExtensionConfiguration::class),
            $this->get(TcaSchemaFactory::class),
            $this->get(ConnectionPool::class),
        );
    }
}
