<?php

namespace Platform\Signals\Services;

use Carbon\CarbonInterface;
use Platform\Core\Models\User;
use Platform\Organization\Models\OrganizationEntity;
use Platform\Signals\Models\Signal;

/**
 * Z3 Eskalation: nur wenn nichts passiert – eine Stufe. Ersetzt die alte VSM-Zeitleiter (Frist ⇒ Ebene und
 * Schweregrad automatisch hoch, Aggregation unabsorbierter S5-Signale) – die gibt es nicht mehr.
 *
 * Auslöser je Signal, Werte aus der gewinnenden Zuordnung (sonst config signals.delivery):
 *  - ignoriert:     keine Reaktion (reacted_at) und zugestellt vor mehr als reaction_days ARBEITSTAGEN (Mo–Fr)
 *  - dauerhaft:     reagiert, aber länger als persistence_days offen
 *  - wiederkehrend: recurrence_count ≥ Schwelle (wieder geöffnet, Z4)
 *  - abgelehnt:     „gehört nicht zu mir“ (mit Begründung) – sofort, aus SignalService::transition('reject')
 *
 * Jeder Auslöser eskaliert genau eine Stufe und nur einmal je Vorkommen (kein stündliches Wiederholen):
 * ein Auslöser zählt nur, wenn die letzte Eskalation (escalated_at) nicht nach seinem Beginn liegt.
 * Ziel je Zuordnung: none (nur markieren) · line (nächster Verantwortlicher über dem Empfänger-Knoten) ·
 * committee (an das Gremium, CircleService). An der Spitze einer Einheit mit eigenem Gremium folgt auf die Linie
 * das Gremium. Cron: `signals:escalate` (stündlich). Algedonisch läuft weiter über den eigenen Listener.
 */
class SignalEscalationService
{
    public const TRIGGERS = ['ignored', 'persistent', 'recurring', 'rejected'];

    public function __construct(
        protected SignalDelivery $delivery,
        protected CircleService $circle,
        protected SignalService $signals,
    ) {
    }

    /** @return array{checked:int, escalated:int, marked:int, committee:int} */
    public function run(?CarbonInterface $now = null): array
    {
        $now ??= now();
        $report = ['checked' => 0, 'escalated' => 0, 'marked' => 0, 'committee' => 0];

        Signal::query()->whereIn('status', ['open', 'acknowledged'])
            ->whereNotNull('delivered_at')
            ->where(fn ($q) => $q->whereNull('stage')->orWhere('stage', 'unit'))
            ->where(fn ($q) => $q->whereNull('snooze_until')->orWhere('snooze_until', '<=', $now))
            ->chunkById(200, function ($chunk) use (&$report, $now) {
                foreach ($chunk as $signal) {
                    $report['checked']++;
                    $due = $this->dueTrigger($signal, $now);
                    if (! $due) {
                        continue;
                    }
                    $outcome = $this->escalate($signal, $due['trigger'], $due['reason'], null, $now);
                    $report[$outcome]++;
                }
            });

        return $report;
    }

    /**
     * Welcher Auslöser ist jetzt fällig (oder null)?
     *
     * @return array{trigger:string, reason:string}|null
     */
    public function dueTrigger(Signal $signal, ?CarbonInterface $now = null): ?array
    {
        $now ??= now();
        $policy = $this->delivery->policy($signal);
        // Ein Auslöser zählt nur, wenn die letzte Eskalation nicht nach seinem Beginn liegt. „ignoriert“ darf gleich sein:
        // beim Übergeben sind Zustellung und Eskalation derselbe Zeitpunkt, die Uhr läuft dann für den Neuen.
        $unused = fn (?CarbonInterface $start, bool $sameMoment = false) => $start
            && (! $signal->escalated_at || ($sameMoment ? $signal->escalated_at->lte($start) : $signal->escalated_at->lt($start)));

        if ((int) $signal->recurrence_count >= $policy['recurrence_count'] && $unused($signal->reopened_at)) {
            return ['trigger' => 'recurring', 'reason' => "wiederkehrend: zum {$signal->recurrence_count}. Mal (Schwelle {$policy['recurrence_count']}× in {$policy['recurrence_window_days']} Tagen)"];
        }
        if (! $signal->reacted_at && $signal->delivered_at
            && $signal->delivered_at->copy()->addWeekdays($policy['reaction_days'])->lte($now) && $unused($signal->delivered_at, true)) {
            return ['trigger' => 'ignored', 'reason' => "ignoriert: {$policy['reaction_days']} Arbeitstage ohne Reaktion"];
        }
        if ($signal->reacted_at && $signal->reacted_at->copy()->addDays($policy['persistence_days'])->lte($now)
            && $unused($signal->delivered_at && $signal->delivered_at->gt($signal->reacted_at) ? $signal->delivered_at : $signal->reacted_at)) {
            return ['trigger' => 'persistent', 'reason' => "dauerhaft: angenommen, aber seit mehr als {$policy['persistence_days']} Tagen offen"];
        }

        return null;
    }

