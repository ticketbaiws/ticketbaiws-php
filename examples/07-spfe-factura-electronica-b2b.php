<?php
/**
 * Factura electrónica B2B (Ley Crea y Crece): sincroniza las facturas recibidas
 * por la SPFE y comunica su pago. Pensado para ejecutarse con un cron.
 */
require __DIR__ . '/../vendor/autoload.php';

use TicketbaiWS\Client;

$tbai = new Client(getenv('TBAIWS_TOKEN'), getenv('TBAIWS_NIF'));
$spfe = $tbai->spfe();

$fichero = __DIR__ . '/ultimo_id_spfe.txt';
$ultimoId = is_file($fichero) ? (int) file_get_contents($fichero) : 0;

$r = $spfe->recibidasDesde($ultimoId);
if ($r->isError()) {
    exit('Error: ' . $r->getMessageText() . PHP_EOL);
}
if ($r->getMessageText() !== '') {
    echo 'Aviso: ' . $r->getMessageText() . PHP_EOL;   // p. ej. falta autorizar la consulta de recibidas
}

foreach ($r->getData() ?: [] as $recibida) {
    echo $recibida['factura'] . ' de ' . $recibida['nombre_emisor'] . ': ' . $recibida['total'] . " €\n";
    // ...guárdala en tu programa de contabilidad...
    $ultimoId = max($ultimoId, (int) $recibida['id']);
}
file_put_contents($fichero, (string) $ultimoId);

// Cuando la pagues:
// $spfe->pago($recibida, date('d/m/Y'), $recibida['vencimiento'], ['sincrono' => true]);

// Cuando te paguen una factura que has emitido:
// $spfe->cobro('A', '2026000245', date('d/m/Y'));
