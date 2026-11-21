<?php

namespace TicketbaiWS\Resource;

use TicketbaiWS\Client;
use TicketbaiWS\Response;

/**
 * Libro del LROE de Batuz (solo Bizkaia) con las cuatro operaciones:
 * alta (POST), modificar (PUT), consultar (GET) y anular (DELETE).
 */
final class LroeRegistro
{
    /** @var Client */
    private $client;
    /** @var string */
    private $ruta;

    public function __construct(Client $client, string $ruta)
    {
        $this->client = $client;
        $this->ruta = $ruta;
    }

    /** @param array<string,mixed> $datos ejercicio + array de registros */
    public function alta(array $datos): Response
    {
        return $this->client->peticion('POST', $this->ruta, $datos);
    }

    /** @param array<string,mixed> $datos ejercicio + array de registros completos */
    public function modificar(array $datos): Response
    {
        return $this->client->peticion('PUT', $this->ruta, $datos);
    }

    /** @param array<string,mixed> $filtros ejercicio (obligatorio) + filtros opcionales + pagina */
    public function consultar(array $filtros): Response
    {
        return $this->client->peticion('GET', $this->ruta, $filtros);
    }

    /** @param array<string,mixed> $datos ejercicio + registros a anular (campos identificativos) */
    public function anular(array $datos): Response
    {
        return $this->client->peticion('DELETE', $this->ruta, $datos);
    }
}
