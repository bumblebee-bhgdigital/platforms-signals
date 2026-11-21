<?php

namespace Platform\Signals\Services;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Platform\Organization\Models\OrganizationEntity;
use Platform\Organization\Models\OrganizationEntitySnapshot;
use Platform\Organization\Services\EntityDimensionBridge;
use Platform\Signals\Enums\SignalSetStatus;
use Platform\Signals\Models\Signal;
use Platform\Signals\Models\SignalMemoryEntry;
use Platform\Signals\Models\SignalSet;
use Platform\Signals\Models\SignalSetTemplate;

/**
 * Kontext für die Auswertung eines KI-Templates durch den verantwortlichen Agenten (per MCP statt Server-Runner).
 * Übernommen aus organization.signal_inference.evaluate; Geltungsbereich = Knoten des Sets statt pauschal alle Knoten.
 *
 * data_sources: snapshots, movement, correspondence, recordings, activity_log, environment, interlinks, strategy (alias zukunftsbild).
 */
class InferenceContextService
{
    public const MAX_ENTITIES = 150;

    public function __construct(protected SignalEvaluationService $evaluation)
    {
    }

    /**
     * Offene Arbeit: KI-Zuordnungen aktiver Sets, die laut Takt dran sind (optional nur für einen Agenten).
     *
     * @return Collection<int, SignalSetTemplate>
     */
    public function dueAssignments(int|array $teamIds, ?int $agentUserId = null, bool $includeNotDue = false): Collection
    {
        return SignalSetTemplate::query()
            ->where('is_active', true)
            ->whereHas('template', fn ($q) => $q->where('kind', 'inference')->where('is_active', true))
            ->whereHas('set', function ($q) use ($teamIds, $agentUserId) {
                $q->whereIn('team_id', (array) $teamIds)->where('status', SignalSetStatus::ACTIVE->value);
                if ($agentUserId) {
                    $q->where('responsible_agent_user_id', $agentUserId);
                }
            })
            ->with(['template', 'set'])
            ->orderBy('signal_set_id')->orderBy('position')
            ->get()
            ->filter(fn (SignalSetTemplate $a) => $includeNotDue || $a->isDue())
            ->values();
    }

    /** @return array<string,mixed> */
    public function build(SignalSetTemplate $assignment, bool $markEvaluated = true): array
    {
        $template = $assignment->template;
        $set = $assignment->set;
        $sources = array_map(fn ($s) => $s === 'zukunftsbild' ? 'strategy' : $s, (array) ($template->data_sources ?: ['snapshots', 'movement']));

        $entityIds = array_slice($this->evaluation->scopeEntityIds($set), 0, self::MAX_ENTITIES);
        $entities = OrganizationEntity::query()->whereIn('id', $entityIds)->with(['type:id,name,code', 'parent:id,name'])->get();
        $ids = $entities->pluck('id')->map(fn ($i) => (int) $i)->all();

        $snapshots = in_array('snapshots', $sources, true) || in_array('movement', $sources, true) ? $this->latestSnapshots($ids) : [];
        $movement = in_array('movement', $sources, true) ? $this->movement($ids) : [];

        $context = [
            'assignment' => [
                'id' => $assignment->id,
                'signal_set_id' => $set->id,
                'signal_set' => $set->title,
                'signal_template_id' => $template->id,
                'template' => $template->name,
                'vsm_system' => $template->vsm_system,
                'dimension' => $template->dimension,
                'severity' => $assignment->effectiveSeverity(),
                'prompt' => $template->prompt,
                'data_sources' => $sources,
                'interval_hours' => $assignment->effectiveIntervalHours() ?: 168,
                'last_evaluated_at' => $assignment->last_evaluated_at?->toIso8601String(),
            ],
            'nodes' => collect(app(SignalSetService::class)->nodeAssignments($set))->map(fn ($inherit, $id) => ['entity_id' => $id, 'inherit_down' => $inherit])->values()->all(),
            'entities' => $entities->map(fn ($e) => array_filter([
                'id' => $e->id,
                'name' => $e->name,
                'type' => $e->type?->code,
                'parent' => $e->parent?->name,
                'snapshot_latest' => $snapshots[$e->id] ?? null,
                'movement_7d' => $movement[$e->id] ?? null,
            ], fn ($v) => $v !== null))->values()->all(),
            'entities_truncated' => count($this->evaluation->scopeEntityIds($set)) > self::MAX_ENTITIES,
        ];

        if (in_array('correspondence', $sources, true)) {
            $context['correspondence'] = $this->correspondence($ids);
        }
        if (in_array('recordings', $sources, true)) {
            $context['recordings'] = $this->recordings($ids);
        }
        if (in_array('activity_log', $sources, true)) {
            $context['recent_activities'] = $this->activities($ids);
        }
        if (in_array('environment', $sources, true)) {
            $context['environment'] = $this->environment((int) $set->team_id);
        }
        if (in_array('interlinks', $sources, true)) {
            $context['interlinks'] = $this->interlinks($ids);
        }
        if (in_array('strategy', $sources, true)) {
            $context['strategy'] = $this->strategy($set);
        }

        $context['existing_signals'] = Signal::query()->whereIn('entity_id', $ids)->whereIn('status', ['open', 'acknowledged'])
            ->latest()->limit(50)->get(['id', 'entity_id', 'severity', 'message', 'status', 'signal_template_id', 'signal_set_id'])
            ->map(fn ($s) => $s->only(['id', 'entity_id', 'severity', 'message', 'status', 'signal_template_id', 'signal_set_id']))->all();
        $context['memory'] = $this->memory((int) $set->team_id, $ids, (int) $template->id);

        $context['how_to_answer'] = 'Beantworte die Frage aus prompt mit den Daten. Für jedes echte Problem: signals.signals.POST mit signal_set_id, signal_template_id, entity_id, message, optional severity und 1–3 actions. '
            . 'Gibt es bereits ein offenes Signal zum Knoten (existing_signals), wird es aktualisiert statt dupliziert. Beachte memory.suppression (verworfene Fehlalarme). '
            . 'Nichts gefunden: signals.inference.POST mit assignment_id und outcome=nothing (optional note als Gedächtnis).';

        if ($markEvaluated) {
            $assignment->forceFill(['last_evaluated_at' => now(), 'last_error' => null])->save();
        }

        return $context;
    }

