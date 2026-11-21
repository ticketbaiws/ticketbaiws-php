<?php
/** Bizkaia, modelo 140: gastos sin factura (LROE 2.2). */
require __DIR__ . '/../vendor/autoload.php';

use TicketbaiWS\Client;

$tbai = new Client(getenv('TBAIWS_TOKEN'), getenv('TBAIWS_NIF'));

$r = $tbai->lroeGastosSinFactura()->alta([
    'ejercicio' => 2026,
    'gastos'    => [
        ['fecha_operacion' => '30/09/2026', 'concepto' => '64201', 'epigrafe' => '165140', 'linea' => 1, 'importe' => 294.00],
        ['fecha_operacion' => '30/09/2026', 'concepto' => '626',   'epigrafe' => '165140', 'linea' => 1, 'importe' => 12.50,
         'nif' => 'A48265169', 'nombre_social' => 'Banco de ejemplo S.A.'],
    ],
]);

foreach ((array) $r->getData('response', []) as $gasto) {
    echo $gasto['concepto'] . ': ' . $gasto['estado'] . PHP_EOL;
}
