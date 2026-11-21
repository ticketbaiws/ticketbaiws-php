<?php
/**
 * Receptor de webhooks: publica este fichero en la URL que registraste con
 * $tbai->crearWebhook('https://tu-web/receptor.php', 'tu-secret').
 */
require __DIR__ . '/../vendor/autoload.php';

use TicketbaiWS\Exception\WebhookException;
use TicketbaiWS\Webhook;

try {
    $aviso = Webhook::recibir(getenv('TBAIWS_WEBHOOK_SECRET'));
} catch (WebhookException $e) {
    http_response_code(401);
    exit;
}

// $aviso['entorno'] = 'test' | 'real'; $aviso['datos'] = cuerpo del aviso
error_log('Aviso Ticketbai WS (' . $aviso['entorno'] . '): ' . json_encode($aviso['datos']));

http_response_code(200);
