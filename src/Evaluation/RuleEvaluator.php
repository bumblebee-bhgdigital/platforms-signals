<?php

namespace Platform\Signals\Evaluation;

use Illuminate\Support\Facades\DB;
use Platform\Organization\Models\OrganizationEntity;
use Platform\Organization\Models\OrganizationEntitySnapshot;
use Platform\Organization\Models\OrganizationEntityType;
use Platform\Signals\Contracts\ResponsibilityLookup;

/**
 * Wertet ein Regel-Muster über die Kennzahlen-Snapshots der Organisation aus.
 * Die Muster (threshold, trend, ratio, cross_dimension) sind 1:1 aus der Organisation übernommen
 * (SignalEvaluationService), damit nach dem Umzug genau dasselbe anschlägt wie vorher.
 *
 * Dazu no_responsible (Z7 „Einheit ohne Verantwortlichen“): jede Einheit (VSM-Klasse carrier) ohne jemanden mit
 * der Fähigkeit (Standard manage) – gefragt über den Vertrag ResponsibilityLookup, nicht über Beziehungsnamen.
 *
 * Liefert je auslösendem Knoten: ['entity_id', 'message', 'metrics'] und optional 'recipient_from_entity_id'
 * (Empfänger ab diesem Knoten suchen statt am Knoten selbst).
 *
 * units_only (alle Kennzahl-Muster): nur Einheiten (VSM-Klasse carrier) werten aus, mit <metric>_cascaded aus dem
 * Snapshot (Summe über den Teilbaum), sonst dem einfachen Wert. Die Meldung nennt dann die größten Beiträger
 * darunter („– vor allem: …“, metrics.contributors). So skaliert eine Regel mit der Zahl der Einheiten statt der Projekte.
 */
class RuleEvaluator
{
    /** Muster, für die units_only gilt (no_responsible arbeitet ohnehin nur an Einheiten). */
    public const UNIT_PATTERNS = ['threshold', 'trend', 'ratio', 'cross_dimension'];

    /** Höchstzahl der Beiträger in der Meldung. */
    public const MAX_CONTRIBUTORS = 3;

    /** Läuft die aktuelle Auswertung je Einheit (units_only)? */
    protected bool $unitsOnly = false;

    /** @var array<string, string>|null Kennzahl => lesbarer Name (pro Instanz geladen) */
    protected ?array $labels = null;

    /** @return array<int, array{entity_id:int, message:string, metrics:array}> */
    public function evaluate(string $patternType, array $conditions, array $entityIds): array
    {
        $this->unitsOnly = self::isUnitsOnly($patternType, $conditions);
        if ($this->unitsOnly) {
            $entityIds = $this->unitIds($entityIds);
        }
        if ($entityIds === []) {
            return [];
        }

        $triggered = match ($patternType) {
            'threshold' => $this->evaluateThreshold($conditions, $entityIds),
            'trend' => $this->evaluateTrend($conditions, $entityIds),
            'cross_dimension' => $this->evaluateCrossDimension($conditions, $entityIds),
            'ratio' => $this->evaluateRatio($conditions, $entityIds),
            'no_responsible' => $this->evaluateNoResponsible($conditions, $entityIds),
            default => [],
        };

        if ($this->unitsOnly && $triggered !== []) {
            $triggered = $this->withContributors($triggered, $this->mainMetric($patternType, $conditions));
        }

        return $triggered;
    }

