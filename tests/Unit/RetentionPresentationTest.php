<?php

require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__,2).'/app/helpers/RetentionPresentation.php';

check('estados internos tienen lenguaje humano',RetentionPresentation::label('NORMAL')==='Sigue su rutina'
    && RetentionPresentation::label('ATTENTION')==='Rutina a medias'
    && RetentionPresentation::label('HIGH_ATTENTION')==='Hace tiempo que no viene'
    && RetentionPresentation::label('INSUFFICIENT_DATA')==='Conociendo su rutina'
    && RetentionPresentation::label('RETURNED')==='Ha vuelto a entrenar');
check('texto normal no usa churn ni predicción',!preg_match('/churn|anomal|riesgo de baja/iu',RetentionPresentation::explanation(['state'=>'NORMAL'])));
check('atención explica comparación propia',str_contains(RetentionPresentation::explanation([
    'state'=>'ATTENTION','baseline_weekly_rate'=>4,'recent_weekly_rate'=>1,
]),'4 veces')&&str_contains(RetentionPresentation::explanation([
    'state'=>'ATTENTION','baseline_weekly_rate'=>4,'recent_weekly_rate'=>1,
]),'1'));
check('alta atención nombra la ventana real',str_contains(RetentionPresentation::explanation([
    'state'=>'HIGH_ATTENTION','baseline_weekly_rate'=>4,'recent_weekly_rate'=>0,
],14),'14 días'));
check('fecha UTC se presenta en timezone tenant',RetentionPresentation::localDateTime('2026-08-20 08:00:00','Europe/Madrid')==='20/08/2026 10:00');
check('actividad técnica se humaniza',RetentionPresentation::activity('BOXEO')==='Boxeo'&&RetentionPresentation::activity('GENERAL')==='Actividad general');
finishTests();
