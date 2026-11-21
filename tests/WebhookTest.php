<?php

namespace TicketbaiWS\Tests;

use PHPUnit\Framework\TestCase;
use TicketbaiWS\Exception\WebhookException;
use TicketbaiWS\Webhook;

final class WebhookTest extends TestCase
{
    public function testFirmaValida(): void
    {
        $cuerpo = '{"serie":"A","numero":"1","status":"OK"}';
        $firma = base64_encode(hash_hmac('sha256', $cuerpo, 'clave', true));
        $this->assertTrue(Webhook::verificarFirma($cuerpo, 'clave', $firma));
        $this->assertFalse(Webhook::verificarFirma($cuerpo, 'otra', $firma));
        $this->assertFalse(Webhook::verificarFirma($cuerpo, 'clave', null));
    }

    public function testRecibir(): void
    {
        $cuerpo = '{"serie":"A","numero":"1","status":"ERROR"}';
        $aviso = Webhook::recibir('clave', $cuerpo, [
            'x-tbaiws-hmac-sha256' => Webhook::firmar($cuerpo, 'clave'),
            'X-TbaiWs-Environment' => 'test',
        ]);
        $this->assertSame('test', $aviso['entorno']);
        $this->assertSame('ERROR', $aviso['datos']['status']);
    }

    public function testRecibirFirmaIncorrecta(): void
    {
        $this->expectException(WebhookException::class);
        Webhook::recibir('clave', '{}', ['X-Tbaiws-Hmac-Sha256' => 'xxx']);
    }
}
