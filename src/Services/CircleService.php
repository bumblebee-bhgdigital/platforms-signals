<?php

namespace Platform\Signals\Services;

use Platform\Core\Models\User;
use Platform\Organization\Models\OrganizationEntity;
use Platform\Signals\Exceptions\SignalSetException;
use Platform\Signals\Models\Signal;
use Platform\Signals\Support\CommitteeGateways;

/**
 * Regelkreis Einheit → Lenkungsausschuss (Architektur v2, MGT-101):
 *  - Ein Signal gehört zuerst der verantwortlichen Einheit (lokal vor übergreifend).
 *  - Lokal gelöst → Ausgang solved_locally (optional mit Verweis auf den Entscheidungsrahmen).
 *  - Faustregel greift → LA-Punkt nach den vier Fragen als Entwurf in die nächste Sitzung (ohne 4b kein LA-Punkt).
 *  - Beschluss im LA → Ausgang nach Beschluss-Art (Maßnahme, zurück an den Bereich, Eskalation, Strategie-Thema).
 */
class CircleService
{
    /** Knotentypen, die keine verantwortliche Einheit sind (Ordner, Gremien, Personen, Agenten). */
    public const NOT_A_UNIT = ['container', 'board', 'person', 'agent', 'system_agent'];

    public const OUTCOMES = ['solved_locally', 'measure', 'returned_to_unit', 'escalated', 'strategy_topic', 'dismissed'];

    /**
     * Verantwortliche Einheit eines Knotens: der Knoten selbst oder der nächste Vorfahr, der eine Einheit ist
     * und einen Verantwortlichen hat („Führt an“). Fallback: nächste Einheit ohne Verantwortlichen, sonst der Knoten.
     */
    public function unitFor(int $entityId): int
    {
        $fallback = null;
        $current = OrganizationEntity::with('type:id,code')->find($entityId);
        for ($depth = 0; $current && $depth < 30; $depth++) {
            $isUnit = ! in_array($current->type?->code, self::NOT_A_UNIT, true);
            if ($isUnit) {
                if ($this->leaderOf($current->id)) {
                    return (int) $current->id;
                }
                $fallback ??= (int) $current->id;
            }
            $current = $current->parent_entity_id ? OrganizationEntity::with('type:id,code')->find($current->parent_entity_id) : null;
        }

        return $fallback ?? $entityId;
    }

    /** Verantwortliche Person einer Einheit (Beziehung „Führt an“): ['entity_id','name','user_id'] oder null. */
    public function leaderOf(int $unitEntityId): ?array
    {
        if (! class_exists(\Platform\Organization\Models\OrganizationEntityRelationship::class)) {
            return null;
        }
        $rel = \Platform\Organization\Models\OrganizationEntityRelationship::query()
            ->where('to_entity_id', $unitEntityId)
            ->whereHas('relationType', fn ($q) => $q->where('code', 'manages'))
            ->with('fromEntity:id,name,linked_user_id')
            ->orderBy('id')->first();
        $person = $rel?->fromEntity;

        return $person ? ['entity_id' => (int) $person->id, 'name' => $person->name, 'user_id' => $person->linked_user_id ? (int) $person->linked_user_id : null] : null;
    }

    /** Signal der Einheit zustellen (beim Anlegen): Einheit setzen, Stufe „unit“. */
    public function deliver(Signal $signal): Signal
    {
        if (! $signal->unit_entity_id && $signal->entity_id) {
            $signal->forceFill(['unit_entity_id' => $this->unitFor((int) $signal->entity_id), 'stage' => $signal->stage ?: 'unit'])->save();
        }

        return $signal;
    }

    /** Lokal gelöst (im Entscheidungsrahmen der Einheit). */
    public function solveLocally(Signal $signal, string $note, ?User $by, ?int $frameDecisionId = null): Signal
    {
        if (trim($note) === '') {
            throw new SignalSetException('resolution_note ist erforderlich: Wodurch hat die Einheit es gelöst?');
        }
        $opts = ['resolution_note' => $note];
        if ($frameDecisionId) {
            $opts['link_type'] = 'management_book_decision';
            $opts['link_id'] = $frameDecisionId;
        }
        $signal = app(SignalService::class)->transition($signal, 'resolve', $by, $opts);
        $signal->forceFill(['outcome' => 'solved_locally', 'frame_ref_id' => $frameDecisionId])->save();

        return $signal->fresh();
    }

