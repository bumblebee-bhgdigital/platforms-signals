<?php

namespace Platform\Signals\Services;

use Platform\Organization\Models\OrganizationEntity;
use Platform\Signals\Enums\SignalSetStatus;
use Platform\Signals\Models\SignalSet;
use Platform\Signals\Models\SignalSetTemplate;

/**
 * Z1 „das nächste gewinnt“: Welche Zuordnung eines Templates gilt an einem Knoten?
 *
 * Kandidaten sind Zuordnungen des Templates in aktiven Sets, die den Knoten erreichen: Sets direkt am Knoten
 * (Abstand 0) und Sets an einem Vorfahren mit inherit_down (Abstand = Ebenen nach oben). Die nächste gewinnt.
 * Bei Gleichstand am selben Knoten geht eine Abwahl vor, sonst die ältere Zuordnung (kleinere ID).
 *
 * Abwahl (opts_out) als nächste: Hängt weiter oben eine verbindliche Zuordnung (is_binding), zählt die Abwahl
 * nicht – dann gilt die nächste Zuordnung weiter oben, die keine Abwahl ist. Sonst gilt hier keine Regel.
 * Pausierte Zuordnungen (is_active = false) und inaktive Templates zählen nicht.
 */
class EffectiveAssignmentService
{
    /** @var array<int, int|null> Knoten => Elternknoten (pro Request) */
    private array $parents = [];

    /** @var array<int, array<int,bool>> Set => [Knoten => inherit_down] (pro Request) */
    private array $setNodes = [];

    public function __construct(protected SignalSetService $sets)
    {
    }

    /** Gewinnende Zuordnung für (Knoten, Template) oder null (keine Regel gilt hier). */
    public function effectiveFor(int $entityId, int $templateId): ?SignalSetTemplate
    {
        return $this->effectiveMap($templateId, [$entityId])[$entityId] ?? null;
    }

    /**
     * Gewinnende Zuordnung je Knoten für ein Template.
     *
     * @param  int[]  $entityIds
     * @return array<int, SignalSetTemplate|null>
     */
    public function effectiveMap(int $templateId, array $entityIds): array
    {
        $byNode = $this->assignmentsByNode($templateId);
        $out = [];
        foreach (array_unique(array_map('intval', $entityIds)) as $entityId) {
            $out[$entityId] = self::pick($this->candidates($entityId, $byNode));
        }

        return $out;
    }

    /**
     * Kandidaten für einen Knoten, nächste zuerst.
     *
     * @return list<array{assignment: SignalSetTemplate, distance: int, via_entity_id: int}>
     */
    public function candidatesFor(int $entityId, int $templateId): array
    {
        return $this->candidates($entityId, $this->assignmentsByNode($templateId));
    }

    /**
     * Entscheidungsregel (rein, ohne Datenbank): nächste gewinnt; Abwahl unter einer verbindlichen Zuordnung zählt nicht.
     *
     * @param  list<array{assignment: SignalSetTemplate, distance: int}>  $candidates  nächste zuerst
     */
    public static function pick(array $candidates): ?SignalSetTemplate
    {
        $nearest = $candidates[0] ?? null;
        if (! $nearest) {
            return null;
        }
        if (! $nearest['assignment']->opts_out) {
            return $nearest['assignment'];
        }
        $above = array_values(array_filter($candidates, fn ($c) => $c['distance'] > $nearest['distance']));
        if (! collect($above)->contains(fn ($c) => $c['assignment']->is_binding)) {
            return null; // abgewählt
        }
        foreach ($above as $c) {
            if (! $c['assignment']->opts_out) {
                return $c['assignment'];
            }
        }

        return null;
    }

    /**
     * @param  array<int, list<array{assignment: SignalSetTemplate, inherit: bool}>>  $byNode
     * @return list<array{assignment: SignalSetTemplate, distance: int, via_entity_id: int}>
     */
    private function candidates(int $entityId, array $byNode): array
    {
        $out = [];
        $chain = array_merge([$entityId], $this->ancestors($entityId));
        foreach ($chain as $distance => $nodeId) {
            foreach ($byNode[$nodeId] ?? [] as $row) {
                if ($distance === 0 || $row['inherit']) {
                    $out[] = ['assignment' => $row['assignment'], 'distance' => $distance, 'via_entity_id' => $nodeId];
                }
            }
        }
        $key = fn ($c) => [$c['distance'], $c['assignment']->opts_out ? 0 : 1, $c['assignment']->id];
        usort($out, fn ($a, $b) => $key($a) <=> $key($b));

        return $out;
    }

    /**
     * Aktive Zuordnungen des Templates in aktiven Sets, nach den Knoten, an denen ihr Set hängt.
     *
     * @return array<int, list<array{assignment: SignalSetTemplate, inherit: bool}>>
     */
    private function assignmentsByNode(int $templateId): array
    {
        $assignments = SignalSetTemplate::query()->where('signal_template_id', $templateId)->where('is_active', true)
            ->whereHas('set', fn ($q) => $q->where('status', SignalSetStatus::ACTIVE->value))
            ->whereHas('template', fn ($q) => $q->where('is_active', true))
            ->with(['set', 'template'])->get();

        $byNode = [];
        foreach ($assignments as $a) {
            foreach ($this->nodesOf($a->set) as $nodeId => $inherit) {
                $byNode[(int) $nodeId][] = ['assignment' => $a, 'inherit' => (bool) $inherit];
            }
        }

        return $byNode;
    }

    /** @return array<int,bool> */
    private function nodesOf(SignalSet $set): array
    {
        return $this->setNodes[$set->id] ??= $this->sets->nodeAssignments($set);
    }

    /** Vorfahren, nächster zuerst (gecacht). @return int[] */
    private function ancestors(int $entityId): array
    {
        $ids = [];
        $current = $entityId;
        for ($depth = 0; $depth < 50; $depth++) {
            if (! array_key_exists($current, $this->parents)) {
                $p = OrganizationEntity::query()->whereKey($current)->value('parent_entity_id');
                $this->parents[$current] = $p ? (int) $p : null;
            }
            $parent = $this->parents[$current];
            if (! $parent || in_array($parent, $ids, true) || $parent === $entityId) {
                break;
            }
            $ids[] = $parent;
            $current = $parent;
        }

        return $ids;
    }
}
