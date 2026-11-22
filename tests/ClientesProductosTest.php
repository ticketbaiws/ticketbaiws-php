<?php

namespace TicketbaiWS\Tests;

use PHPUnit\Framework\TestCase;
use TicketbaiWS\Client;
use TicketbaiWS\Resource\Productos;

final class ClientesProductosTest extends TestCase
{
    /** @var FakeTransport */
    private $http;

    private function client(): Client
    {
        $this->http = new FakeTransport();
        return new Client('TOKEN', 'B01000012', ['transport' => $this->http]);
    }

    public function testClientes(): void
    {
        $c = $this->client();
        $c->clientes()->buscar('Uribe');
        $this->assertSame('GET', $this->http->ultima()['method']);
        $this->assertSame('https://api-test.ticketbaiws.eus/clientes/', $this->http->ultima()['url']);
        $this->assertSame(['pagina' => 0, 'cantidad' => 50, 'buscar' => 'Uribe'], json_decode($this->http->ultima()['body'], true));

        $this->http->responder(200, ['result' => 'OK', 'return' => [['id' => 1]], 'msg' => 'Showing 1 to 1 of 37', 'count' => 37]);
        $this->assertSame(37, $c->clientes()->buscar()->getCount());
        $this->assertNull($c->estado()->getCount());

        $c->clientes()->porNif('B89670855');
        $this->assertSame(['nif' => 'B89670855'], json_decode($this->http->ultima()['body'], true));

        $c->clientes()->obtener(123);
        $this->assertSame('https://api-test.ticketbaiws.eus/clientes/123/', $this->http->ultima()['url']);

        $c->clientes()->crear(['nombre' => 'Uribe S.L.']);
        $this->assertSame('POST', $this->http->ultima()['method']);

        $c->clientes()->modificar(123, ['email' => 'a@b.es']);
        $this->assertSame('PUT', $this->http->ultima()['method']);
        $this->assertSame('https://api-test.ticketbaiws.eus/clientes/123/', $this->http->ultima()['url']);
    }

    public function testProductos(): void
    {
        $c = $this->client();
        $c->productos()->buscar('mantenimiento', ['familia' => '12'], 1, 20);
        $this->assertSame('https://api-test.ticketbaiws.eus/productos/', $this->http->ultima()['url']);
        $this->assertSame(['familia' => '12', 'pagina' => 1, 'cantidad' => 20, 'buscar' => 'mantenimiento'], json_decode($this->http->ultima()['body'], true));

        $c->productos()->obtener('45/12');
        $this->assertSame('https://api-test.ticketbaiws.eus/productos/45%2F12/', $this->http->ultima()['url']);
    }

    public function testLineaDesdeProducto(): void
    {
        $p = ['codigo' => '4512', 'descripcion' => 'Hora de mantenimiento', 'importe_unitario' => 45, 'tipo_iva' => 21, 'tipo_req' => 0, 'tipo_retencion' => 0];
        $this->assertSame(
            ['descripcion' => 'Hora de mantenimiento', 'cantidad' => 3.0, 'importe_unitario' => 45.0, 'tipo_iva' => 21.0, 'tipo_req' => 0.0, 'descuento' => 10.0],
            Productos::linea($p, 3, 10)
        );
    }
}
