<?php
declare(strict_types=1);

namespace EForms;

/**
 * Registry describing built-in field types.
 */
class Spec
{
    private const REGISTRY = [
        'name' => [
            'is_multivalue' => false,
            'html' => ['tag'=>'input','type'=>'text'],
            'validate' => [],
        ],
        'first_name' => [
            'is_multivalue' => false,
            'html' => ['tag'=>'input','type'=>'text'],
            'validate' => [],
        ],
        'last_name' => [
            'is_multivalue' => false,
            'html' => ['tag'=>'input','type'=>'text'],
            'validate' => [],
        ],
        'text' => [
            'is_multivalue' => false,
            'html' => ['tag'=>'input','type'=>'text','attrs_mirror'=>['maxlength'=>null,'minlength'=>null]],
            'validate' => [],
        ],
        'email' => [
            'is_multivalue' => false,
            'html' => ['tag'=>'input','type'=>'email','inputmode'=>'email','attrs_mirror'=>[]],
            'validate' => [],
        ],
        'url' => [
            'is_multivalue' => false,
            'html' => ['tag'=>'input','type'=>'url','attrs_mirror'=>['maxlength'=>null,'minlength'=>null]],
            'validate' => [],
        ],
        'tel' => [
            'is_multivalue' => false,
            'html' => ['tag'=>'input','type'=>'tel','inputmode'=>'tel','attrs_mirror'=>['maxlength'=>null]],
            'validate' => [],
        ],
        'tel_us' => [
            'is_multivalue' => false,
            'html' => ['tag'=>'input','type'=>'tel','inputmode'=>'tel','attrs_mirror'=>['maxlength'=>null]],
            'validate' => [],
        ],
        'number' => [
            'is_multivalue' => false,
            'html' => ['tag'=>'input','type'=>'number','inputmode'=>'decimal','attrs_mirror'=>['min'=>null,'max'=>null,'step'=>null]],
            'validate' => [],
        ],
        'range' => [
            'is_multivalue' => false,
            'html' => ['tag'=>'input','type'=>'range','inputmode'=>'decimal','attrs_mirror'=>['min'=>null,'max'=>null,'step'=>null]],
            'validate' => [],
        ],
        'date' => [
            'is_multivalue' => false,
            'html' => ['tag'=>'input','type'=>'date','attrs_mirror'=>['min'=>null,'max'=>null,'step'=>null]],
            'validate' => [],
        ],
        'textarea' => [
            'is_multivalue' => false,
            'html' => ['tag'=>'textarea','attrs_mirror'=>['maxlength'=>null,'minlength'=>null]],
            'validate' => [],
        ],
        'textarea_html' => [
            'is_multivalue' => false,
            'html' => ['tag'=>'textarea','attrs_mirror'=>['maxlength'=>null,'minlength'=>null]],
            'validate' => [],
        ],
        'zip' => [
            'is_multivalue' => false,
            'html' => ['tag'=>'input','type'=>'text','attrs_mirror'=>['maxlength'=>null,'minlength'=>null]],
            'validate' => [],
        ],
        'zip_us' => [
            'is_multivalue' => false,
            'html' => ['tag'=>'input','type'=>'text','inputmode'=>'numeric','pattern'=>'\d{5}','attrs_mirror'=>['maxlength'=>5]],
            'validate' => ['pattern'=>'/^\d{5}$/'],
        ],
        'select' => [
            'is_multivalue' => false,
            'html' => ['tag'=>'select'],
            'validate' => [],
        ],
        'radio' => [
            'is_multivalue' => false,
            'html' => ['tag'=>'fieldset'],
            'validate' => [],
        ],
        'checkbox' => [
            'is_multivalue' => true,
            'html' => ['tag'=>'fieldset'],
            'validate' => [],
        ],
        'file' => [
            'is_multivalue' => false,
            'html' => ['tag'=>'input','type'=>'file'],
            'validate' => [],
        ],
        'files' => [
            'is_multivalue' => true,
            'html' => ['tag'=>'input','type'=>'file','multiple'=>true],
            'validate' => [],
        ],
    ];

    /**
     * Returns descriptor for field type.
     */
    public static function descriptorFor(string $type): array
    {
        return self::REGISTRY[$type] ?? ['is_multivalue'=>false,'html'=>['tag'=>'input'],'validate'=>[]];
    }

    /**
     * Map accept tokens for file controls.
     */
    public static function acceptTokenMap(): array
    {
        return [
            'image' => 'image/jpeg,image/png,image/gif,image/webp',
            'pdf'   => 'application/pdf',
        ];
    }
}
