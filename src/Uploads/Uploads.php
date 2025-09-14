<?php
declare(strict_types=1);

namespace EForms\Uploads;

use EForms\Config;
use EForms\Spec;
use EForms\Logging;
use EForms\Helpers;

class Uploads
{
    public static function enabled(): bool
    {
        return (bool) Config::get('uploads.enable', false);
    }

    public static function hasUploadFields(array $tpl): bool
    {
        foreach ($tpl['fields'] as $f) {
            $t = $f['type'] ?? '';
            if ($t === 'file' || $t === 'files') {
                return true;
            }
        }
        return false;
    }

    public static function gc(): void
    {
        $dir = rtrim((string) Config::get('uploads.dir', ''), '/');
        $seconds = (int) Config::get('uploads.retention_seconds', 86400);
        if ($dir === '' || $seconds <= 0 || !is_dir($dir)) {
            return;
        }
        $cutoff = time() - $seconds;
        foreach (glob($dir . '/[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]') ?: [] as $sub) {
            foreach (glob($sub . '/*') ?: [] as $f) {
                if (@filemtime($f) !== false && filemtime($f) < $cutoff) {
                    @unlink($f);
                }
            }
            if (count(glob($sub . '/*') ?: []) === 0) {
                @rmdir($sub);
            }
        }
    }

    public static function normalizeAndValidate(array $tpl, array $files): array
    {
        if (defined('EFORMS_FINFO_UNAVAILABLE')) {
            Logging::write('warn', 'EFORMS_FINFO_UNAVAILABLE', ['form_id' => $tpl['id'] ?? '']);
            $errors = [];
            foreach ($tpl['fields'] as $f) {
                $type = $f['type'] ?? '';
                if ($type === 'file' || $type === 'files') {
                    $errors[$f['key']][] = 'File uploads are unsupported on this server.';
                }
            }
            return ['files' => [], 'errors' => $errors];
        }

        $flat = self::flatten($files);
        $errors = [];
        $valid = [];
        $totalRequest = 0;
        $maxFile = (int) Config::get('uploads.max_file_bytes', 5000000);
        $maxFieldBytes = (int) Config::get('uploads.total_field_bytes', 10000000);
        $maxRequest = (int) Config::get('uploads.total_request_bytes', 20000000);
        $maxFiles = (int) Config::get('uploads.max_files', 10);
        $allowedGlobal = Config::get('uploads.allowed_tokens', ['image','pdf']);
        $allowedMime = array_map('strtolower', (array) Config::get('uploads.allowed_mime', []));
        $allowedExt = array_map('strtolower', (array) Config::get('uploads.allowed_ext', []));
        $maxImagePx = (int) Config::get('uploads.max_image_px', 50000000);

        foreach ($tpl['fields'] as $f) {
            $type = $f['type'] ?? '';
            if ($type !== 'file' && $type !== 'files') {
                continue;
            }
            $k = $f['key'];
            $items = $flat[$k] ?? [];
            $accept = $f['accept'] ?? [];
            if (!is_array($accept)) {
                $accept = [];
            }
            $accept = array_intersect($accept, $allowedGlobal);
            $fieldMaxFile = isset($f['max_file_bytes']) && is_int($f['max_file_bytes']) ? $f['max_file_bytes'] : $maxFile;
            $fieldMaxFiles = $type === 'files'
                ? (isset($f['max_files']) && is_int($f['max_files']) ? $f['max_files'] : $maxFiles)
                : 1;
            $fieldBytes = 0;
            $fieldCount = 0;
            foreach ($items as $it) {
                $err = $it['error'];
                $size = $it['size'];
                $name = $it['original_name'];
                if ($err !== UPLOAD_ERR_OK || $size <= 0 || $name === '') {
                    continue;
                }
                if ($size > $fieldMaxFile) {
                    $errors[$k][] = 'This file exceeds the size limit.';
                    continue;
                }
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = $finfo ? strtolower((string) finfo_file($finfo, $it['tmp_name'])) : '';
                if ($finfo) finfo_close($finfo);
                $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
                if (!self::allowedToken($accept, $mime, $ext)) {
                    $errors[$k][] = "This file type isn't allowed.";
                    Logging::write('warn', 'EFORMS_ERR_UPLOAD_TYPE', ['form_id' => $tpl['id'] ?? '', 'field' => $k]);
                    continue;
                }
                if ($allowedMime && !in_array($mime, $allowedMime, true)) {
                    $errors[$k][] = "This file type isn't allowed.";
                    Logging::write('warn', 'EFORMS_ERR_UPLOAD_TYPE', ['form_id' => $tpl['id'] ?? '', 'field' => $k]);
                    continue;
                }
                if ($allowedExt && !in_array($ext, $allowedExt, true)) {
                    $errors[$k][] = "This file type isn't allowed.";
                    Logging::write('warn', 'EFORMS_ERR_UPLOAD_TYPE', ['form_id' => $tpl['id'] ?? '', 'field' => $k]);
                    continue;
                }
                if (str_starts_with($mime, 'image/')) {
                    $dim = @getimagesize($it['tmp_name']);
                    if (!$dim) {
                        $errors[$k][] = 'File upload failed. Please try again.';
                        continue;
                    }
                    if ((int) $dim[0] * (int) $dim[1] > $maxImagePx) {
                        $errors[$k][] = 'Image dimensions too large.';
                        continue;
                    }
                }
                $fieldBytes += $size;
                $fieldCount++;
                $totalRequest += $size;
                [$slug, $extSafe, $originalSafe] = self::sanitizeOriginal($name, $ext);
                $valid[$k][] = [
                    'tmp_name' => $it['tmp_name'],
                    'size' => $size,
                    'mime' => $mime,
                    'slug' => $slug,
                    'ext' => $extSafe,
                    'original_name' => $name,
                    'original_name_safe' => $originalSafe,
                ];
            }
            if (!empty($f['required']) && $fieldCount === 0) {
                $errors[$k][] = 'This field is required.';
            }
            if ($fieldBytes > $maxFieldBytes) {
                $errors[$k][] = 'This file exceeds the size limit.';
            }
            if ($fieldCount > $fieldMaxFiles) {
                $errors[$k][] = 'Too many files.';
            }
        }
        if ($totalRequest > $maxRequest) {
            $errors['_global'][] = 'File upload failed. Please try again.';
        }
        return ['files' => $valid, 'errors' => $errors];
    }

