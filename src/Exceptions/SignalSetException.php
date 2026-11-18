<?php

namespace Platform\Signals\Exceptions;

use RuntimeException;

/** Fachlicher Fehler im Signal-Set-Service (Validierung, ungültiger Übergang). */
class SignalSetException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'VALIDATION_ERROR')
    {
        parent::__construct($message);
    }
}
