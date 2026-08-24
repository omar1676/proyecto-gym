<?php

/** Capa de lenguaje humano. No contiene ni modifica reglas de clasificación. */
final class RetentionPresentation
{
    public static function label(string $state): string
    {
        return match ($state) {
            'NORMAL' => 'Sigue su rutina',
            'ATTENTION' => 'Rutina a medias',
            'HIGH_ATTENTION' => 'Hace tiempo que no viene',
            'INSUFFICIENT_DATA' => 'Conociendo su rutina',
            'RETURNED' => 'Ha vuelto a entrenar',
            default => 'Sin evaluación actual',
        };
    }

    public static function activity(string $family): string
    {
        return match (strtoupper($family)) {
            'GYM' => 'Gym',
            'BOXEO' => 'Boxeo',
            'TATAMI' => 'Tatami',
            default => 'Actividad general',
        };
    }

    public static function workflow(?string $status): string
    {
        return match ($status) {
            'OPEN' => 'Pendiente',
            'REVIEWED' => 'Revisado',
            'POSTPONED' => 'Pospuesto',
            'CONTACTED' => 'Contactado',
            'DISMISSED' => 'Descartado',
            'RETURNED' => 'Ha vuelto',
            default => 'Sin acción pendiente',
        };
    }

    /** @param array<string,mixed> $row */
    public static function explanation(array $row, int $recentDays = 14): string
    {
        $state = (string)($row['display_state'] ?? $row['state'] ?? $row['level'] ?? '');
        $habitual = self::rate((float)($row['baseline_weekly_rate'] ?? 0));
        $recent = self::rate((float)($row['recent_weekly_rate'] ?? 0));
        return match ($state) {
            'NORMAL' => 'Su frecuencia se mantiene dentro de su patrón habitual.',
            'ATTENTION' => "Solía venir unas {$habitual} veces por semana y recientemente viene alrededor de {$recent}.",
            'HIGH_ATTENTION' => "Su frecuencia habitual era de unas {$habitual} visitas por semana y no ha registrado visitas durante los últimos {$recentDays} días.",
            'INSUFFICIENT_DATA' => 'Todavía no hay suficiente historial para comparar su asistencia.',
            'RETURNED' => 'Ha registrado una nueva visita después de necesitar atención.',
            default => 'Todavía no existe una evaluación actual para este socio.',
        };
    }

    public static function localDateTime(?string $utc, string $timezone, string $format = 'd/m/Y H:i'): string
    {
        if ($utc === null || trim($utc) === '') return 'Sin fecha';
        try {
            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone($timezone))->format($format);
        } catch (Throwable) {
            return 'Sin fecha';
        }
    }

    public static function relativeDate(?string $utc, string $timezone, ?DateTimeImmutable $now = null): string
    {
        if ($utc === null || trim($utc) === '') return 'Sin visitas registradas';
        try {
            $zone = new DateTimeZone($timezone);
            $date = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($zone);
            $today = ($now ?? new DateTimeImmutable('now', $zone))->setTime(0, 0);
            $target = $date->setTime(0, 0);
            $days = (int)$target->diff($today)->format('%r%a');
            if ($days === 0) return 'Hoy, ' . $date->format('H:i');
            if ($days === 1) return 'Ayer, ' . $date->format('H:i');
            if ($days > 1 && $days <= 30) return 'Hace ' . $days . ' días';
            return $date->format('d/m/Y');
        } catch (Throwable) {
            return 'Sin fecha';
        }
    }

    private static function rate(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, ',', ''), '0'), ',');
    }
}
