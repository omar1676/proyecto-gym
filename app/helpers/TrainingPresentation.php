<?php

final class TrainingPresentation
{
    private const LABELS = [
        'GYM'=>'Musculación','STRENGTH'=>'Fuerza','BOXEO'=>'Boxeo','MMA'=>'MMA','BJJ'=>'BJJ',
        'CONDITIONING'=>'Acondicionamiento','GENERAL'=>'General','REPS'=>'Repeticiones','TIME'=>'Tiempo',
        'ROUNDS'=>'Rounds','DISTANCE'=>'Distancia','CIRCUIT'=>'Circuito','TECHNIQUE'=>'Técnica',
        'WARMUP'=>'Calentamiento','COOLDOWN'=>'Vuelta a la calma','INICIAL'=>'Inicial',
        'INTERMEDIO'=>'Intermedio','AVANZADO'=>'Avanzado','TODOS'=>'Todos los niveles',
        'FUERZA'=>'Fuerza','HIPERTROFIA'=>'Hipertrofia','TECNICA'=>'Técnica','MOVILIDAD'=>'Movilidad',
        'PREPARACION_FISICA'=>'Preparación física','DRAFT'=>'Borrador','ACTIVE'=>'Activo',
        'COMPLETED'=>'Completado','ARCHIVED'=>'Archivado','PENDING'=>'Pendiente','SKIPPED'=>'Omitida',
    ];

    public static function label(?string $value): string
    {
        $value = strtoupper((string)$value);
        return self::LABELS[$value] ?? ucfirst(mb_strtolower(str_replace('_',' ',$value)));
    }

    public static function seconds(?int $seconds): string
    {
        if ($seconds === null) return '';
        if ($seconds >= 60 && $seconds % 60 === 0) return (int)($seconds / 60) . ' min';
        return $seconds . ' s';
    }

    public static function item(array $item): string
    {
        $type=(string)($item['execution_type']??'');
        return match($type){
            'REPS'=>(int)$item['sets_count'].' × '.(int)$item['reps_count']
                .($item['load_kg']!==null?' · '.rtrim(rtrim((string)$item['load_kg'],'0'),'.').' kg':'')
                .self::rest($item),
            'TIME'=>(int)$item['sets_count'].' × '.self::seconds((int)$item['duration_seconds']).self::rest($item),
            'ROUNDS'=>(int)$item['rounds_count'].' rounds × '.self::seconds((int)$item['round_duration_seconds']).self::rest($item),
            'DISTANCE'=>(string)$item['distance_value'].' '.mb_strtolower((string)$item['distance_unit']).' × '.(int)$item['sets_count'].self::rest($item),
            'CIRCUIT'=>($item['work_seconds']!==null?self::seconds((int)$item['work_seconds']):(int)$item['reps_count'].' repeticiones')
                .' · transición '.self::seconds((int)$item['transition_seconds']),
            'TECHNIQUE'=>$item['duration_seconds']!==null?self::seconds((int)$item['duration_seconds']):((int)$item['rounds_count'].' rounds × '.self::seconds((int)$item['round_duration_seconds'])).self::rest($item),
            default=>'Parámetros pendientes',
        };
    }

    private static function rest(array $item): string
    {
        return isset($item['rest_seconds']) ? ' · descanso ' . self::seconds((int)$item['rest_seconds']) : '';
    }
}
