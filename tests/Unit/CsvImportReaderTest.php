<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/services/CsvImportReader.php';
require_once dirname(__DIR__, 2) . '/app/services/ImportFieldMapper.php';
require_once dirname(__DIR__, 2) . '/app/services/ImportNormalizer.php';

$reader = new CsvImportReader();
$path = dirname(__DIR__, 2) . '/pruebas/fixtures/importaciones/socios_100.csv';
$inspection = $reader->inspect($path, 'socios_100.csv');
check('detecta CSV con BOM y punto y coma', $inspection['delimiter'] === ';');
check('lee diez encabezados UTF-8', count($inspection['headers']) === 10);
$rows = iterator_to_array($reader->rows($path, $inspection['headers'], $inspection['delimiter']), false);
check('omite líneas vacías y devuelve 100 socios', count($rows) === 100);
check('conserva caracteres españoles', in_array($rows[0]['values']['nombre'], ['Lucía','Iñigo','María José','Óscar','Nuria','Raúl','Sofía','Álvaro'], true));
$mapping = ImportFieldMapper::infer($inspection['headers'], 'socios');
check('infiere todos los campos obligatorios de socio', count(array_intersect(ImportFieldMapper::required('socios'), array_filter($mapping))) === 5);
check('empresa_id del archivo no obtiene autoridad', ($mapping['empresa_id'] ?? null) === null && ImportFieldMapper::prohibitedHeaders($inspection['headers']) === ['empresa_id']);
$normal = ImportNormalizer::normalize('socios', ImportFieldMapper::project($rows[0]['values'], $mapping), ['date_format'=>'Y-m-d']);
check('normaliza un socio válido', !$normal['errors'] && str_ends_with((string)$normal['data']['email'], '@example.invalid'));
$ambiguous = ImportNormalizer::normalize('socios', [
    'external_id'=>'x','nombre'=>'Ana','apellidos'=>'Prueba','dni'=>'72000001L',
    'email'=>'ana@example.invalid','telefono'=>'600111222','fecha_alta'=>'03/04/2026','estado'=>'activo',
], ['date_format'=>'Y-m-d']);
check('no interpreta una fecha ambigua con el perfil equivocado', count($ambiguous['errors']) > 0);

$tmp = tempnam(sys_get_temp_dir(), 'migphp_');
file_put_contents($tmp, "external_id,nombre\n1,<?php echo 1;\n");
$phpRejected = false;
try { $reader->inspect($tmp, 'ataque.csv'); } catch (MigrationException $e) { $phpRejected = $e->safeCode() === 'executable_content'; }
check('rechaza código PHP aunque use extensión csv', $phpRejected);
@unlink($tmp);

$tmp = tempnam(sys_get_temp_dir(), 'migbig_');
$handle = fopen($tmp, 'wb'); ftruncate($handle, IMPORT_MAX_BYTES + 1); fclose($handle);
$largeRejected = false;
try { $reader->inspect($tmp, 'grande.csv'); } catch (MigrationException $e) { $largeRejected = $e->safeCode() === 'file_size'; }
check('rechaza archivos que superan el límite', $largeRejected);
@unlink($tmp);

$comma=$reader->inspect(dirname(__DIR__,2).'/pruebas/fixtures/importaciones/socios_5000.csv','socios_5000.csv');
check('detecta también el delimitador coma', $comma['delimiter']===',');

$tmp=tempnam(sys_get_temp_dir(),'migquote_');
file_put_contents($tmp,"external_id,nombre\nQ-1,\"García, Ana\"\n");
$quoted=$reader->inspect($tmp,'quoted.csv');
$quotedRows=iterator_to_array($reader->rows($tmp,$quoted['headers'],$quoted['delimiter']),false);
check('respeta campos entrecomillados con comas', ($quotedRows[0]['values']['nombre'] ?? '')==='García, Ana');
@unlink($tmp);

$tmp=tempnam(sys_get_temp_dir(),'migrows_');
$fh=fopen($tmp,'wb'); fwrite($fh,"external_id,nombre\n");
for($i=0;$i<=IMPORT_MAX_ROWS;$i++) fwrite($fh,$i.",Nombre\n");
fclose($fh);
$rowLimit=false;
try { $meta=$reader->inspect($tmp,'filas.csv'); foreach($reader->rows($tmp,$meta['headers'],$meta['delimiter']) as $_){} }
catch(MigrationException $e){$rowLimit=$e->safeCode()==='row_limit';}
check('rechaza CSV que supera el máximo de filas', $rowLimit);
@unlink($tmp);

finishTests();