    /**
     * In den LA heben: LA-Punkt nach den vier Fragen als Entwurf in die nächste Sitzung des LA über der Einheit.
     *
     * @param  array{title?:string, goal?:string, observation?:string, obstacle?:string, local_solution?:string, system_support?:string, local_solution_confirmed?:bool}  $q
     */
    public function liftToCommittee(Signal $signal, array $q, ?User $by): array
    {
        if ($signal->isClosed()) {
            throw new SignalSetException('Das Signal ist bereits geschlossen.');
        }
        if ($signal->stage === 'committee' && $signal->agenda_ref_id) {
            throw new SignalSetException("Das Signal liegt schon als LA-Punkt vor (#{$signal->agenda_ref_id}).");
        }
        foreach (['goal' => '1 (Ziel)', 'observation' => '2 (Beobachtung)', 'obstacle' => '3 (Hindernis)'] as $k => $label) {
            if (trim((string) ($q[$k] ?? '')) === '') {
                throw new SignalSetException("Frage {$label} fehlt.");
            }
        }
        if (trim((string) ($q['system_support'] ?? '')) === '') {
            throw new SignalSetException('Frage 4b fehlt (Wobei braucht der Bereich das Gesamtsystem?). Ohne 4b kein LA-Punkt – dann bleibt es lokal.');
        }
        if (trim((string) ($q['local_solution'] ?? '')) === '') {
            throw new SignalSetException('Frage 4a fehlt (Was kann der Bereich selbst lösen?). Ohne 4a droht Mikromanagement – erst mit dem Bereich klären.');
        }

        $gateway = CommitteeGateways::get();
        if (! $gateway) {
            throw new SignalSetException('Keine Gremien-Anbindung (Management Book) verfügbar.', 'NOT_AVAILABLE');
        }
        $this->deliver($signal);
        $unitId = (int) ($signal->unit_entity_id ?: $signal->entity_id);
        $committee = $gateway->committeeAbove($unitId);
        if (! $committee) {
            throw new SignalSetException('Über dieser Einheit gibt es keinen aktiven Lenkungsausschuss.', 'NOT_FOUND');
        }
        $meeting = $gateway->nextMeeting((int) $committee['entity_id']);
        if (! $meeting) {
            throw new SignalSetException("Der {$committee['name']} hat keine geplante Sitzung. Erst einen Termin anlegen.", 'NOT_FOUND');
        }

        $item = $gateway->draftAgendaItem((int) $meeting['meeting_id'], [
            'title' => trim((string) ($q['title'] ?? '')) ?: mb_strimwidth(strip_tags($signal->message), 0, 120, '…'),
            'goal' => trim((string) $q['goal']),
            'observation' => trim((string) $q['observation']),
            'obstacle' => trim((string) $q['obstacle']),
            'local_solution' => trim((string) $q['local_solution']),
            'system_support' => trim((string) $q['system_support']),
            'local_solution_confirmed' => (bool) ($q['local_solution_confirmed'] ?? false),
            'unit_entity_id' => $unitId,
            'agent_user_id' => $by && \Platform\Signals\Support\Agents::isAgent($by) ? $by->id : null,
        ], Signal::MORPH_ALIAS, (int) $signal->id);

        $signal->forceFill([
            'stage' => 'committee',
            'committee_entity_id' => (int) $committee['entity_id'],
            'meeting_ref_id' => (int) $meeting['meeting_id'],
            'due_at' => $meeting['scheduled_at'] ?? null,
            'agenda_ref_type' => $item['type'],
            'agenda_ref_id' => $item['id'],
            'acknowledged_at' => $signal->acknowledged_at ?? now(),
            'status' => $signal->status === 'open' ? 'acknowledged' : $signal->status,
        ])->save();
        app(SignalService::class)->comment($signal, "In den {$committee['name']} gehoben: Entwurf eines LA-Punkts für die Sitzung „{$meeting['title']}“.", $by, null, 'system');

        return ['signal' => $signal->fresh(), 'committee' => $committee, 'meeting' => $meeting, 'agenda_item' => $item];
    }

