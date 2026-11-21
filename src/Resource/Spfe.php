<?php

namespace TicketbaiWS\Resource;

use TicketbaiWS\Client;
use TicketbaiWS\Response;

/**
 * Factura electrónica B2B (Ley 18/2022 «Crea y Crece»): Solución Pública de
 * Facturación Electrónica (SPFE) de la AEAT.
 *
 * Ticketbai WS envía a la SPFE, en UBL, las facturas completas a clientes
 * españoles con NIF que emites con el API, y descarga cada hora las facturas
 * que recibes de tus proveedores. Con este recurso comunicas los estados que
 * exige la ley (pago, cobro, rechazo, impago) y consultas las recibidas.
 *
 * Solo para empresas con la SPFE activada (área privada → Factura electrónica
 * B2B, o Cuenta::activarSpfe()). Si no lo está, la respuesta es ERROR.
 *
 *   $spfe = $tbai->spfe();
 *   foreach ($spfe->recibidasDesde($ultimoId)->getData() as $f) { ... }
 *   $spfe->pago($f, '10/11/2026', $f['vencimiento']);
 */
final class Spfe
{
    const COBRO = 'cobro';
    const IMPAGO = 'impago';
    const PAGO = 'pago';
    const RECHAZO = 'rechazo';

    const RECHAZO_COMERCIAL = '01';
    const RECHAZO_NO_AFECTO = '02';

    /** @var Client */
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * POST /spfe-estados/ — comunica un estado con todos sus campos.
     * Por defecto queda en cola (result PENDING); con 'sincrono' => true se
     * envía en el momento y devuelve la respuesta de la AEAT (OK o ERROR).
     *
     * @param array<string,mixed> $datos estado, serie/numero (emitidas) o factura/fecha_factura/nif_emisor/nombre_emisor (recibidas), fecha, vencimiento...
     */
    public function comunicarEstado(array $datos): Response
    {
        return $this->client->peticion('POST', 'spfe-estados', $datos);
    }

    /**
     * Pago de una factura recibida. $recibida puede ser directamente una fila de
     * recibidas() (se usan factura, fecha_factura, nif_emisor y nombre_emisor).
     *
     * @param array<string,mixed> $recibida
     * @param string              $vencimiento dd/mm/aaaa (obligatorio en pago)
     * @param array<string,mixed> $opciones    fecha_recepcion_factura, rectificada, sincrono...
     */
    public function pago(array $recibida, ?string $fecha, string $vencimiento, array $opciones = []): Response
    {
        $datos = array_merge(['estado' => self::PAGO], $this->identificarRecibida($recibida), ['vencimiento' => $vencimiento]);
        if ($fecha !== null) {
            $datos['fecha'] = $fecha;
        }
        return $this->comunicarEstado(array_merge($datos, $opciones));
    }

    /**
     * Rechazo de una factura recibida.
     *
     * @param array<string,mixed> $recibida fila de recibidas() o factura/fecha_factura/nif_emisor/nombre_emisor
     * @param string              $motivo   RECHAZO_COMERCIAL (01) o RECHAZO_NO_AFECTO (02)
     * @param array<string,mixed> $opciones
     */
    public function rechazo(array $recibida, string $motivo = self::RECHAZO_COMERCIAL, array $opciones = []): Response
    {
        return $this->comunicarEstado(array_merge(
            ['estado' => self::RECHAZO],
            $this->identificarRecibida($recibida),
            ['motivo_rechazo' => $motivo],
            $opciones
        ));
    }

    /**
     * Cobro de una factura emitida con Ticketbai WS.
     *
     * @param array<string,mixed> $opciones vencimiento, financiacion, fecha_factura, sincrono...
     */
    public function cobro(string $serie, string $numero, ?string $fecha = null, array $opciones = []): Response
    {
        $datos = ['estado' => self::COBRO, 'serie' => $serie, 'numero' => $numero];
        if ($fecha !== null) {
            $datos['fecha'] = $fecha;
        }
        return $this->comunicarEstado(array_merge($datos, $opciones));
    }

    /**
     * Impago de una factura emitida con Ticketbai WS.
     *
     * @param string              $vencimiento fecha en la que debía pagarse (dd/mm/aaaa)
     * @param array<string,mixed> $opciones
     */
    public function impago(string $serie, string $numero, string $vencimiento, array $opciones = []): Response
    {
        return $this->comunicarEstado(array_merge(
            ['estado' => self::IMPAGO, 'serie' => $serie, 'numero' => $numero, 'vencimiento' => $vencimiento],
            $opciones
        ));
    }

    /**
     * Anula un estado comunicado por error: misma petición con baja = true.
     *
     * @param array<string,mixed> $datos los mismos datos con los que se comunicó
     */
    public function anularEstado(array $datos): Response
    {
        $datos['baja'] = true;
        return $this->comunicarEstado($datos);
    }

    /**
     * GET /spfe-estados/ — últimos 100 estados comunicados con su resultado
     * (PENDING, OK o ERROR con errores). Filtros: id, serie, numero, factura, nif_emisor.
     *
     * @param array<string,mixed> $filtros
     */
    public function estados(array $filtros = []): Response
    {
        return $this->client->peticion('GET', 'spfe-estados', $filtros);
    }

    /**
     * GET /spfe-recibidas/ — facturas recibidas de proveedores.
     * Filtros: desde_id, id, estado (pendiente|pagada|rechazada), nif_emisor,
     * factura, desde, hasta (dd/mm/aaaa), limite (100, máx. 500), ubl (bool).
     * Si la empresa no autoriza la consulta, se avisa en el mensaje.
     *
     * @param array<string,mixed> $filtros
     */
    public function recibidas(array $filtros = []): Response
    {
        return $this->client->peticion('GET', 'spfe-recibidas', $filtros);
    }

    /**
     * Facturas recibidas nuevas desde la última sincronización (id > $desdeId),
     * de la más antigua a la más reciente. Guarda el id más alto para la siguiente llamada.
     */
    public function recibidasDesde(int $desdeId, int $limite = 100, bool $conUbl = false): Response
    {
        $filtros = ['desde_id' => $desdeId, 'limite' => $limite];
        if ($conUbl) {
            $filtros['ubl'] = true;
        }
        return $this->recibidas($filtros);
    }

    /**
     * @param array<string,mixed> $recibida
     * @return array<string,mixed>
     */
    private function identificarRecibida(array $recibida): array
    {
        $out = [];
        foreach (['factura', 'fecha_factura', 'nif_emisor', 'nombre_emisor'] as $campo) {
            if (!isset($recibida[$campo]) || $recibida[$campo] === '') {
                throw new \InvalidArgumentException('Falta el campo "' . $campo . '" de la factura recibida');
            }
            $out[$campo] = $recibida[$campo];
        }
        return $out;
    }
}
