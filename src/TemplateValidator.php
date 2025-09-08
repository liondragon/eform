<?php
declare(strict_types=1);

namespace EForms;

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

    /**
     * Perform structural validation and return context.
     *
     * @param array $tpl
     * @return array{ok:bool,errors:array<int,array{code:string,path:string}>,context?:array}
     */
    public static function preflight(array $tpl): array
    {
        $errors = [];

        // Root unknown keys
        $rootAllowed = ['id','version','title','success','email','fields','submit_button_text','rules'];
        self::checkUnknown($tpl, $rootAllowed, '', $errors);

        // Required + type
        $reqRoot = ['id'=>'string','version'=>null,'success'=>'array','email'=>'array','fields'=>'array','submit_button_text'=>'string'];
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

        // fields
        $fields = is_array($tpl['fields'] ?? null) ? $tpl['fields'] : [];
        $seenKeys = [];
        $rowStack = 0;
        $hasUploads = false;
        $normFields = [];
        $reserved = ['form_id','instance_id','eforms_token','eforms_hp','timestamp','js_ok','ip','submitted_at'];
        $allowedTypes = ['name','email','textarea','tel_us','zip_us','select','radio','checkbox','file','files','row_group'];
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
                } elseif ($mode === 'end') {
                    if ($rowStack > 0) $rowStack--; else $rowStack = -1; // imbalance
                }
                $normFields[] = [
                    'type' => 'row_group',
                    'mode' => $mode,
                    'tag' => $tag,
                    'class' => self::sanitizeClass($f['class'] ?? ''),
                ];
                continue;
            }

            // Non row_group field
            self::checkUnknown($f, ['type','key','label','required','options','multiple','accept','before_html','after_html','class','placeholder','autocomplete','size'], $path, $errors);
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
                    $optSeen = [];
                    foreach ($f['options'] as $oIdx => $opt) {
                        $opath = $path.'options['.$oIdx.'].';
                        self::checkUnknown($opt, ['key','label','disabled'], $opath, $errors);
                        if (!isset($opt['key']) || !is_string($opt['key'])) {
                            $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_REQUIRED,'path'=>$opath.'key'];
                            continue;
                        }
                        if (isset($optSeen[$opt['key']])) {
                            $errors[] = ['code'=>self::EFORMS_ERR_SCHEMA_DUP_KEY,'path'=>$opath.'key'];
                            continue;
                        }
                        $optSeen[$opt['key']] = true;
                    }
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
                if ($accept && empty($intersection)) {
                    $errors[] = ['code'=>self::EFORMS_ERR_ACCEPT_EMPTY,'path'=>$path.'accept'];
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
            ];
        }
        if ($rowStack !== 0) {
            $errors[] = ['code'=>self::EFORMS_ERR_ROW_GROUP_UNBALANCED,'path'=>'fields'];
        }

        $ctx = [
            'has_uploads' => $hasUploads,
            'descriptors' => self::buildDescriptors($normFields),
            'version' => $tpl['version'] ?? '',
            'id' => $tpl['id'] ?? '',
            'email' => $email,
            'success' => $success,
            'rules' => $tpl['rules'] ?? [],
            'fields' => $normFields,
            'max_input_vars_estimate' => count($normFields) * 3,
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

    private static function buildDescriptors(array $fields): array
    {
        $desc = [];
        foreach ($fields as $f) {
            if ($f['type'] === 'row_group') continue;
            $d = Spec::descriptorFor($f['type']);
            if ($f['type'] === 'select' && !empty($f['multiple'])) {
                $d['is_multivalue'] = true;
                $d['html']['multiple'] = true;
            }
            if ($f['type'] === 'files') {
                $d['is_multivalue'] = true;
                $d['html']['multiple'] = true;
            }
            $desc[$f['key']] = $d;
        }
        return $desc;
    }
}
