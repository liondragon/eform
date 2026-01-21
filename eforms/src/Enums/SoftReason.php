<?php
/**
 * Soft reasons enum (closed set per spec).
 *
 * Spec: Spam Decision (docs/Canonical_Spec.md#sec-spam-decision)
 */

enum SoftReason: string
{
    case MinFillTime = 'min_fill_time';
    case AgeAdvisory = 'age_advisory';
    case JsMissing = 'js_missing';
    case OriginSoft = 'origin_soft';

    /**
     * Deduplicate and order by canonical case order.
     *
     * @param list<SoftReason> $reasons
     * @return list<SoftReason>
     */
    public static function normalize(array $reasons): array
    {
        $seen = [];
        foreach ($reasons as $reason) {
            $seen[$reason->value] = $reason;
        }

        $ordered = [];
        foreach (self::cases() as $case) {
            if (isset($seen[$case->value])) {
                $ordered[] = $case;
            }
        }

        return $ordered;
    }

    /**
     * @param list<SoftReason> $reasons
     * @return list<string>
     */
    public static function toStrings(array $reasons): array
    {
        return array_map(fn(self $r) => $r->value, $reasons);
    }
}
