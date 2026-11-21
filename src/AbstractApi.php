<?php

namespace TicketbaiWS;

use TicketbaiWS\Exception\RateLimitException;
use TicketbaiWS\Exception\TicketbaiWSException;
use TicketbaiWS\Http\CurlTransport;
use TicketbaiWS\Http\TransportInterface;

/**
 * Lógica HTTP común a Client (credenciales de empresa) y Cuenta
 * (credenciales de la cuenta de cliente).
 */
abstract class AbstractApi
{
    const VERSION = '1.1.0';

    /** @var string */
    protected $token;
    /** @var string */
    protected $nif;
    /** @var string */
    protected $baseUrl;
    /** @var TransportInterface */
    protected $transport;
    /** @var int */
    protected $reintentos429;
    /** @var callable */
    protected $sleeper;
    /** @var Response|null */
    protected $lastResponse;

    /**
     * Opciones:
     *  - entorno:        "test" (por defecto), "real" o una URL base
     *  - timeout:        segundos (60)
     *  - verificar_ssl:  bool (true)
     *  - reintentos_429: reintentos con espera creciente ante HTTP 429 (3; 0 = ninguno)
     *  - transport:      TransportInterface propio
     *
     * @param array<string,mixed> $opciones
     */
    public function __construct(string $token, string $nif, array $opciones = [])
    {
        $this->token = $token;
        $this->nif = $nif;
        $this->baseUrl = Entorno::url(isset($opciones['entorno']) ? (string) $opciones['entorno'] : $this->entornoPorDefecto());
        $this->transport = isset($opciones['transport'])
            ? $opciones['transport']
            : new CurlTransport(
                isset($opciones['timeout']) ? (int) $opciones['timeout'] : 60,
                isset($opciones['verificar_ssl']) ? (bool) $opciones['verificar_ssl'] : true
            );
        if (!$this->transport instanceof TransportInterface) {
            throw new \InvalidArgumentException('La opción "transport" debe implementar ' . TransportInterface::class);
        }
        $this->reintentos429 = isset($opciones['reintentos_429']) ? max(0, (int) $opciones['reintentos_429']) : 3;
        $this->sleeper = isset($opciones['sleeper']) && is_callable($opciones['sleeper']) ? $opciones['sleeper'] : 'sleep';
    }

    protected function entornoPorDefecto(): string
    {
        return 'test';
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /** Última respuesta recibida (útil para depurar). */
    public function getLastResponse(): ?Response
    {
        return $this->lastResponse;
    }

    /**
     * Petición genérica a cualquier método de la API, también a los que este
     * SDK todavía no envuelve. $ruta sin barras: "tbai", "webhooks/abc123"...
     *
     * Los parámetros de GET y DELETE se envían como JSON en el cuerpo (la API
     * los acepta así y permite estructuras anidadas).
     *
     * @param array<string,mixed> $datos
     * @throws TicketbaiWSException
     */
    public function peticion(string $metodo, string $ruta, array $datos = []): Response
    {
        $metodo = strtoupper($metodo);
        $body = null;
        if (!empty($datos)) {
            $body = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($body === false) {
                throw new TicketbaiWSException('No se pueden codificar los datos en JSON: ' . json_last_error_msg()
                    . '. Comprueba que los textos están en UTF-8.');
            }
        }
        $headers = array_merge(['Content-Type: application/json'], $this->cabecerasComunes());
        return $this->enviar($metodo, $this->url($ruta), $headers, $body, null);
    }

    /**
     * Subida multipart/form-data (sin Content-Type manual: lo genera cURL con el boundary).
     *
     * @param array<string,mixed> $campos
     * @throws TicketbaiWSException
     */
    protected function peticionMultipart(string $ruta, string $rutaFichero, string $campoFichero = 'file', array $campos = []): Response
    {
        if (!is_file($rutaFichero) || !is_readable($rutaFichero)) {
            throw new TicketbaiWSException('El fichero "' . $rutaFichero . '" no existe o no se puede leer');
        }
        $campos[$campoFichero] = new \CURLFile($rutaFichero, 'application/pdf', basename($rutaFichero));
        return $this->enviar('POST', $this->url($ruta), $this->cabecerasComunes(), null, $campos);
    }

    /** @return string[] */
    private function cabecerasComunes(): array
    {
        return [
            'Accept: application/json;charset=UTF-8',
            'Token: ' . $this->token,
            'Nif: ' . $this->nif,
            'User-Agent: ticketbaiws-php/' . self::VERSION . ' PHP/' . PHP_VERSION,
        ];
    }

    private function url(string $ruta): string
    {
        return $this->baseUrl . '/' . trim($ruta, '/') . '/';
    }

    /**
     * @param string[]                 $headers
     * @param array<string,mixed>|null $multipart
     */
    private function enviar(string $metodo, string $url, array $headers, ?string $body, ?array $multipart): Response
    {
        $intento = 0;
        while (true) {
            $res = $this->transport->send($metodo, $url, $headers, $body, $multipart);
            $status = (int) $res['status'];

            if ($status === 429) {
                $retryAfter = (isset($res['headers']['retry-after']) && ctype_digit((string) $res['headers']['retry-after']))
                    ? (int) $res['headers']['retry-after'] : null;
                if ($intento < $this->reintentos429) {
                    $espera = $retryAfter !== null ? $retryAfter : (int) pow(2, $intento);
                    call_user_func($this->sleeper, max(1, min(60, $espera)));
                    $intento++;
                    continue;
                }
                throw new RateLimitException('Límite de peticiones de Ticketbai WS superado (HTTP 429)', $retryAfter);
            }

            return $this->lastResponse = Response::fromHttp($status, (string) $res['body']);
        }
    }
}
