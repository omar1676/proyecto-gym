<?php

require_once __DIR__ . '/../helpers/InputValidator.php';
require_once __DIR__ . '/../helpers/Money.php';
require_once __DIR__ . '/CsvImportReader.php';

/** Normalización conservadora: compara valores normalizados sin reescribir nombres agresivamente. */
final class ImportNormalizer
{
    public static function normalize(string $entity, array $row, array $options = []): array
    {
        return match ($entity) {
            'socios' => self::member($row, $options),
            'productos' => self::product($row),
            'membresias' => self::membership($row, $options),
            default => ['data' => [], 'errors' => [['field' => null, 'code' => 'unsupported_entity', 'message' => 'Entidad no soportada.']], 'warnings' => []],
        };
    }

    private static function member(array $row, array $options): array
    {
        $errors = $warnings = [];
        $external = self::text($row['external_id'] ?? '', 190);
        $name = self::text($row['nombre'] ?? '', 100);
        $surname = self::text($row['apellidos'] ?? '', 150);
        $dni = strtoupper(preg_replace('/[\s-]+/', '', (string) ($row['dni'] ?? '')) ?? '');
        $email = InputValidator::email($row['email'] ?? '');
        $phoneRaw = self::text($row['telefono'] ?? '', 30, false);
        $phone = $phoneRaw === '' ? null : InputValidator::phone($phoneRaw);
        foreach ([['external_id',$external],['nombre',$name],['apellidos',$surname]] as [$field,$value]) {
            if ($value === null || $value === '') $errors[] = self::error($field, 'required_or_invalid', 'Campo obligatorio ausente o no válido.');
        }
        if ($dni === '' || InputValidator::dniNie($dni) === null) {
            $errors[] = self::error('dni', 'invalid_dni', 'DNI/NIE ausente o no válido.');
        }
        if ($email === null) $errors[] = self::error('email', 'invalid_email', 'Correo electrónico ausente o no válido.');
        if ($phoneRaw !== '' && $phone === null) $errors[] = self::error('telefono', 'invalid_phone', 'Teléfono no válido.');

        $date = null;
        $dateRaw = trim((string) ($row['fecha_alta'] ?? ''));
        if ($dateRaw !== '') {
            $format = ($options['date_format'] ?? 'Y-m-d') === 'd/m/Y' ? 'd/m/Y' : 'Y-m-d';
            $parsed = DateTimeImmutable::createFromFormat('!' . $format, $dateRaw);
            if (!$parsed || $parsed->format($format) !== $dateRaw) {
                $errors[] = self::error('fecha_alta', 'invalid_date', 'Fecha no válida para el formato declarado.');
            } else {
                $date = $parsed->format('Y-m-d');
            }
        }

        $state = self::state($row['estado'] ?? 'activo');
        if ($state === null) $errors[] = self::error('estado', 'invalid_state', 'Estado no reconocido.');
        return ['data' => [
            'external_id' => $external,
            'nombre' => $name,
            'apellidos' => $surname,
            'dni' => $dni,
            'email' => $email,
            'telefono' => $phone,
            'fecha_alta' => $date,
            'estado' => $state,
            'sede_externa' => self::text($row['sede_externa'] ?? '', 150, false) ?: null,
        ], 'errors' => $errors, 'warnings' => $warnings];
    }

    private static function product(array $row): array
    {
        $errors = $warnings = [];
        $external = self::text($row['external_id'] ?? '', 190);
        $name = self::text($row['nombre'] ?? '', 150);
        if (!$external) $errors[] = self::error('external_id', 'required_or_invalid', 'Identificador externo obligatorio.');
        if (!$name) $errors[] = self::error('nombre', 'required_or_invalid', 'Nombre obligatorio.');
        $price = InputValidator::money($row['precio'] ?? '', 99999999);
        if ($price === null) $errors[] = self::error('precio', 'invalid_price', 'Precio no válido.');
        $stock = filter_var($row['stock'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483647]]);
        if ($stock === false) $errors[] = self::error('stock', 'invalid_stock', 'Stock no válido.');
        $state = self::state($row['estado'] ?? 'activo');
        if ($state === null) $errors[] = self::error('estado', 'invalid_state', 'Estado no reconocido.');
        return ['data' => [
            'external_id' => $external,
            'nombre' => $name,
            'categoria' => self::text($row['categoria'] ?? '', 100, false) ?: null,
            'precio' => $price,
            'stock' => $stock === false ? null : (int) $stock,
            'estado' => $state,
            'descripcion' => self::text($row['descripcion'] ?? '', 1000, false) ?: null,
        ], 'errors' => $errors, 'warnings' => $warnings];
    }

    private static function membership(array $row, array $options): array
    {
        $errors = [];
        $external = self::text($row['external_id'] ?? '', 190);
        $memberExternal = self::text($row['socio_external_id'] ?? '', 190);
        $tariff = self::text($row['tarifa'] ?? '', 100);
        foreach ([['external_id',$external],['socio_external_id',$memberExternal],['tarifa',$tariff]] as [$field,$value]) {
            if (!$value) $errors[] = self::error($field,'required_or_invalid','Campo obligatorio ausente o no válido.');
        }
        $format = ($options['date_format'] ?? 'Y-m-d') === 'd/m/Y' ? 'd/m/Y' : 'Y-m-d';
        $dates = [];
        foreach (['fecha_inicio','fecha_fin'] as $field) {
            $raw = trim((string)($row[$field] ?? ''));
            $parsed = DateTimeImmutable::createFromFormat('!'.$format,$raw);
            if (!$parsed || $parsed->format($format) !== $raw) $errors[] = self::error($field,'invalid_date','Fecha no válida para el formato declarado.');
            else $dates[$field] = $parsed->format('Y-m-d');
        }
        if (isset($dates['fecha_inicio'],$dates['fecha_fin']) && $dates['fecha_fin'] < $dates['fecha_inicio']) {
            $errors[] = self::error('fecha_fin','invalid_date_range','La fecha fin no puede ser anterior al inicio.');
        }
        $price = InputValidator::money($row['precio_historico'] ?? '',99999999);
        if ($price === null) $errors[] = self::error('precio_historico','invalid_price','Precio histórico no válido.');
        $stateRaw = CsvImportReader::foldHeader((string)($row['estado'] ?? 'activa'));
        $state = in_array($stateRaw,['activa','activo','vigente'],true) ? 'activa'
            : (in_array($stateRaw,['vencida','vencido','caducada'],true) ? 'vencida' : null);
        if ($state === null) $errors[] = self::error('estado','invalid_state','Estado de membresía no reconocido.');
        return ['data'=>[
            'external_id'=>$external,'socio_external_id'=>$memberExternal,'tarifa'=>$tariff,
            'fecha_inicio'=>$dates['fecha_inicio'] ?? null,'fecha_fin'=>$dates['fecha_fin'] ?? null,
            'precio_historico'=>$price,'estado'=>$state,
        ],'errors'=>$errors,'warnings'=>[]];
    }

    private static function text($value, int $max, bool $required = true): ?string
    {
        return InputValidator::text((string) $value, $max, $required);
    }

    private static function state($value): ?string
    {
        $value = CsvImportReader::foldHeader((string) $value);
        if (in_array($value, ['activo','activa','active','alta','1','si','yes'], true)) return 'activo';
        if (in_array($value, ['inactivo','inactiva','inactive','baja','0','no'], true)) return 'inactivo';
        return null;
    }

    private static function error(string $field, string $code, string $message): array
    {
        return ['field' => $field, 'code' => $code, 'message' => $message];
    }
}
