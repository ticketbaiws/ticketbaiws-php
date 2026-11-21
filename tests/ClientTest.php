<?php

namespace TicketbaiWS\Tests;

use PHPUnit\Framework\TestCase;
use TicketbaiWS\Client;
use TicketbaiWS\Cuenta;
use TicketbaiWS\Entorno;
use TicketbaiWS\Exception\RateLimitException;
use TicketbaiWS\Exception\TicketbaiWSException;
use TicketbaiWS\Response;

final class ClientTest extends TestCase
{
    /** @var FakeTransport */
    private $http;
    /** @var array<int,int> */
    private $esperas = [];

    private function client(array $opciones = []): Client
    {
        $this->http = new FakeTransport();
        $this->esperas = [];
        $esperas = &$this->esperas;
        return new Client('TOKEN', 'B01000012', $opciones + [
            'transport' => $this->http,
            'sleeper' => function ($s) use (&$esperas) { $esperas[] = $s; },
        ]);
    }

    public function testEntornoPorDefectoEsPruebas(): void
    {
        $this->assertSame(Entorno::PRUEBAS, $this->client()->getBaseUrl());
        $this->assertSame(Entorno::PRODUCCION, $this->client(['entorno' => 'real'])->getBaseUrl());
        $this->assertSame('https://ejemplo.test', $this->client(['entorno' => 'https://ejemplo.test/'])->getBaseUrl());
    }

    public function testEntornoNoValido(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client(['entorno' => 'otro']);
    }

    public function testCrearFacturaEnviaJsonYCabeceras(): void
    {
        $c = $this->client();
        $this->http->responder(200, ['result' => 'OK', 'return' => ['huella_tbai' => 'TBAI-X', 'qr' => 'iVBOR', 'url' => 'https://batuz.eus/QRTBAI/?id=TBAI-X'], 'msg' => null]);

        $r = $c->crearFactura(['serie' => 'A', 'numero' => '1', 'nombre' => 'Ñandú S.L.', 'total_factura' => 12.1]);

        $p = $this->http->ultima();
        $this->assertSame('POST', $p['method']);
        $this->assertSame('https://api-test.ticketbaiws.eus/tbai/', $p['url']);
        $this->assertSame('TOKEN', $this->http->cabecera('Token'));
        $this->assertSame('B01000012', $this->http->cabecera('Nif'));
        $this->assertStringContainsString('Ñandú', $p['body']);
        $this->assertTrue($r->isOk());
        $this->assertSame('TBAI-X', $r->getData('huella_tbai'));
        $this->assertSame('OK', $r['result']);
    }

    public function testSubsanarAnadeZuzendu(): void
    {
        $c = $this->client();
        $c->subsanarFactura(['serie' => 'A', 'numero' => '1']);
        $this->assertTrue(json_decode($this->http->ultima()['body'], true)['zuzendu']);
    }

    public function testGetYDeleteLlevanParametrosEnElCuerpo(): void
    {
        $c = $this->client();
        $c->anularFactura('A', '7', '25/01/2026');
        $p = $this->http->ultima();
        $this->assertSame('DELETE', $p['method']);
        $this->assertSame(['serie' => 'A', 'numero' => '7', 'fecha' => '25/01/2026'], json_decode($p['body'], true));

        $c->estado();
        $this->assertNull($this->http->ultima()['body']);
    }

    public function testRespuestaPendingEsExito(): void
    {
        $c = $this->client();
        $this->http->responder(200, ['result' => 'PENDING', 'return' => ['huella' => 'H'], 'msg' => '']);
        $r = $c->crearFactura([]);
        $this->assertTrue($r->isPending());
        $this->assertTrue($r->isSuccess());
        $this->assertFalse($r->isError());
    }

    public function testErrorConMensajesEnLista(): void
    {
        $c = $this->client();
        $this->http->responder(200, ['result' => 'ERROR', 'return' => null, 'msg' => ['Falta el NIF', ['El total no cuadra']]]);
        $r = $c->crearFactura([]);
        $this->assertTrue($r->isError());
        $this->assertSame(['Falta el NIF', 'El total no cuadra'], $r->getMessages());
        $this->assertSame('Falta el NIF | El total no cuadra', $r->getMessageText(' | '));
    }

