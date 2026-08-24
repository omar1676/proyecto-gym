<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/helpers/RetentionPolicy.php';

$config = RetentionPolicy::defaults();
$date = new DateTimeImmutable('2026-08-20', new DateTimeZone('Europe/Madrid'));
$windows = RetentionPolicy::windows($date, $config);
check('ventana reciente incluye 14 días completos', $windows['recent_start'] === '2026-08-07' && $windows['recent_end'] === '2026-08-20');
check('baseline termina antes de reciente', $windows['baseline_end'] === '2026-08-06');
check('baseline contiene 56 días', (new DateTimeImmutable($windows['baseline_start']))->diff(new DateTimeImmutable($windows['baseline_end']))->days + 1 === 56);

$base = ['first_historical_date'=>$windows['baseline_start'],'baseline_active_weeks'=>8];
$normal = RetentionPolicy::classify($base + ['baseline_visits'=>40,'recent_visits'=>10], $config, $windows);
$attention = RetentionPolicy::classify($base + ['baseline_visits'=>32,'recent_visits'=>2], $config, $windows);
$high = RetentionPolicy::classify($base + ['baseline_visits'=>32,'recent_visits'=>0], $config, $windows);
$monthly = RetentionPolicy::classify($base + ['baseline_visits'=>4,'recent_visits'=>0], $config, $windows);
$oneVisit = RetentionPolicy::classify($base + ['baseline_visits'=>1,'recent_visits'=>0], $config, $windows);
$twoVisits = RetentionPolicy::classify($base + ['baseline_visits'=>2,'recent_visits'=>0], $config, $windows);
$new = RetentionPolicy::classify(['first_historical_date'=>'2026-08-01','baseline_active_weeks'=>1,'baseline_visits'=>4,'recent_visits'=>0], $config, $windows);
$irregular = RetentionPolicy::classify(array_replace($base, ['baseline_active_weeks'=>2,'baseline_visits'=>16,'recent_visits'=>0]), $config, $windows);
check('5 por semana que continúa queda NORMAL', $normal['state'] === RetentionPolicy::NORMAL);
check('4 por semana a 1 por semana queda ATTENTION', $attention['state'] === RetentionPolicy::ATTENTION && $attention['drop_pct'] === 75.0);
check('4 por semana a cero queda HIGH_ATTENTION', $high['state'] === RetentionPolicy::HIGH_ATTENTION);
check('frecuencia mensual no crea falso positivo', $monthly['state'] === RetentionPolicy::NORMAL);
check('una visita no basta para clasificar', $oneVisit['state'] === RetentionPolicy::INSUFFICIENT_DATA);
check('dos visitas no bastan para clasificar', $twoVisits['state'] === RetentionPolicy::INSUFFICIENT_DATA);
check('socio nuevo queda INSUFFICIENT_DATA', $new['state'] === RetentionPolicy::INSUFFICIENT_DATA);
check('historial concentrado en pocas semanas es insuficiente', $irregular['state'] === RetentionPolicy::INSUFFICIENT_DATA);

check('mapeo gimnasio y pesas produce GYM', RetentionPolicy::activityFamily(null, 'Gimnasio y pesas') === 'GYM');
check('mapeo boxeo produce BOXEO', RetentionPolicy::activityFamily(null, 'Boxeo') === 'BOXEO');
check('MMA/BJJ produce TATAMI', RetentionPolicy::activityFamily(null, 'MMA||BJJ') === 'TATAMI');
check('múltiples disciplinas producen mensaje general', RetentionPolicy::activityFamily('GYM,BOXEO', null) === 'GENERAL');

foreach (['GYM','BOXEO','TATAMI','GENERAL'] as $family) {
    $message = RetentionPolicy::suggestedMessage($config, $family, 'Ana', 'Gimnasio Sintético');
    check("mensaje {$family} usa nombre y no términos económicos", str_contains($message, 'Ana')
        && !preg_match('/cuota|dinero|impag|renovaci/iu', $message));
}
$rejected = false;
try {
    $bad = $config;
    $bad['template_general'] = 'Hola {nombre}, renueva tu cuota.';
    RetentionPolicy::suggestedMessage($bad, 'GENERAL', 'Ana', 'Demo');
} catch (DomainException) { $rejected = true; }
check('plantilla económica queda rechazada', $rejected);

finishTests();
