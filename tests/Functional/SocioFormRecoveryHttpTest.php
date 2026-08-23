<?php

putenv('APP_ENV=test');
require_once dirname(__DIR__) . '/bootstrap.php';

$base = getenv('TEST_BASE_URL') ?: '';
$cookies = tempnam(sys_get_temp_dir(), 'ckf23_');
if ($base === '' || $cookies === false) {
    check('entorno HTTP F23 disponible', false);
    finishTests();
}
register_shutdown_function(static function () use ($cookies): void { @unlink($cookies); });

/** @return array{status:int,headers:string,body:string,location:string} */
function f23Request(string $url, ?array $post, string $cookies): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_HEADER=>true, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_COOKIEFILE=>$cookies, CURLOPT_COOKIEJAR=>$cookies,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $response=(string)curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $headerSize=(int)curl_getinfo($ch,CURLINFO_HEADER_SIZE); curl_close($ch);
    $headers=substr($response,0,$headerSize); $body=substr($response,$headerSize);
    preg_match('/^Location:\s*(.+)$/mi',$headers,$match);
    return ['status'=>$status,'headers'=>$headers,'body'=>$body,'location'=>trim($match[1]??'')];
}

function f23Csrf(string $html): string
{
    return preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $html, $match) ? $match[1] : '';
}

function f23Follow(string $location, string $base, string $cookies): array
{
    if (!preg_match('#^https?://#', $location)) {
        $origin = preg_replace('#/index\.php$#', '', $base);
        $location = rtrim((string)$origin, '/') . '/' . ltrim($location, '/');
    }
    return f23Request($location, null, $cookies);
}

