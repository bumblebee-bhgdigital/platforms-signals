<?php

namespace Platform\Signals\Organization;

use Illuminate\Support\Facades\Event;
use Platform\Signals\Contracts\DeliveryChannel;

/**
 * Adapter: Rückmeldungen aus Parlan über das Ereignis der Organisation (CoordinationEventsRecorded).
 * Die einzige Stelle in Signals, die die Ereignis-Klasse der Organisation kennt (Regel-Test in SignalsChannelTest).
 *
 * Die Organisation feuert das Ereignis je neuem Koordinations-Ereignis aus Parlan (Push oder Abgleich alle 15 Min.):
 * events = list<{event_id, kind, thread, actor_user_id, actor_handle, context_ref, occurred_at}>.
 * Übersetzt wird nur, was zu einem Vorgang gehört; alles andere (request, decision, message, …) wird ignoriert.
 */
class OrganizationParlanFeedback implements DeliveryChannel
{
    private const EVENT = \Platform\Organization\Events\CoordinationEventsRecorded::class;

    /** Parlan-Art → Rückmeldung an Signals. */
    public const KIND_MAP = [
        'handoff_accept' => self::ACCEPTED,
        'handoff_claim' => self::ACCEPTED,
        'handoff_done' => self::DONE,
        'handoff_decline' => self::DECLINED,
    ];

    /** @var list<callable> */
    private array $handlers = [];

    private bool $listening = false;

    public static function available(): bool
    {
        return class_exists(self::EVENT);
    }

    public function onFeedback(callable $handler): void
    {
        $this->handlers[] = $handler;
        if (! $this->listening && self::available()) {
            $this->listening = true;
            Event::listen(self::EVENT, fn ($event) => $this->receive((array) ($event->events ?? [])));
        }
    }

    /**
     * Ereignisse übersetzen und weitergeben.
     *
     * @param  iterable<array|object>  $events
     * @return int Anzahl weitergegebener Rückmeldungen
     */
    public function receive(iterable $events): int
    {
        $passed = 0;
        foreach ($events as $e) {
            $e = (array) $e;
            $kind = self::KIND_MAP[(string) ($e['kind'] ?? '')] ?? null;
            $thread = trim((string) ($e['thread'] ?? ''));
            if (! $kind || $thread === '') {
                continue;
            }
            $eventId = (string) ($e['event_id'] ?? '');
            if ($eventId === '') {
                // ohne ID keine sichere Doppel-Erkennung – stabile Ersatz-ID aus dem Inhalt
                $eventId = 'org:' . sha1($thread . '|' . ($e['kind'] ?? '') . '|' . ($e['actor_user_id'] ?? '') . '|' . ($e['occurred_at'] ?? ''));
            }
            $by = isset($e['actor_user_id']) && $e['actor_user_id'] !== null ? (int) $e['actor_user_id'] : null;
            // Notiz/Begründung: das Ereignis-Format nennt sie (noch) nicht – übliche Feldnamen werden übernommen
            $note = null;
            foreach (['note', 'reason', 'text', 'body', 'comment'] as $field) {
                if (isset($e[$field]) && is_string($e[$field]) && trim($e[$field]) !== '') {
                    $note = trim($e[$field]);
                    break;
                }
            }
            foreach ($this->handlers as $handler) {
                $handler($thread, $kind, $by, $note, $eventId);
            }
            $passed++;
        }

        return $passed;
    }
}