    /** @return array<int,array> */
    protected function latestSnapshots(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return OrganizationEntitySnapshot::query()->whereIn('entity_id', $ids)
            ->whereIn('id', fn ($q) => $q->selectRaw('MAX(id)')->from('organization_entity_snapshots')->whereIn('entity_id', $ids)->groupBy('entity_id'))
            ->get()->mapWithKeys(fn ($s) => [(int) $s->entity_id => $s->metrics])->all();
    }

    protected function movement(array $ids): array
    {
        try {
            return resolve(\Platform\Organization\Services\SnapshotMovementService::class)->forEntitiesBatch($ids, 7);
        } catch (\Throwable) {
            return [];
        }
    }

    protected function correspondence(array $ids): array
    {
        try {
            $threadIds = EntityDimensionBridge::linksForEntities($ids)->where('linkable_type', 'correspondence_thread')->pluck('linkable_id')->unique()->all();
            $threadClass = Relation::getMorphedModel('correspondence_thread');
            if ($threadIds === [] || ! $threadClass || ! class_exists($threadClass)) {
                return ['threads_30d' => 0, 'recent_items' => []];
            }
            $threads = $threadClass::whereIn('id', $threadIds)->where('latest_item_at', '>=', now()->subDays(30))->get();
            $itemClass = Relation::getMorphedModel('correspondence_item');
            $items = ($itemClass && class_exists($itemClass))
                ? $itemClass::whereIn('thread_id', $threads->pluck('id'))->where('correspondence_date', '>=', now()->subDays(30))
                    ->latest('correspondence_date')->limit(10)->get(['id', 'thread_id', 'direction', 'correspondence_date'])
                    ->map(fn ($i) => ['direction' => $i->direction, 'date' => $i->correspondence_date?->format('Y-m-d')])->all()
                : [];

            return ['threads_30d' => $threads->count(), 'recent_items' => $items];
        } catch (\Throwable) {
            return ['threads_30d' => 0, 'recent_items' => []];
        }
    }

