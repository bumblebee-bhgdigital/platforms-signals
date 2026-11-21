<?php

namespace Platform\Signals\Support;

use Platform\Signals\Services\CircleService;

/**
 * Stabile Einstiegsstelle für Gremien-Module (Management Book): meldet einen Beschluss zu einem LA-Punkt,
 * der auf ein Signal verweist. Das Book ruft das nur auf, wenn die Klasse existiert (lose Kopplung).
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
