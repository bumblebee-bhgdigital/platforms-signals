<?php

namespace Platform\Signals\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\User;
use Platform\Organization\Models\OrganizationEntity;
use Platform\Signals\Exceptions\SignalSetException;
use Platform\Signals\Models\Signal;
use Platform\Signals\Models\SignalDeliveryOp;
use Platform\Signals\Models\SignalSet;

/**
 * Z4 „ein Signal, ein Vorgang“: Aufträge an den Agenten des Sets, den Parlan-Vorgang eines Signals zu führen.
 *
 * Signals kann in Parlan nicht selbst handeln – dort zählt nur, was die handelnde Identität signiert. Darum legt
 * Signals hier Aufträge ab (create, handover, comment, reopen, close); der Agent des Sets holt sie per
 * signals.deliveries.GET, führt sie mit seinem Parlan-Zugang aus und meldet per signals.deliveries.POST zurück.
 *
 * Regeln:
 *  - nur für Signale mit Zustellung „Vorgang“ (Politik delivery=task; immer bei Eskalation und „Einheit ohne
 *    Verantwortlichen“)
 *  - höchstens ein create je Signal (solange nicht fehlgeschlagen); kommt ein anderer Auftrag für ein Signal ohne
 *    Vorgang, wird stattdessen der Vorgang angelegt (mit dem aktuellen Stand)
 *  - je Signal der Reihe nach (id aufsteigend); wird ein Signal geschlossen, bevor der Vorgang überhaupt angelegt
 *    wurde, verfallen die offenen Aufträge (cancelled) – kein Vorgang, der sofort wieder zugeht
 *  - Fehler zählen die Versuche hoch; nach MAX_ATTEMPTS ist der Auftrag fehlgeschlagen (Systemkommentar am Signal)
 *
 * Kein Auftrag darf den Lebenszyklus eines Signals verhindern: alle Einstiege fangen Fehler ab.
 */
class SignalDeliveryQueue
{
    public function __construct(protected SignalDelivery $delivery)
    {
    }

    // ── Wann ein Vorgang ─────────────────────────────────────────

    public function wantsTask(Signal $signal): bool
    {
        if ((int) $signal->escalation_level > 0) {
            return true;
        }
        if ($signal->template?->pattern_type === 'no_responsible') {
            return true;
        }

        return ($this->delivery->policy($signal)['delivery'] ?? 'overview') === 'task';
    }

    /** Agent des Sets (Absender und Begleiter); ohne Set der verantwortliche Agent des Signals. */
    public function agentUserId(Signal $signal): ?int
    {
        $id = $signal->signal_set_id ? SignalSet::withTrashed()->whereKey($signal->signal_set_id)->value('responsible_agent_user_id') : null;

        return $id ? (int) $id : ($signal->responsible_user_id ? (int) $signal->responsible_user_id : null);
    }

    // ── Einstiege aus dem Lebenszyklus ───────────────────────────

    /** Zugestellt (Empfänger gefunden) → Vorgang anlegen. */
    public function delivered(Signal $signal): ?SignalDeliveryOp
    {
        return $this->safely(fn () => $this->push($signal, 'create', $this->createPayload($signal)));
    }

    /** Linien-Eskalation → derselbe Vorgang wird an den Nächsten übergeben. $note: Grund, wer ihn hatte, wie lange. */
    public function escalated(Signal $signal, string $note): ?SignalDeliveryOp
    {
        return $this->safely(fn () => $this->push($signal, 'handover', [
            'to' => $this->assigneeEmail($signal),
            'members_add' => $this->memberEmails($signal),
            'note' => $note,
        ], $note));
    }

    /** Übergabe ans Gremium → Hinweis im Vorgang. */
    public function handedToCommittee(Signal $signal, string $committeeName, string $reason): ?SignalDeliveryOp
    {
        $text = "Das liegt jetzt beim {$committeeName}: {$reason}. Der Agent bereitet den Punkt nach den vier Fragen vor "
            . '(Ziel, Beobachtung, Hindernis, was lokal geht und was es vom System braucht). Ihr müsst nichts tun – Rückfragen gern hier.';

        return $this->safely(fn () => $this->push($signal, 'comment', ['text' => $text, 'purpose' => 'committee'], $text));
    }

