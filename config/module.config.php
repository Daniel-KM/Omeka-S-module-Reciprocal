<?php declare(strict_types=1);
namespace Reciprocal;

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\ConfigForm::class => Form\ConfigForm::class,
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'reciprocal' => [
        'config' => [
            'reciprocal_reciprocities' => [
                'dcterms:hasFormat' => 'dcterms:isFormatOf',
                'dcterms:hasPart' => 'dcterms:isPartOf',
                'dcterms:hasVersion' => 'dcterms:isVersionOf',
                'dcterms:requires' => 'dcterms:isRequiredBy',
                'dcterms:references' => 'dcterms:isReferencedBy',
                'dcterms:relation' => 'dcterms:relation',
                'dcterms:replaces' => 'dcterms:isReplacedBy',
                'bibo:cites' => 'bibo:citedBy',
                'bibo:presents' => 'bibo:presentedAt',
            ],
        ],
    ],
];