    protected function recordings(array $ids): array
    {
        try {
            $recIds = EntityDimensionBridge::linksForEntities($ids)->where('linkable_type', 'whisper_recording')->pluck('linkable_id')->unique()->all();
            $class = Relation::getMorphedModel('whisper_recording');
            if ($recIds === [] || ! $class || ! class_exists($class)) {
                return [];
            }

            return $class::whereIn('id', $recIds)->where('created_at', '>=', now()->subDays(30))->latest()->limit(5)
                ->get(['id', 'title', 'summary', 'action_items', 'created_at'])
                ->map(fn ($r) => [
                    'title' => $r->title,
                    'summary' => $r->summary ? mb_substr($r->summary, 0, 500) : null,
                    'action_items' => $r->action_items ? mb_substr((string) (is_array($r->action_items) ? json_encode($r->action_items) : $r->action_items), 0, 500) : null,
                    'date' => $r->created_at?->format('Y-m-d'),
                ])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    protected function activities(array $ids): array
    {
        try {
            if (! class_exists(\Platform\ActivityLog\Models\ActivityLogActivity::class)) {
                return [];
            }
            $type = array_search(OrganizationEntity::class, Relation::morphMap(), true) ?: OrganizationEntity::class;

            return \Platform\ActivityLog\Models\ActivityLogActivity::where('activityable_type', $type)->whereIn('activityable_id', $ids)
                ->where('created_at', '>=', now()->subDays(14))->latest()->limit(20)
                ->get(['id', 'activity_type', 'name', 'message', 'activityable_id', 'created_at'])
                ->map(fn ($a) => [
                    'entity_id' => $a->activityable_id, 'type' => $a->activity_type, 'name' => $a->name,
                    'message' => $a->message ? mb_substr($a->message, 0, 200) : null, 'date' => $a->created_at?->format('Y-m-d'),
                ])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    protected function environment(int $teamId): array
    {
        try {
            if (class_exists(\Platform\Signals\Services\EnvironmentContextService::class)) {
                return app(\Platform\Signals\Services\EnvironmentContextService::class)->buildInferenceContext($teamId);
            }
            if (class_exists(\Platform\Organization\Services\EnvironmentMovementService::class)) {
                return (new \Platform\Organization\Services\EnvironmentMovementService())->buildInferenceContext($teamId);
            }
        } catch (\Throwable) {
        }

        return [];
    }

    protected function interlinks(array $ids): array
    {
        try {
            return \Platform\Organization\Models\OrganizationEntityRelationship::query()
                ->where(fn ($q) => $q->whereIn('from_entity_id', $ids)->orWhereIn('to_entity_id', $ids))
                ->with('relationType:id,name,code')->limit(100)->get()
                ->map(fn ($r) => ['from' => $r->from_entity_id, 'to' => $r->to_entity_id, 'type' => $r->relationType?->code])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** Strategie der Knoten des Sets (Management Book über die StrategySource der Organisation). */
    protected function strategy(SignalSet $set): array
    {
        if (! class_exists(\Platform\Organization\Strategy\StrategySources::class) || ! \Platform\Organization\Strategy\StrategySources::has()) {
            return [];
        }
        $out = [];
        foreach (array_keys(app(SignalSetService::class)->nodeAssignments($set)) as $nodeId) {
            $node = OrganizationEntity::find($nodeId);
            if ($node && ($s = \Platform\Organization\Strategy\StrategySources::forEntity($node))) {
                $out[] = ['entity_id' => $nodeId] + $s;
            }
        }

        return $out;
    }

    protected function memory(int $teamId, array $ids, int $templateId): array
    {
        $base = fn () => SignalMemoryEntry::query()->where('team_id', $teamId)->active()->valid();

        return [
            'entity_memory' => $base()->whereIn('entity_id', $ids)->whereIn('memory_type', ['entity_profile', 'baseline', 'suppression', 'relationship', 'observation'])
                ->orderByDesc('confidence')->limit(50)->get()
                ->map(fn ($m) => ['entity_id' => $m->entity_id, 'type' => $m->memory_type, 'content' => $m->content, 'confidence' => $m->confidence])->all(),
            'template_experience' => $base()->where('signal_template_id', $templateId)->whereIn('memory_type', ['prompt_experience', 'suppression'])
                ->orderByDesc('confidence')->limit(10)->get()
                ->map(fn ($m) => ['type' => $m->memory_type, 'content' => $m->content, 'confidence' => $m->confidence])->all(),
        ];
    }
}
