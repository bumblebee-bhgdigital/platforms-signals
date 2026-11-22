<?php

namespace Platform\Signals\Services;

use Platform\Organization\Models\OrganizationEntity;
use Platform\Signals\Contracts\ResponsibilityLookup;
use Platform\Signals\Models\Signal;
use Platform\Signals\Models\SignalSetTemplate;

/**
 * Z2 Empfänger: Ein Signal geht an den nächstmöglichen Verantwortlichen – egal, wo das Set hängt.
 *
 * Signals stellt der Organisation nur die eine Frage (Vertrag ResponsibilityLookup): „wer ist verantwortlich für
 * Knoten X mit Fähigkeit C?“ – ab dem Knoten des Signals aufwärts. Der Betroffene selbst (entity_id des Signals)
 * wird übersprungen (befangen). Ist ein Agent verantwortlich, ist bei kritisch und nach Eskalation sein Mensch
 * (supervisor) mit dabei. Der Agent des Sets (responsible_user_id) bleibt Absender und Begleiter, nicht Empfänger.
 */
class SignalDelivery
{
    public function __construct(protected EffectiveAssignmentService $assignments)
    {
    }

    public function lookup(): ResponsibilityLookup
    {
        return app(ResponsibilityLookup::class);
    }

    /**
     * Beim Anlegen zustellen: gewinnende Zuordnung festhalten, Empfänger suchen, Begründung schreiben.
     * $fromEntityId: Suche ab einem anderen Knoten (z. B. Elternknoten bei „Einheit ohne Verantwortlichen“).
     */
    public function deliver(Signal $signal, ?int $fromEntityId = null, bool $force = false): Signal
    {
        // geschlossene Signale (z. B. übernommene Historie) werden nicht zugestellt
        if (! $signal->entity_id || $signal->isClosed() || (! $force && ($signal->delivered_at || $signal->recipient_reason))) {
            return $signal;
        }

        if (! $signal->signal_set_template_id && $signal->signal_template_id) {
            $a = $this->assignments->effectiveFor((int) $signal->entity_id, (int) $signal->signal_template_id);
            if ($a) {
                $signal->forceFill(['signal_set_template_id' => $a->id, 'signal_set_id' => $signal->signal_set_id ?: $a->signal_set_id]);
            }
        }

        $policy = $this->policy($signal);
        $from = $fromEntityId ?: (int) $signal->entity_id;
        $result = $this->lookup()->responsibleFor($from, $policy['recipient_capability'], [(int) $signal->entity_id]);

        $signal->forceFill($this->recipientFields($signal, $result) + [
            'delivered_at' => ! empty($result['found']) ? now() : null,
        ])->save();

        // Z4: bei Zustellung als Vorgang den Agenten des Sets beauftragen (nur einmal je Signal)
        if ($signal->delivered_at) {
            app(SignalDeliveryQueue::class)->delivered($signal);
        }

        return $signal;
    }

    /** Wirksame Politik eines Signals (gewinnende Zuordnung, sonst Standards). */
    public function policy(Signal $signal): array
    {
        $a = $signal->signal_set_template_id ? SignalSetTemplate::with('template')->find($signal->signal_set_template_id) : null;

        return SignalSetTemplate::policyFor($a, $signal->severity);
    }

    /**
     * Empfänger-Felder aus einer Antwort der Organisation.
     *
     * @return array{recipient_node_entity_id:?int, recipient_entity_ids:array, recipient_user_ids:array, recipient_reason:string}
     */
    public function recipientFields(Signal $signal, array $result, ?string $prefix = null): array
    {
        $holders = array_values((array) ($result['holders'] ?? []));
        $withSupervisor = in_array($signal->severity, ['critical', 'algedonic'], true) || (int) $signal->escalation_level > 0;

        $entityIds = [];
        $userIds = [];
        foreach ($holders as $h) {
            if (! empty($h['entity_id'])) {
                $entityIds[] = (int) $h['entity_id'];
            }
            if (! empty($h['user_id'])) {
                $userIds[] = (int) $h['user_id'];
            }
            // Agent als Verantwortlicher: bei kritisch und Eskalation ist sein Mensch mit dabei
            if (($h['kind'] ?? null) === 'agent' && $withSupervisor && ! empty($h['supervisor']['user_id'])) {
                $userIds[] = (int) $h['supervisor']['user_id'];
            }
        }

        return [
            'recipient_node_entity_id' => ! empty($result['found']) && ! empty($result['node']['id']) ? (int) $result['node']['id'] : null,
            'recipient_entity_ids' => array_values(array_unique($entityIds)),
            'recipient_user_ids' => array_values(array_unique($userIds)),
            'recipient_reason' => mb_substr(($prefix ? $prefix . ' ' : '') . $this->reason($signal, $result), 0, 2000),
        ];
    }

    /**
     * Lesbare Begründung, z. B. „verantwortlich an Projekt P (Führt an)“ oder
     * „Projekt P: niemand verantwortlich → nächster Verantwortlicher an FOOD.WORKS (Venture Owner)“.
     */
    public function reason(Signal $signal, array $result): string
    {
        $subject = OrganizationEntity::whereKey($signal->entity_id)->value('name') ?? ('#' . $signal->entity_id);
        if (empty($result['found']) || empty($result['node'])) {
            return "{$subject}: niemand verantwortlich gefunden";
        }
        $vias = collect($result['holders'] ?? [])->pluck('via_name')->filter()->unique()->implode(', ');
        $at = ($result['node']['name'] ?? ('#' . $result['node']['id'])) . ($vias !== '' ? " ({$vias})" : '');

        return (int) $result['node']['id'] === (int) $signal->entity_id
            ? "verantwortlich an {$at}"
            : "{$subject}: niemand verantwortlich → nächster Verantwortlicher an {$at}";
    }

    /** Namen der aktuellen Empfänger (für Kommentare und Oberfläche). */
    public function recipientNames(Signal $signal): string
    {
        $ids = array_map('intval', (array) $signal->recipient_entity_ids);
        $names = $ids === [] ? [] : OrganizationEntity::whereIn('id', $ids)->pluck('name')->all();

        return $names === [] ? 'niemandem' : implode(', ', $names);
    }
}
