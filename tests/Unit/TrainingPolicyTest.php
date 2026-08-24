<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/helpers/TrainingPolicy.php';

$cases = [
    'pesas' => ['REPS', ['sets_count'=>4,'reps_count'=>8,'load_kg'=>'70','rest_seconds'=>120], ['sets_count'=>4,'reps_count'=>8,'load_kg'=>'70']],
    'fuerza' => ['REPS', ['sets_count'=>5,'reps_count'=>5,'load_kg'=>'100.000','rest_seconds'=>180], ['sets_count'=>5,'reps_count'=>5,'load_kg'=>'100.000']],
    'saco' => ['ROUNDS', ['rounds_count'=>5,'round_duration_seconds'=>180,'rest_seconds'=>60], ['rounds_count'=>5,'round_duration_seconds'=>180]],
    'boxeo técnico' => ['TECHNIQUE', ['rounds_count'=>4,'round_duration_seconds'=>120,'rest_seconds'=>45], ['rounds_count'=>4,'round_duration_seconds'=>120]],
    'MMA técnico' => ['TECHNIQUE', ['rounds_count'=>4,'round_duration_seconds'=>120,'rest_seconds'=>30], ['rounds_count'=>4,'round_duration_seconds'=>120]],
    'BJJ posicional' => ['ROUNDS', ['rounds_count'=>5,'round_duration_seconds'=>240,'rest_seconds'=>60], ['rounds_count'=>5,'round_duration_seconds'=>240]],
    'estación circuito tiempo' => ['CIRCUIT', ['work_seconds'=>45,'transition_seconds'=>15], ['work_seconds'=>45,'transition_seconds'=>15]],
    'estación circuito reps' => ['CIRCUIT', ['reps_count'=>12,'transition_seconds'=>0], ['reps_count'=>12,'transition_seconds'=>0]],
    'distancia' => ['DISTANCE', ['sets_count'=>2,'distance_value'=>'1500.50','distance_unit'=>'M','rest_seconds'=>90], ['distance_value'=>'1500.50','distance_unit'=>'M']],
];
foreach ($cases as $label => [$type,$input,$expected]) {
    $result = TrainingPolicy::executionParameters($type, $input);
    $ok = true;
    foreach ($expected as $field => $value) $ok = $ok && $result[$field] === $value;
    check("representa {$label}", $ok);
}

$hostile = [
    ['REPS',['sets_count'=>0,'reps_count'=>8]],
    ['REPS',['sets_count'=>4,'reps_count'=>-1]],
    ['REPS',['sets_count'=>4,'reps_count'=>8,'load_kg'=>'NaN']],
    ['REPS',['sets_count'=>4,'reps_count'=>8,'load_kg'=>'Infinity']],
    ['REPS',['sets_count'=>4,'reps_count'=>8,'load_kg'=>'1.0009']],
    ['ROUNDS',['rounds_count'=>1,'round_duration_seconds'=>0]],
    ['TIME',['sets_count'=>1,'duration_seconds'=>'2 min']],
    ['CIRCUIT',['transition_seconds'=>15]],
    ['DISTANCE',['distance_value'=>'999999999.99','distance_unit'=>'M']],
    ['TECHNIQUE',['rounds_count'=>4]],
];
foreach ($hostile as $index => [$type,$input]) {
    $rejected = false;
    try { TrainingPolicy::executionParameters($type, $input); } catch (InvalidArgumentException) { $rejected = true; }
    check('entrada hostil queda rechazada #' . ($index + 1), $rejected);
}

check('carga se conserva como decimal textual', TrainingPolicy::decimal('70.1','Carga',5,3,true) === '70.100');
check('taxonomía multidisciplina conserva valores únicos', TrainingPolicy::disciplines(['MMA','GYM','MMA']) === ['MMA','GYM']);
check('slug normaliza texto humano', TrainingPolicy::slug('Combinación Técnica 1') === 'combinacion-tecnica-1');
check('booleano de formulario acepta exclusivamente 0/1', TrainingPolicy::booleanFlag('1','Completado') === 1
    && TrainingPolicy::booleanFlag('0','Completado') === 0);
$badBoolean=false;
try { TrainingPolicy::booleanFlag('yes','Completado'); } catch (InvalidArgumentException) { $badBoolean=true; }
check('booleano textual ambiguo queda rechazado', $badBoolean);

$clinicalRejected = false;
try { TrainingPolicy::text("Nota\0oculta", 100, 'Notas'); } catch (InvalidArgumentException) { $clinicalRejected = true; }
check('texto con controles queda rechazado', $clinicalRejected);

finishTests();
