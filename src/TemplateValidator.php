<?php
declare(strict_types=1);

namespace EForms;

class TemplateValidator
{
    /**
     * Performs structural preflight on a template.
     *
     * @param array $tpl
     * @return array{ok:bool,errors:array}
     */
    public static function preflight(array $tpl): array
    {
        $errors = [];
        $required = ['id','version','title','success','email','fields','submit_button_text'];
        foreach ($required as $k) {
            if (!array_key_exists($k, $tpl)) {
                $errors[] = "Missing $k";
            }
        }
        if ($errors) {
            return ['ok'=>false,'errors'=>$errors];
        }
        if (!is_string($tpl['id']) || $tpl['id'] === '') {
            $errors[] = 'id';
        }
        if (!is_string($tpl['version']) && !is_numeric($tpl['version'])) {
            $errors[] = 'version';
        }
        if (!is_string($tpl['title'])) {
            $errors[] = 'title';
        }
        if (!is_array($tpl['success'])) {
            $errors[] = 'success';
        }
        if (!is_array($tpl['email'])) {
            $errors[] = 'email';
        }
        if (!is_array($tpl['fields'])) {
            $errors[] = 'fields';
        }
        if (!is_string($tpl['submit_button_text'])) {
            $errors[] = 'submit_button_text';
        }
        // success mode
        if (is_array($tpl['success'])) {
            $mode = $tpl['success']['mode'] ?? '';
            if (!in_array($mode, ['inline','redirect'], true)) {
                $errors[] = 'success.mode';
            } elseif ($mode === 'redirect') {
                if (!isset($tpl['success']['redirect_url']) || !is_string($tpl['success']['redirect_url'])) {
                    $errors[] = 'success.redirect_url';
                }
            }
        }
        // fields
        if (is_array($tpl['fields'])) {
            $seen = [];
            $reserved = ['form_id','instance_id','eforms_token','eforms_hp','timestamp','js_ok','ip','submitted_at'];
            $allowedTypes = ['name','email','textarea','tel_us','zip_us','select','radio','checkbox','row_group'];
            foreach ($tpl['fields'] as $idx => $f) {
                if (!is_array($f)) {
                    $errors[] = "fields[$idx]";
                    continue;
                }
                $type = $f['type'] ?? '';
                if (!in_array($type, $allowedTypes, true)) {
                    $errors[] = "fields[$idx].type";
                    continue;
                }
                if ($type === 'row_group') {
                    if (isset($f['key'])) {
                        $errors[] = "fields[$idx].key";
                    }
                    $mode = $f['mode'] ?? '';
                    if (!in_array($mode, ['start','end'], true)) {
                        $errors[] = "fields[$idx].mode";
                    }
                    $tag = $f['tag'] ?? 'div';
                    if (!in_array($tag, ['div','section'], true)) {
                        $errors[] = "fields[$idx].tag";
                    }
                    continue;
                }
                $key = $f['key'] ?? '';
                if (!is_string($key) || !preg_match('/^[a-z0-9_:-]{1,64}$/', $key)) {
                    $errors[] = "fields[$idx].key";
                    continue;
                }
                if (in_array($key, $reserved, true)) {
                    $errors[] = "fields[$idx].key_reserved";
                    continue;
                }
                if (isset($seen[$key])) {
                    $errors[] = "fields[$idx].key_duplicate";
                    continue;
                }
                $seen[$key] = true;
            }
        }
        return ['ok'=>empty($errors), 'errors'=>$errors];
    }
}
