<?php

return [
    'ctrl' => [
        'title' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_sitemapsnapshot',
        'label' => 'label',
        'label_alt' => 'start_url',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'default_sortby' => 'fetched_at DESC',
        'rootLevel' => 1,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '1' => ['showitem' => 'label, note, --div--;LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_sitemapsnapshot.tab.source, start_url, fetched_at, status'],
    ],
    'columns' => [
        'label' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_sitemapsnapshot.label',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 100,
                'readOnly' => true,
            ],
        ],
        'start_url' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_sitemapsnapshot.start_url',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 2048,
                'readOnly' => true,
            ],
        ],
        'fetched_at' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_sitemapsnapshot.fetched_at',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
                'searchable' => false,
            ],
        ],
        'status' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_sitemapsnapshot.status',
            'config' => [
                'type' => 'input',
                'size' => 20,
                'max' => 20,
                'readOnly' => true,
            ],
        ],
        'note' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_sitemapsnapshot.note',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 5,
                'eval' => 'trim',
            ],
        ],
    ],
];
