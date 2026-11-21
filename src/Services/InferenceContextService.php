<?php

namespace Platform\Signals\Services;

use Illuminate\Support\Collection;
use Platform\Organization\Models\OrganizationEntity;
use Platform\Signals\Enums\SignalSetStatus;
use Platform\Signals\Models\Signal;
use Platform\Signals\Models\SignalMemoryEntry;
use Platform\Signals\Models\SignalSetTemplate;

/**
 * Kontext für die Auswertung eines KI-Templates durch den verantwortlichen Agenten (per MCP statt Server-Runner).
 * Übernommen aus organization.signal_inference.evaluate; Geltungsbereich = Knoten des Sets statt pauschal alle Knoten.
 *
 * Signals liefert nur eigenes Wissen: Frage (prompt), Knoten im Geltungsbereich, offene Signale, Gedächtnis.
 * Daten (Kennzahlen, Korrespondenz, Aufnahmen, Aktivität, Umwelt, Strategie) liest der Agent selbst über die Tools der
 * zuständigen Module – Signals sammelt nichts vor.
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

        $entityIds = array_slice($this->evaluation->scopeEntityIds($set), 0, self::MAX_ENTITIES);
        $entities = OrganizationEntity::query()->whereIn('id', $entityIds)->with(['type:id,name', 'parent:id,name'])->get();
        $ids = $entities->pluck('id')->map(fn ($i) => (int) $i)->all();

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
                'interval_hours' => $assignment->effectiveIntervalHours() ?: 168,
                'last_evaluated_at' => $assignment->last_evaluated_at?->toIso8601String(),
            ],
            'nodes' => collect(app(SignalSetService::class)->nodeAssignments($set))->map(fn ($inherit, $id) => ['entity_id' => $id, 'inherit_down' => $inherit])->values()->all(),
            'entities' => $entities->map(fn ($e) => array_filter([
                'id' => $e->id,
                'name' => $e->name,
                'type' => $e->type?->name,
                'parent' => $e->parent?->name,
            ], fn ($v) => $v !== null))->values()->all(),
            'entities_truncated' => count($this->evaluation->scopeEntityIds($set)) > self::MAX_ENTITIES,
        ];

        $context['existing_signals'] = Signal::query()->whereIn('entity_id', $ids)->whereIn('status', ['open', 'acknowledged'])
            ->latest()->limit(50)->get(['id', 'entity_id', 'severity', 'message', 'status', 'signal_template_id', 'signal_set_id'])
            ->map(fn ($s) => $s->only(['id', 'entity_id', 'severity', 'message', 'status', 'signal_template_id', 'signal_set_id']))->all();
        $context['memory'] = $this->memory((int) $set->team_id, $ids, (int) $template->id);

        $context['how_to_answer'] = 'Beantworte die Frage aus prompt. Die Daten holst du dir selbst über die Tools der zuständigen Module '
            . '(tool_registry.SEARCH) – nur so viel, wie die Frage braucht. Für jedes echte Problem: signals.signals.POST mit signal_set_id, signal_template_id, entity_id, message, optional severity und 1–3 actions. '
            . 'Gibt es bereits ein offenes Signal zum Knoten (existing_signals), wird es aktualisiert statt dupliziert. Beachte memory.suppression (verworfene Fehlalarme). '
            . 'Nichts gefunden: signals.inference.POST mit assignment_id und outcome=nothing (optional note als Gedächtnis).';

        if ($markEvaluated) {
            $assignment->forceFill(['last_evaluated_at' => now(), 'last_error' => null])->save();
        }

        return $context;
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
