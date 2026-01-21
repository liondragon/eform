<?php
/**
 * Timing-derived soft signals for spam decisioning.
 *
 * Spec: Timing checks (docs/Canonical_Spec.md#sec-timing-checks)
 */

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

        $email_retry = self::post_nonempty($post, 'eforms_email_retry');
        $elapsed = $issued_at > 0 ? max(0, $now - $issued_at) : 0;

        if ($min_fill_seconds > 0 && !$email_retry && $elapsed < $min_fill_seconds) {
            $soft_reasons[] = 'min_fill_time';
        }

        if ($max_form_age_seconds > 0 && $elapsed > $max_form_age_seconds) {
            $soft_reasons[] = 'age_advisory';
        }

        $js_ok = self::post_string($post, 'js_ok');
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

    private static function post_nonempty($post, $key)
    {
        $value = self::post_string($post, $key);
        return $value !== '';
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
        $value = self::config_value($config, $path);
        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    private static function config_bool($config, $path, $default)
    {
        $value = self::config_value($config, $path);
        if (is_bool($value)) {
            return $value;
        }

        return $default;
    }

    private static function config_value($config, $path)
    {
        if (!is_array($path)) {
            return null;
        }

        $cursor = $config;
        foreach ($path as $segment) {
            if (!is_array($cursor) || !isset($cursor[$segment])) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }
}
