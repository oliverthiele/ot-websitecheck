<?php

declare(strict_types=1);

defined('TYPO3') or die();

// What a project adds to mark pages that show content only with a parameter.
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('pages', [
    'tx_websitecheckfixture_requires_parameter' => [
        'label' => 'Requires a parameter',
        'config' => [
            'type' => 'check',
        ],
    ],
]);
