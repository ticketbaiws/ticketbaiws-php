<?php
/** Integradores: alta de una empresa cliente con el token de desarrollador. */
require __DIR__ . '/../vendor/autoload.php';

use TicketbaiWS\Cuenta;
use TicketbaiWS\Diputacion;

$cuenta = new Cuenta(getenv('TBAIWS_TOKEN_DESARROLLADOR'), getenv('TBAIWS_NIF_CUENTA'));

$r = $cuenta->crearEmpresa([
    'nombre_social' => 'Mi Cliente S.L.',
    'nif'           => 'B89670855',
    'direccion'     => 'Calle Mayor 1',
    'poblacion'     => 'Bilbao',
    'provincia'     => 'Bizkaia',
    'cp'            => '48001',
    'diputacion'    => Diputacion::BIZKAIA,
]);

print_r($r->getData()); // incluye los tokens de pruebas y de producción de la empresa
