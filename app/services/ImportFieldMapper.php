<?php

require_once __DIR__ . '/CsvImportReader.php';
require_once __DIR__ . '/MigrationException.php';

/** Mapea encabezados externos a un contrato interno pequeño y explícito. */
final class ImportFieldMapper
{
    private const SCHEMAS = [
        'socios' => [
            'external_id' => ['external_id','id_externo','id_cliente','cliente_id','codigo','id'],
            'nombre' => ['nombre','nombre_cliente','first_name','firstname'],
            'apellidos' => ['apellidos','apellido','surnames','last_name','lastname'],
            'dni' => ['dni','nie','nif','documento','document_id'],
            'email' => ['email','correo','correo_electronico','mail'],
            'telefono' => ['telefono','telefono_movil','movil','phone','mobile'],
            'fecha_alta' => ['fecha_alta','alta','created_at','registration_date'],
            'estado' => ['estado','activo','status','state'],
            'sede_externa' => ['sede_externa','sede','centro','gimnasio','site'],
        ],
        'productos' => [
            'external_id' => ['external_id','id_externo','id_producto','producto_id','codigo','sku','id'],
            'nombre' => ['nombre','nombre_producto','producto','name'],
            'categoria' => ['categoria','category','familia'],
            'precio' => ['precio','pvp','price','precio_venta'],
            'stock' => ['stock','existencias','cantidad'],
            'estado' => ['estado','activo','status','state'],
            'descripcion' => ['descripcion','description','detalle'],
        ],
        'membresias' => [
            'external_id' => ['external_id','id_externo','id_membresia','membresia_id','codigo','id'],
            'socio_external_id' => ['socio_external_id','id_socio_externo','cliente_id','socio_id'],
            'tarifa' => ['tarifa','cuota','membresia','tipo_membresia'],
            'fecha_inicio' => ['fecha_inicio','inicio','start_date'],
            'fecha_fin' => ['fecha_fin','fin','end_date','vencimiento'],
            'precio_historico' => ['precio_historico','precio_pagado','precio','importe'],
            'estado' => ['estado','status','state'],
        ],
    ];

    private const REQUIRED = [
        'socios' => ['external_id','nombre','apellidos','dni','email'],
        'productos' => ['external_id','nombre','precio','stock'],
        'membresias' => ['external_id','socio_external_id','tarifa','fecha_inicio','fecha_fin','precio_historico'],
    ];

    public static function supportedEntities(): array
    {
        return array_keys(self::SCHEMAS);
    }

    public static function fields(string $entity): array
    {
        if (!isset(self::SCHEMAS[$entity])) {
            throw new MigrationException('Tipo de importación no soportado.', 'unsupported_entity');
        }
        return array_keys(self::SCHEMAS[$entity]);
    }

    public static function required(string $entity): array
    {
        self::fields($entity);
        return self::REQUIRED[$entity];
    }

    public static function infer(array $headers, string $entity): array
    {
        $fields = self::SCHEMAS[$entity] ?? null;
        if ($fields === null) throw new MigrationException('Tipo de importación no soportado.', 'unsupported_entity');
        $mapping = [];
        $used = [];
        foreach ($headers as $header) {
            $folded = CsvImportReader::foldHeader((string) $header);
            $mapping[(string) $header] = null;
            foreach ($fields as $internal => $aliases) {
                if (!isset($used[$internal]) && in_array($folded, $aliases, true)) {
                    $mapping[(string) $header] = $internal;
                    $used[$internal] = true;
                    break;
                }
            }
        }
        return $mapping;
    }

    public static function validate(array $headers, string $entity, array $mapping): array
    {
        $allowed = self::fields($entity);
        $headerLookup = array_fill_keys($headers, true);
        $clean = [];
        $assigned = [];
        foreach ($mapping as $external => $internal) {
            if (!isset($headerLookup[$external])) continue;
            $internal = trim((string) $internal);
            if ($internal === '') {
                $clean[$external] = null;
                continue;
            }
            if (!in_array($internal, $allowed, true) || isset($assigned[$internal])) {
                throw new MigrationException('El mapeo contiene campos inválidos o repetidos.', 'invalid_mapping');
            }
            $assigned[$internal] = true;
            $clean[$external] = $internal;
        }
        foreach ($headers as $header) $clean[$header] ??= null;
        foreach (self::required($entity) as $required) {
            if (!isset($assigned[$required])) {
                throw new MigrationException('Falta mapear el campo obligatorio: ' . $required . '.', 'missing_mapping');
            }
        }
        return $clean;
    }

    public static function project(array $externalRow, array $mapping): array
    {
        $internal = [];
        foreach ($mapping as $external => $field) {
            if ($field !== null && $field !== '') $internal[$field] = (string) ($externalRow[$external] ?? '');
        }
        return $internal;
    }

    public static function prohibitedHeaders(array $headers): array
    {
        $blocked = ['empresa_id','id_empresa','tenant_id','sede_id','id_sede','id_gimnasio'];
        return array_values(array_filter($headers, static fn($h) => in_array(CsvImportReader::foldHeader((string) $h), $blocked, true)));
    }
}
