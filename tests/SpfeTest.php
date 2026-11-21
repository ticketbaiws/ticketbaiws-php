<?php

namespace TicketbaiWS\Tests;

use PHPUnit\Framework\TestCase;
use TicketbaiWS\Client;
use TicketbaiWS\Cuenta;
use TicketbaiWS\Webhook;

final class SpfeTest extends TestCase
{
    /** @var FakeTransport */
    private $http;

    private function client(): Client
    {
        $this->http = new FakeTransport();
        return new Client('TOKEN', 'B01000012', ['transport' => $this->http]);
    }

    private function cuerpo(): array
    {
        return json_decode($this->http->ultima()['body'], true);
    }

    public function testPagoDeUnaRecibidaUsaSusCampos(): void
    {
        $c = $this->client();
        $recibida = ['id' => 57, 'factura' => 'F2026-0153', 'fecha_factura' => '03/11/2026', 'nif_emisor' => 'B01000017',
            'nombre_emisor' => 'Proveedor S.L.', 'total' => 302.5, 'vencimiento' => '03/12/2026'];
        $c->spfe()->pago($recibida, '10/11/2026', $recibida['vencimiento'], ['sincrono' => true]);
        $p = $this->http->ultima();
        $this->assertSame('POST', $p['method']);
        $this->assertSame('https://api-test.ticketbaiws.eus/spfe-estados/', $p['url']);
        $this->assertSame([
            'estado' => 'pago', 'factura' => 'F2026-0153', 'fecha_factura' => '03/11/2026', 'nif_emisor' => 'B01000017',
            'nombre_emisor' => 'Proveedor S.L.', 'vencimiento' => '03/12/2026', 'fecha' => '10/11/2026', 'sincrono' => true,
        ], $this->cuerpo());
    }

    public function testRechazoCobroImpagoYBaja(): void
    {
        $c = $this->client();
        $r = ['factura' => 'F1', 'fecha_factura' => '01/11/2026', 'nif_emisor' => 'B1', 'nombre_emisor' => 'X'];
        $c->spfe()->rechazo($r, '02');
        $this->assertSame('02', $this->cuerpo()['motivo_rechazo']);
        $this->assertSame('rechazo', $this->cuerpo()['estado']);

        $c->spfe()->cobro('A', '2026000245', '10/11/2026');
        $this->assertSame(['estado' => 'cobro', 'serie' => 'A', 'numero' => '2026000245', 'fecha' => '10/11/2026'], $this->cuerpo());

        $c->spfe()->impago('A', '2026000245', '03/12/2026');
        $this->assertSame('03/12/2026', $this->cuerpo()['vencimiento']);

        $c->spfe()->anularEstado(['estado' => 'cobro', 'serie' => 'A', 'numero' => '2026000245']);
        $this->assertTrue($this->cuerpo()['baja']);
    }

    public function testRecibidaIncompleta(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client()->spfe()->pago(['factura' => 'F1'], null, '03/12/2026');
    }

    public function testConsultas(): void
    {
        $c = $this->client();
        $this->http->responder(200, ['result' => 'OK', 'return' => [['id' => 58, 'factura' => 'F2']], 'msg' => null]);
        $r = $c->spfe()->recibidasDesde(57, 50);
        $this->assertSame('GET', $this->http->ultima()['method']);
        $this->assertSame('https://api-test.ticketbaiws.eus/spfe-recibidas/', $this->http->ultima()['url']);
        $this->assertSame(['desde_id' => 57, 'limite' => 50], $this->cuerpo());
        $this->assertSame(58, $r->getData()[0]['id']);

        $c->spfe()->estados(['serie' => 'A']);
        $this->assertSame('https://api-test.ticketbaiws.eus/spfe-estados/', $this->http->ultima()['url']);
    }

    public function testWebhookConEventos(): void
    {
        $c = $this->client();
        $c->crearWebhook('https://mi.web/aviso', 's', false, true, [Webhook::EVENTO_SPFE_RECIBIDA, Webhook::EVENTO_SPFE_ESTADO]);
        $this->assertSame(['spfe_recibida', 'spfe_estado'], $this->cuerpo()['eventos']);
        $c->crearWebhook('https://mi.web/aviso', 's');
        $this->assertArrayNotHasKey('eventos', $this->cuerpo());
    }

    public function testRecibirAvisoSpfe(): void
    {
        $cuerpo = '{"codigo_webhook":"abc","nif":"B1","evento":"spfe_recibida","id":58,"factura":"F2"}';
        $aviso = Webhook::recibir('k', $cuerpo, ['X-Tbaiws-Hmac-Sha256' => Webhook::firmar($cuerpo, 'k'), 'X-TbaiWs-Topic' => 'spfe_recibida']);
        $this->assertSame('spfe_recibida', $aviso['evento']);
        $this->assertSame(58, $aviso['datos']['id']);

        $cuerpo2 = '{"codigo_webhook":"abc","nif":"B1","serie":"A","numero":"1","estado":"OK"}';
        $aviso2 = Webhook::recibir('k', $cuerpo2, ['X-Tbaiws-Hmac-Sha256' => Webhook::firmar($cuerpo2, 'k')]);
        $this->assertSame('cambio_estado', $aviso2['evento']);
    }

    public function testActivarSpfeEnCuenta(): void
    {
        $http = new FakeTransport();
        (new Cuenta('DEV', 'B9', ['transport' => $http]))->activarSpfe('B01000012');
        $this->assertSame('https://api.ticketbaiws.eus/empresas/B01000012/', $http->ultima()['url']);
        $this->assertSame(['spfe' => true], json_decode($http->ultima()['body'], true));
    }
}
