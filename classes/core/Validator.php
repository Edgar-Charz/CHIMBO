<?php

/**
 * Checks input against a list of rules. Used inside the domain classes.
 *
 * How to use it:
 *   $data = Validator::validate($input, [
 *       'user_phone'     => 'required|phone_tz',
 *       'user_full_name' => 'required|string|min:2|max:100',
 *       'business_name'  => 'nullable|string|max:120',
 *       'region_id'      => 'required|int|min:1',
 *   ]);
 *
 * Result:
 *   - All good → returns only the fields listed above, cleaned:
 *     text trimmed, numbers as int, true/false as bool, phones as +255XXXXXXXXX.
 *   - Something wrong → throws a 422 error listing EVERY wrong field with a Swahili message,
 *     e.g. ['user_phone' => 'Namba ya simu si sahihi.'].
 *
 * Rules:
 *   required       the field must be sent and not empty
 *   nullable       the field may be empty (it becomes null)
 *   string         text (the default)     int / bool / array   the type of value
 *   email          a valid email          phone_tz            a Tanzanian mobile number
 *   digits:6       exactly 6 digits       in:sw,en            one of the listed values
 *   min:2 max:100  length of text, size of a number, or number of items in a list
 *   date           a day, e.g. 2026-10-01                      → returned as "2026-10-01"
 *   datetime       a day and time, e.g. 2026-10-01T08:30 (from <input type="datetime-local">)
 *                  → returned as "2026-10-01 08:30:00" (the time exactly as typed; no time-zone change)
 */
class Validator
{
    public static function validate(array $input, array $rules): array
    {
        $clean_data = [];
        $errors     = [];

        foreach ($rules as $field => $rule_text) {
            $field_rules = self::parseRules($rule_text);
            $was_sent    = array_key_exists($field, $input);
            $has_value   = $was_sent && !self::isEmpty($input[$field]);

            // Empty or missing field
            if (!$has_value) {
                if (isset($field_rules['required'])) {
                    $errors[$field] = 'Sehemu hii inahitajika.';
                } elseif (isset($field_rules['nullable']) && $was_sent) {
                    $clean_data[$field] = null; // sent empty on purpose → clear the value
                }
                continue;
            }

            // Field has a value: convert its type and check its limits
            try {
                $clean_data[$field] = self::applyRules($input[$field], $field_rules);
            } catch (InvalidArgumentException $exception) {
                $errors[$field] = $exception->getMessage();
            }
        }

        if ($errors) {
            throw ApiException::validation($errors);
        }

        return $clean_data;
    }

    /**
     * Turns the rule text into a list:
     * "required|string|max:100" → ['required' => '', 'string' => '', 'max' => '100']
     */
    private static function parseRules(string $rule_text): array
    {
        $parsed_rules = [];
        foreach (explode('|', $rule_text) as $rule) {
            [$name, $argument] = array_pad(explode(':', $rule, 2), 2, '');
            $parsed_rules[$name] = $argument;
        }
        return $parsed_rules;
    }

    /** null, "", "   " and [] all count as empty. */
    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === [] || (is_string($value) && trim($value) === '');
    }

    /** Converts the value to its type, then runs the limit checks (min, max, in …). */
    private static function applyRules(mixed $value, array $rules): mixed
    {
        $value = self::castType($value, $rules);

        foreach ($rules as $name => $argument) {
            match ($name) {
                'min'    => self::checkMin($value, (int) $argument),
                'max'    => self::checkMax($value, (int) $argument),
                'in'     => self::checkIn($value, explode(',', $argument)),
                'digits' => self::checkDigits($value, (int) $argument),
                'email'  => self::checkEmail($value),

                // These were already handled in validate() or castType()
                'required', 'nullable', 'string', 'int', 'bool', 'array', 'phone_tz', 'date', 'datetime' => null,

                // A typo in a rule is a programming mistake, not a user mistake
                default  => throw new LogicException("Unknown validation rule: {$name}"),
            };
        }

        return $value;
    }

    /** Converts the value to the type named in the rules, or fails with a message for the user. */
    private static function castType(mixed $value, array $rules): mixed
    {
        if (isset($rules['int'])) {
            $is_whole_number = is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', trim($value)));
            if (!$is_whole_number) {
                throw new InvalidArgumentException('Weka namba sahihi.');
            }
            return (int) $value;
        }

        if (isset($rules['date'])) {
            return self::parseDate($value, 'Y-m-d', ['Y-m-d'], 'Weka tarehe sahihi, mfano 2026-10-01.');
        }

        if (isset($rules['datetime'])) {
            return self::parseDate($value, 'Y-m-d H:i:s', ['Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s'],
                'Weka tarehe na saa sahihi, mfano 2026-10-01 08:30.');
        }

        if (isset($rules['bool'])) {
            // Accepts true/false, 1/0, "yes"/"no", "on"/"off"
            $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($bool === null) {
                throw new InvalidArgumentException('Thamani hii si sahihi.');
            }
            return $bool;
        }

        if (isset($rules['array'])) {
            if (!is_array($value)) {
                throw new InvalidArgumentException('Orodha inahitajika.');
            }
            return $value;
        }

        if (isset($rules['phone_tz'])) {
            $phone = is_string($value) ? Phone::normalizeTz($value) : null;
            if ($phone === null) {
                throw new InvalidArgumentException('Namba ya simu si sahihi. Mfano: 0712 345 678.');
            }
            return $phone;
        }

        // Everything else is text
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException('Maandishi yanahitajika.');
        }
        return trim((string) $value);
    }

    /** Accepts the value only if it is a real date in one of $accepted_formats (e.g. rejects 2026-02-30). */
    private static function parseDate(mixed $value, string $output_format, array $accepted_formats, string $message): string
    {
        foreach ($accepted_formats as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, trim((string) $value));
            if ($date !== false && $date->format($format) === trim((string) $value)) {
                return $date->format($output_format);
            }
        }
        throw new InvalidArgumentException($message);
    }

    private static function checkMin(mixed $value, int $min): void
    {
        if (is_int($value) && $value < $min) {
            throw new InvalidArgumentException("Kiwango cha chini ni {$min}.");
        }
        if (is_string($value) && mb_strlen($value) < $min) {
            throw new InvalidArgumentException("Angalau herufi {$min} zinahitajika.");
        }
        if (is_array($value) && count($value) < $min) {
            throw new InvalidArgumentException("Angalau vitu {$min} vinahitajika.");
        }
    }

    private static function checkMax(mixed $value, int $max): void
    {
        if (is_int($value) && $value > $max) {
            throw new InvalidArgumentException("Kiwango cha juu ni {$max}.");
        }
        if (is_string($value) && mb_strlen($value) > $max) {
            throw new InvalidArgumentException("Herufi zisizidi {$max}.");
        }
        if (is_array($value) && count($value) > $max) {
            throw new InvalidArgumentException("Vitu visizidi {$max}.");
        }
    }

    private static function checkIn(mixed $value, array $allowed_values): void
    {
        if (!in_array((string) $value, $allowed_values, true)) {
            throw new InvalidArgumentException('Chaguo hili halikubaliki.');
        }
    }

    private static function checkDigits(mixed $value, int $length): void
    {
        if (!preg_match('/^\d{' . $length . '}$/', (string) $value)) {
            throw new InvalidArgumentException("Weka tarakimu {$length}.");
        }
    }

    private static function checkEmail(mixed $value): void
    {
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Barua pepe si sahihi.');
        }
    }
}
