# Inventario estático FitCloud / DORLET

Fecha: 2026-08-25

Método: inspección estática sin ejecutar binarios, instaladores, DLL, scripts ni firmware.

## Paquete

- Origen local: fuera del worktree F26 y fuera del control de versiones.
- Nombre: `Fitcloud_Foto_y_Huella.zip`
- Tamaño: 35.493.095 bytes.
- SHA-256: `AF10A0FB8C1E10BC2E8E428B29C2C71D5BB473E14531CCEDA01221F5620EAE89`
- Entradas de fichero de primer nivel: 43.
- Tamaño descomprimido declarado: 48.337.729 bytes.
- Rutas Zip Slip: 0.
- Rutas duplicadas: 0.
- Entradas cifradas: 0.
- ZIP interno `32 bits.zip`: 3 entradas, sin rutas inseguras.
- El fichero llamado `32 bits.doc` es realmente un ZIP con dos instaladores renombrados.
- No se ejecutó ningún elemento.

## Resumen por extensión física

| Extensión | Cantidad |
|---|---:|
| DLL | 18 |
| EXE | 8 |
| MSI | 4 |
| CAB | 3 |
| TXT | 3 |
| BAT | 2 |
| DOC aparente | 1 |
| MOT | 1 |
| OCX | 1 |
| ZIP | 1 |
| Sin extensión | 1 |

Hay 27 PE ejecutables/bibliotecas y todos son x86. Entre los 31 elementos PE/MSI comprobables, 30 aparecen sin firma Authenticode válida; la única firma válida corresponde a un redistribuible de Microsoft. La ausencia de firma no demuestra malware, pero impide establecer procedencia e integridad editorial del resto.

## Contenedores y nombres engañosos

- `32 bits.doc`: ZIP, no documento; contiene copias de un MSI y un EXE.
- `Sagem MorphoSmart USB Drivers.txt`: MSI, no texto.
- `setup.txt`: ejecutable PE, no texto.
- `32 bits.zip`: contiene `Data1.cab`, un MSI de drivers y `setup.exe`.
- Los CAB contienen drivers `.sys`, catálogos, INF, DLL de instalación y un probable servicio `serv_spusb.exe`; solo se listó el índice del CAB.

## Inventario completo

