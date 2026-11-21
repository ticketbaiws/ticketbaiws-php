# Changelog

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/); versionado [SemVer](https://semver.org/lang/es/).

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
