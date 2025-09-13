<?php
declare(strict_types=1);

namespace EForms\Validation;

use EForms\Config;
use EForms\Logging;
use EForms\Rendering\Renderer;
use EForms\Spec;

/**
 * Template structural validator. Performs strict preflight of template arrays
 * and returns a normalized context used by other subsystems.
 */
class TemplateValidator
{
    public const EFORMS_ERR_SCHEMA_UNKNOWN_KEY   = 'EFORMS_ERR_SCHEMA_UNKNOWN_KEY';
    public const EFORMS_ERR_SCHEMA_ENUM          = 'EFORMS_ERR_SCHEMA_ENUM';
    public const EFORMS_ERR_SCHEMA_REQUIRED      = 'EFORMS_ERR_SCHEMA_REQUIRED';
    public const EFORMS_ERR_SCHEMA_TYPE          = 'EFORMS_ERR_SCHEMA_TYPE';
    public const EFORMS_ERR_SCHEMA_OBJECT        = 'EFORMS_ERR_SCHEMA_OBJECT';
    public const EFORMS_ERR_SCHEMA_DUP_KEY       = 'EFORMS_ERR_SCHEMA_DUP_KEY';
    public const EFORMS_ERR_ACCEPT_EMPTY         = 'EFORMS_ERR_ACCEPT_EMPTY';
    public const EFORMS_ERR_ROW_GROUP_UNBALANCED = 'EFORMS_ERR_ROW_GROUP_UNBALANCED';
    public const EFORMS_ERR_FRAGMENT_UNBALANCED  = 'EFORMS_ERR_FRAGMENT_UNBALANCED';
    public const EFORMS_ERR_FRAGMENT_ROW_TAG     = 'EFORMS_ERR_FRAGMENT_ROW_TAG';
    public const EFORMS_ERR_FRAGMENT_STYLE_ATTR  = 'EFORMS_ERR_FRAGMENT_STYLE_ATTR';

    private const AUTOCOMPLETE_TOKENS = [
        'name','honorific-prefix','given-name','additional-name','family-name',
        'honorific-suffix','nickname','email','username','new-password',
        'current-password','one-time-code','organization-title','organization',
        'street-address','address-line1','address-line2','address-line3',
        'address-level4','address-level3','address-level2','address-level1',
        'country','country-name','postal-code','cc-name','cc-given-name',
        'cc-additional-name','cc-family-name','cc-number','cc-exp',
        'cc-exp-month','cc-exp-year','cc-csc','cc-type','transaction-currency',
        'transaction-amount','language','bday','bday-day','bday-month',
        'bday-year','sex','tel','tel-country-code','tel-national',
        'tel-area-code','tel-local','tel-local-prefix','tel-local-suffix',
        'tel-extension','impp','url','photo','webauthn','shipping',
        'billing','home','work','mobile','fax','pager',
    ];

