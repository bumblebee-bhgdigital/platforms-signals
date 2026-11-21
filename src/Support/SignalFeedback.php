<?php

namespace Platform\Signals\Support;

use Platform\Signals\Services\CircleService;

/**
 * Einstiegsstelle für Rückmeldungen aus Gremien: ein Beschluss (oder ein herausgenommener Vorschlag) zu einem
 * Agendapunkt, der auf ein Signal verweist. Aufgerufen über den Rückweg der Gremien-Brücke (CommitteeGateways).
 */
class SignalFeedback
{
    public static function decision(int $signalId, string $kind, string $decisionType, int $decisionId, string $text, ?int $byUserId = null): void
    {
        try {
            app(CircleService::class)->onDecision($signalId, $kind, $decisionType, $decisionId, $text, $byUserId);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Signals: Beschluss-Rückmeldung fehlgeschlagen', ['signal' => $signalId, 'error' => $e->getMessage()]);
        }
    }
}
