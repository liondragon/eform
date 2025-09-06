<?php
declare(strict_types=1);

namespace EForms;

class Logging
{
    public static function write(string $severity, string $code, array $ctx = []): void
    {
        if (Config::get('logging.mode', 'minimal') === 'off') {
            return;
        }
        $level = (int) Config::get('logging.level', 0);
        $sevLevel = 0;
        if ($severity === 'warn') $sevLevel = 1;
        elseif ($severity === 'info') $sevLevel = 2;
        if ($sevLevel > $level) {
            return;
        }
        $form = $ctx['form_id'] ?? '';
        $inst = $ctx['instance_id'] ?? '';
        $msg = $ctx['msg'] ?? '';
        $meta = $ctx;
        unset($meta['form_id'],$meta['instance_id'],$meta['msg']);
        $line = sprintf('eforms severity=%s code=%s form=%s inst=%s msg="%s" meta=%s',
            $severity, $code, $form, $inst, $msg, json_encode($meta));
        error_log($line);
    }
}
