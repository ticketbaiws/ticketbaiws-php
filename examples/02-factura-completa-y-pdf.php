<?php
/** Factura completa a empresa, consulta de estado y descarga del PDF. */
require __DIR__ . '/../vendor/autoload.php';

use TicketbaiWS\Client;

$tbai = new Client(getenv('TBAIWS_TOKEN'), getenv('TBAIWS_NIF'));

$r = $tbai->crearFactura([
    'fecha'         => date('d/m/Y'),
    'hora'          => date('H:i:s'),
    'serie'         => 'F' . date('Y'),
    'numero'        => '000001',
    'simplificada'  => false,
    'nif'           => 'B89670855',
    'nombre'        => 'Cliente de ejemplo S.L.',
    'direccion'     => 'Calle Mayor 1',   // obligatoria en Araba y Gipuzkoa
    'cp'            => '01001',
    'rectificativa' => false,
    'retencion'     => 0,
    'lineas'        => [
        ['descripcion' => 'Desarrollo web', 'cantidad' => 10, 'importe_unitario' => 50, 'tipo_iva' => 21, 'tipo_req' => 0],
    ],
    'total_factura' => 605.00,
    'sincrono'      => true, // espera la respuesta de Hacienda
]);

echo $r->getResult() . ' ' . $r->getMessageText(' | ') . PHP_EOL;

// Más tarde: estado actual (return.status = OK, PENDING o ERROR)
$estado = $tbai->consultarFactura('F' . date('Y'), '000001');
echo 'Estado en Hacienda: ' . $estado->getData('status') . PHP_EOL;

// PDF de la factura
$pdf = $tbai->pdfFactura('F' . date('Y'), '000001');
if ($pdf->isOk()) {
    file_put_contents(__DIR__ . '/factura.pdf', base64_decode($pdf->getData()));
}
