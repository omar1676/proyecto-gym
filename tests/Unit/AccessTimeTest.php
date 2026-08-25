<?php

require_once __DIR__.'/../bootstrap.php';
require_once dirname(__DIR__,2).'/app/helpers/AccessTime.php';

function f26TimeRejected(string $value):bool{try{AccessTime::parseLocal($value);return false;}catch(DomainException){return true;}}

$valid=AccessTime::parseLocal('2026-10-25T03:30');
check('Madrid se convierte a UTC con offset real',$valid?->format('Y-m-d H:i:s')==='2026-10-25 02:30:00');
check('hora inexistente de primavera se rechaza',f26TimeRejected('2026-03-29T02:30'));
check('hora repetida de otoño se rechaza',f26TimeRejected('2026-10-25T02:30'));
check('medianoche válida se conserva',AccessTime::parseLocal('2026-08-26T00:00')?->format('Y-m-d H:i:s')==='2026-08-25 22:00:00');
check('formato con segundos se rechaza',f26TimeRejected('2026-08-26T00:00:00'));
check('valor vacío representa campo opcional',AccessTime::parseLocal('')===null);
finishTests();
