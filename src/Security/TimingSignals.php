<?php
/**
 * Timing-derived soft signals for spam decisioning.
 *
 * Contract: Timing checks
 */

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../FormProtocol.php';

class TimingSignals
{
    /**
     * Evaluate timing-derived signals.
     *
     * @param array $post POST payload.
     * @param array $record Token record with issued_at/expires.
     * @param array $config Frozen config snapshot.
     * @param int|null $now Optional timestamp for consistency.
     * @return array { soft_reasons, hard_fail }
     */
    public static function evaluate($post, $record, $config, $now = null)
    {
        $post = is_array($post) ? $post : array();
        $record = is_array($record) ? $record : array();

        $issued_at = isset($record['issued_at']) ? (int) $record['issued_at'] : 0;
        $now = is_int($now) ? $now : time();

        $soft_reasons = array();
        $hard_fail = false;

        $min_fill_seconds = self::config_int($config, array('security', 'min_fill_seconds'), 0);
        if ($min_fill_seconds < 0) {
            $min_fill_seconds = 0;
        }

        $max_form_age_seconds = self::config_int($config, array('security', 'max_form_age_seconds'), 0);
        if ($max_form_age_seconds < 0) {
            $max_form_age_seconds = 0;
        }

        $js_hard_mode = self::config_bool($config, array('security', 'js_hard_mode'), false);

        $elapsed = $issued_at > 0 ? max(0, $now - $issued_at) : 0;

        if ($min_fill_seconds > 0 && $elapsed < $min_fill_seconds) {
            $soft_reasons[] = 'min_fill_time';
        }

        if ($max_form_age_seconds > 0 && $elapsed > $max_form_age_seconds) {
            $soft_reasons[] = 'age_advisory';
        }

        $js_ok = self::post_string($post, FormProtocol::FIELD_JS_OK);
        if ($js_ok !== '1') {
            if ($js_hard_mode) {
                return array(
                    'soft_reasons' => array(),
                    'hard_fail' => true,
                );
            }
            $soft_reasons[] = 'js_missing';
        }

        return array(
            'soft_reasons' => $soft_reasons,
            'hard_fail' => $hard_fail,
        );
    }

    private static function post_string($post, $key)
    {
        if (!is_array($post) || !isset($post[$key])) {
            return '';
        }

        $value = $post[$key];
        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    private static function config_int($config, $path, $default)
    {
        $value = Config::value($config, $path);
        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    private static function config_bool($config, $path, $default)
    {
        return Config::bool($config, $path, $default);
    }
}
