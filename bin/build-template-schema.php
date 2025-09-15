#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../src/TemplateSpec.php';
require __DIR__ . '/../src/Spec.php';

use EForms\TemplateSpec;
use EForms\Spec;

$schema = [
    '$schema' => 'http://json-schema.org/draft-07/schema#',
    'type' => 'object',
    'required' => array_keys(TemplateSpec::rootRequired()),
    'additionalProperties' => false,
    'properties' => [
        '$schema' => ['type' => 'string'],
        'id' => ['type' => 'string'],
        'version' => ['type' => ['string','number']],
        'title' => ['type' => 'string'],
        'submit_button_text' => ['type' => 'string'],
        'success' => [
            'type' => 'object',
            'required' => TemplateSpec::successSpec()['required'],
            'properties' => [
                'mode' => ['enum' => TemplateSpec::successSpec()['enums']['mode']],
                'redirect_url' => ['type' => 'string'],
                'message' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ],
        'email' => [
            'type' => 'object',
            'required' => TemplateSpec::emailSpec()['required'],
            'properties' => [
                'display_format_tel' => ['enum' => TemplateSpec::emailSpec()['enums']['display_format_tel']],
                'to' => ['type' => 'string', 'minLength' => 1],
                'subject' => ['type' => 'string', 'minLength' => 1],
                'email_template' => ['type' => 'string'],
                'include_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'additionalProperties' => false,
        ],
        'fields' => [
            'type' => 'array',
            'items' => ['$ref' => '#/definitions/field'],
        ],
        'rules' => [
            'type' => 'array',
            'items' => ['$ref' => '#/definitions/rule'],
        ],
    ],
    'definitions' => [
        'field' => [
            'type' => 'object',
            'required' => ['type'],
            'additionalProperties' => false,
            'properties' => [
                'type' => ['enum' => TemplateSpec::fieldAllowedTypes()],
                'key' => ['type' => 'string'],
                'label' => ['type' => 'string'],
                'required' => ['type' => 'boolean'],
                'options' => ['type' => 'array', 'items' => ['$ref' => '#/definitions/option']],
                'multiple' => ['type' => 'boolean'],
                'accept' => ['type' => 'array', 'items' => ['type' => 'string']],
                'before_html' => ['type' => 'string'],
                'after_html' => ['type' => 'string'],
                'class' => ['type' => 'string'],
                'placeholder' => ['type' => 'string'],
                'autocomplete' => ['type' => 'string'],
                'size' => ['type' => 'integer'],
                'max_length' => ['type' => 'integer'],
                'min' => ['type' => ['number','string']],
                'max' => ['type' => ['number','string']],
                'pattern' => ['type' => 'string'],
                'email_attach' => ['type' => 'boolean'],
                'max_file_bytes' => ['type' => 'integer'],
                'max_files' => ['type' => 'integer'],
                'step' => ['type' => ['number','string']],
                'mode' => ['enum' => TemplateSpec::rowGroupSpec()['enums']['mode']],
                'tag' => ['enum' => TemplateSpec::rowGroupSpec()['enums']['tag']],
            ],
            'allOf' => [
                [
                    'if' => [
                        'properties' => ['type' => ['enum' => ['file','files']]],
                        'required' => ['type'],
                    ],
                    'then' => [
                        'properties' => ['accept' => ['type' => 'array', 'items' => ['type' => 'string']]],
                    ],
                ],
                [
                    'if' => [
                        'properties' => ['type' => ['enum' => ['files']]],
                        'required' => ['type'],
                    ],
                    'then' => [
                        'properties' => ['max_files' => ['type' => 'integer', 'minimum' => 1]],
                    ],
                ],
                [
                    'if' => [
                        'properties' => ['type' => ['enum' => TemplateSpec::sizeAllowedTypes()]],
                        'required' => ['type'],
                    ],
                    'then' => [
                        'properties' => ['size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
                    ],
                    'else' => [
                        'properties' => ['size' => false],
                    ],
                ],
                [
                    'if' => [
                        'properties' => ['email_attach' => new stdClass()],
                        'required' => ['email_attach'],
                    ],
                    'then' => [
                        'properties' => ['type' => ['enum' => ['file','files']]],
                    ],
                ],
            ],
        ],
        'option' => [
            'type' => 'object',
            'required' => ['key','label'],
            'additionalProperties' => false,
            'properties' => [
                'key' => ['type' => 'string'],
                'label' => ['type' => 'string'],
                'disabled' => ['type' => 'boolean'],
            ],
        ],
        'rule' => [
            'type' => 'object',
            'required' => ['rule'],
            'additionalProperties' => false,
            'properties' => [
                'rule' => ['enum' => TemplateSpec::ruleTypes()],
                'target' => ['type' => 'string'],
                'field' => ['type' => 'string'],
                'fields' => ['type' => 'array', 'items' => ['type' => 'string']],
                'equals' => ['type' => 'string'],
                'equals_any' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ],
    ],
];

file_put_contents(__DIR__ . '/../schema/template.schema.json', json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
