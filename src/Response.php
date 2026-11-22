<?php

namespace TicketbaiWS;

/**
 * Respuesta de la API: sobre { result, return, msg }.
 *
 * result vale OK, PENDING (Verifactu: aceptada y en cola de envío) o ERROR.
 * Decide siempre por result, no por el código HTTP.
 *
 * Implementa ArrayAccess para poder seguir usando $r['result'], $r['return']
 * y $r['msg'] como con la clase antigua.
 */
final class Response implements \ArrayAccess, \JsonSerializable
{
    const OK = 'OK';
    const PENDING = 'PENDING';
    const ERROR = 'ERROR';

    /** @var string */
    private $result;
    /** @var mixed */
    private $data;
    /** @var mixed */
    private $msg;
    /** @var int */
    private $httpStatus;
    /** @var string */
    private $rawBody;

    /**
     * @param mixed $data
     * @param mixed $msg
     */
    public function __construct(string $result, $data, $msg, int $httpStatus = 200, string $rawBody = '')
    {
        $this->result = $result;
        $this->data = $data;
        $this->msg = $msg;
        $this->httpStatus = $httpStatus;
        $this->rawBody = $rawBody;
    }

    /** Construye la respuesta a partir del cuerpo HTTP recibido. */
    public static function fromHttp(int $status, string $body): self
    {
        $json = json_decode($body, true);
        if (is_array($json) && isset($json['result'])) {
            $result = in_array($json['result'], [self::OK, self::PENDING], true) ? $json['result'] : self::ERROR;
            return new self(
                $result,
                array_key_exists('return', $json) ? $json['return'] : null,
                array_key_exists('msg', $json) ? $json['msg'] : null,
                $status,
                $body
            );
        }

        if ($status === 404) {
            $msg = 'Recurso o método HTTP no disponible (HTTP 404)';
        } elseif ($body === '') {
            $msg = 'Respuesta vacía de la API (HTTP ' . $status . ')';
        } else {
            $msg = 'Respuesta no válida de la API (HTTP ' . $status . ')';
        }
        return new self(self::ERROR, null, $msg, $status, $body);
    }

    /** OK, PENDING o ERROR. */
    public function getResult(): string
    {
        return $this->result;
    }

    public function isOk(): bool
    {
        return $this->result === self::OK;
    }

    public function isPending(): bool
    {
        return $this->result === self::PENDING;
    }

    /** OK o PENDING: la API ha aceptado la petición. */
    public function isSuccess(): bool
    {
        return $this->result !== self::ERROR;
    }

    public function isError(): bool
    {
        return $this->result === self::ERROR;
    }

    /**
     * Contenido de "return". Con $key devuelve ese campo (o $default).
     *
     * @param mixed $default
     * @return mixed
     */
    public function getData(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->data;
        }
        return (is_array($this->data) && array_key_exists($key, $this->data)) ? $this->data[$key] : $default;
    }

    /**
     * "msg" tal como llega: null, texto, XML de Hacienda en texto o lista.
     *
     * @return mixed
     */
    public function getMessage()
    {
        return $this->msg;
    }

    /**
     * "msg" normalizado a lista de textos (vacía si no hay mensajes).
     *
     * @return string[]
     */
    public function getMessages(): array
    {
        if ($this->msg === null || $this->msg === '') {
            return [];
        }
        if (!is_array($this->msg)) {
            return [(string) $this->msg];
        }
        $out = [];
        array_walk_recursive($this->msg, function ($v) use (&$out) {
            if ($v !== null && $v !== '') {
                $out[] = (string) $v;
            }
        });
        return $out;
    }

    /** "msg" como un único texto, útil para logs o para mostrar al usuario. */
    public function getMessageText(string $separator = "\n"): string
    {
        return implode($separator, $this->getMessages());
    }

    /**
     * Total de resultados de los listados paginados (campo «count» de
     * listarFacturas(), clientes()->buscar(), productos()->buscar()...). null si no viene.
     */
    public function getCount(): ?int
    {
        $json = json_decode($this->rawBody, true);
        return (is_array($json) && isset($json['count']) && is_numeric($json['count'])) ? (int) $json['count'] : null;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getRawBody(): string
    {
        return $this->rawBody;
    }

    /** @return array{result:string, return:mixed, msg:mixed} */
    public function toArray(): array
    {
        return ['result' => $this->result, 'return' => $this->data, 'msg' => $this->msg];
    }

    /** @return array{result:string, return:mixed, msg:mixed} */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return in_array($offset, ['result', 'return', 'msg'], true);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        $a = $this->toArray();
        return isset($a[$offset]) ? $a[$offset] : null;
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        throw new \LogicException('Response es de solo lectura');
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        throw new \LogicException('Response es de solo lectura');
    }
}
