<?php
require_once dirname(__DIR__) . '/bootstrap.php';
$db = Database::getInstance()->getConnection();
$rejected = false;
try { $db->exec('UPDATE producto SET stock=-1 WHERE id_producto=1'); } catch (PDOException $e) { $rejected = true; }
check('MySQL impide stock negativo', $rejected);
$rejected = false;
try { $db->exec("UPDATE producto SET precio=-0.01 WHERE id_producto=1"); } catch (PDOException $e) { $rejected = true; }
check('MySQL impide precio negativo', $rejected);
check('la conexión apunta a la base de pruebas', Database::getInstance()->nombreBase() === DB_NAME_PRUEBAS);
finishTests();
