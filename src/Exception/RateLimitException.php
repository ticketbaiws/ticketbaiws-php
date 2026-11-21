<?php

namespace TicketbaiWS\Exception;

/** HTTP 429: se ha superado el límite de peticiones y se agotaron los reintentos. */
class RateLimitException extends TicketbaiWSException
{
    /** @var int|null */
    private $retryAfter;

    public function __construct(string $message, ?int $retryAfter = null)
    {
        parent::__construct($message, 429);
        $this->retryAfter = $retryAfter;
    }

    /** Segundos indicados por la cabecera Retry-After, si llegó. */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
