<?php
declare(strict_types=1);

namespace EForms;

class Uploads
{
    public static function enabled(): bool
    {
        return (bool) Config::get('uploads.enable', false);
    }

    public static function hasUploadFields(array $tpl): bool
    {
        foreach ($tpl['fields'] as $f) {
            if (($f['type'] ?? '') === 'file') {
                return true;
            }
        }
        return false;
    }
}
