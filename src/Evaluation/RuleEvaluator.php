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
 */
class RuleEvaluator
{
    /** @return array<int, array{entity_id:int, message:string, metrics:array}> */
    public function evaluate(string $patternType, array $conditions, array $entityIds): array
    {
        if ($entityIds === []) {
            return [];
        }

        return match ($patternType) {
            'threshold' => $this->evaluateThreshold($conditions, $entityIds),
            'trend' => $this->evaluateTrend($conditions, $entityIds),
            'cross_dimension' => $this->evaluateCrossDimension($conditions, $entityIds),
            'ratio' => $this->evaluateRatio($conditions, $entityIds),
            'no_responsible' => $this->evaluateNoResponsible($conditions, $entityIds),
            default => [],
        };
    }

    /**
     * Threshold evaluation: compare a single metric against a value.
     * conditions: {"metric": "items_total", "operator": ">", "value": 100}
     */
    protected function evaluateThreshold(array $conditions, array $entityIds): array
    {
        $metric = $conditions['metric'] ?? null;
        $operator = $conditions['operator'] ?? '>';
        $threshold = $conditions['value'] ?? 0;

        if (! $metric) {
            return [];
        }

        $snapshots = $this->getLatestSnapshots($entityIds);
        $entities = $this->getEntityNames($entityIds);
        $triggered = [];

        foreach ($snapshots as $entityId => $snapshot) {
            // Fehlende Kennzahl = Einheit hat dazu keine Daten (z. B. keine Projekte) → nicht als 0 werten
            if (! isset($snapshot->metrics[$metric])) {
                continue;
            }
            $value = $snapshot->metrics[$metric];

            $matches = match ($operator) {
                '>' => $value > $threshold,
                '>=' => $value >= $threshold,
                '<' => $value < $threshold,
                '<=' => $value <= $threshold,
                '==' => $value == $threshold,
                '!=' => $value != $threshold,
                default => false,
            };

            if ($matches) {
                $entityName = $entities[$entityId] ?? "Entity #{$entityId}";
                $triggered[] = [
                    'entity_id' => $entityId,
                    'message' => "{$entityName}: {$metric} = {$value} ({$operator} {$threshold})",
                    'metrics' => [$metric => $value],
                ];
            }
        }

        return $triggered;
    }

    /**
     * Trend evaluation: check if metric moves in a direction over N periods.
     * conditions: {"metric": "time_total_minutes", "direction": "decreasing", "periods": 3, "min_change_percent": 10}
     */
    protected function evaluateTrend(array $conditions, array $entityIds): array
    {
        $metric = $conditions['metric'] ?? null;
        $direction = $conditions['direction'] ?? 'increasing'; // increasing or decreasing
        $periods = $conditions['periods'] ?? 3;
        $minChangePercent = $conditions['min_change_percent'] ?? 10;

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
            $firstValue = $recent->first()->metrics[$metric] ?? 0;

            for ($i = 1; $i < $recent->count(); $i++) {
                $prev = $recent[$i - 1]->metrics[$metric] ?? 0;
                $curr = $recent[$i]->metrics[$metric] ?? 0;
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

            $lastValue = $recent->last()->metrics[$metric] ?? 0;
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
                'message' => "{$entityName}: {$metric} {$dirLabel} über {$periods} Perioden ({$firstValue} → {$lastValue}, " . round($changePercent, 1) . '%)',
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

            $gapFirst = ($first->metrics[$metricA] ?? 0) - ($first->metrics[$metricB] ?? 0);
            $gapLast = ($last->metrics[$metricA] ?? 0) - ($last->metrics[$metricB] ?? 0);

            $isDiverging = abs($gapLast) > abs($gapFirst) * 1.2; // 20% threshold
            $isConverging = abs($gapLast) < abs($gapFirst) * 0.8;

            $matches = ($relationship === 'diverging' && $isDiverging)
                    || ($relationship === 'converging' && $isConverging);

            if ($matches) {
                $entityName = $entities[$entityId] ?? "Entity #{$entityId}";
                $relLabel = $relationship === 'diverging' ? 'divergieren' : 'konvergieren';
                $aVal = $last->metrics[$metricA] ?? 0;
                $bVal = $last->metrics[$metricB] ?? 0;
                $triggered[] = [
                    'entity_id' => $entityId,
                    'message' => "{$entityName}: {$metricA} und {$metricB} {$relLabel} ({$aVal} vs. {$bVal})",
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
            $numVal = $snapshot->metrics[$numerator] ?? 0;
            $denVal = $snapshot->metrics[$denominator] ?? 0;

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