    /**
     * Perform structural validation and return context.
     *
     * @param array $tpl
     * @param string|null $srcPath
     * @return array{ok:bool,errors:array<int,array{code:string,path:string}>,context?:array}
     */
    public static function preflight(array $tpl, ?string $srcPath = null): array
    {
        $errors = [];

        $maxFields = Config::get('validation.max_fields_per_form', 150);
        $maxOptions = Config::get('validation.max_options_per_group', 100);

        // Root unknown keys
        $rootAllowed = ['id','version','title','success','email','fields','submit_button_text','rules','$schema'];
        self::checkUnknown($tpl, $rootAllowed, '', $errors);

        // Required + type
        $reqRoot = ['id'=>'string','version'=>null,'title'=>'string','success'=>'array','email'=>'array','fields'=>'array','submit_button_text'=>'string'];
        foreach ($reqRoot as $k => $type) {
            if (!array_key_exists($k, $tpl)) {
                $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_REQUIRED,'path'=>$k];
                continue;
            }
            if ($type === 'string' && !is_string($tpl[$k])) {
                $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_TYPE,'path'=>$k];
            } elseif ($type === 'array' && !is_array($tpl[$k])) {
                $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_OBJECT,'path'=>$k];
            }
        }

        if (isset($tpl['$schema']) && !is_string($tpl['$schema'])) {
            $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_TYPE,'path'=>'$schema'];
        }

        // success
        $success = is_array($tpl['success'] ?? null) ? $tpl['success'] : [];
        self::checkUnknown($success, ['mode','redirect_url','message'], 'success.', $errors);
        $mode = $success['mode'] ?? null;
        if (!in_array($mode, ['inline','redirect'], true)) {
            $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_ENUM,'path'=>'success.mode'];
        } elseif ($mode === 'redirect') {
            if (empty($success['redirect_url']) || !is_string($success['redirect_url'])) {
                $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_REQUIRED,'path'=>'success.redirect_url'];
            }
        }

        // email block
        $email = is_array($tpl['email'] ?? null) ? $tpl['email'] : [];
        self::checkUnknown($email, ['display_format_tel','to','subject','email_template','include_fields'], 'email.', $errors);
        if (isset($email['display_format_tel'])) {
            $enum = ['xxx-xxx-xxxx','(xxx) xxx-xxxx','xxx.xxx.xxxx'];
            if (!in_array($email['display_format_tel'], $enum, true)) {
                $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_ENUM,'path'=>'email.display_format_tel'];
                unset($email['display_format_tel']);
            }
        }

        $tmpl = $email['email_template'] ?? 'default';
        if (!is_string($tmpl) || !preg_match('/^[a-z0-9_-]+$/', $tmpl)) {
            $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_ENUM,'path'=>'email.email_template'];
        } else {
            $base = __DIR__ . '/../../templates/email/' . $tmpl;
            if (!is_file($base . '.txt.php') && !is_file($base . '.html.php')) {
                $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_ENUM,'path'=>'email.email_template'];
            }
        }
        $email['email_template'] = $tmpl;

        // fields
        $fields = is_array($tpl['fields'] ?? null) ? $tpl['fields'] : [];
        $seenKeys = [];
        $rowStack = 0;
        $extraRowEnd = false;
        $hasUploads = false;
        $normFields = [];
        $realFieldCount = 0;
        $reserved = ['form_id','instance_id','eforms_token','eforms_hp','timestamp','js_ok','ip','submitted_at'];
        $allowedTypes = ['name','first_name','last_name','text','email','textarea','textarea_html','url','tel','tel_us','number','range','date','zip','zip_us','select','radio','checkbox','file','files','row_group'];
        foreach ($fields as $idx => $f) {
            $path = 'fields['.$idx.'].';
            if (!is_array($f)) {
                $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_OBJECT,'path'=>rtrim($path,'.')];
                continue;
            }
            $type = $f['type'] ?? null;
            if (!in_array($type, $allowedTypes, true)) {
                $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_ENUM,'path'=>$path.'type'];
                continue;
            }
            if ($type === 'row_group') {
                self::checkUnknown($f, ['type','mode','tag','class'], $path, $errors);
                $mode = $f['mode'] ?? null;
                if (!in_array($mode, ['start','end'], true)) {
                    $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_ENUM,'path'=>$path.'mode'];
                }
                $tag = $f['tag'] ?? 'div';
                if (!in_array($tag, ['div','section'], true)) {
                    $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_ENUM,'path'=>$path.'tag'];
                }
                if ($mode === 'start') {
                    $rowStack++;
                    $normFields[] = [
                        'type' => 'row_group',
                        'mode' => $mode,
                        'tag' => $tag,
                        'class' => self::sanitizeClass($f['class'] ?? ''),
                    ];
                } elseif ($mode === 'end') {
                    if ($rowStack > 0) {
                        $rowStack--;
                        $normFields[] = [
                            'type' => 'row_group',
                            'mode' => $mode,
                            'tag' => $tag,
                            'class' => self::sanitizeClass($f['class'] ?? ''),
                        ];
                    } else {
                        $extraRowEnd = true;
                    }
                }
                continue;
            }

            // Non row_group field
            self::checkUnknown(
                $f,
                [
                    'type','key','label','required','options','multiple','accept','before_html','after_html','class',
                    'placeholder','autocomplete','size','max_length','min','max','pattern','email_attach',
                    'max_file_bytes','max_files','step'
                ],
                $path,
                $errors
            );
            $key = $f['key'] ?? null;
            if (!is_string($key) || !preg_match('/^[a-z0-9_:-]{1,64}$/', $key)) {
                $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_TYPE,'path'=>$path.'key'];
                continue;
            }
            if (in_array($key, $reserved, true)) {
                $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_ENUM,'path'=>$path.'key'];
                continue;
            }
            if (isset($seenKeys[$key])) {
                $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_DUP_KEY,'path'=>$path.'key'];
                continue;
            }
            $seenKeys[$key] = true;

            // options
            if (isset($f['options'])) {
                if (!is_array($f['options'])) {
                    $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_TYPE,'path'=>$path.'options'];
                } else {
                    if (count($f['options']) > $maxOptions) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_ENUM,'path'=>$path.'options'];
                    }
                    $optSeen = [];
                    foreach ($f['options'] as $oIdx => $opt) {
                        $opath = $path.'options['.$oIdx.'].';
                        self::checkUnknown($opt, ['key','label','disabled'], $opath, $errors);
                        if (!isset($opt['key']) || !is_string($opt['key'])) {
                            $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_REQUIRED,'path'=>$opath.'key'];
                            continue;
                        }
                        if (!isset($opt['label']) || !is_string($opt['label'])) {
                            $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_REQUIRED,'path'=>$opath.'label'];
                        }
                        if (isset($opt['disabled']) && !is_bool($opt['disabled'])) {
                            $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_TYPE,'path'=>$opath.'disabled'];
                        }
                        if (isset($optSeen[$opt['key']])) {
                            $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_DUP_KEY,'path'=>$opath.'key'];
                            continue;
                        }
                        $optSeen[$opt['key']] = true;
                    }
                }
            }

            // before/after_html fragments must be balanced and not contain row tags
            if (isset($f['before_html']) && is_string($f['before_html'])) {
                if (!self::isBalancedFragment($f['before_html'])) {
                    $errors[] = ['code'=>self::EFORMS_ERR_FRAGMENT_UNBALANCED,'path'=>$path.'before_html'];
                } elseif (self::fragmentContainsRowTag($f['before_html'])) {
                    $errors[] = ['code'=>self::EFORMS_ERR_FRAGMENT_ROW_TAG,'path'=>$path.'before_html'];
                } elseif (self::fragmentContainsStyleAttr($f['before_html'])) {
                    $errors[] = ['code'=>self::EFORMS_ERR_FRAGMENT_STYLE_ATTR,'path'=>$path.'before_html'];
                } else {
                    $f['before_html'] = \wp_kses_post($f['before_html']);
                }
            }
            if (isset($f['after_html']) && is_string($f['after_html'])) {
                if (!self::isBalancedFragment($f['after_html'])) {
                    $errors[] = ['code'=>self::EFORMS_ERR_FRAGMENT_UNBALANCED,'path'=>$path.'after_html'];
                } elseif (self::fragmentContainsRowTag($f['after_html'])) {
                    $errors[] = ['code'=>self::EFORMS_ERR_FRAGMENT_ROW_TAG,'path'=>$path.'after_html'];
                } elseif (self::fragmentContainsStyleAttr($f['after_html'])) {
                    $errors[] = ['code'=>self::EFORMS_ERR_FRAGMENT_STYLE_ATTR,'path'=>$path.'after_html'];
                } else {
                    $f['after_html'] = \wp_kses_post($f['after_html']);
                }
            }

            // accept intersection for files
            if (in_array($type, ['file','files'], true)) {
                $hasUploads = true;
                $accept = $f['accept'] ?? [];
                if (!is_array($accept)) {
                    $accept = [];
                }
                $global = Config::get('uploads.allowed_tokens', ['image','pdf']);
                $intersection = array_intersect($accept, $global);
                if (empty($accept) || empty($intersection)) {
                    $errors[] = ['code'=>self::EFORMS_ERR_ACCEPT_EMPTY,'path'=>$path.'accept'];
                }
                if (isset($f['email_attach']) && !is_bool($f['email_attach'])) {
                    $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_TYPE,'path'=>$path.'email_attach'];
                }
                if (isset($f['max_file_bytes'])) {
                    if (!is_int($f['max_file_bytes'])) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_TYPE,'path'=>$path.'max_file_bytes'];
                    } elseif ($f['max_file_bytes'] < 1) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_ENUM,'path'=>$path.'max_file_bytes'];
                    }
                }
                if (isset($f['max_files'])) {
                    if ($type !== 'files') {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_ENUM,'path'=>$path.'max_files'];
                    } elseif (!is_int($f['max_files'])) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_TYPE,'path'=>$path.'max_files'];
                    } elseif ($f['max_files'] < 1) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_ENUM,'path'=>$path.'max_files'];
                    }
                }
            }

            if (isset($f['autocomplete'])) {
                if (!is_string($f['autocomplete'])) {
                    $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_TYPE,'path'=>$path.'autocomplete'];
                    unset($f['autocomplete']);
                } else {
                    $token = strtolower(trim($f['autocomplete']));
                    if ($token === '' || preg_match('/\s/', $token)) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_ENUM,'path'=>$path.'autocomplete'];
                        unset($f['autocomplete']);
                    } elseif ($token !== 'on' && $token !== 'off' && !in_array($token, self::AUTOCOMPLETE_TOKENS, true)) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_ENUM,'path'=>$path.'autocomplete'];
                        unset($f['autocomplete']);
                    } else {
                        $f['autocomplete'] = $token;
                    }
                }
            }

            if (isset($f['size'])) {
                if (!is_int($f['size'])) {
                    $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_TYPE,'path'=>$path.'size'];
                    unset($f['size']);
                } elseif ($f['size'] < 1 || $f['size'] > 100) {
                    $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_ENUM,'path'=>$path.'size'];
                    unset($f['size']);
                }
            }

            // numeric and pattern constraints
            if (isset($f['max_length'])) {
                if (!is_int($f['max_length'])) {
                    $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_TYPE,'path'=>$path.'max_length'];
                } elseif ($f['max_length'] < 1 || $f['max_length'] > 1000) {
                    $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_ENUM,'path'=>$path.'max_length'];
                }
            }
            $minVal = $f['min'] ?? null;
            $maxVal = $f['max'] ?? null;
            $stepVal = $f['step'] ?? null;
            if ($minVal !== null && !is_numeric($minVal)) {
                $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_TYPE,'path'=>$path.'min'];
            }
            if ($maxVal !== null && !is_numeric($maxVal)) {
                $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_TYPE,'path'=>$path.'max'];
            }
            if (is_numeric($minVal) && is_numeric($maxVal) && $minVal > $maxVal) {
                $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_ENUM,'path'=>$path.'min'];
            }
            if ($stepVal !== null) {
                if (!is_numeric($stepVal)) {
                    $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_TYPE,'path'=>$path.'step'];
                } elseif ($stepVal <= 0) {
                    $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_ENUM,'path'=>$path.'step'];
                }
            }
            if (isset($f['pattern'])) {
                if (!is_string($f['pattern'])) {
                    $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_TYPE,'path'=>$path.'pattern'];
                } elseif (@preg_match('#'.$f['pattern'].'#', '') === false) {
                    $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_ENUM,'path'=>$path.'pattern'];
                }
            }

            $normFields[] = [
                'type' => $type,
                'key' => $key,
                'label' => $f['label'] ?? null,
                'required' => !empty($f['required']),
                'options' => $f['options'] ?? null,
                'multiple' => !empty($f['multiple']),
                'accept' => $f['accept'] ?? null,
                'class' => self::sanitizeClass($f['class'] ?? ''),
                'before_html' => isset($f['before_html']) && is_string($f['before_html']) ? $f['before_html'] : null,
                'after_html' => isset($f['after_html']) && is_string($f['after_html']) ? $f['after_html'] : null,
                'placeholder' => isset($f['placeholder']) && is_string($f['placeholder']) ? substr($f['placeholder'],0,255) : null,
                'autocomplete' => isset($f['autocomplete']) && is_string($f['autocomplete']) ? $f['autocomplete'] : null,
                'size' => isset($f['size']) && is_int($f['size']) ? $f['size'] : null,
                'max_length' => isset($f['max_length']) && is_int($f['max_length']) ? $f['max_length'] : null,
                'min' => is_numeric($minVal) ? $minVal + 0 : null,
                'max' => is_numeric($maxVal) ? $maxVal + 0 : null,
                'pattern' => is_string($f['pattern'] ?? null) ? $f['pattern'] : null,
                'max_file_bytes' => isset($f['max_file_bytes']) && is_int($f['max_file_bytes']) ? $f['max_file_bytes'] : null,
                'max_files' => isset($f['max_files']) && is_int($f['max_files']) ? $f['max_files'] : null,
                'step' => (is_numeric($stepVal) && $stepVal > 0) ? $stepVal + 0 : null,
            ];
            $realFieldCount++;
        }
        if ($extraRowEnd) {
            Logging::write('warn', self::EFORMS_ERR_ROW_GROUP_UNBALANCED, ['form_id'=>$tpl['id'] ?? '']);
        }
        if ($rowStack !== 0) {
            Logging::write('warn', self::EFORMS_ERR_ROW_GROUP_UNBALANCED, ['form_id'=>$tpl['id'] ?? '']);
            $errors[] = ['code'=>self::EFORMS_ERR_ROW_GROUP_UNBALANCED,'path'=>'fields'];
        }

        if ($realFieldCount > $maxFields) {
            $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_ENUM,'path'=>'fields'];
        }

        $allowedMeta = ['ip','submitted_at','form_id','instance_id'];
        if (isset($email['include_fields'])) {
            if (!is_array($email['include_fields'])) {
                $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_TYPE,'path'=>'email.include_fields'];
                $email['include_fields'] = [];
            } else {
                $filtered = [];
                foreach ($email['include_fields'] as $idx => $fld) {
                    if (!is_string($fld)) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_TYPE,'path'=>'email.include_fields['.$idx.']'];
                        continue;
                    }
                    if (!isset($seenKeys[$fld]) && !in_array($fld, $allowedMeta, true)) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_ENUM,'path'=>'email.include_fields['.$idx.']'];
                        continue;
                    }
                    $filtered[] = $fld;
                }
                $email['include_fields'] = $filtered;
            }
        } else {
            $email['include_fields'] = [];
        }

        $rules = is_array($tpl['rules'] ?? null) ? $tpl['rules'] : [];
        $allowedRules = ['required_if','required_if_any','required_unless','matches','one_of','mutually_exclusive'];
        foreach ($rules as $rIdx => $rule) {
            $rpath = 'rules['.$rIdx.'].';
            if (!is_array($rule)) {
                $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_OBJECT,'path'=>rtrim($rpath,'.')];
                continue;
            }
            $type = $rule['rule'] ?? '';
            if (!in_array($type, $allowedRules, true)) {
                $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_ENUM,'path'=>$rpath.'rule'];
                continue;
            }
            switch ($type) {
                case 'required_if':
                    self::checkUnknown($rule, ['rule','field','other','equals'], $rpath, $errors);
                    if (!isset($rule['field'])) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_REQUIRED,'path'=>$rpath.'field'];
                    } elseif (!is_string($rule['field'])) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_TYPE,'path'=>$rpath.'field'];
                    }
                    if (!isset($rule['other'])) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_REQUIRED,'path'=>$rpath.'other'];
                    } elseif (!is_string($rule['other'])) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_TYPE,'path'=>$rpath.'other'];
                    }
                    if (!array_key_exists('equals', $rule)) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_REQUIRED,'path'=>$rpath.'equals'];
                    } elseif (!is_scalar($rule['equals'])) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_TYPE,'path'=>$rpath.'equals'];
                    }
                    break;
                case 'required_if_any':
                    self::checkUnknown($rule, ['rule','field','fields','equals_any'], $rpath, $errors);
                    if (!isset($rule['field'])) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_REQUIRED,'path'=>$rpath.'field'];
                    } elseif (!is_string($rule['field'])) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_TYPE,'path'=>$rpath.'field'];
                    }
                    if (!isset($rule['fields'])) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_REQUIRED,'path'=>$rpath.'fields'];
                    } elseif (!is_array($rule['fields'])) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_TYPE,'path'=>$rpath.'fields'];
                    }
                    if (!isset($rule['equals_any'])) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_REQUIRED,'path'=>$rpath.'equals_any'];
                    } elseif (!is_array($rule['equals_any'])) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_TYPE,'path'=>$rpath.'equals_any'];
                    }
                    break;
                case 'required_unless':
                    self::checkUnknown($rule, ['rule','field','other','equals'], $rpath, $errors);
                    if (!isset($rule['field'])) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_REQUIRED,'path'=>$rpath.'field'];
                    } elseif (!is_string($rule['field'])) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_TYPE,'path'=>$rpath.'field'];
                    }
                    if (!isset($rule['other'])) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_REQUIRED,'path'=>$rpath.'other'];
                    } elseif (!is_string($rule['other'])) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_TYPE,'path'=>$rpath.'other'];
                    }
                    if (!array_key_exists('equals', $rule)) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_REQUIRED,'path'=>$rpath.'equals'];
                    } elseif (!is_scalar($rule['equals'])) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_TYPE,'path'=>$rpath.'equals'];
                    }
                    break;
                case 'matches':
                    self::checkUnknown($rule, ['rule','field','other'], $rpath, $errors);
                    if (!isset($rule['field'])) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_REQUIRED,'path'=>$rpath.'field'];
                    } elseif (!is_string($rule['field'])) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_TYPE,'path'=>$rpath.'field'];
                    }
                    if (!isset($rule['other'])) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_REQUIRED,'path'=>$rpath.'other'];
                    } elseif (!is_string($rule['other'])) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_TYPE,'path'=>$rpath.'other'];
                    }
                    break;
                case 'one_of':
                case 'mutually_exclusive':
                    self::checkUnknown($rule, ['rule','fields'], $rpath, $errors);
                    if (!isset($rule['fields'])) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_REQUIRED,'path'=>$rpath.'fields'];
                    } elseif (!is_array($rule['fields'])) {
                        $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_TYPE,'path'=>$rpath.'fields'];
                    }
                    break;
            }
        }

        // max_input_vars estimate (excludes uploads)
        $estimate = 5; // form_id, instance_id, eforms_hp, timestamp, js_ok
        $maxOptsEstimate = Config::get('validation.max_options_per_group', 100);
        foreach ($normFields as $nf) {
            $type = $nf['type'];
            if ($type === 'row_group' || $type === 'file' || $type === 'files') {
                continue;
            }
            if ($type === 'checkbox' || ($type === 'select' && !empty($nf['multiple']))) {
                $count = count($nf['options'] ?? []);
                $estimate += min($count, $maxOptsEstimate);
            } else {
                $estimate++;
            }
        }

        $version = $tpl['version'] ?? '';
        if ($version === '' && $srcPath && is_file($srcPath)) {
            $version = (string) (@filemtime($srcPath) ?: '');
        }
        $ctx = [
            'has_uploads' => $hasUploads,
            'descriptors' => self::buildDescriptors($tpl, $normFields, $errors),
            'version' => $version,
            'id' => $tpl['id'] ?? '',
            'title' => $tpl['title'] ?? '',
            'email' => $email,
            'success' => $success,
            'rules' => $rules,
            'fields' => $normFields,
            'max_input_vars_estimate' => $estimate,
        ];

        return ['ok'=>empty($errors), 'errors'=>$errors, 'context'=>$ctx];
    }

    private static function checkUnknown(array $obj, array $allowed, string $prefix, array &$errors): void
    {
        foreach ($obj as $k => $_) {
            if (!in_array($k, $allowed, true)) {
                $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_UNKNOWN_KEY,'path'=>$prefix.$k];
            }
        }
    }

    private static function sanitizeClass(string $class): string
    {
        $tokens = preg_split('/\s+/', trim($class)) ?: [];
        $keep = [];
        foreach ($tokens as $t) {
            if ($t === '') continue;
            if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $t)) continue;
            if (!in_array($t, $keep, true)) $keep[] = $t;
        }
        $out = implode(' ', $keep);
        if (strlen($out) > 128) {
            $out = substr($out, 0, 128);
        }
        return $out;
    }

    private static function isBalancedFragment(string $html): bool
    {
        if ($html === '') return true;
        preg_match_all('/<\/?([a-z0-9]+)[^>]*?>/i', $html, $matches, PREG_SET_ORDER);
        $stack = [];
        foreach ($matches as $m) {
            $tag = strtolower($m[1]);
            $isClose = ($m[0][1] ?? '') === '/';
            if ($tag === 'br') continue;
            if ($isClose) {
                $prev = array_pop($stack);
                if ($prev !== $tag) return false;
            } else {
                $stack[] = $tag;
            }
        }
        return empty($stack);
    }

    private static function fragmentContainsRowTag(string $html): bool
    {
        return $html !== '' && preg_match('/<\/?(?:div|section)\b/i', $html) === 1;
    }

    private static function fragmentContainsStyleAttr(string $html): bool
    {
        return $html !== '' && preg_match('/<[^>]*\\bstyle\\s*=/i', $html) === 1;
    }

    private static function buildDescriptors(array $tpl, array $fields, array &$errors): array
    {
        $all = Spec::typeDescriptors();
        $desc = [];
        foreach ($fields as $f) {
            $type = $f['type'] ?? '';
            if ($type === 'row_group') {
                continue;
            }

            $d = $all[$type] ?? Spec::descriptorFor($type);
            $type = $d['type'];
            $d['constants'] = $d['constants'] ?? [];

            if ($type === 'select' && !empty($f['multiple'])) {
                $d['is_multivalue'] = true;
                $d['html']['multiple'] = true;
            }
            if ($type === 'files') {
                $d['is_multivalue'] = true;
                $d['html']['multiple'] = true;
            }

            $overrideKeys = [
                'required','options','multiple','accept','placeholder','autocomplete','size',
                'max_length','min','max','pattern','max_file_bytes','max_files','step',
            ];
            foreach ($overrideKeys as $k) {
                if (array_key_exists($k, $f)) {
                    $d[$k] = $f[$k];
                }
            }

            $handlers = $d['handlers'] ?? [];
            $d['handlers'] = [];

            $ctxBase = 'fields.' . ($f['key'] ?? '') . '.';
            $handlerTypes = [
                'validator'  => ['id' => $handlers['validator_id'] ?? '', 'resolver' => fn(string $id) => Validator::resolve($id, $ctxBase . 'validator')],
                'normalizer' => ['id' => $handlers['normalizer_id'] ?? '', 'resolver' => fn(string $id) => Normalizer::resolve($id, $ctxBase . 'normalizer')],
                'renderer'   => ['id' => $handlers['renderer_id'] ?? '', 'resolver' => fn(string $id) => Renderer::resolve($id, $ctxBase . 'renderer')],
            ];

            foreach ($handlerTypes as $kind => $info) {
                try {
                    $d['handlers'][$kind] = $info['resolver']($info['id']);
                } catch (\RuntimeException $e) {
                    $errors[] = [
                        'code' => self::EFORMS_ERR_SCHEMA_ENUM,
                        'path' => 'fields.' . ($f['key'] ?? '') . '.' . $kind,
                    ];
                }
            }

            $d['form_id'] = $tpl['id'] ?? '';
            $d['key'] = $f['key'];

            $desc[$f['key']] = $d;
        }
        return $desc;
    }
}
