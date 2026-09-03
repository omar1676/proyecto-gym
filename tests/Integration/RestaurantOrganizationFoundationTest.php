<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/RestaurantOrganizationTestFactory.php';

function restaurantRejected(callable $operation): bool
{
    try {
        $operation();
        return false;
    } catch (DomainException | InvalidArgumentException | RuntimeException) {
        return true;
    }
}

$db = Database::getInstance()->getConnection();
$companies = [];
try {
    $actor = RestaurantOrganizationTestFactory::actor($db);
    check('existe operador global sintético para el test', $actor > 0);

    $companyA = RestaurantOrganizationTestFactory::createCompany($db, 'alpha');
    $companyB = RestaurantOrganizationTestFactory::createCompany($db, 'beta');
    $inactive = RestaurantOrganizationTestFactory::createCompany($db, 'cancelled', false);
    $faulted = RestaurantOrganizationTestFactory::createCompany($db, 'faulted');
    $auditFaulted = RestaurantOrganizationTestFactory::createCompany($db, 'audit-faulted');
    $companies = [$companyA, $companyB, $inactive, $faulted, $auditFaulted];

    $service = new RestaurantOrganizationService($db, $actor);
    $inputA = RestaurantOrganizationTestFactory::input($companyA, 'compartida', [
        'brand_name' => 'Marca Compartida',
        'legal_entity_name' => 'Entidad Compartida',
        'location_name' => 'Centro Compartido',
    ]);
    $createdA = $service->provision($inputA);
    check('foundation crea un account activo', !$createdA['duplicate'] && $createdA['status'] === 'ACTIVE');
    check('foundation crea exactamente cuatro raíces relacionadas',
        (int) $db->query("SELECT COUNT(*) FROM restaurant_account WHERE id_empresa={$companyA}")->fetchColumn() === 1
        && (int) $db->query("SELECT COUNT(*) FROM restaurant_brand WHERE id_empresa={$companyA}")->fetchColumn() === 1
        && (int) $db->query("SELECT COUNT(*) FROM restaurant_legal_entity WHERE id_empresa={$companyA}")->fetchColumn() === 1
        && (int) $db->query("SELECT COUNT(*) FROM restaurant_location WHERE id_empresa={$companyA}")->fetchColumn() === 1
    );
    $snapshotA = $service->findScoped($companyA, (int) $createdA['account_id']);
    check('holding, marca, entidad legal y local conservan identidad separada',
        $snapshotA !== null
        && (int) $snapshotA['brand_id'] > 0
        && (int) $snapshotA['legal_entity_id'] > 0
        && (int) $snapshotA['location_id'] > 0
    );
    check('local conserva timezone explícita', ($snapshotA['timezone'] ?? '') === 'Europe/Madrid');

    $retryA = $service->provision($inputA);
    check('reintento idempotente devuelve el mismo account',
        $retryA['duplicate'] && (int) $retryA['account_id'] === (int) $createdA['account_id']
    );
    $conflictingRetry = $inputA;
    $conflictingRetry['brand_name'] = 'Marca Distinta';
    check('misma clave con payload distinto queda rechazada', restaurantRejected(
        fn() => $service->provision($conflictingRetry)
    ));
    $otherKey = RestaurantOrganizationTestFactory::input($companyA, 'otra-clave');
    check('otra clave no duplica el producto de la organización', restaurantRejected(
        fn() => $service->provision($otherKey)
    ));
    check('sigue existiendo un único account por organización',
        (int) $db->query("SELECT COUNT(*) FROM restaurant_account WHERE id_empresa={$companyA}")->fetchColumn() === 1
    );

    $inputB = RestaurantOrganizationTestFactory::input($companyB, 'compartida-b', [
        'brand_name' => 'Marca Compartida',
        'legal_entity_name' => 'Entidad Compartida',
        'location_name' => 'Centro Compartido',
    ]);
    $createdB = $service->provision($inputB);
    check('dos tenants pueden reutilizar nombres y slugs', !$createdB['duplicate'] && $createdB['status'] === 'ACTIVE');
    check('consulta scoped no devuelve account de otro tenant',
        $service->findScoped($companyB, (int) $createdA['account_id']) === null
        && $service->findScoped($companyA, (int) $createdB['account_id']) === null
    );

    $crossTenantRejected = false;
    try {
        $stmt = $db->prepare(
            "INSERT INTO restaurant_location
             (id_restaurant_account,id_empresa,id_restaurant_brand,id_restaurant_legal_entity,
              name,slug,timezone,status,version)
             VALUES (:account,:company,:brand,:legal,'Cruce','cruce','Europe/Madrid','ACTIVE',1)"
        );
        $stmt->execute([
            ':account' => $createdA['account_id'],
            ':company' => $companyB,
            ':brand' => $createdB['brand_id'],
            ':legal' => $createdB['legal_entity_id'],
        ]);
    } catch (PDOException $error) {
        $crossTenantRejected = (string) $error->getCode() === '23000';
    }
    check('DB rechaza referencias cruzadas aunque falle PHP', $crossTenantRejected);

    $direction = RestaurantOrganizationTestFactory::createTenantActor($db, $companyB, 'direccion');
    check('dirección tenant no puede construir servicio Platform', restaurantRejected(
        fn() => new RestaurantOrganizationService($db, $direction)
    ));
    $boundSuperadmin = RestaurantOrganizationTestFactory::createTenantActor($db, $companyB, 'superadmin');
    check('superadmin ligado a tenant queda rechazado', restaurantRejected(
        fn() => new RestaurantOrganizationService($db, $boundSuperadmin)
    ));

    check('tenant CANCELLED/inactivo no recibe Restaurants', restaurantRejected(
        fn() => $service->provision(RestaurantOrganizationTestFactory::input($inactive, 'inactive'))
    ));
    check('rechazo lifecycle deja cero efectos',
        (int) $db->query("SELECT COUNT(*) FROM restaurant_account WHERE id_empresa={$inactive}")->fetchColumn() === 0
    );

    $faultService = new RestaurantOrganizationService(
        $db,
        $actor,
        static function (string $step): void {
            if ($step === 'brand') {
                throw new RuntimeException('synthetic fault');
            }
        }
    );
    check('fallo intermedio se propaga como fallo', restaurantRejected(
        fn() => $faultService->provision(RestaurantOrganizationTestFactory::input($faulted, 'faulted'))
    ));
    check('fallo intermedio revierte account marca entidad y local',
        (int) $db->query("SELECT COUNT(*) FROM restaurant_account WHERE id_empresa={$faulted}")->fetchColumn() === 0
        && (int) $db->query("SELECT COUNT(*) FROM restaurant_brand WHERE id_empresa={$faulted}")->fetchColumn() === 0
        && (int) $db->query("SELECT COUNT(*) FROM restaurant_legal_entity WHERE id_empresa={$faulted}")->fetchColumn() === 0
        && (int) $db->query("SELECT COUNT(*) FROM restaurant_location WHERE id_empresa={$faulted}")->fetchColumn() === 0
    );
    $successAudit = $db->query(
        "SELECT id_empresa,id_gimnasio,id_usuario,resultado,origin,reason_code
           FROM log_actividad
          WHERE accion='RESTAURANT_ORGANIZATION_PROVISIONED' AND id_empresa={$companyA}
          ORDER BY id DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    check('auditoría de éxito conserva actor global y tenant sin sede Gym',
        $successAudit
        && (int) $successAudit['id_empresa'] === $companyA
        && $successAudit['id_gimnasio'] === null
        && (int) $successAudit['id_usuario'] === $actor
        && $successAudit['resultado'] === 'exito'
        && $successAudit['origin'] === 'SYSTEM'
    );
    check('rollback no produce auditoría de éxito falsa',
        (int) $db->query(
            "SELECT COUNT(*) FROM log_actividad
              WHERE id_empresa={$faulted} AND accion='RESTAURANT_ORGANIZATION_PROVISIONED' AND resultado='exito'"
        )->fetchColumn() === 0
    );
    check('rollback queda auditado como fallo',
        (int) $db->query(
            "SELECT COUNT(*) FROM log_actividad
              WHERE id_empresa={$faulted} AND accion='RESTAURANT_ORGANIZATION_PROVISION_FAILED' AND resultado='fallo'"
        )->fetchColumn() === 1
    );

    $auditFaultService = new RestaurantOrganizationService(
        $db,
        $actor,
        static function (string $step): void {
            if ($step === 'audit') {
                throw new RuntimeException('synthetic post-audit fault');
            }
        }
    );
    check('fallo tras escribir auditoría REQUIRED se propaga', restaurantRejected(
        fn() => $auditFaultService->provision(RestaurantOrganizationTestFactory::input($auditFaulted, 'audit-faulted'))
    ));
    check('transacción revierte dominio y auditoría de éxito juntos',
        (int) $db->query("SELECT COUNT(*) FROM restaurant_account WHERE id_empresa={$auditFaulted}")->fetchColumn() === 0
        && (int) $db->query(
            "SELECT COUNT(*) FROM log_actividad
              WHERE id_empresa={$auditFaulted} AND accion='RESTAURANT_ORGANIZATION_PROVISIONED'"
        )->fetchColumn() === 0
    );

    check('UUID no válida queda rechazada antes de escribir', restaurantRejected(fn() => $service->provision(
        RestaurantOrganizationTestFactory::input($inactive, 'invalid-key', ['idempotency_key' => 'not-a-key'])
    )));
    check('timezone inventada queda rechazada', restaurantRejected(fn() => $service->provision(
        RestaurantOrganizationTestFactory::input($inactive, 'invalid-timezone', ['timezone' => 'Mars/Jama'])
    )));
} catch (Throwable $error) {
    check('foundation organizativa completa', false);
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
} finally {
    RestaurantOrganizationTestFactory::cleanup($db, $companies);
}
finishTests();
