<?php

/** Reglas deterministas del dominio Training. No genera entrenamientos. */
final class TrainingPolicy
{
    public const DISCIPLINES = ['GYM','STRENGTH','BOXEO','MMA','BJJ','CONDITIONING','GENERAL'];
    public const EXECUTION_TYPES = ['REPS','TIME','ROUNDS','DISTANCE','CIRCUIT','TECHNIQUE'];
    public const OBJECTIVES = ['FUERZA','HIPERTROFIA','ACONDICIONAMIENTO','TECNICA','MOVILIDAD','GENERAL','PREPARACION_FISICA'];
    public const LEVELS = ['INICIAL','INTERMEDIO','AVANZADO','TODOS'];
    public const DIFFICULTIES = ['INICIAL','INTERMEDIO','AVANZADO'];
    public const MUSCLE_GROUPS = ['PECHO','ESPALDA','PIERNAS','HOMBROS','BICEPS','TRICEPS','CORE','FULL_BODY','OTROS'];
    public const EQUIPMENT = ['PESO_CORPORAL','BARRA','MANCUERNAS','MAQUINA','POLEA','BANCO','SACO','MANOPLAS','COMBA','TATAMI','BATTLE_ROPE','CARDIO','OTRO'];
    public const BLOCK_TYPES = ['WARMUP','TECHNIQUE','STRENGTH','CIRCUIT','CONDITIONING','COOLDOWN','GENERAL'];