| Ruta relativa | Bytes | SHA-256 | Clasificación | Arquitectura | Producto/versión |
|---|---:|---|---|---|---|
| `Fitcloud Foto y Huella/32 bits/32 bits.doc` | 2294707 | `0CBCE0F0E52FD05D579A380340A73EA36F89425366F6C88CE91C90C40997CD3E` | INSTALLER/ARCHIVE (.doc engañoso); ZIP | — | — |
| `Fitcloud Foto y Huella/32 bits/32 bits.zip` | 2456480 | `024A7E2A30243489DF899451DA4CFAADA308D4CE1CFB7AF885E755CC284F8C85` | INSTALLER/ARCHIVE; ZIP | — | — |
| `Fitcloud Foto y Huella/32 bits/Data1.cab` | 161713 | `5F10E94FCF09FB6B9903E5671452350A0E7FE20DBD81E7D71A9398FE06C2AF10` | DRIVER ARCHIVE; CAB | — | — |
| `Fitcloud Foto y Huella/32 bits/Old/Data1.cab` | 221851 | `FFFC630578085766B7394B402EED65F7D080A37D4624E51F8B01189A3FAAFF06` | DRIVER ARCHIVE; CAB | — | — |
| `Fitcloud Foto y Huella/32 bits/Old/Sagem MorphoSmart USB Drivers.msi` | 594260 | `28158962677EE1EE8EEDED901E811B30B6A56ED95CC0CF0F1383476AC1EF95C7` | DRIVER/INSTALLER; OLE/CFB | — | — |
| `Fitcloud Foto y Huella/32 bits/Old/setup.exe` | 2080762 | `0087898A28B58E5A0C6B2A7707F940E9D00A0E026AA32281080C6D9C29FB6231` | INSTALLER; PE | x86 | Sagem MorphoSmart USB Drivers / 2.41.22.2 |
| `Fitcloud Foto y Huella/32 bits/Sagem MorphoSmart USB Drivers.msi` | 761344 | `93A142E78C98024A5CA523EA7DAF956F72AE6F1C9F7977363B230037FBA417A0` | DRIVER/INSTALLER; OLE/CFB | — | — |
| `Fitcloud Foto y Huella/32 bits/Sagem MorphoSmart USB Drivers.txt` | 761344 | `93A142E78C98024A5CA523EA7DAF956F72AE6F1C9F7977363B230037FBA417A0` | DRIVER/INSTALLER (.txt engañoso); OLE/CFB | — | — |
| `Fitcloud Foto y Huella/32 bits/setup.exe` | 2197018 | `645E9D4570CEC99194DAA8D3A715D40F00933BADB6DCB8853C9DE5E93E21B745` | INSTALLER; PE | x86 | Sagem MorphoS / 3.54 |
| `Fitcloud Foto y Huella/32 bits/setup.txt` | 2197018 | `645E9D4570CEC99194DAA8D3A715D40F00933BADB6DCB8853C9DE5E93E21B745` | INSTALLER (.txt engañoso); PE | x86 | Sagem MorphoS / 3.54 |
| `Fitcloud Foto y Huella/32 bits/Test Reconocedor/ActiveMKit_Enrol.dll` | 421888 | `0C304DCE20D525A2301C0BAAACD8BD50A12E8AC31D8CB6351811AE3CF15667A8` | SDK/DLL; PE | x86 | ActiveMorphoKit Module / 3, 10, 0, 0 |
| `Fitcloud Foto y Huella/32 bits/Test Reconocedor/ActiveMKit_EnrolRes0a.dll` | 45056 | `1BD56D45B49A18528EE2B39A5EE2DC16731D0F5337FA676F13DEE98C5DB5F56D` | SDK/DLL; PE | x86 | ActiveMorphoKit Module / 3, 10, 0, 0 |
| `Fitcloud Foto y Huella/32 bits/Test Reconocedor/AMK_MSO300_Tester.exe` | 172032 | `14CDA15D7406194E0F6D40E857087060A78586CFFE40CA43594D609CF2F11738` | SDK TEST EXECUTABLE; PE | x86 | AMK_MSO300.dll / 3.8.1sdkmso-FDK |
| `Fitcloud Foto y Huella/32 bits/Test Reconocedor/AMK_MSO300.dll` | 294912 | `1A9E2544F7D222661FCC691CB6DA7DF75015822C9AB74797147E337884DE227D` | SDK/DLL; PE | x86 | AMK_MSO300.dll / 3.8.1sdkmso-FDK |
| `Fitcloud Foto y Huella/32 bits/Test Reconocedor/ImageCompress.dll` | 53248 | `050E92CF75633DA9C727BCE6012348C70AF2CC3DCAC11E3A2ECB86749694A328` | SDK/DLL; PE | x86 | ImageCompress / 1, 0, 0, 0 |
| `Fitcloud Foto y Huella/32 bits/Test Reconocedor/MORPHO_SDK.dll` | 196608 | `A15DD40969DD531FC7724903A774196CDB45613B4941A8BE71370F086EF8E0D9` | SDK/DLL; PE | x86 | MorphoSmart Software Development Kit / 4,4,0,0 |
| `Fitcloud Foto y Huella/32 bits/Test Reconocedor/MSO_SpUsb.dll` | 131124 | `AA9C5ABF4413C6D4E5B5828C65E86A6FC7AACD703947EA5473184823282C7834` | SDK/DLL; PE | x86 | SAGEM S.A. Mso_SpUsb / 4,4,0,0 |
| `Fitcloud Foto y Huella/32 bits/Test Reconocedor/MSO100.dll` | 98304 | `E62E9C6DF30E38C8C680D5932CCEADF8210786324FC10E00524173533A66A044` | SDK/DLL; PE | x86 | MorphoSmart SDK / 4,4,0,0 |
| `Fitcloud Foto y Huella/32 bits/Test Reconocedor/Registrar.bat` | 29 | `6679FA073B1731FBEF0BACFC7AEAB52B514E6B9B86A819A1AF6F146F9A99376D` | CONFIG/SCRIPT (no ejecutado); datos | — | — |
| `Fitcloud Foto y Huella/64 bits/Data1.cab` | 108496 | `745C4BEAF033BD3FD4D0C718E7FADD92F11003A7E4B6A2D1EF50CA2CB0A17DE3` | DRIVER ARCHIVE; CAB | — | — |
| `Fitcloud Foto y Huella/64 bits/Sagem MorphoSmart USB 64 bits Drivers.msi` | 889856 | `CF11AD0C18AE637A569F3A120A8D0AC752D99BBB211E9F5DF706E418405CCF60` | DRIVER/INSTALLER; OLE/CFB | — | — |
| `Fitcloud Foto y Huella/64 bits/setup.exe` | 4774137 | `F18EFD3BA127184E5AD4342FF70F5DD97FEB51CC7CDDD2DD1DE8F08D20CA229A` | INSTALLER; PE | x86 | Sagem MorphoS / 3.54 |
| `Fitcloud Foto y Huella/ActiveMKit_Enrol.dll` | 421888 | `0C304DCE20D525A2301C0BAAACD8BD50A12E8AC31D8CB6351811AE3CF15667A8` | SDK/DLL; PE | x86 | ActiveMorphoKit Module / 3, 10, 0, 0 |
| `Fitcloud Foto y Huella/ActiveMKit_EnrolRes0a.dll` | 45056 | `1BD56D45B49A18528EE2B39A5EE2DC16731D0F5337FA676F13DEE98C5DB5F56D` | SDK/DLL; PE | x86 | ActiveMorphoKit Module / 3, 10, 0, 0 |
| `Fitcloud Foto y Huella/AMK_MSO300.dll` | 294912 | `1A9E2544F7D222661FCC691CB6DA7DF75015822C9AB74797147E337884DE227D` | SDK/DLL; PE | x86 | AMK_MSO300.dll / 3.8.1sdkmso-FDK |
| `Fitcloud Foto y Huella/configuracion/configuracion.exe` | 2079744 | `E1E7F0CE2469A18E6453C9D59A06053737D88A16FFB0678D8DE4B0D0E4CAA323` | CONFIG EXECUTABLE; PE | x86 | — |
| `Fitcloud Foto y Huella/configuracion/datoscfg` | 1676 | `513BD32FD6473E8A2B6A7A5087513C31BC450B78767BDB80C0706F6AA4F75444` | CONFIG; Base64/XML | — | — |
| `Fitcloud Foto y Huella/configuracion/libeay32.dll` | 1114624 | `56F69B6D7FAE68E7C6AAC88AE68D8A9DCE0C4299CFE8635B2138E45433C75D53` | DLL/OCX; PE | x86 | The OpenSSL Toolkit / 0.9.8r |
| `Fitcloud Foto y Huella/configuracion/ssleay32.dll` | 275456 | `494E06380DC07ACFCC7F8B80C49DFA0D5F5C1B391742D3A4C4DBBE42F2845AD7` | DLL/OCX; PE | x86 | The OpenSSL Toolkit / 0.9.8r |
| `Fitcloud Foto y Huella/Flash/ASDx V134 FITCLOUD V102.mot` | 1966696 | `D0CDA7A95A835E9C8E4A097AE4C61EE5E0025628635D4A13F7D75956841664C9` | FIRMWARE; S-record | — | — |
| `Fitcloud Foto y Huella/Flash/Dorlet Grabador Flash.msi` | 5027328 | `4FD1991F36BE4B0FBA511F8F5B5FB39D745C5FA8EA05654BC37CD169B7186483` | INSTALLER; OLE/CFB | — | — |
| `Fitcloud Foto y Huella/Flash/GrabadorFlash_ES_5_4_1.exe` | 6300816 | `BB23484058354DCF2B242E36C06C168A0DE23CA368A2150981660A006A83F8B0` | INSTALLER; PE | x86 | Dorlet Grabador Flash / 5.4.0 |
| `Fitcloud Foto y Huella/fotoclientes.exe` | 2825728 | `8446047A78EB0427A5F9FF48A592AE76861282E1B0B33CB88BD9E9BEDB668E17` | EXECUTABLE; PE | x86 | — |
| `Fitcloud Foto y Huella/ImageCompress.dll` | 53248 | `050E92CF75633DA9C727BCE6012348C70AF2CC3DCAC11E3A2ECB86749694A328` | SDK/DLL; PE | x86 | ImageCompress / 1, 0, 0, 0 |
| `Fitcloud Foto y Huella/libeay32.dll` | 1114624 | `56F69B6D7FAE68E7C6AAC88AE68D8A9DCE0C4299CFE8635B2138E45433C75D53` | DLL/OCX; PE | x86 | The OpenSSL Toolkit / 0.9.8r |
| `Fitcloud Foto y Huella/loghuella.txt` | 0 | `E3B0C44298FC1C149AFBF4C8996FB92427AE41E4649B934CA495991B7852B855` | TEMP/LOG VACÍO; vacío | — | — |
| `Fitcloud Foto y Huella/MORPHO_SDK.dll` | 196608 | `A15DD40969DD531FC7724903A774196CDB45613B4941A8BE71370F086EF8E0D9` | SDK/DLL; PE | x86 | MorphoSmart Software Development Kit / 4,4,0,0 |
| `Fitcloud Foto y Huella/MSO_SpUsb.dll` | 131124 | `AA9C5ABF4413C6D4E5B5828C65E86A6FC7AACD703947EA5473184823282C7834` | SDK/DLL; PE | x86 | SAGEM S.A. Mso_SpUsb / 4,4,0,0 |
| `Fitcloud Foto y Huella/MSO100.dll` | 98304 | `E62E9C6DF30E38C8C680D5932CCEADF8210786324FC10E00524173533A66A044` | SDK/DLL; PE | x86 | MorphoSmart SDK / 4,4,0,0 |
| `Fitcloud Foto y Huella/registrar.bat` | 190 | `3D7E58BBCB9D57F17C9C07E2FFB4394206A659D51B80A3C4C119D1426EBD0837` | CONFIG/SCRIPT (no ejecutado); datos | — | — |
| `Fitcloud Foto y Huella/ssleay32.dll` | 275456 | `494E06380DC07ACFCC7F8B80C49DFA0D5F5C1B391742D3A4C4DBBE42F2845AD7` | DLL/OCX; PE | x86 | The OpenSSL Toolkit / 0.9.8r |
| `Fitcloud Foto y Huella/Videocapocx/videocapx.ocx` | 1116160 | `766004C8738F5598D64FE00D45F441417B79A5F0ACD549F0585A5C33CD63DBC7` | DLL/OCX; PE | x86 | VideoCapX ActiveX Control Module / 6, 3, 0, 508 |
| `Fitcloud Foto y Huella/Videocapocx/wmfdist.exe` | 4085904 | `FD0754A2EF3567859DB0BF3C75F18EC50AAEAE6A7561AFF9E7F6C7775A945ED7` | INSTALLER; PE | x86 | Windows Media Component Setup Application / 9.00.00.2980 |

