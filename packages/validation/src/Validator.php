<?php

declare(strict_types=1);

namespace Hydra\Validation;

use Hydra\Validation\Contracts\RuleInterface;
use Hydra\Validation\Contracts\ShortCircuitInterface;

/**
 * Validates a set of input values against a per-field list of rules.
 */
final class Validator
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, list<RuleInterface>> $rules keyed by field, dot paths and `*` wildcards allowed
     */
    public function validate(array $data, array $rules): Result
    {
        $errors = [];
        $validated = [];

        foreach ($rules as $pattern => $fieldRules) {
            foreach (Path::expand($data, (string) $pattern) as $field) {
                $this->validateField($data, $field, $fieldRules, $errors, $validated);
            }
        }

        return new Result($errors, $validated);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<RuleInterface> $fieldRules
     * @param array<string, string> $errors
     * @param array<string, mixed> $validated
     */
    private function validateField(
        array $data,
        string $field,
        array $fieldRules,
        array &$errors,
        array &$validated,
    ): void {
        $value = Path::get($data, $field);
        $context = new Context($data, $field);
        $failed = false;

        foreach ($fieldRules as $rule) {
            if ($rule instanceof ShortCircuitInterface && $rule->shortCircuits($value, $context)) {
                break;
            }

            $message = $rule->validate($value, $context);

            if ($message !== null) {
                $errors[$field] = $message;
                $failed = true;
                break; // first failure wins; move to the next field
            }
        }

        // A field enters the validated subset only when it was declared in the
        // rules, actually present in the input (absent stays absent, never
        // invented as null), and produced no error.
        if (!$failed && Path::has($data, $field)) {
            Path::set($validated, $field, $value);
        }
    }
}
