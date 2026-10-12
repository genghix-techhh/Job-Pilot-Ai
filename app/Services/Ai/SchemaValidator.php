<?php

namespace App\Services\Ai;

/**
 * Validates decoded JSON against the small JSON-Schema subset our prompts use
 * (type, properties, required, items, enum, minimum/maximum, min/maxItems, maxLength).
 * Gemini's schema mode is not trusted blindly: every response is checked locally.
 */
class SchemaValidator
{
    /** @return list<string> human-readable problems (empty = valid) */
    public function validate(mixed $data, array $schema, string $path = '$'): array
    {
        $errors = [];
        $types = (array) ($schema['type'] ?? []);

        if ($types !== [] && ! $this->matchesAnyType($data, $types)) {
            return ["{$path}: expected ".implode('|', $types).', got '.get_debug_type($data)];
        }

        if (isset($schema['enum']) && ! in_array($data, $schema['enum'], true)) {
            $errors[] = "{$path}: value not in allowed set";
        }

        if (is_numeric($data) && ! is_string($data)) {
            if (isset($schema['minimum']) && $data < $schema['minimum']) {
                $errors[] = "{$path}: below minimum {$schema['minimum']}";
            }
            if (isset($schema['maximum']) && $data > $schema['maximum']) {
                $errors[] = "{$path}: above maximum {$schema['maximum']}";
            }
        }

        if (is_string($data) && isset($schema['maxLength']) && mb_strlen($data) > $schema['maxLength']) {
            $errors[] = "{$path}: longer than {$schema['maxLength']} characters";
        }

        $isObject = in_array('object', $types, true) && is_array($data) && ($data === [] || ! array_is_list($data));
        if ($isObject) {
            foreach ($schema['required'] ?? [] as $key) {
                if (! array_key_exists($key, (array) $data)) {
                    $errors[] = "{$path}.{$key}: required field missing";
                }
            }
            foreach ($schema['properties'] ?? [] as $key => $sub) {
                if (array_key_exists($key, (array) $data)) {
                    array_push($errors, ...$this->validate($data[$key], $sub, "{$path}.{$key}"));
                }
            }
        }

        if (is_array($data) && array_is_list($data) && in_array('array', $types, true)) {
            if (isset($schema['minItems']) && count($data) < $schema['minItems']) {
                $errors[] = "{$path}: fewer than {$schema['minItems']} items";
            }
            if (isset($schema['maxItems']) && count($data) > $schema['maxItems']) {
                $errors[] = "{$path}: more than {$schema['maxItems']} items";
            }
            if (isset($schema['items'])) {
                foreach ($data as $i => $item) {
                    array_push($errors, ...$this->validate($item, $schema['items'], "{$path}[{$i}]"));
                }
            }
        }

        return $errors;
    }

    /** @param list<string> $types */
    private function matchesAnyType(mixed $data, array $types): bool
    {
        foreach ($types as $type) {
            $ok = match ($type) {
                'string' => is_string($data),
                'integer' => is_int($data) || (is_float($data) && floor($data) === $data),
                'number' => is_int($data) || is_float($data),
                'boolean' => is_bool($data),
                'null' => $data === null,
                'array' => is_array($data) && array_is_list($data),
                'object' => is_array($data) && (! array_is_list($data) || $data === []),
                default => true,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }
}