## SDK y drivers identificados

Componentes Morpho/Sagem únicos, con copias duplicadas en varias carpetas:

- ActiveMorphoKit Enrol 1.7 / producto 3.10.
- AMK_MSO300 COM library 3.8.1sdkmso-FDK.
- MorphoSmart SDK 4.4.
- MSO100 4.4.
- MSO_SpUsb 4.4.
- ImageCompress 1.0.
- Drivers MorphoSmart USB de 32 y 64 bits.
- Probable servicio USB `serv_spusb.exe` dentro de los CAB.
- El lanzador etiquetado como 64 bits sigue siendo PE x86; el CAB sí declara un driver `usbsagmso_x64.sys`.

Componentes DORLET:

- Firmware textual S-record: `ASDx V134 FITCLOUD V102.mot`.
- Instalador `Dorlet Grabador Flash.msi`.
- Lanzador Dorlet Grabador Flash 5.4.0, x86.

Dependencias heredadas:

- OpenSSL 0.9.8r, x86.
- VideoCapX ActiveX 6.3.0.508.
- Windows Media Component Setup 9.00.

## Configuración y secretos

`datoscfg` es texto Base64 que decodifica a XML UTF-16. Contiene:

- hostname bajo `fitcloud.es`, con subdominio específico redactado;
- configuración de acceso seguro;
- flags DORLET/torno;
- número de lector y campos de control local;
- usuario técnico no vacío;
- contraseña técnica no vacía.

