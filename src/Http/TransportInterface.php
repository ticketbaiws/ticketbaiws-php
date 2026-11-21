<?php

namespace TicketbaiWS\Http;

/**
 * Capa de transporte HTTP. La implementación por defecto usa cURL; puedes
 * inyectar la tuya (por ejemplo, para tests o para usar Guzzle/PSR-18).
 */
interface TransportInterface
{
    /**
     * @param string                    $method    GET, POST, PUT o DELETE
     * @param string                    $url       URL completa
     * @param string[]                  $headers   Cabeceras "Nombre: valor"
     * @param string|null               $body      Cuerpo JSON (null = sin cuerpo)
     * @param array<string,mixed>|null  $multipart Campos multipart; los ficheros como \CURLFile
     *
     * @return array{status:int, body:string, headers:array<string,string>}
     *         Cabeceras con el nombre en minúsculas.
     *
     * @throws \TicketbaiWS\Exception\ConnectionException
     */
    public function send(string $method, string $url, array $headers, ?string $body = null, ?array $multipart = null): array;
}
