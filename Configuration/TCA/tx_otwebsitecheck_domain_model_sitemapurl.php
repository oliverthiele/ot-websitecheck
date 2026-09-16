<?php

return [
    'ctrl' => [
        'title' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_sitemapurl',
        'label' => 'url',
        'rootLevel' => 1,
        'hideTable' => true,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '1' => ['showitem' => 'snapshot, document, language, sitemap_group, url, path, lastmod'],
    ],
    'columns' => [
        'snapshot' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_sitemapurl.snapshot',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'tx_otwebsitecheck_domain_model_sitemapsnapshot',
                'readOnly' => true,
            ],
        ],
        'document' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_sitemapurl.document',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'tx_otwebsitecheck_domain_model_sitemapdocument',
                'readOnly' => true,
            ],
        ],
        'language' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_sitemapurl.language',
            'config' => [
                'type' => 'input',
                'size' => 10,
                'max' => 35,
                'readOnly' => true,
            ],
        ],
        'sitemap_group' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_sitemapurl.sitemap_group',
            'config' => [
                'type' => 'input',
                'size' => 20,
                'max' => 100,
                'readOnly' => true,
            ],
        ],
        'url' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_sitemapurl.url',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 2048,
                'readOnly' => true,
            ],
        ],
        'path' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_sitemapurl.path',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 2048,
                'readOnly' => true,
                'searchable' => false,
            ],
        ],
        'lastmod' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_sitemapurl.lastmod',
            'config' => [
                'type' => 'input',
                'size' => 25,
                'max' => 40,
                'readOnly' => true,
                'searchable' => false,
            ],
        ],
    ],
];
