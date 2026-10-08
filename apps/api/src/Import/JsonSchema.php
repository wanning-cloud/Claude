<?php

declare(strict_types=1);

namespace Cockpit\Import;

/**
 * Minimal JSON Schema validator for the keywords that z.toJSONSchema emits for the import schema
 * (type, properties, required, additionalProperties, propertyNames, items, enum, const, pattern,
 * minLength, maxLength, minimum, maximum, exclusiveMinimum, anyOf). Unknown keywords are rejected
 * by a test so the PHP side never silently ignores a rule.
 */
final class JsonSchema
{
    public const SUPPORTED = [
        '$schema', 'type', 'properties', 'required', 'additionalProperties', 'propertyNames', 'items', 'enum',
        'const', 'pattern', 'minLength', 'maxLength', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum',
        'anyOf', 'description', 'format',
    ];

    /** @param array<string, mixed> $schema @return list<string> errors in German, with JSON path */
    public static function validate(mixed $value, array $schema, string $path = '$'): array
    {
        $errors = [];
        if (isset($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $option) {
                if (self::validate($value, $option, $path) === []) {
                    return [];
                }
            }
            return ["{$path}: passt zu keiner erlaubten Form."];
        }
        if (isset($schema['type']) && !self::hasType($value, $schema['type'])) {
            return ["{$path}: erwartet " . implode(' oder ', (array) $schema['type']) . '.'];
        }
        if (isset($schema['const']) && $value !== $schema['const']) {
            $errors[] = "{$path}: muss " . json_encode($schema['const']) . ' sein.';
        }
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            $errors[] = "{$path}: erlaubt sind " . implode(', ', array_map('json_encode', $schema['enum'])) . '.';
        }
        if (is_string($value)) {
            $length = mb_strlen($value);
            if (isset($schema['minLength']) && $length < $schema['minLength']) {
                $errors[] = "{$path}: darf nicht leer sein.";
            }
            if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
                $errors[] = "{$path}: ist zu lang.";
            }
            if (isset($schema['pattern']) && !preg_match('/' . str_replace('/', '\/', $schema['pattern']) . '/u', $value)) {
                $errors[] = "{$path}: hat nicht das erwartete Format.";
            }
        }
        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = "{$path}: muss mindestens {$schema['minimum']} sein.";
            }
            if (isset($schema['exclusiveMinimum']) && $value <= $schema['exclusiveMinimum']) {
                $errors[] = "{$path}: muss größer als {$schema['exclusiveMinimum']} sein.";
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = "{$path}: darf höchstens {$schema['maximum']} sein.";
            }
            if (isset($schema['exclusiveMaximum']) && $value >= $schema['exclusiveMaximum']) {
                $errors[] = "{$path}: muss kleiner als {$schema['exclusiveMaximum']} sein.";
            }
        }
        if (is_array($value) && array_is_list($value) && isset($schema['items']) && ($schema['type'] ?? null) === 'array') {
            foreach ($value as $i => $item) {
                array_push($errors, ...self::validate($item, $schema['items'], "{$path}[{$i}]"));
            }
        }
        if (is_array($value) && ($schema['type'] ?? null) === 'object') {
            foreach ($schema['required'] ?? [] as $key) {
                if (!array_key_exists($key, $value)) {
                    $errors[] = "{$path}.{$key}: fehlt.";
                }
            }
            $properties = $schema['properties'] ?? [];
            foreach ($value as $key => $item) {
                $key = (string) $key;
                if (isset($schema['propertyNames'])) {
                    array_push($errors, ...self::validate($key, $schema['propertyNames'], "{$path}.{$key} (Name)"));
                }
                if (isset($properties[$key])) {
                    array_push($errors, ...self::validate($item, $properties[$key], "{$path}.{$key}"));
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = "{$path}.{$key}: ist nicht vorgesehen.";
                } elseif (is_array($schema['additionalProperties'] ?? null)) {
                    array_push($errors, ...self::validate($item, $schema['additionalProperties'], "{$path}.{$key}"));
                }
            }
        }
        return $errors;
    }

    private static function hasType(mixed $value, string|array $types): bool
    {
        foreach ((array) $types as $type) {
            $ok = match ($type) {
                'object' => is_array($value) && ($value === [] || !array_is_list($value)),
                'array' => is_array($value) && array_is_list($value),
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                default => false,
            };
            if ($ok) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $schema @return list<string> keywords this validator does not know */
    public static function unsupportedKeywords(array $schema): array
    {
        $out = [];
        foreach ($schema as $key => $value) {
            if (!in_array($key, self::SUPPORTED, true)) {
                $out[] = (string) $key;
            }
            $children = match ($key) {
                'properties' => is_array($value) ? array_values($value) : [],
                'items', 'propertyNames' => [$value],
                'additionalProperties' => is_array($value) ? [$value] : [],
                'anyOf' => $value,
                default => [],
            };
            foreach ($children as $child) {
                if (is_array($child)) {
                    array_push($out, ...self::unsupportedKeywords($child));
                }
            }
        }
        return array_values(array_unique($out));
    }
}
