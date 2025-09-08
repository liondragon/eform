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
        $flat = self::flatten($files);
        $errors = [];
        $valid = [];
        $totalRequest = 0;
        $maxFile = (int) Config::get('uploads.max_file_bytes', 5000000);
        $maxFieldBytes = (int) Config::get('uploads.total_field_bytes', 10000000);
        $maxRequest = (int) Config::get('uploads.total_request_bytes', 20000000);
        $maxFiles = (int) Config::get('uploads.max_files', 10);
        $allowedGlobal = Config::get('uploads.allowed_tokens', ['image','pdf']);

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
            $fieldBytes = 0;
            $fieldCount = 0;
            foreach ($items as $it) {
                $err = $it['error'];
                $size = $it['size'];
                $name = $it['original_name'];
                if ($err !== UPLOAD_ERR_OK || $size <= 0 || $name === '') {
                    continue;
                }
                if ($size > $maxFile) {
                    $errors[$k][] = 'File too large.';
                    continue;
                }
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = $finfo ? (string) finfo_file($finfo, $it['tmp_name']) : '';
                if ($finfo) finfo_close($finfo);
                $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
                if (!self::allowedToken($accept, $mime, $ext)) {
                    $errors[$k][] = 'Invalid file type.';
                    continue;
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
                $errors[$k][] = 'Total upload size exceeded.';
            }
            if ($fieldCount > $maxFiles || ($type === 'file' && $fieldCount > 1)) {
                $errors[$k][] = 'Too many files.';
            }
        }
        if ($totalRequest > $maxRequest) {
            $errors['_global'][] = 'Upload limit exceeded.';
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
        foreach ($files as $k => $list) {
            foreach ($list as $item) {
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
                $out[$k][] = [
                    'path' => $rel,
                    'size' => $item['size'],
                    'mime' => $item['mime'],
                    'original_name' => $item['original_name'],
                    'original_name_safe' => $item['original_name_safe'],
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
        if (in_array('image', $accept, true) && str_starts_with($mime, 'image/')) {
            return true;
        }
        if (in_array('pdf', $accept, true) && $mime === 'application/pdf' && $ext === 'pdf') {
            return true;
        }
        return false;
    }

    private static function sanitizeOriginal(string $name, string $ext): array
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        if (Config::get('uploads.transliterate', true)) {
            $base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base) ?: $base;
        }
        $base = strtolower(preg_replace('/[^A-Za-z0-9_-]+/', '-', $base));
        $base = trim($base, '-');
        $max = (int) Config::get('uploads.original_maxlen', 100);
        if (strlen($base) > $max) {
            $base = substr($base, 0, $max);
        }
        if ($base === '') {
            $base = 'file';
        }
        $extSafe = strtolower($ext);
        $originalSafe = $base . ($extSafe !== '' ? '.' . $extSafe : '');
        return [$base, $extSafe, $originalSafe];
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
