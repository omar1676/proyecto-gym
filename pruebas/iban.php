<?php
/**
 * IBAN: validación con dígito de control y persistencia en el socio.
 *
 * La validación no es de formato: aplica el mod 97 de la ISO 7064, el mismo
 * que usa el banco. Por eso detecta un dígito cambiado o dos cifras
 * intercambiadas, que son los errores de tecleo habituales en mostrador.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/_arranque.php';   // base de pruebas, nunca la de trabajo

$raiz = dirname(__DIR__);
require_once $raiz . '/app/helpers/Iban.php';
require_once $raiz . '/app/models/UserModel.php';
require_once $raiz . '/app/models/MembresiaModel.php';

$db = Database::getInstance()->getConnection();
$ok = 0; $fallos = 0;
function comprobar(string $d, $esperado, $real) {
    global $ok, $fallos;
    if ((string) $esperado === (string) $real) { $ok++; echo "  OK   $d\n"; }
    else { $fallos++; echo "  FALLO $d — esperaba [$esperado], obtuve [$real]\n"; }
}

echo "== VALIDACIÓN ==\n";
comprobar('IBAN español correcto',            true,  Iban::esValido('ES9121000418450200051332'));
comprobar('acepta espacios al teclear',       true,  Iban::esValido('ES91 2100 0418 4502 0005 1332'));
comprobar('detecta un dígito cambiado',       false, Iban::esValido('ES9121000418450200051333'));
comprobar('detecta cifras intercambiadas',    false, Iban::esValido('ES9112000418450200051332'));
comprobar('detecta longitud incorrecta',      false, Iban::esValido('ES912100041845020005133'));
comprobar('rechaza texto suelto',             false, Iban::esValido('mi cuenta'));
comprobar('acepta IBAN alemán',               true,  Iban::esValido('DE89370400440532013000'));
comprobar('acepta IBAN británico con letras', true,  Iban::esValido('GB82WEST12345698765432'));

echo "\n== NORMALIZACIÓN Y PRESENTACIÓN ==\n";
comprobar('quita espacios y pone mayúsculas', 'ES9121000418450200051332', Iban::normalizar('es91 2100 0418 4502 0005 1332'));
comprobar('formatea en grupos de cuatro',     'ES91 2100 0418 4502 0005 1332', Iban::formatear('ES9121000418450200051332'));
comprobar('enmascara para el listado',        'ES91 **** **** **** **** 1332', Iban::enmascarar('ES9121000418450200051332'));

echo "\n== PERSISTENCIA EN EL SOCIO ==\n";
$user = new UserModel(1);
$idSocio = 3;
$db->exec("UPDATE usuario SET iban = NULL WHERE id_usuario = $idSocio");

comprobar('parte sin IBAN', true, empty($user->buscarPorId($idSocio)['iban']));

$user->actualizarIban($idSocio, Iban::normalizar('ES91 2100 0418 4502 0005 1332'));
$guardado = $user->buscarPorId($idSocio)['iban'];
comprobar('se guarda normalizado', 'ES9121000418450200051332', $guardado);

$m = new MembresiaModel(1);
$fila = null;
foreach ($m->listarSocios() as $s) { if ((int) $s['id_usuario'] === $idSocio) $fila = $s; }
comprobar('el listado de socios lo devuelve', 'ES9121000418450200051332', $fila['iban'] ?? '');

echo "\n== AISLAMIENTO ENTRE SEDES ==\n";
// Un empleado de otra sede no debe poder tocar el IBAN de este socio.
$userOtraSede = new UserModel(999);
$userOtraSede->actualizarIban($idSocio, 'DE89370400440532013000');
comprobar('otra sede no puede cambiarlo', 'ES9121000418450200051332', $user->buscarPorId($idSocio)['iban']);

$db->exec("UPDATE usuario SET iban = NULL WHERE id_usuario = $idSocio");

echo "\n== RESUMEN: $ok correctas, $fallos fallidas ==\n";
exit($fallos === 0 ? 0 : 1);
