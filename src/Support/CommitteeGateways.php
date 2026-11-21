<?php

namespace Platform\Signals\Support;

use Platform\Signals\Contracts\CommitteeGateway;
use Platform\Signals\Models\Signal;

/**
 * Registry für die Gremien-Brücke. Ein Gremien-Modul registriert sich beim Booten, wenn Signals da ist;
 * Signals hängt dabei seinen Rückweg an – nur für Objekte vom eigenen Typ.
 */
class CommitteeGateways
{
    private static ?CommitteeGateway $gateway = null;

    public static function register(CommitteeGateway $gateway): void
    {
        self::$gateway = $gateway;
        $gateway->onOutcome(function (string $linkType, int $linkId, string $kind, string $refType, int $refId, string $text, ?int $byUserId = null) {
            if ($linkType === Signal::MORPH_ALIAS) {
                SignalFeedback::decision($linkId, $kind, $refType, $refId, $text, $byUserId);
            }
        });
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
