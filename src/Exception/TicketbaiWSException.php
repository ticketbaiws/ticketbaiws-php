<?php

namespace TicketbaiWS\Exception;

/**
 * Excepción base del SDK. Solo se lanza ante fallos de transporte o de uso
 * (red, límite de peticiones, JSON no codificable, fichero inexistente...).
 * Un rechazo de Hacienda o un error de validación de la API NO lanza
 * excepción: llega como Response con result = ERROR.
 */
class TicketbaiWSException extends \RuntimeException
{
}
