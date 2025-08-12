<?php
// includes/Normalizer.php

class ValueNormalizer {
    /**
     * Normalize a string in a lossless manner.
     *
     * Applies wp_unslash, trims whitespace, and converts to NFC if the Intl
     * Normalizer class is available.
     */
    public static function normalize(string $value): string {
        $value = wp_unslash($value);
        $value = trim($value);
        if (class_exists('\\Normalizer')) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_C);
        }
        return $value;
    }
}
