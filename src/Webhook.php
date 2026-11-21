<?php

namespace TicketbaiWS;

use TicketbaiWS\Exception\WebhookException;

/**
 * Recepción de avisos de webhook de Ticketbai WS.
 *
 * Cada aviso llega firmado con HMAC-SHA256 del cuerpo RAW usando el secret
 * del webhook, en base64, en la cabecera X-Tbaiws-Hmac-Sha256. La cabecera
 * X-TbaiWs-Environment indica el entorno (test o real).
 *
 *   $aviso = \TicketbaiWS\Webhook::recibir('mi-secret');   // lanza WebhookException si la firma no vale
 *   $aviso['datos'];   // cuerpo decodificado
 */
final class Webhook
{
    const CABECERA_FIRMA = 'X-Tbaiws-Hmac-Sha256';
    const CABECERA_ENTORNO = 'X-TbaiWs-Environment';

    private function __construct()
    {
    }

    /** Calcula la firma esperada para un cuerpo. */
    public static function firmar(string $cuerpo, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $cuerpo, $secret, true));
    }

    /** Comprueba la firma en tiempo constante. $cuerpo debe ser el cuerpo RAW, sin decodificar. */
    public static function verificarFirma(string $cuerpo, string $secret, ?string $firmaRecibida): bool
    {
        if ($firmaRecibida === null || $firmaRecibida === '') {
            return false;
        }
        return hash_equals(self::firmar($cuerpo, $secret), trim($firmaRecibida));
    }

    /**
     * Lee el aviso de la petición actual (php://input y $_SERVER), verifica la
     * firma y devuelve ['entorno' => 'test'|'real'|null, 'datos' => array].
     *
     * @param string|null          $cuerpo   Para tests o frameworks: cuerpo RAW
     * @param array<string,string>|null $cabeceras Para tests o frameworks: cabeceras (cualquier capitalización)
     * @return array{entorno:?string, datos:array<string,mixed>}
     * @throws WebhookException
     */
    public static function recibir(string $secret, ?string $cuerpo = null, ?array $cabeceras = null): array
    {
        if ($cuerpo === null) {
            $cuerpo = (string) file_get_contents('php://input');
        }
        $firma = self::cabecera(self::CABECERA_FIRMA, $cabeceras);
        if (!self::verificarFirma($cuerpo, $secret, $firma)) {
            throw new WebhookException('Firma del webhook no válida');
        }
        $datos = json_decode($cuerpo, true);
        if (!is_array($datos)) {
            throw new WebhookException('El cuerpo del webhook no es JSON válido');
        }
        return ['entorno' => self::cabecera(self::CABECERA_ENTORNO, $cabeceras), 'datos' => $datos];
    }

    /** @param array<string,string>|null $cabeceras */
    private static function cabecera(string $nombre, ?array $cabeceras): ?string
    {
        if ($cabeceras !== null) {
            foreach ($cabeceras as $k => $v) {
                if (strcasecmp((string) $k, $nombre) === 0) {
                    return is_array($v) ? (string) reset($v) : (string) $v;
                }
            }
            return null;
        }
        $clave = 'HTTP_' . strtoupper(str_replace('-', '_', $nombre));
        return isset($_SERVER[$clave]) ? (string) $_SERVER[$clave] : null;
    }
}
