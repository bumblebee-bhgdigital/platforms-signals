<?php

namespace Platform\Signals\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Platform\Organization\Models\OrganizationEntity;
use Platform\Signals\Enums\SignalSetStatus;
use Platform\Signals\Evaluation\RuleEvaluator;
use Platform\Signals\Models\Signal;
use Platform\Signals\Models\SignalSet;
use Platform\Signals\Models\SignalSetTemplate;
use Platform\Signals\Models\SignalTemplate;

/**
 * Wertet die Regel-Templates aktiver Signal-Sets an deren Knoten aus.
 *
 * Unterschied zur Organisation: keine Auswertung pauschal über alle Knoten mehr, sondern nur dort,
 * wo ein Set hängt (direkt, mit inherit_down auch an allen Unterknoten). Takt je Zuordnung
 * (every_snapshot | daily | weekly). Ausgelöste Signale landen beim verantwortlichen Agenten des Sets.
 *
 * KI-Templates (kind=inference) wertet nicht der Server aus, sondern der Agent per MCP.
 */
class SignalEvaluationService
{
    public function __construct(
        protected RuleEvaluator $rules,
        protected SignalSetService $sets,
    ) {
    }

    /**
     * @param  int|null  $setId  nur dieses Set
     * @param  bool  $force  Takt ignorieren
     * @param  bool  $dryRun  nichts schreiben, nur berichten
     * @return array{sets:int, evaluated:int, created:int, resolved:int, skipped_not_due:int, results:array}
     */
    public function run(?int $setId = null, bool $force = false, bool $dryRun = false, ?int $teamId = null): array
    {
        $query = SignalSet::query()->where('status', SignalSetStatus::ACTIVE->value)
            ->with(['templateAssignments.template']);
        if ($setId) {
            $query->whereKey($setId);
        }
        if ($teamId) {
            $query->where('team_id', $teamId);
        }

        $report = ['sets' => 0, 'evaluated' => 0, 'created' => 0, 'resolved' => 0, 'skipped_not_due' => 0, 'results' => []];

        foreach ($query->get() as $set) {
            $report['sets']++;
            $entityIds = null; // lazy, nur wenn eine Zuordnung dran ist

            foreach ($set->templateAssignments as $assignment) {
                $template = $assignment->template;
                if (! $assignment->is_active || ! $template || ! $template->is_active || ! $template->isRule()) {
                    continue;
                }
                if (! $force && ! $assignment->isDue()) {
                    $report['skipped_not_due']++;
                    continue;
                }

                $entityIds ??= $this->scopeEntityIds($set);

                try {
                    $result = $this->evaluateAssignment($set, $assignment, $entityIds, $dryRun);
                    $report['evaluated']++;
                    $report['created'] += $result['created'];
                    $report['resolved'] += $result['resolved'];
                    $report['results'][] = $result;
                    if (! $dryRun) {
                        $assignment->forceFill(['last_evaluated_at' => now(), 'last_error' => null])->save();
                    }
                } catch (\Throwable $e) {
                    Log::warning('Signals: Auswertung fehlgeschlagen', ['set' => $set->id, 'template' => $template->id, 'error' => $e->getMessage()]);
                    $report['results'][] = ['set_id' => $set->id, 'template_id' => $template->id, 'error' => $e->getMessage()];
                    if (! $dryRun) {
                        $assignment->forceFill(['last_evaluated_at' => now(), 'last_error' => mb_substr($e->getMessage(), 0, 2000)])->save();
                    }
                }
            }
        }

        return $report;
    }

