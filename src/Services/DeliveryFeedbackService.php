<?php

namespace Platform\Signals\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\User;
use Platform\Signals\Contracts\DeliveryChannel;
use Platform\Signals\Models\Signal;

/**
 * Rückweg aus dem Zustell-Kanal (Z4): Reaktion im Vorgang → Reaktion am Signal.
 *
 *   angenommen → gesehen (Reaktionsuhr stoppt)
 *   erledigt   → gelöst, Notiz „In Parlan erledigt“ (+ Notiz aus dem Vorgang)
 *   abgelehnt  → „gehört nicht zu mir“, Begründung „In Parlan abgelehnt“ (+ Notiz) – eskaliert eine Stufe (Übergabe)
 *
 * Zugeordnet über den aktuellen oder einen früheren Vorgang des Signals (neuer Vorgang beim Wiederöffnen).
 *
 * Ignoriert: unbekannte Vorgänge, Reaktionen des Set-Agenten selbst (er führt den Vorgang nur), geschlossene
 * Signale und doppelte Ereignisse (event_id in signal_delivery_events, eindeutig).
 */
class DeliveryFeedbackService
{
    public function __construct(protected SignalService $signals, protected SignalDeliveryQueue $queue)
    {
    }

    /** @return string applied | duplicate | unknown_thread | ignored_agent | ignored_closed | ignored_kind */
    public function handle(string $thread, string $kind, ?int $byUserId, ?string $note, string $eventId): string
    {
        if (! in_array($kind, DeliveryChannel::KINDS, true)) {
            return 'ignored_kind';
        }
        $thread = trim($thread);
        $signal = $thread !== '' ? $this->queue->signalForThread($thread) : null;
        if (! $signal) {
            return 'unknown_thread';
        }
        $eventId = $eventId !== '' ? $eventId : 'anon:' . sha1($thread . '|' . $kind . '|' . $byUserId . '|' . $note);
        $inserted = DB::table('signal_delivery_events')->insertOrIgnore([
            'event_id' => mb_substr($eventId, 0, 191), 'signal_id' => $signal->id, 'kind' => $kind, 'thread' => $thread,
            'actor_user_id' => $byUserId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if (! $inserted) {
            return 'duplicate';
        }

        $result = $this->apply($signal, $kind, $byUserId, $note);
        DB::table('signal_delivery_events')->where('event_id', mb_substr($eventId, 0, 191))->update(['result' => $result, 'updated_at' => now()]);

        return $result;
    }

    private function apply(Signal $signal, string $kind, ?int $byUserId, ?string $note): string
    {
        $agentId = $this->queue->agentUserId($signal);
        if ($byUserId !== null && $agentId !== null && $byUserId === $agentId) {
            return 'ignored_agent';
        }
        if ($signal->isClosed()) {
            return 'ignored_closed';
        }
        $by = $byUserId ? User::find($byUserId) : null;
        $note = $note !== null && trim($note) !== '' ? trim($note) : null;

        match ($kind) {
            DeliveryChannel::ACCEPTED => $this->signals->transition($signal, 'acknowledge', $by),
            DeliveryChannel::DONE => $this->signals->transition($signal, 'resolve', $by, [
                'resolution_note' => 'In Parlan erledigt' . ($note ? ': ' . $note : ''),
                'from_channel' => true,
            ]),
            DeliveryChannel::DECLINED => $this->signals->transition($signal, 'reject', $by, [
                'reason' => 'In Parlan abgelehnt' . ($note ? ': ' . $note : ''),
            ]),
        };

        return 'applied';
    }
}