    /**
     * Rückweg aus dem Gremium: Beschluss zu einem LA-Punkt, der auf ein Signal verweist.
     * Beschluss-Arten: measure | return_to_unit | escalation | frame | strategy_topic (andere → als Maßnahme behandelt).
     */
    public function onDecision(int $signalId, string $kind, string $decisionType, int $decisionId, string $text, ?int $byUserId = null): ?Signal
    {
        $signal = Signal::query()->find($signalId);
        if (! $signal) {
            return null;
        }
        $by = $byUserId ? User::find($byUserId) : null;
        $note = 'Beschluss: ' . mb_strimwidth($text, 0, 500, '…');

        switch ($kind) {
            case 'return_to_unit':
                $signal->forceFill([
                    'stage' => 'unit', 'outcome' => 'returned_to_unit', 'status' => 'acknowledged',
                    'resolution_link_type' => $decisionType, 'resolution_link_id' => $decisionId,
                ])->save();
                app(SignalService::class)->comment($signal, "Zurück an den Bereich – {$note}", $by, null, 'system');
                break;
            case 'escalation':
                $signal->forceFill(['stage' => 'escalated', 'outcome' => 'escalated', 'resolution_link_type' => $decisionType, 'resolution_link_id' => $decisionId])->save();
                app(SignalService::class)->comment($signal, "Eskaliert – {$note}", $by, null, 'system');
                break;
            case 'strategy_topic':
                $signal->forceFill(['stage' => 'strategy', 'outcome' => 'strategy_topic', 'resolution_link_type' => $decisionType, 'resolution_link_id' => $decisionId])->save();
                app(SignalService::class)->comment($signal, "Thema fürs Strategiemeeting – {$note}", $by, null, 'system');
                break;
            default: // measure, frame, …
                if (! $signal->isClosed()) {
                    app(SignalService::class)->transition($signal, 'resolve', $by, [
                        'resolution_note' => $note,
                        'link_type' => $decisionType,
                        'link_id' => $decisionId,
                    ]);
                }
                $signal->forceFill(['outcome' => 'measure', 'frame_ref_id' => $kind === 'frame' ? $decisionId : $signal->frame_ref_id])->save();
        }

        return $signal->fresh();
    }

    /** Lage eines Lenkungsausschusses für den Steward: nächste Sitzung, Signale im LA, Kandidaten aus den Einheiten. */
    public function committeeSituation(int $committeeEntityId, int|array $teamIds): array
    {
        $gateway = CommitteeGateways::get();
        $meeting = $gateway?->nextMeeting($committeeEntityId);

        // Bestand von vor dem Regelkreis: offene Signale ohne Einheit nachträglich zustellen
        Signal::query()->whereIn('team_id', (array) $teamIds)->whereNull('unit_entity_id')
            ->whereIn('status', ['open', 'acknowledged'])->limit(500)->get()
            ->each(fn (Signal $s) => $this->deliver($s));

        $inCommittee = Signal::query()->whereIn('team_id', (array) $teamIds)->where('committee_entity_id', $committeeEntityId)
            ->whereIn('status', ['open', 'acknowledged'])->get();

        // Kandidaten: offene Signale an Einheiten, deren nächster LA dieser ist
        $candidates = Signal::query()->whereIn('team_id', (array) $teamIds)->where('stage', 'unit')
            ->whereIn('status', ['open', 'acknowledged'])->whereNotNull('unit_entity_id')->get()
            ->filter(function (Signal $s) use ($gateway, $committeeEntityId) {
                static $cache = [];
                $u = (int) $s->unit_entity_id;
                $cache[$u] ??= $gateway?->committeeAbove($u)['entity_id'] ?? null;

                return (int) $cache[$u] === $committeeEntityId;
            })->values();

        $units = $candidates->pluck('unit_entity_id')->merge($inCommittee->pluck('unit_entity_id'))->filter()->unique()->values();
        $unitInfo = $units->mapWithKeys(fn ($u) => [(int) $u => [
            'name' => OrganizationEntity::whereKey($u)->value('name'),
            'leader' => $this->leaderOf((int) $u),
            'frames' => $gateway ? $gateway->frames((int) $u) : [],
        ]])->all();

        return [
            'next_meeting' => $meeting,
            'in_committee' => $inCommittee,
            'candidates' => $candidates,
            'units' => $unitInfo,
        ];
    }
}