**SECRET_FOUND=true**

**CONFIG_SECRET_PRESENT=true**

No se muestra ningún valor y no se probó la credencial. Debe considerarse potencialmente activa hasta que el proveedor confirme lo contrario.

**ROTATE_AFTER_INVESTIGATION=true**

Los demás patrones aparentes de password/token encontrados en instaladores corresponden a recursos genéricos de Windows Installer, manifiestos o textos de interfaz; se clasifican como falsos positivos.

## PII y biometría

- No se identificaron bases de clientes, fotos, DNI, IBAN, emails de clientes ni registros personales dentro del paquete.
- Los emails detectados automáticamente pertenecen a metadatos/documentación de terceros.
- No se identificaron templates, minucias ni imágenes biométricas almacenadas.
- Sí existen SDK, funciones y UI capaces de capturar, enrolar, identificar, verificar y eliminar biometría en tiempo de ejecución.

**PII_FOUND=false**

**BIOMETRIC_DATA_FOUND=false**

Esta conclusión se limita al contenido estático del ZIP; no describe el equipo instalado ni sus bases de datos.

## Bases locales y documentación

- Archivos DB/MDB/SQLite/Firebird encontrados: 0.
- Evidencia SQL embebida relevante en los ejecutables principales: 0.
- Evidencia de SOAP/CGI y comunicación remota: sí.
- Documentación oficial del contrato FitCloud/DORLET: 0.
- No hay un manual de API, WSDL, esquema de mensajes autorizado ni licencia/contrato de SDK dentro del paquete.
