<?php

namespace Washi;

/**
 * Internal data processing utilities for extracting and casting values.
 * Shared by Entity, Request and the helpers.
 */
class Cast
{
    /**
     * Standardizes various input formats into an associative array.
     */
    public static function extract(mixed $source): array
    {
        if ($source === null) {
            return [];
        }
        if (is_array($source)) {
            return $source;
        }
        if (is_string($source)) {
            return json_decode($source, true) ?: [];
        }
        if (is_object($source)) {
            if (method_exists($source, 'toArray')) {
                return $source->toArray();
            }
            if ($source instanceof \JsonSerializable) {
                return (array)$source->jsonSerialize();
            }
            return get_object_vars($source);
        }
        return (array)$source;
    }

    /**
     * Ensures a value is scalar (not an array). If it is an array, returns the first element.
     */
    public static function ensureScalar(mixed $v): mixed
    {
        return is_array($v) ? ($v[0] ?? null) : $v;
    }

    public static function asString(mixed $v, ?string $default = null): ?string
    {
        $v = self::ensureScalar($v);
        return ($v === null) ? $default : (string)$v;
    }

    public static function asInt(mixed $v, int $default = 0): int
    {
        $v = self::ensureScalar($v);
        return is_numeric($v) ? (int)$v : $default;
    }

    public static function asFloat(mixed $v, float $default = 0.0): float
    {
        $v = self::ensureScalar($v);
        return is_numeric($v) ? (float)$v : $default;
    }

    public static function asBool(mixed $v, bool $default = false): bool
    {
        $v = self::ensureScalar($v);
        if ($v === null) {
            return $default;
        }
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int)$v !== 0;
        }
        if (is_string($v)) {
            $v = strtolower($v);
            return $v === '1' || $v === 'true' || $v === 'on' || $v === 'yes';
        }
        return (bool)$v;
    }

    public static function asArray(mixed $v, array $default = []): array
    {
        if (is_array($v)) {
            return $v;
        }
        if (is_string($v) && str_starts_with($v, '[')) {
            return json_decode($v, true) ?: $default;
        }
        return ($v === null) ? $default : [$v];
    }

    public static function asJson(mixed $v, array $default = []): array
    {
        if (empty($v)) {
            return $default;
        }
        if (is_array($v)) {
            return $v;
        }
        return json_decode($v, true) ?: $default;
    }

    /**
     * Converts various inputs into a DateTime object.
     * Supports: DateTimeInterface, timestamp integers, or date strings.
     */
    public static function asDateTime(mixed $v, mixed $default = null): ?\DateTime
    {
        if ($v instanceof \DateTime) {
            return $v;
        }
        if ($v instanceof \DateTimeInterface) {
            return new \DateTime($v->format('Y-m-d H:i:s.u'), $v->getTimezone());
        }
        if (empty($v)) {
            return ($default instanceof \DateTimeInterface) ? $default : null;
        }
        try {
            if (is_numeric($v)) {
                return (new \DateTime())->setTimestamp((int)$v);
            }
            return new \DateTime((string)$v);
        } catch (\Exception) {
            return ($default instanceof \DateTimeInterface) ? $default : null;
        }
    }
}
