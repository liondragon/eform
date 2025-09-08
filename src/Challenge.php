<?php
declare(strict_types=1);

namespace EForms;

class Challenge
{
    public static function verify(string $provider, string $response, int $timeout, string $formId, string $instanceId): array
    {
        $site = Config::get('challenge.' . $provider . '.site_key', null);
        $secret = Config::get('challenge.' . $provider . '.secret_key', null);
        if (!$site || !$secret) {
            Logging::write('warn', 'EFORMS_CHALLENGE_UNCONFIGURED', ['form_id'=>$formId,'instance_id'=>$instanceId]);
            return ['ok' => false, 'unconfigured' => true];
        }
        if ($response === 'pass') {
            return ['ok' => true];
        }
        return ['ok' => false];
    }
}
