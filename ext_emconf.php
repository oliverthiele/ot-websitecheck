<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Website Check',
    'description' => 'Store TYPO3 XML sitemaps as snapshots, record HTTP status and error markers per URL, and check whether the URLs of a live site still lead to the same content after a relaunch.',
    'category' => 'misc',
    'author' => 'Oliver Thiele',
    'author_email' => 'mail@oliver-thiele.de',
    'state' => 'alpha',
    'version' => '0.4.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.3.0-14.3.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
    'autoload' => [
        'psr-4' => [
            'OliverThiele\\OtWebsitecheck\\' => 'Classes',
        ],
    ],
];
