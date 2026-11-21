# Ticketbai WS — cliente PHP para TicketBAI y Verifactu

[![Tests](https://github.com/ticketbaiws/ticketbaiws-php/actions/workflows/tests.yml/badge.svg)](https://github.com/ticketbaiws/ticketbaiws-php/actions/workflows/tests.yml)
[![Packagist](https://img.shields.io/packagist/v/ticketbaiws/ticketbaiws-php.svg)](https://packagist.org/packages/ticketbaiws/ticketbaiws-php)
[![PHP](https://img.shields.io/packagist/php-v/ticketbaiws/ticketbaiws-php.svg)](https://packagist.org/packages/ticketbaiws/ticketbaiws-php)
[![Licencia MIT](https://img.shields.io/badge/licencia-MIT-blue.svg)](LICENSE)

Librería PHP oficial de la [API de Ticketbai WS](https://ticketbaiws.eus/es/documentacion-api/) para emitir facturas **TicketBAI** (Araba, Bizkaia/Batuz y Gipuzkoa) y **Verifactu** (AEAT) desde cualquier tienda online, TPV o ERP.

Ticketbai WS genera y firma el XML, lo encadena, lo envía a Hacienda y te devuelve la huella, la URL de cotejo y el código QR. Tú solo envías los datos de la factura en JSON; esta librería se encarga del resto: autenticación, reintentos, errores y respuestas.

- Un único cliente para **TicketBAI y Verifactu**: el sistema lo decide la configuración de la empresa.
- **Factura electrónica B2B** (Ley Crea y Crece, SPFE de la AEAT): pagos, cobros, rechazos e impagos y facturas recibidas.
- **LROE de Batuz** (Bizkaia): facturas recibidas, gastos sin factura y criterio de caja.
- **Webhooks** con verificación de firma HMAC.
- Gestión de **empresas y licencias** para integradores.
- Sin dependencias: solo `ext-curl` y `ext-json`. **PHP 7.2 a 8.5**.

> 🇬🇧 *Official PHP client for the Ticketbai WS API (TicketBAI e-invoicing for the Basque Country and Verifactu for Spain's AEAT). See the [English summary](#english) at the end.*

## Índice

- [Instalación](#instalación)
- [Primeros pasos](#primeros-pasos)
- [Respuestas y errores](#respuestas-y-errores)
- [Facturas](#facturas)
- [Documento de representación](#documento-de-representación)
- [Factura electrónica B2B (SPFE)](#factura-electrónica-b2b-spfe)
- [LROE de Batuz (Bizkaia)](#lroe-de-batuz-bizkaia)
- [Webhooks](#webhooks)
- [Empresas y licencias (integradores)](#empresas-y-licencias-integradores)
- [Opciones del cliente](#opciones-del-cliente)
- [Métodos no envueltos](#métodos-no-envueltos)
- [Migrar desde class_ticketbaiwsclient](#migrar-desde-class_ticketbaiwsclient)

## Instalación

```bash
composer require ticketbaiws/ticketbaiws-php
```

¿Sin Composer? Descarga el repositorio e incluye `src/` con tu autoloader, o usa el fichero único [`legacy/class_ticketbaiwsclient.php`](legacy/class_ticketbaiwsclient.php).

## Primeros pasos

Necesitas una cuenta en [ticketbaiws.eus](https://ticketbaiws.eus). En el área privada encontrarás dos tokens por empresa: uno de **pruebas** y otro de **producción**.

```php
require 'vendor/autoload.php';

use TicketbaiWS\Client;

$tbai = new Client('TU_TOKEN', 'B01000012', ['entorno' => 'test']); // 'test' o 'real'

$r = $tbai->crearFactura([
    'fecha'         => date('d/m/Y'),
    'hora'          => date('H:i:s'),
    'serie'         => 'T2026',
    'numero'        => '000001',
    'simplificada'  => true,
    'nif'           => '',
    'nombre'        => '',
    'rectificativa' => false,
    'retencion'     => 0,
    'lineas'        => [
        ['descripcion' => 'Café', 'cantidad' => 2, 'importe_unitario' => 1.5, 'tipo_iva' => 10, 'tipo_req' => 0],
    ],
    'total_factura' => 3.30,
]);

if ($r->isSuccess()) {
    $identificador = $r->getData('huella_tbai') ?: $r->getData('huella'); // TicketBAI | Verifactu
    $urlCotejo     = $r->getData('url');
    $qrPng         = base64_decode($r->getData('qr'));
} else {
    echo $r->getMessageText();
}
```

**Importante:**

- Los importes de las líneas (`importe_unitario`) van **sin IVA**; `total_factura` es el total **con IVA**.
- El envío a Hacienda se hace **en segundo plano** y la respuesta es casi inmediata. Si necesitas la respuesta de Hacienda en la misma llamada, añade `'sincrono' => true`. También puedes consultar el estado más tarde o recibirlo por [webhook](#webhooks).
- Todos los campos de la factura están en la [documentación del API](https://ticketbaiws.eus/es/documentacion-api/) y en la [especificación OpenAPI](https://api.ticketbaiws.eus/openapi.json).

## Respuestas y errores

Todos los métodos devuelven un `TicketbaiWS\Response` con el sobre `{ result, return, msg }` de la API:

| Método | Devuelve |
|---|---|
| `getResult()` | `OK`, `PENDING` (Verifactu: aceptada y en cola) o `ERROR` |
| `isOk()`, `isPending()`, `isError()` | — |
| `isSuccess()` | `true` con `OK` o `PENDING` |
| `getData($campo = null, $porDefecto = null)` | el contenido de `return`, o uno de sus campos |
| `getMessage()` | `msg` tal cual: `null`, texto, XML de Hacienda o lista |
| `getMessages()` / `getMessageText($sep)` | `msg` normalizado a lista de textos o a un único texto |
| `getHttpStatus()`, `getRawBody()` | para depurar |

`Response` también se puede usar como array (`$r['result']`, `$r['return']`, `$r['msg']`) y se serializa con `json_encode()`.

**Un rechazo de Hacienda no es una excepción**: llega como `Response` con `result = ERROR` y los motivos en `msg`. Solo se lanzan excepciones (todas heredan de `TicketbaiWS\Exception\TicketbaiWSException`) cuando no hay respuesta que procesar:

| Excepción | Cuándo |
|---|---|
| `ConnectionException` | Error de red, DNS, TLS o timeout |
| `RateLimitException` | HTTP 429 tras agotar los reintentos (`getRetryAfter()`) |
| `TicketbaiWSException` | Datos no codificables en JSON (textos que no son UTF-8), fichero inexistente... |

```php
use TicketbaiWS\Exception\TicketbaiWSException;

try {
    $r = $tbai->crearFactura($factura);
    if ($r->isError()) {
        // Error de validación o rechazo de Hacienda: corrige y reenvía (subsanarFactura)
        log_error($r->getMessageText(' | '));
    }
} catch (TicketbaiWSException $e) {
    // Sin respuesta de la API: reintenta más tarde
}
```

## Facturas

| Método | API |
|---|---|
| `crearFactura(array $factura)` | `POST /tbai/` |
| `subsanarFactura(array $factura)` | `POST /tbai/` con `zuzendu: true` |
| `consultarFactura($serie, $numero)` | `GET /tbai/` (`return.status`: OK, PENDING o ERROR) |
| `anularFactura($serie, $numero, $fecha = null)` | `DELETE /tbai/` |
| `listarFacturas(array $filtros)` | `GET /tbai-list/` (`fecha_inicio`, `fecha_fin`, `serie`, `pagina`...) |
| `completarFactura(array $datos)` | `POST /tbai-completar/` (completa a partir de simplificadas) |
| `xmlFactura($serie, $numero)` | `GET /tbai-xml/` |
| `pdfFactura($serie, $numero)` | `GET /tbai-pdf/` (PDF en base64) |
| `facturae($serie, $numero, array $face = [])` | `GET /facturae/` (FacturaE 3.2 en base64; códigos DIR3 para FACe). **Solo producción** |
| `reenviarFactura($serie, $numero)` | `PUT /reset-tbai/` |
| `estado()` / `estaOperativo()` | `GET /status/` |
| `validarNif($nif, $nombre = null)` | `POST /validar-nif/` (censo AEAT) |
| `validarNifVies($nif, $pais)` | `POST /validar-nif-vies/` |

### Factura completa con respuesta de Hacienda

```php
$r = $tbai->crearFactura([
    'fecha' => '21/11/2026', 'hora' => '10:30:00',
    'serie' => 'F2026', 'numero' => '000123',
    'simplificada' => false,
    'nif' => 'B89670855', 'nombre' => 'Cliente S.L.',
    'direccion' => 'Calle Mayor 1', 'cp' => '01001',   // obligatorios en Araba y Gipuzkoa
    'rectificativa' => false, 'retencion' => 0,
    'lineas' => [
        ['descripcion' => 'Servicio', 'cantidad' => 1, 'importe_unitario' => 100, 'tipo_iva' => 21, 'tipo_req' => 0],
    ],
    'total_factura' => 121.00,
    'sincrono' => true,
]);
```

### Rectificativa

```php
$r = $tbai->crearFactura([
    // ... mismos datos de cabecera
    'rectificativa'       => true,
    'clave_rectificativa' => 'R1',  // R1-R4, o R5 en simplificadas
    'tipo_rectificativa'  => 'I',   // I: por diferencias, S: sustitutiva
    'rectificadas'        => [['serie' => 'F2026', 'numero' => '000123', 'fecha' => '21/11/2026']],
    'lineas'              => [['cantidad' => -1, 'importe_unitario' => 100, 'tipo_iva' => 21, 'tipo_req' => 0]],
    'total_factura'       => -121.00,
]);
```

Si la factura rectificada no se emitió con Ticketbai WS, añade en cada elemento de `rectificadas` sus importes (`base`, `cuota`, `recargo`).

### Subsanación (zuzendu)

Si Hacienda devuelve errores o avisos, reenvía los datos corregidos con `subsanarFactura()`. La serie, el número y el total deben ser los de la factura original. En Bizkaia se aplica a las facturas rechazadas por Batuz y conserva la huella y el QR; para cambiar una factura ya aceptada, emite una rectificativa.

### PDF y QR

```php
file_put_contents('factura.pdf', base64_decode($tbai->pdfFactura('F2026', '000123')->getData()));
file_put_contents('qr.png', base64_decode($r->getData('qr')));
```

## Documento de representación

Verifactu, Bizkaia y Gipuzkoa (en Araba la autorización es el registro del certificado de dispositivo):

```php
$modelo = $tbai->modeloDocRepresentante([           // solo personas jurídicas
    'nombre_representante' => 'Ane Etxeberria', 'nif_representante' => '12345678Z',
    'poblacion_representante' => 'Bilbao', 'direccion_representante' => 'Calle Mayor 1',
]);
file_put_contents('representacion.pdf', base64_decode($modelo->getData()));

// ...firmado con certificado reconocido (FNMT, Izenpe...)
$tbai->enviarDocRepresentante('representacion-firmado.pdf');
$tbai->descargarDocRepresentante();
$tbai->revocarDocRepresentante();   // solo antes del primer envío en real
```

## Factura electrónica B2B (SPFE)

La Ley 18/2022 «Crea y Crece» (RD 238/2026 y Orden HAC/1028/2026) obliga a expedir las facturas entre empresas y profesionales en formato electrónico y a informar de su estado: **pago, cobro, rechazo o impago**. Ticketbai WS envía automáticamente a la **SPFE de la AEAT**, en UBL, las facturas completas a clientes españoles con NIF que emites con `crearFactura()`, y descarga cada hora las facturas que recibes de tus proveedores. No tienes que generar el UBL ni hablar con la AEAT.

Se activa por empresa, en el área privada (*Factura electrónica B2B*) o por API: `$cuenta->activarSpfe($nif)` o `'spfe' => true` en `crearEmpresa()`. Al activarla se envían las facturas desde ese día.

```php
use TicketbaiWS\Resource\Spfe;

$spfe = $tbai->spfe();

// 1. Facturas recibidas nuevas desde la última sincronización
$r = $spfe->recibidasDesde($ultimoId);
foreach ($r->getData() as $recibida) {
    // id, factura, fecha_factura, nif_emisor, nombre_emisor, base, cuota, total, vencimiento, estado...
    $ultimoId = $recibida['id'];
}
if ($r->getMessageText() !== '') {
    // Aviso: falta autorizar la consulta de recibidas (certificado de la empresa o apoderamiento)
}

// 2. Pago o rechazo de una recibida (se le pasa la fila tal cual)
$spfe->pago($recibida, '10/11/2026', $recibida['vencimiento']);
$spfe->rechazo($recibida, Spfe::RECHAZO_COMERCIAL);

// 3. Cobro o impago de una factura que has emitido
$spfe->cobro('A', '2026000245', '10/11/2026');
$spfe->impago('A', '2026000245', '03/12/2026');

// Resultado: PENDING (en cola), OK o ERROR. Con 'sincrono' => true se espera a la AEAT
$spfe->estados(['serie' => 'A']);
$spfe->anularEstado(['estado' => 'cobro', 'serie' => 'A', 'numero' => '2026000245']);
```

| Método de `spfe()` | API |
|---|---|
| `pago($recibida, $fecha, $vencimiento, $opciones = [])` | `POST /spfe-estados/` con `estado: pago` |
| `rechazo($recibida, $motivo = '01', $opciones = [])` | `POST /spfe-estados/` con `estado: rechazo` |
| `cobro($serie, $numero, $fecha = null, $opciones = [])` | `POST /spfe-estados/` con `estado: cobro` |
| `impago($serie, $numero, $vencimiento, $opciones = [])` | `POST /spfe-estados/` con `estado: impago` |
| `comunicarEstado(array $datos)` / `anularEstado(array $datos)` | `POST /spfe-estados/` (con `baja: true` para anular) |
| `estados(array $filtros = [])` | `GET /spfe-estados/` |
| `recibidas(array $filtros = [])` / `recibidasDesde($id, $limite = 100, $conUbl = false)` | `GET /spfe-recibidas/` |

Para enterarte sin consultar, suscribe un webhook a los eventos `spfe_envio`, `spfe_estado` y `spfe_recibida` (ver [Webhooks](#webhooks)).

## LROE de Batuz (Bizkaia)

Cada libro tiene `alta()`, `modificar()`, `consultar()` y `anular()`:

| Libro | Método |
|---|---|
| Facturas recibidas (compras) | `$tbai->lroeRecibidas()` |
| Gastos sin factura (modelo 140, apartado 2.2) | `$tbai->lroeGastosSinFactura()` |
| Criterio de caja — cobros | `$tbai->lroeCriterioCajaCobros()` |
| Criterio de caja — pagos | `$tbai->lroeCriterioCajaPagos()` |

```php
$r = $tbai->lroeGastosSinFactura()->alta([
    'ejercicio' => 2026,
    'gastos' => [
        ['fecha_operacion' => '30/09/2026', 'concepto' => '64201', 'epigrafe' => '165140', 'linea' => 1, 'importe' => 294.00],
    ],
]);

$compras = $tbai->lroeRecibidas()->consultar(['ejercicio' => 2026, 'nif' => 'B01000012']);
```

`$tbai->epigrafes()` devuelve el catálogo de epígrafes del IAE.

## Webhooks

Recibe un aviso cada vez que cambia el estado de una factura (por ejemplo, de PENDING a OK o ERROR). Cada entorno (pruebas y producción) tiene sus propios webhooks.

| Evento (`Webhook::EVENTO_*`) | Cuándo |
|---|---|
| `cambio_estado` (por defecto) | Una factura TicketBAI o Verifactu pasa a OK o ERROR |
| `spfe_envio` | Resultado del envío de una factura a la SPFE |
| `spfe_estado` | Resultado de un pago, cobro, rechazo o impago comunicado |
| `spfe_recibida` | Factura nueva recibida de un proveedor (se avisa aunque el webhook sea `solo_errores`) |

```php
use TicketbaiWS\Webhook;

$w = $tbai->crearWebhook('https://tu-web.com/tbaiws-aviso.php', 'un-secret-largo', $soloErrores = false, true, Webhook::EVENTOS);
$codigo = $w->getData('codigo');

$tbai->modificarWebhook($codigo, ['activo' => false]);
$tbai->listarWebhooks(['activo' => true]);
```

En el receptor, verifica la firma (`X-Tbaiws-Hmac-Sha256`) antes de procesar el aviso:

```php
use TicketbaiWS\Webhook;
use TicketbaiWS\Exception\WebhookException;

try {
    $aviso = Webhook::recibir('un-secret-largo');   // lee php://input y las cabeceras
} catch (WebhookException $e) {
    http_response_code(401);
    exit;
}
$aviso['evento'];    // cambio_estado, spfe_envio, spfe_estado o spfe_recibida
$aviso['entorno'];   // 'test' o 'real' (cabecera X-TbaiWs-Environment)
$aviso['datos'];     // cuerpo del aviso decodificado
```

El cuerpo de cada evento está descrito en la sección `webhooks` del [openapi.json](https://api.ticketbaiws.eus/openapi.json). Si usas un framework, pásale el cuerpo RAW y las cabeceras: `Webhook::recibir($secret, $request->getContent(), $request->headers->all())`.

## Empresas y licencias (integradores)

Para dar de alta empresas de tus clientes desde tu software. Usa el **token de desarrollador** y el **NIF de tu cuenta** (área privada), no los de una empresa. Estos métodos solo existen en producción.

```php
use TicketbaiWS\Cuenta;
use TicketbaiWS\Diputacion;

$cuenta = new Cuenta('TOKEN_DESARROLLADOR', 'NIF_DE_TU_CUENTA');

$r = $cuenta->crearEmpresa([
    'nombre_social' => 'Mi Cliente S.L.', 'nif' => 'B89670855',
    'direccion' => 'Calle Mayor 1', 'poblacion' => 'Bilbao', 'provincia' => 'Bizkaia', 'cp' => '48001',
    'diputacion' => Diputacion::BIZKAIA,   // ARABA, BIZKAIA, GIPUZKOA o VERIFACTU
]);
// $r->getData() incluye los tokens de pruebas y de producción de la empresa

$cuenta->editarEmpresa('B89670855', ['email' => 'facturas@micliente.com']);
$cuenta->activarSpfe('B89670855');   // factura electrónica B2B (Ley Crea y Crece)
$cuenta->listarEmpresas();
$cuenta->crearLicencia($plan, $cantidad = 1, $meses = 12, 'anual');
$cuenta->listarLicencias();
```

## Opciones del cliente

```php
$tbai = new Client($token, $nif, [
    'entorno'        => 'test',   // 'test' | 'real' | URL base
    'timeout'        => 60,       // segundos
    'verificar_ssl'  => true,
    'reintentos_429' => 3,        // reintentos con espera creciente (respeta Retry-After)
    'transport'      => null,     // TicketbaiWS\Http\TransportInterface propio
]);
```

Con `transport` puedes usar tu propio cliente HTTP (Guzzle, PSR-18...) o un doble en tus tests. `getLastResponse()` devuelve la última respuesta recibida.

## Métodos no envueltos

Si la API publica un método que esta versión todavía no envuelve, llámalo con `peticion()`:

```php
$r = $tbai->peticion('GET', 'nuevo-metodo', ['param' => 'valor']);
```

## Migrar desde class_ticketbaiwsclient

La clase antigua sigue incluida (`legacy/class_ticketbaiwsclient.php`) y se carga con el autoloader de Composer, así que el código existente funciona sin cambios. Equivalencias:

| Antes | Ahora |
|---|---|
| `new class_ticketbaiwsclient($url, $token, $nif)` | `new Client($token, $nif, ['entorno' => 'real'])` |
| `nuevo_tbai($f)` → `return` o `msg` | `crearFactura($f)` → `Response` |
| `check_tbai($f)` | `consultarFactura($serie, $numero)` |
| `anula_tbai($f)` | `anularFactura($serie, $numero, $fecha)` |
| `tbai_list($f)` | `listarFacturas($filtros)` |
| `tbai_pdf($f)` / `xml_tbai($f)` | `pdfFactura()` / `xmlFactura()` |
| `reset_tbai($f)` | `reenviarFactura()` |
| `lroe_recibidas_alta($d)` | `lroeRecibidas()->alta($d)` |
| `spfe_estados_alta($d)` | `spfe()->comunicarEstado($d)` (o `pago()`, `cobro()`...) |
| `spfe_recibidas_consulta($d)` | `spfe()->recibidas($d)` |
| `webhooks_alta($d)` | `crearWebhook($url, $secret, ...)` |
| `webhooks_verificar_firma(...)` | `Webhook::verificarFirma(...)` |
| `empresas_alta($d)` | `(new Cuenta(...))->crearEmpresa($d)` |

## Desarrollo

```bash
composer install
composer test
```

## Soporte

- Documentación del API: https://ticketbaiws.eus/es/documentacion-api/
- Guía rápida, ejemplos y terminal de pruebas: https://ticketbaiws.eus/es/desarrolladores/
- Estado del servicio: https://ticketbaiws.eus/es/desarrolladores/estado/
- Incidencias de la librería: [GitHub Issues](https://github.com/ticketbaiws/ticketbaiws-php/issues)

## English

`ticketbaiws/ticketbaiws-php` is the official PHP client for the [Ticketbai WS](https://ticketbaiws.eus) API, a service that signs, chains and submits invoices to the Basque tax authorities (**TicketBAI**: Araba, Bizkaia/Batuz, Gipuzkoa) and to Spain's AEAT (**Verifactu**), returning the fingerprint, the verification URL and the QR code.

```php
$client = new TicketbaiWS\Client($token, $nif, ['entorno' => 'test']); // or 'real'
$res = $client->crearFactura($invoice);  // POST /tbai/
$res->isSuccess(); $res->getData('qr'); $res->getMessageText();
```

Method names follow the Spanish field names of the API. Full API reference: https://api.ticketbaiws.eus/openapi.json

## Licencia

MIT © [Berein Internet S.L.](https://ticketbaiws.eus)
