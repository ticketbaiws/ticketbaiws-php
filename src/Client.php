<?php

namespace TicketbaiWS;

use TicketbaiWS\Resource\LroeRegistro;

/**
 * Cliente de la API de Ticketbai WS con las credenciales de una EMPRESA
 * (token de pruebas o de producción + NIF del emisor).
 *
 * El mismo cliente sirve para TicketBAI (Araba, Bizkaia, Gipuzkoa) y para
 * Verifactu: la API decide el sistema según cómo esté dada de alta la empresa.
 *
 *   $tbai = new \TicketbaiWS\Client($token, 'B01000012', ['entorno' => 'test']);
 *   $r = $tbai->crearFactura([...]);
 *   if ($r->isSuccess()) { $qr = $r->getData('qr'); } else { echo $r->getMessageText(); }
 *
 * Rechazos de Hacienda y errores de validación llegan como Response con
 * result = ERROR. Solo se lanzan excepciones por fallos de transporte
 * (ConnectionException, RateLimitException) o de uso (TicketbaiWSException).
 */
class Client extends AbstractApi
{
    // -----------------------------------------------------------------
    // Sistema
    // -----------------------------------------------------------------

    /** GET /status/ — conectividad, certificado y licencia. */
    public function estado(): Response
    {
        return $this->peticion('GET', 'status');
    }

    /** true si GET /status/ devuelve OK. */
    public function estaOperativo(): bool
    {
        return $this->estado()->isOk();
    }

    /** POST /validar-nif/ — censo de la AEAT. $nombre obligatorio para personas físicas. Solo producción. */
    public function validarNif(string $nif, ?string $nombre = null): Response
    {
        $datos = ['nif' => $nif];
        if ($nombre !== null) {
            $datos['nombre'] = $nombre;
        }
        return $this->peticion('POST', 'validar-nif', $datos);
    }

    /** POST /validar-nif-vies/ — NIF-IVA intracomunitario. $pais ISO-3166 alfa-2. Solo producción. */
    public function validarNifVies(string $nif, string $pais): Response
    {
        return $this->peticion('POST', 'validar-nif-vies', ['nif' => $nif, 'pais' => $pais]);
    }

    // -----------------------------------------------------------------
    // Facturas (TicketBAI y Verifactu)
    // -----------------------------------------------------------------

    /**
     * POST /tbai/ — emite una factura. Los importes de las líneas van SIN IVA.
     * Por defecto el envío a Hacienda se hace en segundo plano; pasa
     * 'sincrono' => true para esperar su respuesta.
     *
     * Devuelve huella_tbai (TicketBAI) o huella (Verifactu), qr (PNG en base64) y url.
     *
     * @param array<string,mixed> $factura
     */
    public function crearFactura(array $factura): Response
    {
        return $this->peticion('POST', 'tbai', $factura);
    }

    /**
     * Reenvía una factura con errores o avisos con los datos corregidos
     * (zuzendu / subsanación). Serie, número y total deben ser los originales.
     *
     * @param array<string,mixed> $factura
     */
    public function subsanarFactura(array $factura): Response
    {
        $factura['zuzendu'] = true;
        return $this->crearFactura($factura);
    }

    /** GET /tbai/ — huella, QR, URL y estado actual (return.status: OK, PENDING o ERROR). */
    public function consultarFactura(string $serie, string $numero): Response
    {
        return $this->peticion('GET', 'tbai', ['serie' => $serie, 'numero' => $numero]);
    }

    /**
     * DELETE /tbai/ — anula una factura. No se podrá volver a usar ese número.
     * $fecha (dd/mm/aaaa) opcional: si no se indica, la última emitida con esa serie y número.
     */
    public function anularFactura(string $serie, string $numero, ?string $fecha = null): Response
    {
        $datos = ['serie' => $serie, 'numero' => $numero];
        if ($fecha !== null) {
            $datos['fecha'] = $fecha;
        }
        return $this->peticion('DELETE', 'tbai', $datos);
    }

    /**
     * GET /tbai-list/ — listado paginado (250 por página).
     * $filtros: fecha_inicio y fecha_fin (dd/mm/aaaa, obligatorias), serie,
     * pagina, json_orig, xml_request, pedido.
     *
     * @param array<string,mixed> $filtros
     */
    public function listarFacturas(array $filtros): Response
    {
        return $this->peticion('GET', 'tbai-list', $filtros);
    }

    /**
     * POST /tbai-completar/ — emite una factura completa a partir de una o
     * varias simplificadas (fecha, hora, nif, nombre, direccion, cp, serie,
     * numero, simplificadas[]...).
     *
     * @param array<string,mixed> $datos
     */
    public function completarFactura(array $datos): Response
    {
        return $this->peticion('POST', 'tbai-completar', $datos);
    }

