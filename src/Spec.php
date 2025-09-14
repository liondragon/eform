<?php
declare(strict_types=1);

namespace EForms;

/**
 * Registry describing built-in field types.
 */
class Spec
{
    private const HANDLERS_DEFAULT = [
        'validator_id' => '',
        'normalizer_id' => '',
        'renderer_id' => '',
    ];
    private const HANDLERS_TEXT = [
        'validator_id' => 'text',
        'normalizer_id' => 'text',
        'renderer_id' => 'text',
    ];
    private const HANDLERS_EMAIL = [
        'validator_id' => 'email',
        'normalizer_id' => 'email',
        'renderer_id' => 'email',
    ];
    private const HANDLERS_URL = [
        'validator_id' => 'url',
        'normalizer_id' => 'url',
        'renderer_id' => 'url',
    ];
    private const HANDLERS_TEL = [
        'validator_id' => 'tel',
        'normalizer_id' => 'tel',
        'renderer_id' => 'tel',
    ];
    private const HANDLERS_TEL_US = [
        'validator_id' => 'tel_us',
        'normalizer_id' => 'tel_us',
        'renderer_id' => 'tel_us',
    ];
    private const HANDLERS_NUMBER = [
        'validator_id' => 'number',
        'normalizer_id' => 'number',
        'renderer_id' => 'number',
    ];
    private const HANDLERS_RANGE = [
        'validator_id' => 'range',
        'normalizer_id' => 'range',
        'renderer_id' => 'range',
    ];
    private const HANDLERS_DATE = [
        'validator_id' => 'date',
        'normalizer_id' => 'date',
        'renderer_id' => 'date',
    ];
    private const HANDLERS_TEXTAREA = [
        'validator_id' => 'textarea',
        'normalizer_id' => 'textarea',
        'renderer_id' => 'textarea',
    ];
    private const HANDLERS_TEXTAREA_HTML = [
        'validator_id' => 'textarea_html',
        'normalizer_id' => 'textarea_html',
        'renderer_id' => 'textarea_html',
    ];
    private const HANDLERS_ZIP = [
        'validator_id' => 'zip',
        'normalizer_id' => 'zip',
        'renderer_id' => 'zip',
    ];
    private const HANDLERS_ZIP_US = [
        'validator_id' => 'zip_us',
        'normalizer_id' => 'zip_us',
        'renderer_id' => 'zip_us',
    ];
    private const HANDLERS_SELECT = [
        'validator_id' => 'select',
        'normalizer_id' => 'select',
        'renderer_id' => 'select',
    ];
    private const HANDLERS_RADIO = [
        'validator_id' => 'radio',
        'normalizer_id' => 'radio',
        'renderer_id' => 'radio',
    ];
    private const HANDLERS_CHECKBOX = [
        'validator_id' => 'checkbox',
        'normalizer_id' => 'checkbox',
        'renderer_id' => 'checkbox',
    ];
    private const HANDLERS_FILE = [
        'validator_id' => 'file',
        'normalizer_id' => 'file',
        'renderer_id' => 'file',
    ];
    private const HANDLERS_FILES = [
        'validator_id' => 'files',
        'normalizer_id' => 'files',
        'renderer_id' => 'files',
    ];

