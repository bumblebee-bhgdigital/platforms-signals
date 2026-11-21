<?php

namespace Platform\Signals\Services;

use Platform\Core\Models\User;
use Platform\Organization\Models\OrganizationEntity;
use Platform\Organization\Models\OrganizationEntityRelationship;
use Platform\Organization\Models\OrganizationEntityType;
use Platform\Signals\Support\Agents;
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
    public const OUTCOMES = ['solved_locally', 'measure', 'returned_to_unit', 'escalated', 'strategy_topic', 'dismissed'];

    /** @var array<int, array>|null Träger-Knoten => Gremium (pro Request) */
    private ?array $byCarrier = null;

    /**
     * Verantwortliche Einheit eines Knotens: der Knoten selbst oder sein nächster Vorfahr, der ein Träger ist
     * (VSM-Klasse carrier der Organisation – lebensfähiges System). Ohne Träger im Pfad: der Knoten selbst.
     */
    public function unitFor(int $entityId): int
    {
        return $this->carrierOf($entityId) ?? $entityId;
    }

    public function carrierOf(?int $entityId): ?int
    {
        $current = $entityId ? OrganizationEntity::with('type:id,vsm_class')->find($entityId) : null;
        for ($depth = 0; $current && $depth < 30; $depth++) {
            if ($current->type?->vsm_class === OrganizationEntityType::VSM_CLASS_CARRIER) {
                return (int) $current->id;
            }
            $current = $current->parent_entity_id ? OrganizationEntity::with('type:id,vsm_class')->find($current->parent_entity_id) : null;
        }

        return null;
    }

    /** Verantwortliche Person einer Einheit (Beziehung aus signals.circle.leader_relation): ['entity_id','name','user_id'] oder null. */
    public function leaderOf(int $unitEntityId): ?array
    {
        $person = $this->related(config('signals.circle.leader_relation', 'manages'), $unitEntityId)->first();

        return $person ? ['entity_id' => (int) $person->id, 'name' => $person->name, 'user_id' => $person->linked_user_id ? (int) $person->linked_user_id : null] : null;
    }

    /** Knoten, die per Beziehung $code auf $toEntityId zeigen. */
    private function related(string $code, int $toEntityId): \Illuminate\Support\Collection
    {
        return OrganizationEntityRelationship::query()->where('to_entity_id', $toEntityId)
            ->whereHas('relationType', fn ($q) => $q->where('code', $code))
            ->with('fromEntity:id,name,linked_user_id')->orderBy('id')->get()->pluck('fromEntity')->filter()->values();
    }

    // ---- Rekursion: Gremien über Trägern ----

    /** Gremien nach ihrem Träger (nächster Träger über dem Gremium-Knoten). */
    private function committeesByCarrier(): array
    {
        if ($this->byCarrier !== null) {
            return $this->byCarrier;
        }
        $this->byCarrier = [];
        foreach (CommitteeGateways::get()?->committees() ?? [] as $c) {
            $parent = OrganizationEntity::whereKey($c['entity_id'])->value('parent_entity_id');
            if ($carrier = $this->carrierOf($parent ? (int) $parent : null)) {
                $this->byCarrier[$carrier] ??= $c + ['carrier_entity_id' => $carrier];
            }
        }

        return $this->byCarrier;
    }

    /**
     * Nächster LA über einem Knoten: der LA des nächsten Trägers, der einen hat. proxy_for_entity_id = oberster Träger
     * unterhalb, der keinen eigenen LA hat (der LA arbeitet stellvertretend für ihn).
     */
    public function committeeAbove(int $entityId): ?array
    {
        $byCarrier = $this->committeesByCarrier();
        $proxy = null;
        $carrier = $this->carrierOf($entityId);
        for ($depth = 0; $carrier && $depth < 30; $depth++) {
            if (isset($byCarrier[$carrier])) {
                return $this->describe($byCarrier[$carrier]) + ['proxy_for_entity_id' => $proxy];
            }
            $proxy = $carrier;
            $parent = OrganizationEntity::whereKey($carrier)->value('parent_entity_id');
            $carrier = $this->carrierOf($parent ? (int) $parent : null);
        }

        return null;
    }

    public function committeeInfo(int $committeeEntityId): ?array
    {
        $c = collect($this->committeesByCarrier())->firstWhere('entity_id', $committeeEntityId);

        return $c ? $this->describe($c) : null;
    }

    /** Nächster LA über einem Gremium (Eskalation eine Ebene höher). */
    public function committeeAboveCommittee(int $committeeEntityId): ?array
    {
        $c = $this->committeeInfo($committeeEntityId);
        $parent = $c ? OrganizationEntity::whereKey($c['carrier_entity_id'])->value('parent_entity_id') : null;

        return $parent ? $this->committeeAbove((int) $parent) : null;
    }

    /** Ebene aus der Struktur: ein LA, unter dem weitere LAs arbeiten, ist Gruppen-Ebene; sonst operativ. */
    private function describe(array $c): array
    {
        $children = collect($this->committeesByCarrier())->filter(function ($other) use ($c) {
            if ($other['entity_id'] === $c['entity_id']) {
                return false;
            }
            $parent = OrganizationEntity::whereKey($other['carrier_entity_id'])->value('parent_entity_id');
            $up = $parent ? $this->carrierOf((int) $parent) : null;
            for ($d = 0; $up && $d < 30; $d++) {
                if (isset($this->byCarrier[$up])) {
                    return $up === $c['carrier_entity_id'];
                }
                $p = OrganizationEntity::whereKey($up)->value('parent_entity_id');
                $up = $p ? $this->carrierOf((int) $p) : null;
            }

            return false;
        });

        return [
            'committee_id' => (int) $c['committee_id'],
            'entity_id' => (int) $c['entity_id'],
            'name' => $c['name'],
            'carrier_entity_id' => (int) $c['carrier_entity_id'],
            'carrier_name' => OrganizationEntity::whereKey($c['carrier_entity_id'])->value('name'),
            'level' => $children->isNotEmpty() ? 'group' : 'unit',
            'sub_committees' => $children->pluck('entity_id')->map(fn ($id) => (int) $id)->values()->all(),
        ];
    }

    // ---- Stewards: Menschen und/oder Agenten ----

    /**
     * Wer betreut den Regelkreis eines Gremiums: Beziehung aus signals.circle.steward_relation zum Gremium-Knoten
     * plus die vom Gremien-Modul genannten Verantwortlichen.
     */
    public function stewards(int $committeeEntityId): array
    {
        $out = [];
        foreach ($this->related(config('signals.circle.steward_relation', 'stewards'), $committeeEntityId) as $e) {
            $out['e' . $e->id] = ['entity_id' => (int) $e->id, 'name' => $e->name, 'user_id' => $e->linked_user_id ? (int) $e->linked_user_id : null];
        }
        $c = collect(CommitteeGateways::get()?->committees() ?? [])->firstWhere('entity_id', $committeeEntityId);
        foreach ($c['responsible_user_ids'] ?? [] as $uid) {
            if (! collect($out)->contains('user_id', (int) $uid)) {
                $e = OrganizationEntity::query()->where('linked_user_id', $uid)->first(['id', 'name']);
                $out['u' . $uid] = ['entity_id' => $e ? (int) $e->id : null, 'name' => $e?->name ?? User::whereKey($uid)->value('name'), 'user_id' => (int) $uid];
            }
        }

        return array_values(array_map(fn ($s) => $s + ['is_agent' => $s['user_id'] ? Agents::isAgent(User::find($s['user_id'])) : false], $out));
    }

    /** Gremien, deren Regelkreis ein Nutzer betreut (Mensch oder Agent). */
    public function committeesFor(int $userId): array
    {
        return collect($this->committeesByCarrier())
            ->filter(fn ($c) => collect($this->stewards((int) $c['entity_id']))->contains('user_id', $userId))
            ->map(fn ($c) => $this->describe($c))->values()->all();
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
            $frame = collect(CommitteeGateways::get()?->frames((int) ($signal->unit_entity_id ?: $signal->entity_id)) ?? [])->firstWhere('decision_id', $frameDecisionId);
            if (! $frame) {
                throw new SignalSetException("Entscheidungsrahmen #{$frameDecisionId} gilt nicht für diese Einheit.", 'NOT_FOUND');
            }
            $opts['link_type'] = $frame['type'];
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
        $escalated = $signal->stage === 'escalated' && $signal->committee_entity_id;
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
        // Eskaliertes Signal: in den LA, an den es eskaliert wurde (eine Ebene höher), sonst der LA über der Einheit
        $committee = $escalated
            ? ['entity_id' => (int) $signal->committee_entity_id, 'name' => OrganizationEntity::whereKey($signal->committee_entity_id)->value('name') ?? 'Lenkungsausschuss']
            : $this->committeeAbove($unitId);
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
            'agent_user_id' => $by && Agents::isAgent($by) ? $by->id : null,
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
                // eine Ebene höher: Kandidat im nächsten LA über diesem Gremium (Herkunft bleibt sichtbar)
                $from = $signal->committee_entity_id;
                $upper = $from ? $this->committeeAboveCommittee((int) $from) : null;
                $signal->forceFill([
                    'stage' => 'escalated', 'outcome' => 'escalated',
                    'origin_committee_entity_id' => $from,
                    'committee_entity_id' => $upper['entity_id'] ?? null,
                    'meeting_ref_id' => null, 'due_at' => null, 'agenda_ref_type' => null, 'agenda_ref_id' => null,
                    'resolution_link_type' => $decisionType, 'resolution_link_id' => $decisionId,
                ])->save();
                app(SignalService::class)->comment($signal, $upper
                    ? "Eskaliert an {$upper['name']} – {$note}"
                    : "Eskaliert, aber über diesem Gremium gibt es keinen weiteren Lenkungsausschuss – {$note}", $by, null, 'system');
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

    /**
     * Lage eines Lenkungsausschusses für seine Stewards (Menschen oder Agenten): Ebene, Stewards, nächste Sitzung,
     * Signale im LA, an ihn eskalierte Signale und Kandidaten – nur Signale, für die er der nächste LA ist (Rekursion).
     */
    public function committeeSituation(int $committeeEntityId, int|array $teamIds): array
    {
        $gateway = CommitteeGateways::get();
        $meeting = $gateway?->nextMeeting($committeeEntityId);

        // Einheit offener Signale auf Stufe „unit“ aus der aktuellen Struktur ableiten (Bestand, umgehängte Knoten)
        Signal::query()->whereIn('team_id', (array) $teamIds)->whereIn('status', ['open', 'acknowledged'])
            ->where(fn ($q) => $q->whereNull('unit_entity_id')->orWhere('stage', 'unit'))->limit(500)->get()
            ->each(function (Signal $s) {
                $unit = $this->unitFor((int) $s->entity_id);
                if ((int) $s->unit_entity_id !== $unit) {
                    $s->forceFill(['unit_entity_id' => $unit, 'stage' => $s->stage ?: 'unit'])->save();
                }
            });

        $open = fn () => Signal::query()->whereIn('team_id', (array) $teamIds)->whereIn('status', ['open', 'acknowledged']);
        $inCommittee = $open()->where('stage', 'committee')->where('committee_entity_id', $committeeEntityId)->get();
        $escalated = $open()->where('stage', 'escalated')->where('committee_entity_id', $committeeEntityId)->get();

        $above = [];
        $candidates = $open()->where('stage', 'unit')->whereNotNull('unit_entity_id')->get()
            ->filter(function (Signal $s) use ($committeeEntityId, &$above) {
                $u = (int) $s->unit_entity_id;
                $above[$u] ??= $this->committeeAbove($u);

                return (int) ($above[$u]['entity_id'] ?? 0) === $committeeEntityId;
            })->values();

        $units = $candidates->pluck('unit_entity_id')->merge($inCommittee->pluck('unit_entity_id'))->merge($escalated->pluck('unit_entity_id'))
            ->filter()->unique()->values();
        $unitInfo = $units->mapWithKeys(fn ($u) => [(int) $u => [
            'name' => OrganizationEntity::whereKey($u)->value('name'),
            'leader' => $this->leaderOf((int) $u),
            'frames' => $gateway ? $gateway->frames((int) $u) : [],
            'proxy_for' => ($p = $above[(int) $u]['proxy_for_entity_id'] ?? null) ? ['entity_id' => (int) $p, 'name' => OrganizationEntity::whereKey($p)->value('name')] : null,
        ]])->all();

        return [
            'committee' => $this->committeeInfo($committeeEntityId) ?? ['entity_id' => $committeeEntityId, 'name' => OrganizationEntity::whereKey($committeeEntityId)->value('name')],
            'stewards' => $this->stewards($committeeEntityId),
            'next_meeting' => $meeting,
            'in_committee' => $inCommittee,
            'escalated_here' => $escalated,
            'candidates' => $candidates,
            'units' => $unitInfo,
        ];
    }
}
