<?php

namespace TicketbaiWS;

/** URLs base de la API. */
final class Entorno
{
    const PRUEBAS = 'https://api-test.ticketbaiws.eus';
    const PRODUCCION = 'https://api.ticketbaiws.eus';

    private function __construct()
    {
    }

    /** Acepta "test"/"pruebas", "real"/"produccion"/"production" o una URL. */
    public static function url(string $entorno): string
    {
        switch (strtolower($entorno)) {
            case 'test':
            case 'pruebas':
            case 'sandbox':
                return self::PRUEBAS;
            case 'real':
            case 'produccion':
            case 'producción':
            case 'production':
            case 'prod':
                return self::PRODUCCION;
        }
        if (preg_match('#^https?://#i', $entorno)) {
            return rtrim($entorno, '/');
        }
        throw new \InvalidArgumentException('Entorno no válido: "' . $entorno . '". Usa "test", "real" o una URL.');
    }
}
