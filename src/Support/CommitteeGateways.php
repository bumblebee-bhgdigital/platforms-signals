<?php

namespace Platform\Signals\Support;

use Platform\Signals\Contracts\CommitteeGateway;

/** Registry für die Gremien-Brücke (das Book registriert sich beim Booten, wenn Signals da ist). */
class CommitteeGateways
{
    private static ?CommitteeGateway $gateway = null;

    public static function register(CommitteeGateway $gateway): void
    {
        self::$gateway = $gateway;
    }

    public static function get(): ?CommitteeGateway
    {
        return self::$gateway;
    }

    public static function has(): bool
    {
        return self::$gateway !== null;
    }

    public static function forget(): void
    {
        self::$gateway = null;
    }
}
