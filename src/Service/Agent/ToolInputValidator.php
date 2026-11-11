<?php

declare(strict_types=1);

namespace App\Service\Agent;

/**
 * Schema-driven validation of tool arguments BEFORE execution (session 2
 * hardening). Validates model-generated input against the tool's own
 * input_schema(): type, required, additionalProperties:false, maxLength,
 * pattern, enum, minimum/maximum. Invalid input NEVER reaches a tool - the
 * refusal is returned to the model as a tool_result DATA block.
 *
 * Deliberately minimal: it knows exactly the JSON-Schema keywords used by
 * the five phase-1 tools and fails CLOSED on what it does not understand.
 */
final class ToolInputValidator
{
    /**
     * @param array<mixed>            $schema the tool's input_schema()
     * @param array<string, mixed>    $input  the model-generated arguments
     *
     * @return list<string> empty list == valid; otherwise stable refusal reasons
     */
    public function validate(array $schema, array $input): array
    {
        if ('object' !== ($schema['type'] ?? 'object')) {
            return ['invalid schema type'];
        }

        $errors = [];

        $known = ['required', 'properties', 'additionalProperties', 'type'];
        // Unknown schema keywords fail closed: a tool with keywords this
        // validator does not understand is not validated.
        foreach (array_keys($schema) as $keyword) {
            if (!\in_array($keyword, $known, true)) {
                return ['schema uses unsupported keywords'];
            }
        }

        $properties = \is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = \is_array($schema['required'] ?? null) ? $schema['required'] : [];

        if (false === ($schema['additionalProperties'] ?? true)) {
            foreach (array_keys($input) as $key) {
                if (!\array_key_exists($key, $properties)) {
                    $errors[] = 'unexpected argument '.(string) $key;
                }
            }
        }

        foreach ($required as $name) {
            if (!\is_string($name) || !\array_key_exists($name, $input)) {
                $errors[] = 'missing required argument '.(string) $name;
            }
        }

        foreach ($properties as $property => $rule) {
            if (!\array_key_exists((string) $property, $input)) {
                continue;
            }
            $rule = \is_array($rule) ? $rule : [];
            $value = $input[$property] ?? null;
            $this->validateProperty((string) $property, $rule, $value, $errors);
        }

        return $errors;
    }

    /**
     * @param array<mixed>          $rule
     * @param mixed                 $value
     * @param list<string>          $errors
     */
    private function validateProperty(string $property, array $rule, $value, array &$errors): void
    {
        $type = \is_string($rule['type'] ?? null) ? (string) $rule['type'] : 'string';

        $valid = match ($type) {
            'string' => \is_string($value),
            'integer' => \is_int($value),
            'number' => \is_int($value) || \is_float($value),
            'boolean' => \is_bool($value),
            default => false, // fail closed
        };

        if (!$valid) {
            $errors[] = $property.' must be '.$type;

            return;
        }

        if (\is_int($rule['maxLength'] ?? null) && \is_string($value) && mb_strlen($value) > $rule['maxLength']) {
            $errors[] = $property.' exceeds maxLength';
        }

        if (\is_string($rule['pattern'] ?? null) && \is_string($value)) {
            $pattern = (string) $rule['pattern'];
            if ('/' !== substr($pattern, 0, 1)) {
                // JSON-Schema patterns are unanchored regexes; match ONLY the
                // whole value so no payload can ride inside a longer string.
                $pattern = '/^(?:'.$pattern.')$/D';
            }
            if (1 !== @preg_match($pattern, $value)) {
                $errors[] = $property.' does not match the required pattern';
            }
        }

        if (\is_array($rule['enum'] ?? null) && !\in_array($value, $rule['enum'], true)) {
            $errors[] = $property.' is not one of the allowed values';
        }

        if ('integer' === $type || 'number' === $type) {
            if (\is_int($rule['minimum'] ?? null) && $value < $rule['minimum']) {
                $errors[] = $property.' is below the minimum';
            }
            if (\is_int($rule['maximum'] ?? null) && $value > $rule['maximum']) {
                $errors[] = $property.' is above the maximum';
            }
        }
    }
}
