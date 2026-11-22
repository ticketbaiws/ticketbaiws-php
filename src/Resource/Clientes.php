<?php

namespace TicketbaiWS\Resource;

use TicketbaiWS\Client;
use TicketbaiWS\Response;

/**
 * Clientes de la herramienta de facturación del área privada.
 *
 * No dependen del entorno: con el token de pruebas se consultan y modifican
 * los mismos clientes que con el de producción. Los campos coinciden con los
 * de crearFactura() (nombre, nif, direccion, cp, poblacion, pais_cliente...).
 *
 *   $c = $tbai->clientes()->buscar('Uribe')->getData()[0];
 *   $tbai->crearFactura(['nif' => $c['nif'], 'nombre' => $c['nombre'], ...]);
 */
final class Clientes
{
    /** @var Client */
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * GET /clientes/ — busca por texto en NIF, nombre, nombre comercial, email
     * y teléfono. Paginado: getData() es la página; el total va en la respuesta
     * («Showing x to y of n» en el mensaje).
     */
    public function buscar(string $texto = '', int $pagina = 0, int $cantidad = 50): Response
    {
        $filtros = ['pagina' => $pagina, 'cantidad' => $cantidad];
        if ($texto !== '') {
            $filtros['buscar'] = $texto;
        }
        return $this->client->peticion('GET', 'clientes', $filtros);
    }

    /** GET /clientes/?nif= — cliente con ese NIF exacto (lista vacía si no existe). */
    public function porNif(string $nif): Response
    {
        return $this->client->peticion('GET', 'clientes', ['nif' => $nif]);
    }

    /** GET /clientes/{id}/ */
    public function obtener(int $id): Response
    {
        return $this->client->peticion('GET', 'clientes/' . $id);
    }

    /**
     * POST /clientes/ — nombre (obligatorio), nombre_comercial, nif, tipo_documento,
     * direccion, cp, poblacion, provincia, pais (ISO-2 o nombre), email, telefono, web.
     * ERROR si ya existe otro cliente con el mismo NIF (o, sin NIF, con el mismo nombre).
     *
     * @param array<string,mixed> $cliente
     */
    public function crear(array $cliente): Response
    {
        return $this->client->peticion('POST', 'clientes', $cliente);
    }

    /**
     * PUT /clientes/{id}/ — modificación parcial: solo cambian los campos enviados.
     *
     * @param array<string,mixed> $cambios
     */
    public function modificar(int $id, array $cambios): Response
    {
        return $this->client->peticion('PUT', 'clientes/' . $id, $cambios);
    }
}