    public static function enum(mixed $value, array $allowed, string $field): string
    {
        $value = strtoupper(trim((string) $value));
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($field . ' no válido.');
        }
        return $value;
    }

    public static function optionalEnum(mixed $value, array $allowed, string $field): ?string
    {
        if ($value === null || trim((string) $value) === '') return null;
        return self::enum($value, $allowed, $field);
    }

    public static function text(mixed $value, int $max, string $field, bool $required = true): ?string
    {
        $raw = (string) ($value ?? '');
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $raw)) {
            throw new InvalidArgumentException($field . ' contiene caracteres no permitidos.');
        }
        $text = trim($raw);
        if ($required && $text === '') throw new InvalidArgumentException($field . ' es obligatorio.');
        if (mb_strlen($text) > $max) throw new InvalidArgumentException($field . ' supera el límite permitido.');
        return $text === '' ? null : $text;
    }

    public static function slug(mixed $value): string
    {
        $value = mb_strtolower(trim((string) $value));
        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($converted !== false) $value = $converted;
        }
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        if ($value === '' || strlen($value) > 160) throw new InvalidArgumentException('Identificador no válido.');
        return $value;
    }

    /** @return list<string> */
    public static function disciplines(mixed $values): array
    {
        if (!is_array($values)) $values = [$values];
        $result = [];
        foreach ($values as $value) {
            $discipline = self::enum($value, self::DISCIPLINES, 'Disciplina');
            $result[$discipline] = true;
        }
        if ($result === []) throw new InvalidArgumentException('Debe indicarse al menos una disciplina.');
        return array_keys($result);
    }

    public static function positiveInt(mixed $value, string $field, int $max, bool $required = true): ?int
    {
        if (($value === null || $value === '') && !$required) return null;
        $filtered = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);
        if ($filtered === false) throw new InvalidArgumentException($field . ' no válido.');
        return (int) $filtered;
    }

    public static function nonNegativeInt(mixed $value, string $field, int $max, bool $required = false): ?int
    {
        if (($value === null || $value === '') && !$required) return null;
        $filtered = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $max]]);
        if ($filtered === false) throw new InvalidArgumentException($field . ' no válido.');
        return (int) $filtered;
    }

    public static function booleanFlag(mixed $value, string $field): int
    {
        if ($value === true || $value === 1 || $value === '1') return 1;
        if ($value === false || $value === 0 || $value === '0') return 0;
        throw new InvalidArgumentException($field . ' no válido.');
    }

    public static function decimal(mixed $value, string $field, int $wholeDigits, int $scale, bool $required = false): ?string
    {
        if (($value === null || trim((string) $value) === '') && !$required) return null;
        $raw = trim((string) $value);
        $pattern = '/^(?:0|[1-9]\d{0,' . max(0, $wholeDigits - 1) . '})(?:\.\d{1,' . $scale . '})?$/';
        if (!preg_match($pattern, $raw)) throw new InvalidArgumentException($field . ' no válido.');
        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        return $whole . ($fraction === '' ? '' : '.' . str_pad($fraction, $scale, '0'));
    }

    /** @return array<string,int|string|null> */
    public static function executionParameters(string $type, array $input): array
    {
        $type = self::enum($type, self::EXECUTION_TYPES, 'Tipo de ejecución');
        $out = [
            'sets_count' => null, 'reps_count' => null, 'load_kg' => null,
            'duration_seconds' => null, 'rounds_count' => null,
            'round_duration_seconds' => null, 'rest_seconds' => null,
            'distance_value' => null, 'distance_unit' => null,
            'work_seconds' => null, 'transition_seconds' => null,
        ];
        $rest = self::nonNegativeInt($input['rest_seconds'] ?? null, 'Descanso', 86400);

        if ($type === 'REPS') {
            $out['sets_count'] = self::positiveInt($input['sets_count'] ?? null, 'Series', 100);
            $out['reps_count'] = self::positiveInt($input['reps_count'] ?? null, 'Repeticiones', 10000);
            $out['load_kg'] = self::decimal($input['load_kg'] ?? null, 'Carga', 5, 3);
            $out['rest_seconds'] = $rest ?? 0;
        } elseif ($type === 'TIME') {
            $out['sets_count'] = self::positiveInt($input['sets_count'] ?? 1, 'Series', 100);
            $out['duration_seconds'] = self::positiveInt($input['duration_seconds'] ?? null, 'Duración', 86400);
            $out['rest_seconds'] = $rest ?? 0;
        } elseif ($type === 'ROUNDS') {
            $out['rounds_count'] = self::positiveInt($input['rounds_count'] ?? null, 'Rounds', 1000);
            $out['round_duration_seconds'] = self::positiveInt($input['round_duration_seconds'] ?? null, 'Duración del round', 86400);
            $out['rest_seconds'] = $rest ?? 0;
        } elseif ($type === 'DISTANCE') {
            $out['sets_count'] = self::positiveInt($input['sets_count'] ?? 1, 'Series', 100);
            $out['distance_value'] = self::decimal($input['distance_value'] ?? null, 'Distancia', 8, 2, true);
            $out['distance_unit'] = self::enum($input['distance_unit'] ?? '', ['M','KM'], 'Unidad de distancia');
            $out['rest_seconds'] = $rest ?? 0;
        } elseif ($type === 'CIRCUIT') {
            $out['work_seconds'] = self::positiveInt($input['work_seconds'] ?? null, 'Trabajo', 86400, false);
            $out['reps_count'] = self::positiveInt($input['reps_count'] ?? null, 'Repeticiones', 10000, false);
            if ($out['work_seconds'] === null && $out['reps_count'] === null) {
                throw new InvalidArgumentException('Una estación necesita tiempo o repeticiones.');
            }
            $out['transition_seconds'] = self::nonNegativeInt($input['transition_seconds'] ?? 0, 'Transición', 86400, true);
        } else {
            $out['rounds_count'] = self::positiveInt($input['rounds_count'] ?? null, 'Rounds', 1000, false);
            $out['round_duration_seconds'] = self::positiveInt($input['round_duration_seconds'] ?? null, 'Duración del round', 86400, false);
            $out['duration_seconds'] = self::positiveInt($input['duration_seconds'] ?? null, 'Duración', 86400, false);
            if ($out['duration_seconds'] === null && ($out['rounds_count'] === null || $out['round_duration_seconds'] === null)) {
                throw new InvalidArgumentException('La técnica necesita duración o rounds completos.');
            }
            $out['rest_seconds'] = $rest ?? 0;
        }
        return $out;
    }
}