    public static function isUnitsOnly(string $patternType, array $conditions): bool
    {
        return in_array($patternType, self::UNIT_PATTERNS, true) && filter_var($conditions['units_only'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Nur die Einheiten (VSM-Klasse carrier) unter den Knoten.
     *
     * @param  int[]  $entityIds
     * @return int[]
     */
    public function unitIds(array $entityIds): array
    {
        if ($entityIds === []) {
            return [];
        }

        return OrganizationEntity::query()->whereIn('id', $entityIds)
            ->whereHas('type', fn ($q) => $q->where('vsm_class', OrganizationEntityType::VSM_CLASS_CARRIER))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Nächste Einheit (carrier) ab einem Knoten aufwärts (der Knoten selbst zählt mit), z. B. für die Auflösungsnotiz.
     *
     * @return array{id:int, name:string}|null
     */
    public function nearestUnit(int $entityId): ?array
    {
        $current = OrganizationEntity::with('type:id,vsm_class')->find($entityId, ['id', 'name', 'parent_entity_id', 'entity_type_id']);
        for ($depth = 0; $current && $depth < 50; $depth++) {
            if ($current->type?->vsm_class === OrganizationEntityType::VSM_CLASS_CARRIER) {
                return ['id' => (int) $current->id, 'name' => (string) $current->name];
            }
            $current = $current->parent_entity_id
                ? OrganizationEntity::with('type:id,vsm_class')->find($current->parent_entity_id, ['id', 'name', 'parent_entity_id', 'entity_type_id'])
                : null;
        }

        return null;
    }

    /**
     * Threshold evaluation: compare a single metric against a value.
     * conditions: {"metric": "items_total", "operator": ">", "value": 100}
     * Kombiniert (alle müssen zutreffen): {"all": [{"metric": "planner_items_open", "operator": ">=", "value": 5},
     *                                             {"metric": "planner_items_done_7d", "operator": "==", "value": 0}]}
     */
    protected function evaluateThreshold(array $conditions, array $entityIds): array
    {
        $parts = $this->thresholdParts($conditions);
        if ($parts === []) {
            return [];
        }

        $snapshots = $this->getLatestSnapshots($entityIds);
        $entities = $this->getEntityNames($entityIds);
        $triggered = [];

        foreach ($snapshots as $entityId => $snapshot) {
            $texts = [];
            $values = [];
            foreach ($parts as ['metric' => $metric, 'operator' => $operator, 'value' => $threshold]) {
                $value = $this->metricValue((array) $snapshot->metrics, $metric);
                // Fehlende Kennzahl = Einheit hat dazu keine Daten (z. B. keine Projekte) → nicht als 0 werten
                if ($value === null || ! $this->compare($value, $operator, $threshold)) {
                    continue 2;
                }
                $values[$metric] = $value;
                $texts[] = "{$this->label($metric)} = {$value} ({$operator} {$threshold})";
            }

            $entityName = $entities[$entityId] ?? "Entity #{$entityId}";
            $triggered[] = [
                'entity_id' => $entityId,
                'message' => "{$entityName}: " . implode(' und ', $texts),
                'metrics' => $values,
            ];
        }

        return $triggered;
    }

    /**
     * Teilbedingungen einer Schwelle: entweder „all“ (Liste) oder die einzelne metric/operator/value.
     *
     * @return list<array{metric:string, operator:string, value:mixed}>
     */
    protected function thresholdParts(array $conditions): array
    {
        $list = isset($conditions['all']) && is_array($conditions['all']) && $conditions['all'] !== []
            ? $conditions['all']
            : [$conditions];
        $parts = [];
        foreach ($list as $part) {
            if (! is_array($part) || empty($part['metric'])) {
                return []; // unvollständige Teilbedingung → Regel schlägt nicht an (wie ohne metric)
            }
            $parts[] = ['metric' => (string) $part['metric'], 'operator' => (string) ($part['operator'] ?? '>'), 'value' => $part['value'] ?? 0];
        }

        return $parts;
    }

    protected function compare($value, string $operator, $threshold): bool
    {
        return match ($operator) {
            '>' => $value > $threshold,
            '>=' => $value >= $threshold,
            '<' => $value < $threshold,
            '<=' => $value <= $threshold,
            '==' => $value == $threshold,
            '!=' => $value != $threshold,
            default => false,
        };
    }

    /**
     * Trend evaluation: check if metric moves in a direction over N periods.
     * conditions: {"metric": "time_total_minutes", "direction": "decreasing", "periods": 3, "min_change_percent": 10}
     * min_value (optional): nur anschlagen, wenn der letzte Wert mindestens so groß ist (kleine Ausgangswerte ausblenden).
     */
    protected function evaluateTrend(array $conditions, array $entityIds): array
    {
        $metric = $conditions['metric'] ?? null;
        $direction = $conditions['direction'] ?? 'increasing'; // increasing or decreasing
        $periods = $conditions['periods'] ?? 3;
        $minChangePercent = $conditions['min_change_percent'] ?? 10;
        $minValue = isset($conditions['min_value']) && is_numeric($conditions['min_value']) ? (float) $conditions['min_value'] : null;

        if (! $metric) {
            return [];
        }

        $entities = $this->getEntityNames($entityIds);
        $triggered = [];

        // Get snapshots over the last N*2 days to find enough data points
        $snapshots = OrganizationEntitySnapshot::whereIn('entity_id', $entityIds)
            ->where('snapshot_date', '>=', now()->subDays($periods * 2 + 1))
            ->orderBy('snapshot_date')
            ->get()
            ->groupBy('entity_id');

        foreach ($entityIds as $entityId) {
            $entitySnapshots = $snapshots[$entityId] ?? collect();

            if ($entitySnapshots->count() < $periods) {
                continue;
            }

            // Take the last N snapshots (unique by date, prefer latest)
            $recent = $entitySnapshots->unique('snapshot_date')->sortByDesc('snapshot_date')->take($periods)->reverse()->values();

            if ($recent->count() < $periods) {
                continue;
            }

            // Check if all consecutive changes go in the expected direction
            $consistent = true;
            $totalChange = 0;
            $firstValue = $this->metricValue((array) $recent->first()->metrics, $metric) ?? 0;

            for ($i = 1; $i < $recent->count(); $i++) {
                $prev = $this->metricValue((array) $recent[$i - 1]->metrics, $metric) ?? 0;
                $curr = $this->metricValue((array) $recent[$i]->metrics, $metric) ?? 0;
                $diff = $curr - $prev;

                if ($direction === 'increasing' && $diff <= 0) {
                    $consistent = false;
                    break;
                }
                if ($direction === 'decreasing' && $diff >= 0) {
                    $consistent = false;
                    break;
                }
            }

            if (! $consistent) {
                continue;
            }

            $lastValue = $this->metricValue((array) $recent->last()->metrics, $metric) ?? 0;
            if ($minValue !== null && $lastValue < $minValue) {
                continue;
            }
            $changePercent = $firstValue != 0
                ? abs(($lastValue - $firstValue) / $firstValue) * 100
                : ($lastValue != 0 ? 100 : 0);

            if ($changePercent < $minChangePercent) {
                continue;
            }

            $entityName = $entities[$entityId] ?? "Entity #{$entityId}";
            $dirLabel = $direction === 'increasing' ? 'steigend' : 'fallend';
            $triggered[] = [
                'entity_id' => $entityId,
                'message' => "{$entityName}: {$this->label($metric)} {$dirLabel} über {$periods} Perioden ({$firstValue} → {$lastValue}, " . round($changePercent, 1) . '%)',
                'metrics' => [$metric => $lastValue, 'change_percent' => round($changePercent, 1)],
            ];
        }

        return $triggered;
    }

    /**
     * Cross-dimension evaluation: check if two metrics diverge or converge.
     * conditions: {"metric_a": "revenue_total", "metric_b": "costs_total", "relationship": "diverging"}
     */
    protected function evaluateCrossDimension(array $conditions, array $entityIds): array
    {
        $metricA = $conditions['metric_a'] ?? null;
        $metricB = $conditions['metric_b'] ?? null;
        $relationship = $conditions['relationship'] ?? 'diverging'; // diverging or converging

        if (! $metricA || ! $metricB) {
            return [];
        }

        $entities = $this->getEntityNames($entityIds);
        $triggered = [];

        // Need at least 2 data points to detect divergence
        $snapshots = OrganizationEntitySnapshot::whereIn('entity_id', $entityIds)
            ->where('snapshot_date', '>=', now()->subDays(14))
            ->orderBy('snapshot_date')
            ->get()
            ->groupBy('entity_id');

        foreach ($entityIds as $entityId) {
            $entitySnapshots = $snapshots[$entityId] ?? collect();
            $unique = $entitySnapshots->unique('snapshot_date')->sortBy('snapshot_date')->values();

            if ($unique->count() < 2) {
                continue;
            }

            $first = $unique->first();
            $last = $unique->last();

            $m = fn ($snap, $key) => $this->metricValue((array) $snap->metrics, $key) ?? 0;
            $gapFirst = $m($first, $metricA) - $m($first, $metricB);
            $gapLast = $m($last, $metricA) - $m($last, $metricB);

            $isDiverging = abs($gapLast) > abs($gapFirst) * 1.2; // 20% threshold
            $isConverging = abs($gapLast) < abs($gapFirst) * 0.8;

            $matches = ($relationship === 'diverging' && $isDiverging)
                    || ($relationship === 'converging' && $isConverging);

            if ($matches) {
                $entityName = $entities[$entityId] ?? "Entity #{$entityId}";
                $relLabel = $relationship === 'diverging' ? 'divergieren' : 'konvergieren';
                $aVal = $m($last, $metricA);
                $bVal = $m($last, $metricB);
                $triggered[] = [
                    'entity_id' => $entityId,
                    'message' => "{$entityName}: {$this->label($metricA)} und {$this->label($metricB)} {$relLabel} ({$aVal} vs. {$bVal})",
                    'metrics' => [$metricA => $aVal, $metricB => $bVal],
                ];
            }
        }

        return $triggered;
    }

    /**
     * Ratio evaluation: compare ratio of two metrics against a threshold.
     * conditions: {"numerator": "items_done", "denominator": "items_total", "operator": "<", "value": 0.5,
     *               "min_denominator": 5, "grace_days": 14}
     * min_denominator: Mindestmenge im Nenner (Default 0 = aus); grace_days: Karenz für neu angelegte Entities (Default 0 = aus).
     */
    protected function evaluateRatio(array $conditions, array $entityIds): array
    {
        $numerator = $conditions['numerator'] ?? null;
        $denominator = $conditions['denominator'] ?? null;
        $operator = $conditions['operator'] ?? '<';
        $threshold = $conditions['value'] ?? 0.5;
        $minDenominator = (float) ($conditions['min_denominator'] ?? 0);
        $graceDays = (int) ($conditions['grace_days'] ?? 0);

        if (! $numerator || ! $denominator) {
            return [];
        }

        $snapshots = $this->getLatestSnapshots($entityIds);
        $entities = $this->getEntityNames($entityIds);
        $graceEntityIds = $graceDays > 0
            ? OrganizationEntity::whereIn('id', $entityIds)
                ->where('created_at', '>', now()->subDays($graceDays))
                ->pluck('id')
                ->all()
            : [];
        $triggered = [];

        foreach ($snapshots as $entityId => $snapshot) {
            $numVal = $this->metricValue((array) $snapshot->metrics, $numerator) ?? 0;
            $denVal = $this->metricValue((array) $snapshot->metrics, $denominator) ?? 0;

            if ($denVal == 0 || $denVal < $minDenominator) {
                continue;
            }

            if (in_array($entityId, $graceEntityIds, true)) {
                continue;
            }

            $ratio = $numVal / $denVal;

            $matches = match ($operator) {
                '>' => $ratio > $threshold,
                '>=' => $ratio >= $threshold,
                '<' => $ratio < $threshold,
                '<=' => $ratio <= $threshold,
                '==' => abs($ratio - $threshold) < 0.001,
                default => false,
            };

            if ($matches) {
                $entityName = $entities[$entityId] ?? "Entity #{$entityId}";
                $ratioFormatted = round($ratio * 100, 1);
                $thresholdFormatted = round($threshold * 100, 1);
                $triggered[] = [
                    'entity_id' => $entityId,
                    'message' => "{$entityName}: {$numerator}/{$denominator} = {$ratioFormatted}% ({$operator} {$thresholdFormatted}%)",
                    'metrics' => [$numerator => $numVal, $denominator => $denVal, 'ratio' => round($ratio, 4)],
                ];
            }
        }

        return $triggered;
    }

    /**
     * Einheit ohne Verantwortlichen: Träger (VSM-Klasse carrier) im Geltungsbereich, an denen niemand die Fähigkeit hält.
     * conditions: {"capability": "manage"} (optional). Empfänger ist der Verantwortliche darüber (er muss benennen).
     */
    protected function evaluateNoResponsible(array $conditions, array $entityIds): array
    {
        $capability = (string) ($conditions['capability'] ?? 'manage') ?: 'manage';
        $lookup = app(ResponsibilityLookup::class);
        $triggered = [];

        $carriers = OrganizationEntity::with('type:id,vsm_class')->whereIn('id', $entityIds)->get(['id', 'name', 'parent_entity_id', 'entity_type_id'])
            ->filter(fn ($e) => $e->type?->vsm_class === OrganizationEntityType::VSM_CLASS_CARRIER);

        foreach ($carriers as $unit) {
            if ($lookup->holdersAt((int) $unit->id, $capability) !== []) {
                continue;
            }
            $triggered[] = [
                'entity_id' => (int) $unit->id,
                'message' => "{$unit->name}: Einheit ohne Verantwortlichen – niemand hält hier die Fähigkeit „{$capability}“. Bitte einen Verantwortlichen eintragen.",
                'metrics' => ['holders' => 0, 'capability' => $capability],
                'recipient_from_entity_id' => $unit->parent_entity_id ? (int) $unit->parent_entity_id : null,
            ];
        }

        return $triggered;
    }

    /**
     * Wert einer Kennzahl aus einem Snapshot; bei units_only bevorzugt <metric>_cascaded (Summe über den Teilbaum).
     * null = Kennzahl fehlt.
     */
    protected function metricValue(array $metrics, string $metric)
    {
        if ($this->unitsOnly && isset($metrics[$metric . '_cascaded'])) {
            return $metrics[$metric . '_cascaded'];
        }

        return $metrics[$metric] ?? null;
    }

    /** Lesbarer Name einer Kennzahl – nur bei units_only (sonst bleibt die Meldung wie bisher beim Schlüssel). */
    protected function label(string $metric): string
    {
        if (! $this->unitsOnly) {
            return $metric;
        }
        if ($this->labels === null) {
            $this->labels = [];
            try {
                $registry = class_exists(\Platform\Organization\Services\EntityLinkRegistry::class) ? app(\Platform\Organization\Services\EntityLinkRegistry::class) : null;
                foreach ((array) $registry?->allMetricDefinitions() as $key => $def) {
                    if (! empty($def['label'])) {
                        $this->labels[$key] = (string) $def['label'];
                    }
                }
            } catch (\Throwable) {
                // ohne Kennzahl-Katalog bleibt es beim Schlüssel
            }
        }

        return $this->labels[$metric] ?? $metric;
    }

    /** Kennzahl, nach der die Beiträger einer Einheit sortiert werden. */
    protected function mainMetric(string $patternType, array $conditions): ?string
    {
        $key = match ($patternType) {
            'threshold' => $this->thresholdParts($conditions)[0]['metric'] ?? null,
            'trend' => $conditions['metric'] ?? null,
            'ratio' => $conditions['numerator'] ?? null,
            'cross_dimension' => $conditions['metric_a'] ?? null,
            default => null,
        };

        return $key ? (string) $key : null;
    }

    /**
     * Größte Beiträger je Einheit anhängen: Unterknoten (über parent_entity_id, ohne die Einheit selbst) mit dem
     * einfachen Wert der Hauptkennzahl > 0, höchstens MAX_CONTRIBUTORS. An einer eingebetteten Einheit wird nicht
     * weiter abgestiegen – sie zählt selbst als Beiträger (mit ihrer Teilbaum-Summe).
     * Gebündelt: eine Abfrage je Baumebene über alle Einheiten, eine für die Snapshots.
     */
    protected function withContributors(array $triggered, ?string $metric): array
    {
        if (! $metric) {
            return $triggered;
        }

        $rootOf = [];     // Knoten => Einheit, zu der er beiträgt
        $nested = [];     // eingebettete Einheiten (dort nicht weiter absteigen)
        $names = [];
        $queue = array_map(fn ($t) => (int) $t['entity_id'], $triggered);
        $owner = array_combine($queue, $queue);
        for ($depth = 0; $queue !== [] && $depth < 50; $depth++) {
            $children = OrganizationEntity::with('type:id,vsm_class')->whereIn('parent_entity_id', $queue)->where('is_active', true)
                ->get(['id', 'name', 'parent_entity_id', 'entity_type_id']);
            $next = [];
            foreach ($children as $child) {
                $id = (int) $child->id;
                if (isset($rootOf[$id])) {
                    continue; // schon gesehen (Mehrfachpfad/Zyklus)
                }
                $rootOf[$id] = $owner[(int) $child->parent_entity_id];
                $names[$id] = (string) $child->name;
                if ($child->type?->vsm_class === OrganizationEntityType::VSM_CLASS_CARRIER) {
                    $nested[$id] = true;
                    continue;
                }
                $owner[$id] = $rootOf[$id];
                $next[] = $id;
            }
            $queue = $next;
        }

        $snapshots = $this->getLatestSnapshots(array_keys($rootOf));
        $byUnit = [];
        foreach ($rootOf as $id => $unitId) {
            $metrics = (array) ($snapshots[$id]->metrics ?? []);
            $value = isset($nested[$id]) ? ($metrics[$metric . '_cascaded'] ?? $metrics[$metric] ?? null) : ($metrics[$metric] ?? null);
            if (is_numeric($value) && $value > 0) {
                $byUnit[$unitId][] = ['entity_id' => $id, 'name' => $names[$id], 'value' => $value + 0];
            }
        }

        foreach ($triggered as &$hit) {
            $list = $byUnit[(int) $hit['entity_id']] ?? [];
            usort($list, fn ($a, $b) => $b['value'] <=> $a['value'] ?: strcmp($a['name'], $b['name']));
            $list = array_slice($list, 0, self::MAX_CONTRIBUTORS);
            $hit['metrics']['contributors'] = $list;
            if ($list !== []) {
                $hit['message'] .= ' – vor allem: ' . implode(', ', array_map(fn ($c) => "{$c['name']} (" . (is_float($c['value']) ? round($c['value'], 1) : $c['value']) . ')', $list));
            }
        }
        unset($hit);

        return $triggered;
    }

    /**
     * Get the latest snapshot for each entity (no N+1).
     *
     * @return array<int, OrganizationEntitySnapshot>
     */
    protected function getLatestSnapshots(array $entityIds): array
    {
        if (empty($entityIds)) {
            return [];
        }

        $latestDates = OrganizationEntitySnapshot::whereIn('entity_id', $entityIds)
            ->select('entity_id', DB::raw('MAX(snapshot_date) as max_date'))
            ->groupBy('entity_id');

        $snapshots = OrganizationEntitySnapshot::joinSub($latestDates, 'latest', function ($join) {
            $join->on('organization_entity_snapshots.entity_id', '=', 'latest.entity_id')
                 ->on('organization_entity_snapshots.snapshot_date', '=', 'latest.max_date');
        })->get();

        return $snapshots->keyBy('entity_id')->all();
    }

    /**
     * Get entity names by IDs (batch load).
     */
    protected function getEntityNames(array $entityIds): array
    {
        return OrganizationEntity::whereIn('id', $entityIds)
            ->pluck('name', 'id')
            ->all();
    }
}
