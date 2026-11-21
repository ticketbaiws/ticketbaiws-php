# Changelog

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/); versionado [SemVer](https://semver.org/lang/es/).

## [1.1.0] - 2026-11-22

Cubre la especificación `openapi.json` 1.0.5.

### Añadido
- Factura electrónica B2B (Ley Crea y Crece, SPFE de la AEAT): `$client->spfe()` con `pago()`, `rechazo()`, `cobro()`, `impago()`, `comunicarEstado()`, `anularEstado()`, `estados()`, `recibidas()` y `recibidasDesde()`.
- `Cuenta::activarSpfe($nif, $activar = true)` y parámetro `spfe` en `crearEmpresa()` / `editarEmpresa()`.
- Eventos de webhook: `crearWebhook(..., $eventos)` y constantes `Webhook::EVENTO_*` (`cambio_estado`, `spfe_envio`, `spfe_estado`, `spfe_recibida`). `Webhook::recibir()` devuelve también `evento`.
- Clase de un solo fichero: `spfe_estados_alta()`, `spfe_estados_consulta()` y `spfe_recibidas_consulta()`.

### Cambiado
- Documentado que `facturae()` solo existe en producción.

## [1.0.0] - 2026-11-21

Primera versión publicada en Packagist. Cubre la especificación `openapi.json` 1.0.3.

### Añadido
- `TicketbaiWS\Client`: facturas TicketBAI y Verifactu, documento de representación, LROE de Batuz (recibidas, gastos sin factura, criterio de caja), webhooks, validación de NIF.
- `TicketbaiWS\Cuenta`: empresas y licencias con el token de desarrollador.
- `TicketbaiWS\Response` con `isOk()`, `isPending()`, `isError()`, `getData()`, `getMessages()`.
- `TicketbaiWS\Webhook` para verificar la firma HMAC de los avisos.
- Reintentos automáticos ante HTTP 429 (respeta `Retry-After`).
- Transporte HTTP intercambiable (`TransportInterface`).
- `legacy/class_ticketbaiwsclient.php`: la clase de un solo fichero, revisada, para el código existente.