    /** @return array{set_id:int, template_id:int, template:string, nodes:int, triggered:array, created:int, resolved:int} */
    public function evaluateAssignment(SignalSet $set, SignalSetTemplate $assignment, array $entityIds, bool $dryRun = false): array
    {
        $template = $assignment->template;
        $conditions = $assignment->effectiveConditions();
        $entityIds = $this->filterByEntityType($entityIds, $conditions);
        $triggered = $this->rules->evaluate((string) $template->pattern_type, $conditions, $entityIds);
        $triggeredIds = array_map(fn ($t) => (int) $t['entity_id'], $triggered);

        $resolved = 0;
        $created = 0;

        // Signale, deren Bedingung nicht mehr erfüllt ist, schließen sich selbst (wie bisher).
        $stale = Signal::query()
            ->where('signal_set_id', $set->id)->where('signal_template_id', $template->id)
            ->whereIn('status', ['open', 'acknowledged'])
            ->when($triggeredIds !== [], fn ($q) => $q->whereNotIn('entity_id', $triggeredIds))
            ->get();
        foreach ($stale as $signal) {
            $resolved++;
            if (! $dryRun) {
                $signal->update([
                    'status' => 'resolved',
                    'resolved_at' => now(),
                    'acknowledged_at' => $signal->acknowledged_at ?? now(),
                    'resolution_note' => $signal->resolution_note ?: 'Automatisch aufgelöst: Bedingung nicht mehr erfüllt.',
                ]);
            }
        }

        foreach ($triggered as $hit) {
            $exists = Signal::query()
                ->where('signal_set_id', $set->id)->where('signal_template_id', $template->id)
                ->where('entity_id', $hit['entity_id'])
                ->whereIn('status', ['open', 'acknowledged'])
                ->exists();
            if ($exists) {
                continue;
            }
            $created++;
            if (! $dryRun) {
                Signal::create([
                    'team_id' => $set->team_id,
                    'signal_set_id' => $set->id,
                    'signal_template_id' => $template->id,
                    'source' => 'rule',
                    'source_type' => Signal::SOURCE_TYPE_RULE_CRON,
                    'entity_id' => $hit['entity_id'],
                    'status' => 'open',
                    'severity' => $assignment->effectiveSeverity(),
                    'message' => $hit['message'],
                    'trigger_metrics' => $hit['metrics'],
                    'responsible_user_id' => $set->responsible_agent_user_id,
                ]);
            }
        }

        return [
            'set_id' => $set->id,
            'template_id' => $template->id,
            'template' => $template->name,
            'nodes' => count($entityIds),
            'triggered' => $triggered,
            'created' => $created,
            'resolved' => $resolved,
        ];
    }

    /**
     * Optionaler Typ-Filter in den Bedingungen: entity_types (nur diese Knotentypen) bzw.
     * exclude_entity_types (diese nicht), jeweils Typ-Codes wie container, board, initiative.
     *
     * @param  int[]  $entityIds
     * @return int[]
     */
    public function filterByEntityType(array $entityIds, array $conditions): array
    {
        $only = array_values(array_filter((array) ($conditions['entity_types'] ?? [])));
        $except = array_values(array_filter((array) ($conditions['exclude_entity_types'] ?? [])));
        if ($entityIds === [] || ($only === [] && $except === [])) {
            return $entityIds;
        }

        return OrganizationEntity::query()->whereIn('id', $entityIds)
            ->whereHas('type', function ($q) use ($only, $except) {
                if ($only !== []) {
                    $q->whereIn('code', $only);
                }
                if ($except !== []) {
                    $q->whereNotIn('code', $except);
                }
            })
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Knoten, an denen ein Set wirkt: die verknüpften Knoten, bei inherit_down plus alle aktiven Unterknoten.
     *
     * @return int[]
     */
    public function scopeEntityIds(SignalSet $set): array
    {
        $ids = [];
        foreach ($this->sets->nodeAssignments($set) as $entityId => $inherit) {
            $ids[] = (int) $entityId;
            if ($inherit) {
                $ids = array_merge($ids, $this->descendantIds((int) $entityId));
            }
        }
        $ids = array_values(array_unique($ids));

        return $ids === [] ? [] : OrganizationEntity::query()->whereIn('id', $ids)->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return int[] */
    public function descendantIds(int $rootId): array
    {
        $ids = [];
        $queue = [$rootId];
        for ($depth = 0; $queue !== [] && $depth < 50; $depth++) {
            $children = OrganizationEntity::query()->whereIn('parent_entity_id', $queue)->where('is_active', true)
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
            $children = array_values(array_diff($children, $ids, [$rootId]));
            $ids = array_merge($ids, $children);
            $queue = $children;
        }

        return $ids;
    }

    /**
     * Templates, die an einem Knoten wirken (über direkte und vererbte Sets) – für Brief/Agenten.
     *
     * @return Collection<int, array{set: SignalSet, assignment: SignalSetTemplate, template: SignalTemplate, source: string}>
     */
    public function templatesForNode(int $entityId, ?string $kind = null): Collection
    {
        $out = collect();
        foreach ($this->sets->setsForNode($entityId) as $row) {
            $set = $row['set'];
            if ($set->status !== SignalSetStatus::ACTIVE) {
                continue;
            }
            foreach ($set->templateAssignments()->with('template')->get() as $a) {
                if (! $a->is_active || ! $a->template || ! $a->template->is_active) {
                    continue;
                }
                if ($kind && $a->template->kind !== $kind) {
                    continue;
                }
                $out->push(['set' => $set, 'assignment' => $a, 'template' => $a->template, 'source' => $row['source']]);
            }
        }

        return $out;
    }
}
