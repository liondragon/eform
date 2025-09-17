<?php
declare(strict_types=1);

namespace EForms;

/**
 * Registry describing template structure and enums.
 */
class TemplateSpec
{
    private const ROOT_ALLOWED = [
        'id','version','title','success','email','fields','submit_button_text','rules','$schema'
    ];

    /** @var array<string,string|null> */
    private const ROOT_REQUIRED = [
        'id' => 'string',
        'version' => null,
        'title' => 'string',
        'success' => 'array',
        'email' => 'array',
        'fields' => 'array',
        'submit_button_text' => 'string',
    ];

    private const SUCCESS = [
        'allowed' => ['mode','redirect_url','message'],
        'required' => ['mode'],
        'enums' => [
            'mode' => ['inline','redirect'],
        ],
    ];

    private const EMAIL = [
        'allowed' => ['display_format_tel','to','subject','email_template','include_fields'],
        'required' => ['to','subject'],
        'enums' => [
            'display_format_tel' => ['xxx-xxx-xxxx','(xxx) xxx-xxxx','xxx.xxx.xxxx'],
        ],
    ];

    private const FIELDS = [
        'reserved_keys' => [
            'form_id','instance_id','submission_id','eforms_token','eforms_hp','timestamp','js_ok','ip','submitted_at'
        ],
        'allowed_types' => [
            'name','first_name','last_name','text','email','textarea','textarea_html','url','tel','tel_us','number',
            'range','date','zip','zip_us','select','radio','checkbox','file','files','row_group'
        ],
        'row_group' => [
            'allowed' => ['type','mode','tag','class'],
            'enums' => [
                'mode' => ['start','end'],
                'tag' => ['div','section'],
            ],
        ],
        'field_allowed' => [
            'type','key','label','required','options','multiple','accept','before_html','after_html','class',
            'placeholder','autocomplete','size','max_length','min','max','pattern','email_attach',
            'max_file_bytes','max_files','step'
        ],
        'size_allowed_types' => ['text','tel','tel_us','url','email'],
        'allowed_meta' => ['ip','submitted_at','form_id','instance_id','submission_id','slot'],
    ];

    private const RULES = [
        'required_if' => [
            'allowed' => ['rule','target','field','equals'],
            'required' => ['target','field','equals'],
        ],
        'required_if_any' => [
            'allowed' => ['rule','target','fields','equals_any'],
            'required' => ['target','fields','equals_any'],
        ],
        'required_unless' => [
            'allowed' => ['rule','target','field','equals'],
            'required' => ['target','field','equals'],
        ],
        'matches' => [
            'allowed' => ['rule','target','field'],
            'required' => ['target','field'],
        ],
        'one_of' => [
            'allowed' => ['rule','fields'],
            'required' => ['fields'],
        ],
        'mutually_exclusive' => [
            'allowed' => ['rule','fields'],
            'required' => ['fields'],
        ],
    ];

    private const AUTOCOMPLETE_TOKENS = [
        'name','honorific-prefix','given-name','additional-name','family-name',
        'honorific-suffix','nickname','email','username','one-time-code',
        'organization-title','organization','street-address','address-line1',
        'address-line2','address-line3','address-level4','address-level3',
        'address-level2','address-level1','country','country-name','postal-code',
        'cc-name','cc-given-name','cc-additional-name','cc-family-name',
        'cc-number','cc-exp','cc-exp-month','cc-exp-year','cc-csc','cc-type',
        'transaction-currency','transaction-amount','language','bday',
        'bday-day','bday-month','bday-year','sex','tel','tel-country-code',
        'tel-national','tel-area-code','tel-local','tel-local-prefix',
        'tel-local-suffix','tel-extension','impp','url','photo','webauthn',
        'shipping','billing','home','work','mobile','fax','pager',
    ];

    /** @return list<string> */
    public static function rootAllowed(): array { return self::ROOT_ALLOWED; }

    /** @return array<string,string|null> */
    public static function rootRequired(): array { return self::ROOT_REQUIRED; }

    /** @return array{allowed:array,required:array,enums:array} */
    public static function successSpec(): array { return self::SUCCESS; }

    /** @return array{allowed:array,required:array,enums:array} */
    public static function emailSpec(): array { return self::EMAIL; }

    /** @return list<string> */
    public static function fieldAllowedTypes(): array { return self::FIELDS['allowed_types']; }

    /** @return list<string> */
    public static function reservedFieldKeys(): array { return self::FIELDS['reserved_keys']; }

    /** @return list<string> */
    public static function fieldAttributes(): array { return self::FIELDS['field_allowed']; }

    /** @return array{allowed:array,enums:array} */
    public static function rowGroupSpec(): array { return self::FIELDS['row_group']; }

    /** @return list<string> */
    public static function sizeAllowedTypes(): array { return self::FIELDS['size_allowed_types']; }

    /** @return list<string> */
    public static function allowedMeta(): array { return self::FIELDS['allowed_meta']; }

    /** @return list<string> */
    public static function ruleTypes(): array { return array_keys(self::RULES); }

    /** @return array{allowed:array,required:array} */
    public static function ruleSpec(string $rule): array { return self::RULES[$rule] ?? ['allowed'=>[],'required'=>[]]; }

    /** @return list<string> */
    public static function autocompleteTokens(): array { return self::AUTOCOMPLETE_TOKENS; }
}
