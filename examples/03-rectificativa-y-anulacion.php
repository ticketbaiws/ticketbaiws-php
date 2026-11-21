<?php
/** Rectificativa por diferencias y anulación. */
require __DIR__ . '/../vendor/autoload.php';

use TicketbaiWS\Client;

$tbai = new Client(getenv('TBAIWS_TOKEN'), getenv('TBAIWS_NIF'));

$r = $tbai->crearFactura([
    'fecha'              => date('d/m/Y'),
    'hora'               => date('H:i:s'),
    'serie'              => 'R' . date('Y'),
    'numero'             => '000001',
    'simplificada'       => false,
    'nif'                => 'B89670855',
    'nombre'             => 'Cliente de ejemplo S.L.',
    'direccion'          => 'Calle Mayor 1',
    'cp'                 => '01001',
    'rectificativa'      => true,
    'clave_rectificativa'=> 'R1',
    'tipo_rectificativa' => 'I',
    'rectificadas'       => [
        ['serie' => 'F' . date('Y'), 'numero' => '000001', 'fecha' => date('d/m/Y')],
    ],
    'retencion'          => 0,
    'lineas'             => [
        ['descripcion' => 'Devolución 2 horas', 'cantidad' => -2, 'importe_unitario' => 50, 'tipo_iva' => 21, 'tipo_req' => 0],
    ],
    'total_factura'      => -121.00,
]);
echo $r->getResult() . PHP_EOL;

// Anular (no se podrá reutilizar el número)
$a = $tbai->anularFactura('F' . date('Y'), '000002');
echo $a->getResult() . ' ' . $a->getMessageText() . PHP_EOL;
