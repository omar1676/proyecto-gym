<?php

/** Conversión estricta de fechas locales de la UI a instantes UTC. */
final class AccessTime
{
    public static function parseLocal(string $value, string $timezone = 'Europe/Madrid'): ?DateTimeImmutable
    {
        $value=trim($value);
        if($value==='') return null;
        if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/',$value)) throw new DomainException('La fecha u hora no es válida.');
        $zone=new DateTimeZone($timezone);
        $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,$zone);
        $errors=DateTimeImmutable::getLastErrors();
        if($date===false||(is_array($errors)&&($errors['warning_count']||$errors['error_count']))
            ||$date->format('Y-m-d\TH:i')!==$value) {
            throw new DomainException('La hora local no existe por el cambio horario. Elige otra hora.');
        }
        if(self::isAmbiguous($value,$zone)) {
            throw new DomainException('La hora local es ambigua por el cambio horario. Elige una hora distinta.');
        }
        return $date->setTimezone(new DateTimeZone('UTC'));
    }

    private static function isAmbiguous(string $value, DateTimeZone $zone): bool
    {
        $wall=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new DateTimeZone('UTC'));
        if($wall===false) return false;
        $wallTs=$wall->getTimestamp();
        $transitions=$zone->getTransitions($wallTs-172800,$wallTs+172800);
        if(!is_array($transitions)||count($transitions)<2) return false;
        $previous=(int)$transitions[0]['offset'];
        foreach(array_slice($transitions,1) as $transition){
            $next=(int)$transition['offset'];
            if($next<$previous){
                $repeatedStart=(int)$transition['ts']+$next;
                $repeatedEnd=(int)$transition['ts']+$previous;
                if($wallTs>=$repeatedStart&&$wallTs<$repeatedEnd) return true;
            }
            $previous=$next;
        }
        return false;
    }
}