$db=Database::getInstance()->getConnection();
$created=[];
try {
    $login=f23Request($base.'?action=login',null,$cookies);
    $r=f23Request($base.'?action=autenticar_gimnasio',[
        '_csrf'=>f23Csrf($login['body']),'email'=>'cleto.reyes.villaviciosa@gmail.com','contrasena'=>'cleto2026',
    ],$cookies);
    $employee=f23Follow($r['location'],$base,$cookies);
    $r=f23Request($base.'?action=autenticar',[
        '_csrf'=>f23Csrf($employee['body']),'usuario'=>'daniel','contrasena'=>'1234',
    ],$cookies);
    $panel=f23Request($base.'?action=admin_socios',null,$cookies);
    check('sesión sintética de recepción abre socios', $panel['status']===200 && str_contains($panel['body'],'Nuevo socio'));

    $common=[
        'nombre'=>'Conservada','apellidos'=>'Recepción F23','email'=>'f23.form@example.invalid',
        'telefono'=>'+34 600 123 123','usuario'=>'f23_form_user','contrasena'=>'Synthetic-F23-only!',
        'id_tipo_membresia'=>'0','metodo_pago'=>'efectivo','id_suplemento'=>'0',
        '_operation_id'=>bin2hex(random_bytes(16)),'volver_pagina'=>'1','volver_buscar'=>'',
    ];
    $invalid=f23Request($base.'?action=admin_socio_registrar',array_merge($common,[
        '_csrf'=>f23Csrf($panel['body']),'dni'=>'12345678A','iban'=>'',
    ]),$cookies);
    $recovered=f23Follow($invalid['location'],$base,$cookies);
    check('DNI inválido rechaza sin crear socio', (int)$db->query("SELECT COUNT(*) FROM usuario WHERE email='f23.form@example.invalid'")->fetchColumn()===0);
    check('alta inválida conserva campos no secretos', str_contains($recovered['body'],'value="Conservada"')
        && str_contains($recovered['body'],'value="f23.form@example.invalid"')
        && str_contains($recovered['body'],'value="12345678A"'));
    check('alta inválida no repuebla contraseña', preg_match('/<input[^>]+id="alta-contrasena"[^>]+value=/i',$recovered['body'])===0);
    check('DNI inválido muestra error inline y aria', str_contains($recovered['body'],'id="alta-dni-error"')
        && preg_match('/id="alta-dni"[^>]+aria-invalid="true"/i',$recovered['body'])===1);

    $valid=f23Request($base.'?action=admin_socio_registrar',array_merge($common,[
        '_csrf'=>f23Csrf($recovered['body']),'dni'=>'12345678Z','iban'=>'','contrasena'=>'Synthetic-F23-only!',
        '_operation_id'=>bin2hex(random_bytes(16)),
    ]),$cookies);
    $createdId=(int)$db->query("SELECT id_usuario FROM usuario WHERE email='f23.form@example.invalid'")->fetchColumn();
    if ($createdId>0) $created[]=$createdId;
    check('corregir solo DNI permite completar alta', $valid['status']===302 && $createdId>0);

    $createdRow=$db->query("SELECT * FROM usuario WHERE id_usuario={$createdId}")->fetch();
    $withoutCsrf=f23Request($base.'?action=admin_socio_editar',[
        'id_socio'=>$createdId,'profile_version'=>$createdRow['profile_version'],
        'nombre'=>'CSRF no debe guardar','apellidos'=>$createdRow['apellidos'],
        'dni'=>'00000000T','email'=>$createdRow['email'],'telefono'=>$createdRow['telefono'],'iban'=>'',
    ],$cookies);
    check('edición sin CSRF no cambia el socio', $withoutCsrf['status']===302
        && $db->query("SELECT nombre FROM usuario WHERE id_usuario={$createdId}")->fetchColumn()===$createdRow['nombre']);

    $panel=f23Request($base.'?action=admin_socios',null,$cookies);
    $edit=f23Request($base.'?action=admin_socio_editar',[
        '_csrf'=>f23Csrf($panel['body']),'id_socio'=>$createdId,
        'profile_version'=>$createdRow['profile_version'],'nombre'=>'Conservada','apellidos'=>'Recepción F23',
        'dni'=>'00000000T','email'=>'f23.form@example.invalid','telefono'=>'+34 600 123 123','iban'=>'',
        'volver_pagina'=>'1','volver_buscar'=>'',
    ],$cookies);
    $edited=$db->query("SELECT dni,profile_version FROM usuario WHERE id_usuario={$createdId}")->fetch();
    check('edición HTTP permite corregir DNI con versión optimista', $edit['status']===302
        && $edited['dni']==='00000000T'
        && (int)$edited['profile_version']===(int)$createdRow['profile_version']+1);

    $panel=f23Request($base.'?action=admin_socios',null,$cookies);
    $ibanCommon=$common;
    $ibanCommon['email']='f23.iban@example.invalid'; $ibanCommon['usuario']='f23_iban_user';
    $ibanCommon['dni']='00000000T'; $ibanCommon['id_tipo_membresia']='1'; $ibanCommon['metodo_pago']='transferencia';
    $invalidIban=f23Request($base.'?action=admin_socio_registrar',array_merge($ibanCommon,[
        '_csrf'=>f23Csrf($panel['body']),'iban'=>'ES001234','_operation_id'=>bin2hex(random_bytes(16)),
    ]),$cookies);
    $recoveredIban=f23Follow($invalidIban['location'],$base,$cookies);
    check('IBAN inválido rechaza sin crear socio', (int)$db->query("SELECT COUNT(*) FROM usuario WHERE email='f23.iban@example.invalid'")->fetchColumn()===0);
    check('IBAN inválido conserva formulario y error inline', str_contains($recoveredIban['body'],'value="f23.iban@example.invalid"')
        && str_contains($recoveredIban['body'],'value="ES001234"')
        && str_contains($recoveredIban['body'],'id="alta-iban-error"'));
} catch (Throwable $exception) {
    check('flujo HTTP F23 completo', false);
    fwrite(STDERR,get_class($exception).': '.$exception->getMessage()."\n");
} finally {
    if ($created !== []) {
        $ids=implode(',',array_map('intval',$created));
        $db->exec("DELETE FROM log_actividad WHERE id_usuario_afectado IN ({$ids}) OR id_usuario IN ({$ids})");
        $db->exec("DELETE FROM usuario WHERE id_usuario IN ({$ids})");
    }
}

finishTests();
