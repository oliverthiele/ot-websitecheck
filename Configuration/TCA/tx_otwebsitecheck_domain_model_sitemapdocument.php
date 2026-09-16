<?php

return [
    'ctrl' => [
        'title' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_sitemapdocument',
        'label' => 'url',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'rootLevel' => 1,
        'hideTable' => true,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '1' => ['showitem' => 'snapshot, language, url, parent_url, sitemap_group, document_type, http_status, body'],
    ],
    'columns' => [
        'snapshot' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_sitemapdocument.snapshot',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'tx_otwebsitecheck_domain_model_sitemapsnapshot',
                'readOnly' => true,
            ],
        ],
        'language' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_sitemapdocument.language',
            'config' => [
                'type' => 'input',
                'size' => 10,
                'max' => 35,
                'readOnly' => true,
            ],
        ],
        'url' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_sitemapdocument.url',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 2048,
                'readOnly' => true,
            ],
        ],
        'parent_url' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_sitemapdocument.parent_url',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 2048,
                'readOnly' => true,
            ],
        ],
        'sitemap_group' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_sitemapdocument.sitemap_group',
            'config' => [
                'type' => 'input',
                'size' => 20,
                'max' => 100,
                'readOnly' => true,
            ],
        ],
        'document_type' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_sitemapdocument.document_type',
            'config' => [
                'type' => 'input',
                'size' => 20,
                'max' => 20,
                'readOnly' => true,
            ],
        ],
        'http_status' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_sitemapdocument.http_status',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'body' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_sitemapdocument.body',
            'config' => [
                'type' => 'text',
                'cols' => 80,
                'rows' => 20,
                'readOnly' => true,
                'searchable' => false,
            ],
        ],
    ],
];
