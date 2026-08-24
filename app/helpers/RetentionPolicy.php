<?php

/** Reglas puras, deterministas y explicables del motor de retención V1. */
final class RetentionPolicy
{
    public const ALGORITHM_VERSION = 'retention-v1';
    public const INSUFFICIENT_DATA = 'INSUFFICIENT_DATA';
    public const NORMAL = 'NORMAL';
    public const ATTENTION = 'ATTENTION';
    public const HIGH_ATTENTION = 'HIGH_ATTENTION';

    /** @return array<string,int|float|string> */
    public static function defaults(): array
    {
        return [
            'timezone' => 'Europe/Madrid',
            'baseline_days' => 56,
            'recent_days' => 14,
            'min_history_days' => 28,
            'min_baseline_visits' => 4,
            'min_baseline_active_weeks' => 4,
            'min_baseline_weekly_rate' => 0.75,
            'attention_drop_pct' => 50.0,
            'high_attention_drop_pct' => 75.0,
            'cooldown_days' => 14,
            'template_general' => 'Hola, {nombre}. ¡Hace unos días que no te vemos! Esperamos que todo vaya bien. Cuando quieras volver a entrenar, aquí te esperamos.',
            'template_gym' => 'Hola, {nombre}. ¡Hace unos días que no te vemos por el gimnasio! Esperamos que todo vaya genial. Las pesas te echan de menos. Cuando quieras volver a entrenar, aquí te esperamos.',
            'template_boxeo' => 'Hola, {nombre}. ¡Hace unos días que no te vemos por boxeo! Esperamos que todo vaya bien. Los guantes te echan de menos. Cuando quieras volver, aquí te esperamos.',
            'template_tatami' => 'Hola, {nombre}. ¡Hace unos días que no te vemos por el tatami! Esperamos que todo vaya genial. El tatami te echa de menos. Cuando quieras volver a entrenar, aquí te esperamos.',
        ];
    }

    /** @return array{baseline_start:string,baseline_end:string,recent_start:string,recent_end:string} */
    public static function windows(DateTimeImmutable $evaluationDate, array $config): array
    {
        $baselineDays = (int) $config['baseline_days'];
        $recentDays = (int) $config['recent_days'];
        $recentEnd = $evaluationDate;
        $recentStart = $recentEnd->modify('-' . ($recentDays - 1) . ' days');
        $baselineEnd = $recentStart->modify('-1 day');
        $baselineStart = $baselineEnd->modify('-' . ($baselineDays - 1) . ' days');
        return [
            'baseline_start' => $baselineStart->format('Y-m-d'),
            'baseline_end' => $baselineEnd->format('Y-m-d'),
            'recent_start' => $recentStart->format('Y-m-d'),
            'recent_end' => $recentEnd->format('Y-m-d'),
        ];
    }

    /** @return array{state:string,baseline_rate:float,recent_rate:float,drop_pct:float,reason_code:string} */
    public static function classify(array $stats, array $config, array $windows): array
    {
        $baselineVisits = max(0, (int) ($stats['baseline_visits'] ?? 0));
        $recentVisits = max(0, (int) ($stats['recent_visits'] ?? 0));
        $activeWeeks = max(0, (int) ($stats['baseline_active_weeks'] ?? 0));
        $baselineRate = round($baselineVisits / ((int) $config['baseline_days'] / 7), 2);
        $recentRate = round($recentVisits / ((int) $config['recent_days'] / 7), 2);
        $first = (string) ($stats['first_historical_date'] ?? '');
        $historyDays = 0;
        if ($first !== '') {
            $historyDays = (new DateTimeImmutable($first))->diff(new DateTimeImmutable($windows['baseline_end']))->days;
        }
        if ($first === ''
            || $historyDays < (int) $config['min_history_days']
            || $baselineVisits < (int) $config['min_baseline_visits']
            || $activeWeeks < (int) $config['min_baseline_active_weeks']) {
            return self::result(self::INSUFFICIENT_DATA, $baselineRate, $recentRate, 0.0, 'RETENTION_HISTORY_INSUFFICIENT');
        }
        if ($baselineRate < (float) $config['min_baseline_weekly_rate']) {
            return self::result(self::NORMAL, $baselineRate, $recentRate, 0.0, 'RETENTION_LOW_HABITUAL_FREQUENCY');
        }
        $drop = $baselineRate > 0
            ? round(max(0.0, min(100.0, (($baselineRate - $recentRate) / $baselineRate) * 100)), 2)
            : 0.0;
        if ($recentVisits === 0 && $drop >= (float) $config['high_attention_drop_pct']) {
            return self::result(self::HIGH_ATTENTION, $baselineRate, $recentRate, $drop, 'RETENTION_NO_RECENT_ATTENDANCE');
        }
        if ($drop >= (float) $config['attention_drop_pct']) {
            return self::result(self::ATTENTION, $baselineRate, $recentRate, $drop, 'RETENTION_FREQUENCY_DROP');
        }
        return self::result(self::NORMAL, $baselineRate, $recentRate, $drop, 'RETENTION_PATTERN_STABLE');
    }

    /** @return array{state:string,baseline_rate:float,recent_rate:float,drop_pct:float,reason_code:string} */
    private static function result(string $state, float $baseline, float $recent, float $drop, string $reason): array
    {
        return ['state' => $state, 'baseline_rate' => $baseline, 'recent_rate' => $recent, 'drop_pct' => $drop, 'reason_code' => $reason];
    }

    public static function activityFamily(?string $mapped, ?string $membershipNames): string
    {
        $families = [];
        foreach (explode(',', strtoupper((string) $mapped)) as $family) {
            $family = trim($family);
            if (in_array($family, ['GYM', 'BOXEO', 'TATAMI'], true)) $families[$family] = true;
        }
        if ($families === []) {
            foreach (explode('||', (string) $membershipNames) as $name) {
                $name = mb_strtolower(self::stripAccents(trim($name)), 'UTF-8');
                if ($name === '') continue;
                if (str_contains($name, 'boxeo')) $families['BOXEO'] = true;
                if (str_contains($name, 'mma') || str_contains($name, 'bjj')
                    || str_contains($name, 'jiu-jitsu') || str_contains($name, 'jiu jitsu')
                    || str_contains($name, 'tatami')) $families['TATAMI'] = true;
                if (str_contains($name, 'pesas') || str_contains($name, 'gimnasio')) $families['GYM'] = true;
            }
        }
        return count($families) === 1 ? (string) array_key_first($families) : 'GENERAL';
    }

    public static function suggestedMessage(array $config, string $family, string $firstName, string $gymName): string
    {
        $key = match ($family) {
            'GYM' => 'template_gym', 'BOXEO' => 'template_boxeo', 'TATAMI' => 'template_tatami',
            default => 'template_general',
        };
        $message = strtr((string) $config[$key], [
            '{nombre}' => trim($firstName),
            '{gimnasio}' => trim($gymName),
        ]);
        if (preg_match('/\b(cuota|dinero|impag|renovaci[oó]n)\b/iu', $message)) {
            throw new DomainException('La plantilla de retención contiene términos económicos no permitidos.');
        }
        return trim($message);
    }

    private static function stripAccents(string $value): string
    {
        return strtr($value, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
    }
}