    /**
     * Eine Stufe eskalieren. Ergebnis: escalated (an den Nächsten übergeben) | committee (ans Gremium) | marked (nur markiert).
     */
    public function escalate(Signal $signal, string $trigger, string $reason, ?User $by = null, ?CarbonInterface $now = null): string
    {
        $now ??= now();
        $policy = $this->delivery->policy($signal);
        $had = $this->whoHadIt($signal, $now);
        $base = ['escalation_reason' => mb_substr($reason, 0, 500), 'escalated_at' => $now];

        if ($policy['escalation_target'] === 'none') {
            return $this->mark($signal, $base, "Eskalation markiert ({$reason}) – {$had}. Ziel der Zuordnung: keins, bleibt im Lagebild.", $by);
        }

        $current = (int) ($signal->recipient_node_entity_id ?: $signal->entity_id);
        $committee = null;
        if ($policy['escalation_target'] === 'committee') {
            $committee = $this->circle->committeeAbove((int) ($signal->unit_entity_id ?: $this->circle->unitFor((int) $signal->entity_id)));
            if (! $committee) {
                return $this->mark($signal, $base, "Eskalation ({$reason}) – {$had}. Ziel Gremium, aber über dieser Einheit gibt es keinen Lenkungsausschuss.", $by);
            }
        } elseif ($this->circle->isCarrier($current)) {
            // An der Spitze einer Einheit folgt auf die Linie ihr eigenes Gremium, erst danach die Ebene darüber
            $committee = $this->circle->ownCommittee($current);
        }

        if ($committee) {
            $this->circle->handToCommittee($signal, $committee);
            $signal->forceFill($base + ['escalation_level' => (int) $signal->escalation_level + 1])->save();
            $this->signals->comment($signal, "Eskaliert ({$reason}) – {$had}. An den {$committee['name']} übergeben: der Steward bereitet den LA-Punkt nach den vier Fragen vor.", $by, null, 'system');

            return 'committee';
        }

        // Linie: nächster Verantwortlicher über dem Knoten des Empfängers (Betroffener und bisherige Empfänger übersprungen)
        $parent = OrganizationEntity::whereKey($current)->value('parent_entity_id');
        $exclude = array_values(array_unique(array_merge([(int) $signal->entity_id], array_map('intval', (array) $signal->recipient_entity_ids), $this->entityIdsOf($by))));
        $result = $parent ? $this->delivery->lookup()->responsibleFor((int) $parent, $policy['recipient_capability'], $exclude) : ['found' => false];
        if (empty($result['found'])) {
            return $this->mark($signal, $base, "Eskalation ({$reason}) – {$had}. Darüber ist niemand verantwortlich – bleibt bei den bisherigen Empfängern.", $by);
        }

        $signal->escalation_level = (int) $signal->escalation_level + 1;
        $fields = $this->delivery->recipientFields($signal, $result);
        $signal->forceFill($base + $fields + ['delivered_at' => $now, 'reacted_at' => null])->save();
        $this->signals->comment($signal, "Eskaliert ({$reason}) – {$had}. Jetzt bei " . $this->delivery->recipientNames($signal) . ": {$fields['recipient_reason']}.", $by, null, 'system');

        return 'escalated';
    }

    private function mark(Signal $signal, array $base, string $comment, ?User $by): string
    {
        $signal->forceFill($base)->save();
        $this->signals->comment($signal, $comment, $by, null, 'system');

        return 'marked';
    }

    /** „lag 4 Arbeitstage bei Anna (Projekt P)“ */
    private function whoHadIt(Signal $signal, CarbonInterface $now): string
    {
        $since = $signal->delivered_at ?? $signal->created_at ?? $now;
        $workdays = (int) $since->diffInWeekdays($now);
        $node = $signal->recipient_node_entity_id ? OrganizationEntity::whereKey($signal->recipient_node_entity_id)->value('name') : null;

        return 'lag ' . $workdays . ' Arbeitstag' . ($workdays === 1 ? '' : 'e') . ' bei ' . $this->delivery->recipientNames($signal) . ($node ? " ({$node})" : '');
    }

    /** @return int[] Knoten eines Nutzers (z. B. wer abgelehnt hat) */
    private function entityIdsOf(?User $user): array
    {
        return $user ? OrganizationEntity::where('linked_user_id', $user->id)->pluck('id')->map(fn ($i) => (int) $i)->all() : [];
    }
}