    /** Kam wieder (Z4) → alter Vorgang wird wieder geöffnet. */
    public function reopened(Signal $signal, string $note): ?SignalDeliveryOp
    {
        return $this->safely(fn () => $this->push($signal, 'reopen', [
            'note' => $note,
            'to' => $this->assigneeEmail($signal),
            'members_add' => $this->memberEmails($signal),
        ], $note));
    }

    /** Auf Seiten von Signals gelöst oder verworfen (auch automatisch) → Vorgang schließen. */
    public function closed(Signal $signal, string $note): ?SignalDeliveryOp
    {
        return $this->safely(fn () => $this->push($signal, 'close', ['note' => $note]));
    }

    /**
     * Erinnerung zur Halbzeit der Reaktionszeit – einmal je Zustell-Zyklus (Teil von signals:escalate).
     * Ein Zyklus beginnt mit delivered_at (Zustellung, Übergabe, Wiederöffnen).
     */
    public function remindIfDue(Signal $signal, ?CarbonInterface $now = null): bool
    {
        $now ??= now();
        if ($signal->reacted_at || ! $signal->delivered_at || $signal->isClosed() || ! $this->wantsTask($signal)) {
            return false;
        }
        if (! $this->hasCreate($signal)) {
            return false;
        }
        $days = (int) $this->delivery->policy($signal)['reaction_days'];
        $half = $days / 2;
        $midpoint = $signal->delivered_at->copy()->addWeekdays((int) floor($half))->addHours((int) round(($half - floor($half)) * 24));
        if ($midpoint->gt($now)) {
            return false;
        }
        $already = SignalDeliveryOp::query()->where('signal_id', $signal->id)->where('op', 'comment')
            ->where('created_at', '>=', $signal->delivered_at)->get()
            ->contains(fn (SignalDeliveryOp $o) => ($o->payload['purpose'] ?? null) === 'reminder');
        if ($already) {
            return false;
        }
        $text = 'Kurze Erinnerung: Hierauf hat noch niemand reagiert, die Hälfte der Reaktionszeit (' . $days . ' Arbeitstag' . ($days === 1 ? '' : 'e')
            . ') ist um. Ein Klick reicht – annehmen, erledigt mit Notiz oder ablehnen mit Begründung. Was hindert euch? Wobei braucht ihr Unterstützung?';

        return (bool) $this->safely(fn () => $this->push($signal, 'comment', ['text' => $text, 'purpose' => 'reminder'], $text));
    }

    // ── Kern ─────────────────────────────────────────────────────

    /**
     * Auftrag einreihen. $note geht in den Vorgang ein, falls statt des Auftrags erst der Vorgang angelegt wird.
     */
    public function push(Signal $signal, string $op, array $payload, ?string $note = null): ?SignalDeliveryOp
    {
        if (! in_array($op, SignalDeliveryOp::OPS, true) || ! $this->wantsTask($signal)) {
            return null;
        }

        return DB::transaction(function () use ($signal, $op, $payload, $note) {
            $create = SignalDeliveryOp::query()->where('signal_id', $signal->id)->where('op', 'create')
                ->whereIn('status', ['pending', 'done'])->lockForUpdate()->orderByDesc('id')->first();

            if ($op === 'create') {
                return $create ? null : $this->insertCreate($signal, $payload);
            }
            if (! $create) {
                // kein Vorgang: schließen ist nichts, alles andere legt den Vorgang mit dem aktuellen Stand an
                return $op === 'close' ? null : $this->insertCreate($signal, $this->createPayload($signal, $note));
            }
            if ($create->status === 'pending' && ! $signal->delivery_thread) {
                if ($op === 'close') {
                    // geschlossen, bevor der Vorgang existiert: offene Aufträge verfallen
                    SignalDeliveryOp::query()->where('signal_id', $signal->id)->where('status', 'pending')
                        ->update(['status' => 'cancelled', 'last_error' => 'Signal geschlossen, bevor der Vorgang angelegt wurde.', 'updated_at' => now()]);
                    $signal->forceFill(['delivery_state' => null])->saveQuietly();

                    return null;
                }
                if ($op === 'reopen') {
                    return null; // der Vorgang wird ohnehin erst angelegt
                }
            }

            return SignalDeliveryOp::create(['signal_id' => $signal->id, 'op' => $op, 'payload' => $payload]);
        });
    }

    private function insertCreate(Signal $signal, array $payload): SignalDeliveryOp
    {
        $signal->forceFill([
            'delivery_state' => 'pending',
            'delivery_room_entity_id' => $signal->delivery_room_entity_id ?: $signal->entity_id,
        ])->saveQuietly();

        return SignalDeliveryOp::create(['signal_id' => $signal->id, 'op' => 'create', 'payload' => $payload]);
    }

