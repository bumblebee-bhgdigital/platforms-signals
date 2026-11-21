<?php

namespace Platform\Signals\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Signals\Models\SignalSetTemplate;
use Platform\Signals\Services\InferenceContextService;
use Platform\Signals\Tools\Concerns\HandlesSignals;

class GetInferenceWorkTool implements ToolContract, ToolMetadataContract
{
    use HandlesSignals;

    public function getName(): string
    {
        return 'signals.inference.GET';
    }

    public function getDescription(): string
    {
        return 'GET /signals/inference - Auswertung der KI-Templates durch den verantwortlichen Agenten (ersetzt den alten Server-Runner). '
            . 'Ohne assignment_id: Liste der fälligen KI-Zuordnungen (Set + Template) – als Agent standardmäßig nur die eigenen (verantwortlicher Agent des Sets); all=true auch nicht fällige, '
            . 'agent_user_id filtert auf einen Agenten. Mit assignment_id: vollständiger Kontext zur Auswertung (Prompt, Knoten des Sets mit Snapshots und Bewegung, '
            . 'Korrespondenz, Aufnahmen, Aktivität, Umwelt, Strategie je data_sources, offene Signale, Gedächtnis) und markiert die Zuordnung als ausgewertet. '
            . 'Danach: signals.signals.POST für Befunde bzw. signals.inference.POST outcome=nothing.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'assignment_id' => ['type' => 'integer', 'description' => 'Zuordnung (signal_set_templates.id) – liefert den Kontext.'],
                'all' => ['type' => 'boolean', 'description' => 'Auch nicht fällige Zuordnungen auflisten.'],
                'agent_user_id' => ['type' => 'integer', 'description' => 'Nur Sets dieses Agenten (Default als Agent: man selbst).'],
                'dry_run' => ['type' => 'boolean', 'description' => 'Mit assignment_id: Kontext laden, ohne als ausgewertet zu markieren.'],
                'team_id' => ['type' => 'integer'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            ['team' => $team, 'error' => $error] = $this->resolveTeam($arguments, $context);
            if ($error) {
                return $error;
            }
            $service = app(InferenceContextService::class);

            if (! empty($arguments['assignment_id'])) {
                $assignment = SignalSetTemplate::query()->with(['template', 'set'])->find((int) $arguments['assignment_id']);
                if (! $assignment || ! $assignment->set || (int) $assignment->set->team_id !== (int) $team->id || ! $assignment->template?->isInference()) {
                    return ToolResult::error('NOT_FOUND', 'KI-Zuordnung nicht gefunden (assignment_id = signal_set_templates.id eines KI-Templates).');
                }

                return ToolResult::success(['context' => $service->build($assignment, empty($arguments['dry_run']))]);
            }

            $agentId = $arguments['agent_user_id'] ?? (\Platform\Signals\Support\Agents::isAgent($context->user) ? $context->user->id : null);
            $due = $service->dueAssignments($team->id, $agentId ? (int) $agentId : null, ! empty($arguments['all']));

            return ToolResult::success([
                'work' => $due->map(fn ($a) => [
                    'assignment_id' => $a->id,
                    'signal_set_id' => $a->signal_set_id,
                    'signal_set' => $a->set?->title,
                    'responsible_agent_user_id' => $a->set?->responsible_agent_user_id,
                    'signal_template_id' => $a->signal_template_id,
                    'template' => $a->template?->name,
                    'vsm_system' => $a->template?->vsm_system,
                    'interval_hours' => $a->effectiveIntervalHours() ?: 168,
                    'last_evaluated_at' => $a->last_evaluated_at?->toIso8601String(),
                    'due' => $a->isDue(),
                ])->values()->all(),
                'count' => $due->count(),
                'message' => $due->isEmpty() ? 'Keine KI-Auswertung fällig.' : 'Je Zuordnung signals.inference.GET mit assignment_id aufrufen und auswerten.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return array_merge($this->writeMeta(['inference', 'agent', 'context', 'vsm']), ['category' => 'query']);
    }
}
