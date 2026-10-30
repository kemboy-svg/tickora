<?php

namespace App\Services\Payments\Concerns;

/**
 * Typed reads from decoded provider JSON, which is untrusted input.
 */
trait ReadsProviderJson
{
    /**
     * @param  array<mixed>  $data
     */
    private static function str(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : null);
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function arr(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== $name) {
                continue;
            }

            $value = is_array($value) ? ($value[0] ?? null) : $value;

            return is_string($value) ? trim($value) : null;
        }

        return null;
    }
}