    public function hasCreate(Signal $signal): bool
    {
        return SignalDeliveryOp::query()->where('signal_id', $signal->id)->where('op', 'create')->whereIn('status', ['pending', 'done'])->exists();
    }

    // ── Inhalt des Vorgangs ──────────────────────────────────────

    /** @return array<string,mixed> */
    public function createPayload(Signal $signal, ?string $note = null): array
    {
        $signal->loadMissing('template:id,name,pattern_type', 'entity:id,name');
        $entity = $signal->entity?->name ?? ('#' . $signal->entity_id);
        $what = $signal->template?->name ?: mb_strimwidth(strip_tags((string) $signal->message), 0, 60, '…');
        $url = $this->signalUrl($signal);
        $days = (int) $this->delivery->policy($signal)['reaction_days'];

        $lines = [trim((string) $signal->message), ''];
        if ($signal->recipient_reason) {
            $lines[] = '_Warum bei dir: ' . $signal->recipient_reason . '_';
            $lines[] = '';
        }
        if ($note) {
            $lines[] = '_Hintergrund: ' . $note . '_';
            $lines[] = '';
        }
        array_push($lines,
            '**Hilfe statt Macht** – drei kurze Fragen an euch:',
            '- Was hindert euch?',
            '- Was könnt ihr selbst lösen?',
            '- Wobei braucht ihr Unterstützung?',
            '',
            '**So reagierst du hier** (innerhalb von ' . $days . ' Arbeitstag' . ($days === 1 ? '' : 'en') . '):',
            '- **Annehmen** – du kümmerst dich darum.',
            '- **Erledigt** mit einer kurzen Notiz, wodurch.',
            '- **Ablehnen** mit Begründung, wenn es nicht zu dir gehört – dann geht es an die richtige Stelle.',
        );
        if ($url) {
            $lines[] = '';
            $lines[] = "[Signal in der Plattform]({$url})";
        }

        return [
            'title' => mb_strimwidth("Signal: {$what} in {$entity}", 0, 120, '…'),
            'markdown' => implode("\n", $lines),
            'room' => ['object' => 'entity', 'id' => (int) ($signal->delivery_room_entity_id ?: $signal->entity_id)],
            'members' => $this->memberEmails($signal),
            'assignee' => $this->assigneeEmail($signal),
            'severity' => $signal->severity,
            'url' => $url,
        ];
    }

    /** Empfänger (inkl. Mensch eines Agenten bei kritisch/Eskalation – steckt schon in recipient_user_ids). @return string[] */
    public function memberEmails(Signal $signal): array
    {
        $ids = array_values(array_unique(array_map('intval', (array) $signal->recipient_user_ids)));

        return $ids === [] ? [] : User::whereIn('id', $ids)->whereNotNull('email')->pluck('email')->unique()->values()->all();
    }

    /** Genau ein Verantwortlicher → seine E-Mail (Vorgang zugewiesen); mehrere → keiner (offene Aufgabe, wer zuerst übernimmt). */
    public function assigneeEmail(Signal $signal): ?string
    {
        $entityIds = array_values(array_unique(array_map('intval', (array) $signal->recipient_entity_ids)));
        if (count($entityIds) !== 1) {
            return null;
        }
        $userId = OrganizationEntity::whereKey($entityIds[0])->value('linked_user_id');
        if (! $userId && count((array) $signal->recipient_user_ids) === 1) {
            $userId = ((array) $signal->recipient_user_ids)[0];
        }

        return $userId ? User::whereKey($userId)->value('email') : null;
    }

    public function signalUrl(Signal $signal): ?string
    {
        try {
            return route('signals.signals.show', $signal->id);
        } catch (\Throwable) {
            return null;
        }
    }

    // ── Für den Agenten ──────────────────────────────────────────

