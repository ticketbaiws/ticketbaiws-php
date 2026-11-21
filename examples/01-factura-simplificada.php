<?php
/**
 * Factura simplificada (ticket) — TicketBAI o Verifactu.
 * Mismo código para los dos sistemas: la API usa el de la empresa.
 */
require __DIR__ . '/../vendor/autoload.php';

use TicketbaiWS\Client;

$tbai = new Client(getenv('TBAIWS_TOKEN'), getenv('TBAIWS_NIF'), ['entorno' => 'test']);

$r = $tbai->crearFactura([
    'fecha'         => date('d/m/Y'),
    'hora'          => date('H:i:s'),
    'serie'         => 'T' . date('Y'),
    'numero'        => '000001',
    'simplificada'  => true,
    'nif'           => '',
    'nombre'        => '',
    'rectificativa' => false,
    'retencion'     => 0,
    'lineas'        => [
        ['descripcion' => 'Café con leche', 'cantidad' => 2, 'importe_unitario' => 1.5, 'tipo_iva' => 10, 'tipo_req' => 0],
        ['descripcion' => 'Pintxo',         'cantidad' => 1, 'importe_unitario' => 2.5, 'tipo_iva' => 10, 'tipo_req' => 0],
    ],
    'total_factura' => 6.05, // importes de línea SIN IVA; el total CON IVA
]);

if ($r->isError()) {
    exit('Error: ' . $r->getMessageText() . PHP_EOL);
}

// TicketBAI devuelve huella_tbai; Verifactu, huella
echo 'Identificador: ' . ($r->getData('huella_tbai') ?: $r->getData('huella')) . PHP_EOL;
echo 'URL de cotejo: ' . $r->getData('url') . PHP_EOL;
file_put_contents(__DIR__ . '/qr.png', base64_decode($r->getData('qr')));
