<?php
declare(strict_types=1);

namespace Researched;

final class Support
{
    public static function rootPath(string $path = ''): string
    {
        $root = dirname(__DIR__);
        return $path === '' ? $root : $root . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }

    public static function loadEnv(?string $file = null): void
    {
        $file ??= self::rootPath('.env');
        if (!is_file($file) || !is_readable($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $trimmed, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key === '' || getenv($key) !== false) {
                continue;
            }

            if (
                strlen($value) >= 2
                && (($value[0] === '"' && $value[strlen($value) - 1] === '"')
                    || ($value[0] === "'" && $value[strlen($value) - 1] === "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }

    public static function env(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value === false || trim($value) === '') {
            return $default;
        }
        return $value;
    }

    public static function now(): string
    {
        return gmdate('c');
    }

    public static function id(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(8));
    }

    /**
     * Return a JSON response and stop execution.
     *
     * @param mixed $payload
     */
    public static function jsonResponse(mixed $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * @return array<string,mixed>
     */
    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Request body must be valid JSON.');
        }
        return $decoded;
    }

    public static function preserveText(mixed $value, int $maxLength = 30000): string
    {
        if (!is_scalar($value) && $value !== null) {
            return '';
        }
        $text = str_replace(["\r\n", "\r"], "\n", (string) $value);
        $text = str_replace("\0", '', $text);
        if (self::length($text) > $maxLength) {
            $text = self::truncate($text, $maxLength);
        }
        return $text;
    }

    public static function cleanText(mixed $value, int $maxLength = 10000): string
    {
        if (!is_scalar($value) && $value !== null) {
            return '';
        }
        $text = trim((string) $value);
        $text = preg_replace('/\R/u', "\n", $text) ?? $text;
        if (self::length($text) > $maxLength) {
            $text = self::truncate($text, $maxLength);
        }
        return $text;
    }

    public static function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    }

    public static function truncate(string $text, int $maxLength): string
    {
        if ($maxLength < 0) {
            return '';
        }
        return function_exists('mb_substr')
            ? mb_substr($text, 0, $maxLength)
            : substr($text, 0, $maxLength);
    }

    public static function canonicalJson(array $value): string
    {
        $normalised = self::sortRecursively($value);
        return json_encode($normalised, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function sortRecursively(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map([self::class, 'sortRecursively'], $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = self::sortRecursively($item);
        }
        return $value;
    }

    /**
     * @param array<string,mixed> $record
     */
    public static function stableRecordHash(array $record): string
    {
        $copy = $record;
        unset($copy['id'], $copy['created_at'], $copy['updated_at'], $copy['hash']);
        return hash('sha256', self::canonicalJson($copy));
    }

    public static function boolEnv(string $key, bool $default = false): bool
    {
        $value = self::env($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}