    /**
     * Offene Aufträge für Signale, deren Set-Agent $agentUserId ist – älteste zuerst. Je Signal ist nur der älteste
     * offene Auftrag „ready“; die anderen warten (waits_for_op_id).
     *
     * @return list<array<string,mixed>>
     */
    public function pendingFor(int $agentUserId, ?int $signalId = null, int $limit = 100): array
    {
        $setIds = SignalSet::withTrashed()->where('responsible_agent_user_id', $agentUserId)->pluck('id')->all();
        $ops = SignalDeliveryOp::query()->where('status', 'pending')
            ->whereHas('signal', fn ($q) => $q->where(fn ($w) => $w->whereIn('signal_set_id', $setIds ?: [0])
                ->orWhere(fn ($x) => $x->whereNull('signal_set_id')->where('responsible_user_id', $agentUserId))))
            ->when($signalId, fn ($q) => $q->where('signal_id', $signalId))
            ->with('signal.entity:id,name')
            ->orderBy('id')->limit($limit)->get();

        $first = [];

        return $ops->map(function (SignalDeliveryOp $o) use (&$first) {
            $s = $o->signal;
            $waits = $first[$o->signal_id] ?? null;
            $first[$o->signal_id] ??= $o->id;
            $room = (int) ($s->delivery_room_entity_id ?: $s->entity_id);

            return [
                'op_id' => $o->id,
                'signal_id' => $o->signal_id,
                'op' => $o->op,
                'payload' => $o->payload,
                'thread' => $s->delivery_thread,
                'room' => ['object' => 'entity', 'id' => $room, 'name' => $s->entity?->name],
                'ready' => $waits === null,
                'waits_for_op_id' => $waits,
                'attempts' => (int) $o->attempts,
                'last_error' => $o->last_error,
                'created_at' => $o->created_at?->toIso8601String(),
                'signal' => [
                    'status' => $s->status, 'severity' => $s->severity, 'delivery_state' => $s->delivery_state,
                    'message' => mb_strimwidth((string) $s->message, 0, 300, '…'),
                ],
            ];
        })->values()->all();
    }

    /**
     * Rückmeldung des Agenten zu einem Auftrag.
     *
     * @return array{op:SignalDeliveryOp, signal:Signal}
     */
    public function report(SignalDeliveryOp $op, bool $ok, ?string $thread = null, ?string $error = null): array
    {
        if ($op->status !== 'pending') {
            throw new SignalSetException("Auftrag #{$op->id} ist nicht mehr offen (Status {$op->status}).", 'VALIDATION_ERROR');
        }
        $earlier = SignalDeliveryOp::query()->where('signal_id', $op->signal_id)->where('status', 'pending')->where('id', '<', $op->id)->min('id');
        if ($earlier) {
            throw new SignalSetException("Erst Auftrag #{$earlier} für dieses Signal erledigen – je Signal der Reihe nach.", 'VALIDATION_ERROR');
        }
        $signal = $op->signal;
        $thread = $thread !== null && trim($thread) !== '' ? trim($thread) : null;

        if ($ok) {
            if ($op->op === 'create' && ! $thread && ! $signal->delivery_thread) {
                throw new SignalSetException('thread ist beim Anlegen Pflicht: die ID des neuen Parlan-Vorgangs.', 'VALIDATION_ERROR');
            }
            $op->forceFill(['status' => 'done', 'done_at' => now(), 'last_error' => null])->save();
            $fields = $thread ? ['delivery_thread' => $thread] : [];
            $fields['delivery_state'] = match ($op->op) {
                'create', 'reopen' => 'open',
                'close' => 'closed',
                default => $signal->delivery_state ?: 'open',
            };
            $signal->forceFill($fields)->saveQuietly();

            return ['op' => $op->fresh(), 'signal' => $signal->fresh()];
        }

        $attempts = (int) $op->attempts + 1;
        $failed = $attempts >= SignalDeliveryOp::MAX_ATTEMPTS;
        $op->forceFill([
            'attempts' => $attempts,
            'last_error' => mb_substr(trim((string) $error) ?: 'ohne Angabe', 0, 2000),
            'status' => $failed ? 'failed' : 'pending',
        ])->save();
        if ($failed) {
            if ($op->op === 'create') {
                $signal->forceFill(['delivery_state' => null])->saveQuietly();
            }
            app(SignalService::class)->comment($signal, "Zustellung in Parlan fehlgeschlagen ({$op->op}) nach {$attempts} Versuchen: "
                . mb_strimwidth((string) $op->last_error, 0, 300, '…') . ' Das Signal bleibt über die Seite erreichbar.', null, null, 'system');
        }

        return ['op' => $op->fresh(), 'signal' => $signal->fresh()];
    }

    private function safely(callable $fn): ?SignalDeliveryOp
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            Log::warning('Signals: Auftrag an den Zustell-Agenten fehlgeschlagen', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
