<?php

return [
    'ctrl' => [
        'title' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_check',
        'label' => 'url',
        'label_alt' => 'environment,http_status',
        'label_alt_force' => true,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'default_sortby' => 'checked_at DESC',
        'rootLevel' => 1,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '1' => ['showitem' => 'page_uid, url, path, environment, source, http_status, error_marker, checked_at, reviewed, note'],
    ],
    'columns' => [
        'page_uid' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_check.page_uid',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'url' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_check.url',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'path' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_check.path',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'readOnly' => true,
                'searchable' => false,
            ],
        ],
        'environment' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_check.environment',
            'config' => [
                'type' => 'input',
                'size' => 20,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'source' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_check.source',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'readOnly' => true,
                'searchable' => false,
            ],
        ],
        'http_status' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_check.http_status',
            'config' => [
                'type' => 'number',
            ],
        ],
        'error_marker' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_check.error_marker',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'readOnly' => true,
            ],
        ],
        'checked_at' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_check.checked_at',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
                'searchable' => false,
            ],
        ],
        'reviewed' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_check.reviewed',
            'config' => [
                'type' => 'check',
                'default' => 0,
            ],
        ],
        'note' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_check.note',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 5,
                'eval' => 'trim',
            ],
        ],
    ],
];
