<?php

namespace TicketbaiWS\Http;

use TicketbaiWS\Exception\ConnectionException;

final class CurlTransport implements TransportInterface
{
    /** @var int */
    private $timeout;
    /** @var bool */
    private $verifySsl;

    public function __construct(int $timeout = 60, bool $verifySsl = true)
    {
        $this->timeout = $timeout;
        $this->verifySsl = $verifySsl;
    }

    public function send(string $method, string $url, array $headers, ?string $body = null, ?array $multipart = null): array
    {
        $ch = curl_init();
        $responseHeaders = [];

        if ($multipart !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart);
        } else {
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
            } else {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            }
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(15, $this->timeout));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifySsl);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verifySsl ? 2 : 0);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$responseHeaders) {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $responseHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }
            return strlen($line);
        });

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = $errno ? curl_error($ch) : '';
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }

        if ($errno) {
            throw new ConnectionException('Error de conexión con Ticketbai WS (curl #' . $errno . '): ' . $error, $errno);
        }

        return ['status' => $status, 'body' => (string) $response, 'headers' => $responseHeaders];
    }
}