    public static function store(array $files): array
    {
        $out = [];
        $base = rtrim((string) Config::get('uploads.dir', ''), '/');
        if ($base === '') {
            return $out;
        }
        Helpers::ensure_private_dir($base);
        $names = [];
        foreach ($files as $k => $list) {
            foreach ($list as $item) {
                $safeName = self::uniqueName($item['original_name_safe'], $names);
                $rel = self::buildPath($base, $item['tmp_name'], $item['slug'], $item['ext']);
                $dest = $base . '/' . $rel;
                $dir = dirname($dest);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0700, true);
                }
                if (!@move_uploaded_file($item['tmp_name'], $dest)) {
                    @rename($item['tmp_name'], $dest);
                }
                @chmod($dest, 0600);
                $fullSha = hash_file('sha256', $dest);
                $out[$k][] = [
                    'path' => $rel,
                    'size' => $item['size'],
                    'mime' => $item['mime'],
                    'original_name' => $item['original_name'],
                    'original_name_safe' => $safeName,
                    'sha256' => $fullSha,
                ];
            }
        }
        return $out;
    }

    private static function flatten(array $files): array
    {
        $out = [];
        foreach ($files as $key => $f) {
            if (!isset($f['name'])) continue;
            if (is_array($f['name'])) {
                $count = count($f['name']);
                for ($i = 0; $i < $count; $i++) {
                    $out[$key][] = [
                        'tmp_name' => $f['tmp_name'][$i] ?? '',
                        'original_name' => $f['name'][$i] ?? '',
                        'size' => (int) ($f['size'][$i] ?? 0),
                        'error' => (int) ($f['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                    ];
                }
            } else {
                $out[$key][] = [
                    'tmp_name' => $f['tmp_name'] ?? '',
                    'original_name' => $f['name'] ?? '',
                    'size' => (int) ($f['size'] ?? 0),
                    'error' => (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE),
                ];
            }
        }
        return $out;
    }

    private static function allowedToken(array $accept, string $mime, string $ext): bool
    {
        $map = Spec::acceptTokenMap();
        foreach ($accept as $token) {
            if (!isset($map[$token])) continue;
            foreach ($map[$token] as $m => $exts) {
                if ($mime === $m && in_array($ext, $exts, true)) {
                    return true;
                }
                if ($mime === 'application/octet-stream' && in_array($ext, $exts, true)) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function deleteStored(array $files): void
    {
        $base = rtrim((string) Config::get('uploads.dir', ''), '/');
        if ($base === '') return;
        foreach ($files as $list) {
            foreach ($list as $item) {
                $path = $base . '/' . ($item['path'] ?? '');
                if ($path !== $base . '/') {
                    @unlink($path);
                }
            }
        }
    }

    public static function unlinkTemps(array $files): void
    {
        foreach (self::flatten($files) as $items) {
            foreach ($items as $it) {
                $tmp = $it['tmp_name'] ?? '';
                if ($tmp && is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        }
    }

    private static function sanitizeOriginal(string $name, string $ext): array
    {
        $base = basename($name);
        if (class_exists('Normalizer')) {
            $base = \Normalizer::normalize($base, \Normalizer::FORM_C) ?: $base;
        }
        if (function_exists('sanitize_file_name')) {
            $tmp = sanitize_file_name($base . ($ext !== '' ? '.' . $ext : ''));
            $sanitizedExt = strtolower(pathinfo($tmp, PATHINFO_EXTENSION));
            if ($sanitizedExt !== '') {
                $ext = $sanitizedExt;
            }
        }

        $raw = pathinfo($base, PATHINFO_FILENAME);
        $raw = preg_replace('/[\x00-\x1F\x7F]+/', '', $raw);
        $raw = preg_replace('/[\s.]+/', ' ', $raw);
        $raw = trim($raw, ' .');

        $max = (int) Config::get('uploads.original_maxlen', 100);

        $display = $raw;
        if (Config::get('uploads.transliterate', true)) {
            $display = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $display) ?: $display;
        }
        $display = str_replace(['\\','/','<','>',':','"','|','?','*'], '-', $display);
        $display = preg_replace('/\s+/', ' ', $display);
        $display = trim($display, ' ');
        if (strlen($display) > $max) {
            $display = substr($display, 0, $max);
        }
        if ($display === '') {
            $display = 'file';
        }
        $reserved = ['con','prn','aux','nul','com1','com2','com3','com4','com5','com6','com7','com8','com9','lpt1','lpt2','lpt3','lpt4','lpt5','lpt6','lpt7','lpt8','lpt9'];
        if (in_array(strtolower($display), $reserved, true)) {
            $display = strtolower($display) . '_';
        }

        $slugBase = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $display) ?: $display;
        $slug = strtolower(preg_replace('/[^A-Za-z0-9_-]+/', '-', $slugBase));
        $slug = trim($slug, '-');
        if (strlen($slug) > $max) {
            $slug = substr($slug, 0, $max);
        }
        if ($slug === '') {
            $slug = 'file';
        }

        $extSafe = strtolower($ext);
        $originalSafe = strtolower($display) . ($extSafe !== '' ? '.' . $extSafe : '');
        return [$slug, $extSafe, $originalSafe];
    }

    private static function uniqueName(string $name, array &$used): string
    {
        $candidate = $name;
        $i = 1;
        while (isset($used[$candidate])) {
            $i++;
            $base = pathinfo($name, PATHINFO_FILENAME);
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $candidate = $base . ' (' . $i . ')' . ($ext !== '' ? '.' . $ext : '');
        }
        $used[$candidate] = true;
        return $candidate;
    }

    private static function buildPath(string $baseDir, string $tmp, string $slug, string $ext): string
    {
        $date = gmdate('Ymd');
        $sha = substr(hash_file('sha256', $tmp), 0, 16);
        $seq = 1;
        $maxRel = (int) Config::get('uploads.max_relative_path_chars', 180);
        do {
            $name = $slug . '-' . $sha . '-' . $seq . ($ext !== '' ? '.' . $ext : '');
            $rel = $date . '/' . $name;
            if (strlen($rel) > $maxRel) {
                $allow = $maxRel - strlen($date . '/-' . $sha . '-' . $seq . ($ext !== '' ? '.' . $ext : ''));
                if ($allow < 1) {
                    $allow = 1;
                }
                $slug = substr($slug, 0, $allow);
                continue;
            }
            $dest = $baseDir . '/' . $rel;
            if (!file_exists($dest)) {
                return $rel;
            }
            $seq++;
        } while (true);
    }
}
