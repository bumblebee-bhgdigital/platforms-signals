<?php

namespace Platform\Signals\Support;

use Platform\Signals\Contracts\DeliveryChannel;
use Platform\Signals\Services\DeliveryFeedbackService;

/**
 * Registry für den Zustell-Kanal (wie CommitteeGateways). Beim Registrieren hängt Signals seinen Rückweg an:
 * Rückmeldungen (angenommen, erledigt, abgelehnt) gehen an den DeliveryFeedbackService.
 */
class DeliveryChannels
{
    private static ?DeliveryChannel $channel = null;

    public static function register(DeliveryChannel $channel): void
    {
        self::$channel = $channel;
        $channel->onFeedback(function (string $thread, string $kind, ?int $byUserId = null, ?string $note = null, string $eventId = '') {
            try {
                app(DeliveryFeedbackService::class)->handle($thread, $kind, $byUserId, $note, $eventId);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Signals: Rückmeldung aus dem Zustell-Kanal fehlgeschlagen', ['thread' => $thread, 'kind' => $kind, 'error' => $e->getMessage()]);
            }
        });
    }

    public static function get(): ?DeliveryChannel
    {
        return self::$channel;
    }

    public static function has(): bool
    {
        return self::$channel !== null;
    }

    public static function forget(): void
    {
        self::$channel = null;
    }
}
