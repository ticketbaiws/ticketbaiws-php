<?php

namespace TicketbaiWS\Resource;

use TicketbaiWS\Client;
use TicketbaiWS\Response;

/**
 * Productos de la herramienta de facturación del área privada (solo consulta).
 *
 * Cada producto trae descripcion, importe_unitario (sin IVA), tipo_iva,
 * tipo_req y tipo_retencion: los mismos nombres que las líneas de crearFactura().
 */
final class Productos
{
    /** @var Client */
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * GET /productos/ — busca por texto (nombre es/en/eu, referencia, marca,
     * código HS o familia). $filtros admite además referencia (exacta) y familia.
     *
     * @param array<string,mixed> $filtros
     */
    public function buscar(string $texto = '', array $filtros = [], int $pagina = 0, int $cantidad = 50): Response
    {
        $filtros = array_merge($filtros, ['pagina' => $pagina, 'cantidad' => $cantidad]);
        if ($texto !== '') {
            $filtros['buscar'] = $texto;
        }
        return $this->client->peticion('GET', 'productos', $filtros);
    }

    /** GET /productos/{codigo}/ — $codigo es el campo «codigo» de buscar(). */
    public function obtener(string $codigo): Response
    {
        return $this->client->peticion('GET', 'productos/' . rawurlencode($codigo));
    }

    /**
     * Línea de factura lista para crearFactura() a partir de un producto.
     *
     * @param array<string,mixed> $producto fila de buscar() u obtener()
     * @return array<string,mixed>
     */
    public static function linea(array $producto, float $cantidad = 1.0, ?float $descuento = null): array
    {
        $linea = [
            'descripcion' => isset($producto['descripcion']) ? $producto['descripcion'] : '',
            'cantidad' => $cantidad,
            'importe_unitario' => isset($producto['importe_unitario']) ? (float) $producto['importe_unitario'] : 0.0,
            'tipo_iva' => isset($producto['tipo_iva']) ? (float) $producto['tipo_iva'] : 0.0,
            'tipo_req' => isset($producto['tipo_req']) ? (float) $producto['tipo_req'] : 0.0,
        ];
        if ($descuento !== null) {
            $linea['descuento'] = $descuento;
        }
        return $linea;
    }
}
