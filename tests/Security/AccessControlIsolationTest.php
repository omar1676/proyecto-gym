<?php

require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__) . '/Support/AccessControlTestFactory.php';
require_once dirname(__DIR__, 2) . '/app/helpers/Authorization.php';
require_once dirname(__DIR__, 2) . '/app/services/AccessControlRepository.php';
require_once dirname(__DIR__, 2) . '/app/services/AccessControlSyncService.php';
require_once dirname(__DIR__, 2) . '/app/services/MockAccessControlProvider.php';

function rejected(callable $operation): bool {
    try { $operation(); return false; } catch (DomainException|PDOException $e) { return true; }
}

$db = Database::getInstance()->getConnection();
AccessControlTestFactory::cleanup($db);
$a = AccessControlTestFactory::createTenant($db, 'seca');
$b = AccessControlTestFactory::createTenant($db, 'secb');
$a2 = AccessControlTestFactory::createSite($db, $a['empresa'], 'seca2');
$memberA2 = AccessControlTestFactory::createMember($db, $a['empresa'], $a2, 'seca_member2');
$repo = new AccessControlRepository($db, 3, 5);
$repo->mapIdentity($a['empresa'], $a['sede'], $a['member'], 'mock', 'opaque-a');
$repo->mapIdentity($a['empresa'], $a2, $memberA2, 'mock', 'opaque-a2');
$repo->mapIdentity($b['empresa'], $b['sede'], $b['member'], 'mock', 'opaque-b');

check('Empresa A no lee identidad externa de B', $repo->findIdentityByExternal($a['empresa'], $a['sede'], 'mock', 'opaque-b') === null);
check('Sede A1 no lee identidad de A2', $repo->findIdentityByExternal($a['empresa'], $a['sede'], 'mock', 'opaque-a2') === null);
check('A no mapea socio B dentro de su tenant', rejected(fn() => $repo->mapIdentity($a['empresa'], $a['sede'], $b['member'], 'mock', 'hostile-b')));
check('A1 no remapea socio A1 dentro de A2', rejected(fn() => $repo->mapIdentity($a['empresa'], $a2, $a['member'], 'mock', 'hostile-site')));

$crossMember = new AccessDecision($a['empresa'], $a['sede'], $b['member'], 'BLOQUEADO', 'MEMBER_INACTIVE', null, null, 'cross-member');
check('A no encola decisión sobre socio B', rejected(fn() => $repo->enqueue($crossMember, 'mock', $a['actor'])));
$crossSite = new AccessDecision($a['empresa'], $b['sede'], $a['member'], 'BLOQUEADO', 'MEMBER_INACTIVE', null, null, 'cross-site');
check('A no manipula una sede de B', rejected(fn() => $repo->enqueue($crossSite, 'mock', $a['actor'])));
$validA = new AccessDecision($a['empresa'], $a['sede'], $a['member'], 'PERMITIDO', 'MEMBERSHIP_ACTIVE', null, null, 'valid-a');
check('actor de B no crea trabajo en A', rejected(fn() => $repo->enqueue($validA, 'mock', $b['actor'])));

$provider = new MockAccessControlProvider();
$provider->addCredential($b['empresa'], $b['sede'], $b['member'], 'opaque-provider-b');
check('provider mock separa credencial por tenant', $provider->findCredential($a['empresa'], $a['sede'], 'opaque-provider-b')->code() === 'NOT_FOUND');
$provider->addCredential($a['empresa'], $a2, $memberA2, 'opaque-provider-a2');
check('provider mock separa credencial por sede', $provider->findCredential($a['empresa'], $a['sede'], 'opaque-provider-a2')->code() === 'NOT_FOUND');

$shadowB = new AccessControlSyncService('shadow', false, new MockAccessControlProvider(), $repo);
$decisionB = new AccessDecision($b['empresa'], $b['sede'], $b['member'], 'REVISAR', 'PAYMENT_REVIEW', null, null, 'valid-b');
$shadowB->request($decisionB, $b['actor'], 'security-test');
check('listado A no ve trabajos B', count($repo->listJobs($a['empresa'], null)) === 0);
check('listado B solo devuelve su trabajo', count($repo->listJobs($b['empresa'], null)) === 1);
check('auditoría A no ve B', count($repo->listAudit($a['empresa'], null)) === 0);

check('dirección puede ver acceso', Authorization::can('direccion', 'access.view'));
check('dirección puede gestionar y sincronizar', Authorization::can('direccion', 'access.manage') && Authorization::can('direccion', 'access.sync'));
check('admin de sede solo ve y audita', Authorization::can('admin', 'access.view') && Authorization::can('admin', 'access.audit') && !Authorization::can('admin', 'access.manage') && !Authorization::can('admin', 'access.sync'));
check('recepción solo recibe consulta y temporal limitado', Authorization::can('recepcion', 'access.view')
    && Authorization::can('recepcion', 'access.temporary')
    && !Authorization::can('recepcion', 'access.manage')
    && !Authorization::can('recepcion', 'access.permanent')
    && !Authorization::can('recepcion', 'access.sync')
    && !Authorization::can('recepcion', 'access.audit'));

$columns = $db->query(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema=DATABASE() AND table_name LIKE 'access_%'
       AND LOWER(column_name) REGEXP 'huella|biometr|finger|template|minutiae'"
)->fetchColumn();
check('el esquema no almacena biometría', (int) $columns === 0);

AccessControlTestFactory::cleanup($db);
finishTests();