    /**
     * Return descriptors for all built-in field types.
     */
    public static function typeDescriptors(): array
    {
        return [
            'name' => [
                'type' => 'name',
                'is_multivalue' => false,
                'html' => ['tag'=>'input','type'=>'text','autocomplete'=>'name'],
                'constants' => [],
                'validate' => [],
                'handlers' => self::HANDLERS_TEXT,
            ],
            'first_name' => [
                'type' => 'first_name',
                'is_multivalue' => false,
                'html' => ['tag'=>'input','type'=>'text','autocomplete'=>'given-name'],
                'constants' => [],
                'validate' => [],
                'handlers' => self::HANDLERS_TEXT,
            ],
            'last_name' => [
                'type' => 'last_name',
                'is_multivalue' => false,
                'html' => ['tag'=>'input','type'=>'text','autocomplete'=>'family-name'],
                'constants' => [],
                'validate' => [],
                'handlers' => self::HANDLERS_TEXT,
            ],
            'text' => [
                'type' => 'text',
                'is_multivalue' => false,
                'html' => ['tag'=>'input','type'=>'text','attrs_mirror'=>['maxlength'=>null,'minlength'=>null]],
                'constants' => [],
                'validate' => [],
                'handlers' => self::HANDLERS_TEXT,
            ],
            'email' => [
                'type' => 'email',
                'is_multivalue' => false,
                'html' => [
                    'tag'=>'input',
                    'type'=>'email',
                    'inputmode'=>'email',
                    'autocomplete'=>'email',
                    'attrs_mirror'=>['maxlength'=>null,'minlength'=>null],
                ],
                'constants' => [
                    'spellcheck' => 'false',
                    'autocapitalize' => 'off',
                ],
                'validate' => [],
                'handlers' => self::HANDLERS_EMAIL,
            ],
            'url' => [
                'type' => 'url',
                'is_multivalue' => false,
                'html' => [
                    'tag'=>'input',
                    'type'=>'url',
                    'attrs_mirror'=>['maxlength'=>null,'minlength'=>null],
                ],
                'constants' => [
                    'spellcheck' => 'false',
                    'autocapitalize' => 'off',
                ],
                'validate' => [],
                'handlers' => self::HANDLERS_URL,
            ],
            'tel' => [
                'type' => 'tel',
                'is_multivalue' => false,
                'html' => ['tag'=>'input','type'=>'tel','inputmode'=>'tel','autocomplete'=>'tel','attrs_mirror'=>['maxlength'=>null]],
                'constants' => [],
                'validate' => [],
                'handlers' => self::HANDLERS_TEL,
            ],
            'tel_us' => [
                'type' => 'tel_us',
                'is_multivalue' => false,
                'html' => [
                    'tag'=>'input',
                    'type'=>'tel',
                    'inputmode'=>'tel',
                    'pattern'=>'\\d{3}-?\\d{3}-?\\d{4}',
                    'autocomplete'=>'tel',
                    'attrs_mirror'=>['maxlength'=>null],
                ],
                'constants' => [],
                'validate' => [],
                'handlers' => self::HANDLERS_TEL_US,
            ],
            'number' => [
                'type' => 'number',
                'is_multivalue' => false,
                'html' => ['tag'=>'input','type'=>'number','inputmode'=>'decimal','attrs_mirror'=>['min'=>null,'max'=>null,'step'=>null]],
                'constants' => [],
                'validate' => [],
                'handlers' => self::HANDLERS_NUMBER,
            ],
            'range' => [
                'type' => 'range',
                'is_multivalue' => false,
                'html' => ['tag'=>'input','type'=>'range','inputmode'=>'decimal','attrs_mirror'=>['min'=>null,'max'=>null,'step'=>null]],
                'constants' => [],
                'validate' => [],
                'handlers' => self::HANDLERS_RANGE,
            ],
            'date' => [
                'type' => 'date',
                'is_multivalue' => false,
                'html' => ['tag'=>'input','type'=>'date','attrs_mirror'=>['min'=>null,'max'=>null,'step'=>null]],
                'constants' => [],
                'validate' => [],
                'handlers' => self::HANDLERS_DATE,
            ],
            'textarea' => [
                'type' => 'textarea',
                'is_multivalue' => false,
                'html' => ['tag'=>'textarea','attrs_mirror'=>['maxlength'=>null,'minlength'=>null]],
                'constants' => [],
                'validate' => [],
                'handlers' => self::HANDLERS_TEXTAREA,
            ],
            'textarea_html' => [
                'type' => 'textarea_html',
                'is_multivalue' => false,
                'html' => ['tag'=>'textarea','attrs_mirror'=>['maxlength'=>null,'minlength'=>null]],
                'constants' => [],
                'validate' => [],
                'handlers' => self::HANDLERS_TEXTAREA_HTML,
            ],
            'zip' => [
                'type' => 'zip',
                'is_multivalue' => false,
                'html' => ['tag'=>'input','type'=>'text','attrs_mirror'=>['maxlength'=>null,'minlength'=>null]],
                'constants' => [],
                'validate' => [],
                'handlers' => self::HANDLERS_ZIP,
            ],
            'zip_us' => [
                'type' => 'zip_us',
                'is_multivalue' => false,
                'html' => [
                    'tag'=>'input',
                    'type'=>'text',
                    'inputmode'=>'numeric',
                    'pattern'=>'\\d{5}',
                    'autocomplete'=>'postal-code',
                    'attrs_mirror'=>['maxlength'=>5],
                ],
                'constants' => [],
                'validate' => ['pattern'=>'/^\\d{5}$/'],
                'handlers' => self::HANDLERS_ZIP_US,
            ],
            'select' => [
                'type' => 'select',
                'is_multivalue' => false,
                'html' => ['tag'=>'select'],
                'constants' => [],
                'validate' => [],
                'handlers' => self::HANDLERS_SELECT,
            ],
            'radio' => [
                'type' => 'radio',
                'is_multivalue' => false,
                'html' => ['tag'=>'fieldset'],
                'constants' => [],
                'validate' => [],
                'handlers' => self::HANDLERS_RADIO,
            ],
            'checkbox' => [
                'type' => 'checkbox',
                'is_multivalue' => true,
                'html' => ['tag'=>'fieldset'],
                'constants' => [],
                'validate' => [],
                'handlers' => self::HANDLERS_CHECKBOX,
            ],
            'file' => [
                'type' => 'file',
                'is_multivalue' => false,
                'html' => ['tag'=>'input','type'=>'file'],
                'constants' => [],
                'validate' => [],
                'handlers' => self::HANDLERS_FILE,
            ],
            'files' => [
                'type' => 'files',
                'is_multivalue' => true,
                'html' => ['tag'=>'input','type'=>'file','multiple'=>true],
                'constants' => [],
                'validate' => [],
                'handlers' => self::HANDLERS_FILES,
            ],
        ];
    }

    /**
     * Returns descriptor for field type.
     */
    public static function descriptorFor(string $type): array
    {
        $all = self::typeDescriptors();
        return $all[$type] ?? [
            'type' => $type,
            'is_multivalue' => false,
            'html' => ['tag'=>'input'],
            'constants' => [],
            'validate' => [],
            'handlers' => self::HANDLERS_DEFAULT,
        ];
    }

    /**
     * Map accept tokens for file controls.
     * Each token maps to MIME types and allowed extensions.
     */
    public static function acceptTokenMap(): array
    {
        return [
            'image' => [
                'image/jpeg' => ['jpg','jpeg'],
                'image/png'  => ['png'],
                'image/gif'  => ['gif'],
                'image/webp' => ['webp'],
            ],
            'pdf' => [
                'application/pdf' => ['pdf'],
            ],
        ];
    }
}