    /** GET /tbai-xml/ — xml_request y xml_response (en texto). */
    public function xmlFactura(string $serie, string $numero): Response
    {
        return $this->peticion('GET', 'tbai-xml', ['serie' => $serie, 'numero' => $numero]);
    }

    /** GET /tbai-pdf/ — PDF de la factura en base64 (en return). */
    public function pdfFactura(string $serie, string $numero): Response
    {
        return $this->peticion('GET', 'tbai-pdf', ['serie' => $serie, 'numero' => $numero]);
    }

    /**
     * GET /facturae/ — XML FacturaE 3.2 en base64. Para FACe añade en $face:
     * cod_organo_gestor, cod_unidad_tramitadora, cod_oficina_contable.
     *
     * @param array<string,string> $face
     */
    public function facturae(string $serie, string $numero, array $face = []): Response
    {
        return $this->peticion('GET', 'facturae', array_merge(['serie' => $serie, 'numero' => $numero], $face));
    }

    /** PUT /reset-tbai/ — fuerza el reenvío de una factura que sigue PENDIENTE. */
    public function reenviarFactura(string $serie, string $numero): Response
    {
        return $this->peticion('PUT', 'reset-tbai', ['serie' => $serie, 'numero' => $numero]);
    }

    // -----------------------------------------------------------------
    // Documento de representación (Verifactu, Bizkaia y Gipuzkoa; no Araba)
    // -----------------------------------------------------------------

    /**
     * GET /doc-representante/modelo/ — modelo en PDF (base64) para firmar.
     * Personas jurídicas: nombre_representante, nif_representante,
     * poblacion_representante, direccion_representante.
     *
     * @param array<string,string> $representante
     */
    public function modeloDocRepresentante(array $representante = []): Response
    {
        return $this->peticion('GET', 'doc-representante/modelo', $representante);
    }

    /** POST /doc-representante/ — sube el PDF firmado con certificado reconocido. */
    public function enviarDocRepresentante(string $rutaPdfFirmado): Response
    {
        return $this->peticionMultipart('doc-representante', $rutaPdfFirmado, 'file');
    }

    /** GET /doc-representante/ — documento cargado (PDF en base64). */
    public function descargarDocRepresentante(): Response
    {
        return $this->peticion('GET', 'doc-representante');
    }

    /** DELETE /doc-representante/ — solo mientras no haya envíos en real. */
    public function revocarDocRepresentante(): Response
    {
        return $this->peticion('DELETE', 'doc-representante');
    }

    // -----------------------------------------------------------------
    // Batuz / LROE (solo Bizkaia)
    // -----------------------------------------------------------------

    /** GET /epigrafes/ — epígrafes del IAE (codigo, nombre_es, nombre_eu). */
    public function epigrafes(): Response
    {
        return $this->peticion('GET', 'epigrafes');
    }

    /** LROE — facturas recibidas (compras): ->alta(), ->modificar(), ->consultar(), ->anular(). */
    public function lroeRecibidas(): LroeRegistro
    {
        return new LroeRegistro($this, 'lroe-recibidas');
    }

    /** LROE 2.2 — gastos sin factura (modelo 140: autónomos y entidades en atribución de rentas). */
    public function lroeGastosSinFactura(): LroeRegistro
    {
        return new LroeRegistro($this, 'lroe-gastos-sinfactura');
    }

    /** LROE — criterio de caja, cobros. */
    public function lroeCriterioCajaCobros(): LroeRegistro
    {
        return new LroeRegistro($this, 'lroe-critcaja-cobros');
    }

    /** LROE — criterio de caja, pagos. */
    public function lroeCriterioCajaPagos(): LroeRegistro
    {
        return new LroeRegistro($this, 'lroe-critcaja-pagos');
    }

    // -----------------------------------------------------------------
    // Webhooks (cada entorno tiene los suyos)
    // -----------------------------------------------------------------

    /** POST /webhooks/ — avisa de los cambios de estado de las facturas. */
    public function crearWebhook(string $url, string $secret, bool $soloErrores = false, bool $activo = true): Response
    {
        return $this->peticion('POST', 'webhooks', [
            'url' => $url,
            'secret' => $secret,
            'solo_errores' => $soloErrores,
            'activo' => $activo,
        ]);
    }

    /**
     * PUT /webhooks/{codigo}/ — $cambios: url, secret, solo_errores, activo.
     *
     * @param array<string,mixed> $cambios
     */
    public function modificarWebhook(string $codigo, array $cambios): Response
    {
        return $this->peticion('PUT', 'webhooks/' . rawurlencode($codigo), $cambios);
    }

    /**
     * GET /webhooks/ — lista los webhooks de los dos entornos (campo entorno: test|real).
     * $filtros opcionales: solo_errores, activo (bool).
     *
     * @param array<string,bool> $filtros
     */
    public function listarWebhooks(array $filtros = []): Response
    {
        return $this->peticion('GET', 'webhooks', $filtros);
    }
}
