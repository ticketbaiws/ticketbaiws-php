<?php

namespace TicketbaiWS\Tests;

use TicketbaiWS\Http\TransportInterface;

final class FakeTransport implements TransportInterface
{
    /** @var array<int,array{status:int,body:string,headers:array<string,string>}> */
    public $cola = [];
    /** @var array<int,array<string,mixed>> */
    public $peticiones = [];

    public function responder(int $status, $body, array $headers = []): self
    {
        $this->cola[] = ['status' => $status, 'body' => is_string($body) ? $body : json_encode($body), 'headers' => $headers];
        return $this;
    }

    public function send(string $method, string $url, array $headers, ?string $body = null, ?array $multipart = null): array
    {
        $this->peticiones[] = compact('method', 'url', 'headers', 'body', 'multipart');
        if (!$this->cola) {
            return ['status' => 200, 'body' => '{"result":"OK","return":null,"msg":null}', 'headers' => []];
        }
        return array_shift($this->cola);
    }

    public function ultima(): array
    {
        return end($this->peticiones);
    }

    public function cabecera(string $nombre): ?string
    {
        foreach ($this->ultima()['headers'] as $h) {
            if (stripos($h, $nombre . ':') === 0) {
                return trim(substr($h, strlen($nombre) + 1));
            }
        }
        return null;
    }
}