    public function testCuerpoNoJsonEsError(): void
    {
        $c = $this->client();
        $this->http->responder(502, '<html>Bad gateway</html>');
        $r = $c->estado();
        $this->assertTrue($r->isError());
        $this->assertSame(502, $r->getHttpStatus());
        $this->assertSame('<html>Bad gateway</html>', $r->getRawBody());
    }

    public function testReintentaEn429ConRetryAfter(): void
    {
        $c = $this->client();
        $this->http->responder(429, '', ['retry-after' => '3'])->responder(429, '')->responder(200, ['result' => 'OK', 'return' => null, 'msg' => 'Ready']);
        $r = $c->estado();
        $this->assertTrue($r->isOk());
        $this->assertSame([3, 2], $this->esperas);
        $this->assertCount(3, $this->http->peticiones);
    }

    public function testLanzaRateLimitAlAgotarReintentos(): void
    {
        $c = $this->client(['reintentos_429' => 1]);
        $this->http->responder(429, '', ['retry-after' => '5'])->responder(429, '', ['retry-after' => '5']);
        try {
            $c->estado();
            $this->fail('Debería lanzar RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame(5, $e->getRetryAfter());
        }
    }

    public function testUtf8InvalidoLanzaExcepcionSinEnviar(): void
    {
        $c = $this->client();
        $this->expectException(TicketbaiWSException::class);
        try {
            $c->crearFactura(['nombre' => "\xE1rbol"]);
        } finally {
            $this->assertCount(0, $this->http->peticiones);
        }
    }

    public function testLroeGastosSinFactura(): void
    {
        $c = $this->client();
        $c->lroeGastosSinFactura()->anular(['ejercicio' => 2026, 'gastos' => [['fecha_operacion' => '30/09/2026', 'concepto' => '626', 'epigrafe' => '165140', 'linea' => 1]]]);
        $p = $this->http->ultima();
        $this->assertSame('DELETE', $p['method']);
        $this->assertSame('https://api-test.ticketbaiws.eus/lroe-gastos-sinfactura/', $p['url']);
        $this->assertSame('626', json_decode($p['body'], true)['gastos'][0]['concepto']);
    }

    public function testWebhooks(): void
    {
        $c = $this->client();
        $c->crearWebhook('https://mi.web/aviso', 's3cr3t', true);
        $this->assertSame(['url' => 'https://mi.web/aviso', 'secret' => 's3cr3t', 'solo_errores' => true, 'activo' => true], json_decode($this->http->ultima()['body'], true));
        $c->modificarWebhook('ab c', ['activo' => false]);
        $this->assertSame('https://api-test.ticketbaiws.eus/webhooks/ab%20c/', $this->http->ultima()['url']);
        $this->assertSame('PUT', $this->http->ultima()['method']);
    }

    public function testDocRepresentanteMultipart(): void
    {
        $c = $this->client();
        $pdf = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($pdf, '%PDF-1.7');
        $c->enviarDocRepresentante($pdf);
        $p = $this->http->ultima();
        unlink($pdf);
        $this->assertInstanceOf(\CURLFile::class, $p['multipart']['file']);
        $this->assertNull($this->http->cabecera('Content-Type'));
    }

    public function testDocRepresentanteFicheroInexistente(): void
    {
        $this->expectException(TicketbaiWSException::class);
        $this->client()->enviarDocRepresentante('/no/existe.pdf');
    }

    public function testCuentaUsaProduccionYRutaConNif(): void
    {
        $http = new FakeTransport();
        $cuenta = new Cuenta('DEV', 'B99999999', ['transport' => $http]);
        $cuenta->editarEmpresa('B01000012', ['nif' => 'IGNORADO', 'email' => 'a@b.es']);
        $p = $http->ultima();
        $this->assertSame('PUT', $p['method']);
        $this->assertSame('https://api.ticketbaiws.eus/empresas/B01000012/', $p['url']);
        $this->assertSame(['email' => 'a@b.es'], json_decode($p['body'], true));
        $this->assertSame('DEV', $http->cabecera('Token'));
    }

    public function testRespuestaEsSoloLectura(): void
    {
        $r = new Response('OK', null, null);
        $this->expectException(\LogicException::class);
        $r['result'] = 'ERROR';
    }
}
