<?php

namespace Platform\Signals\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Signals\Models\SignalMemoryEntry;
use Platform\Signals\Models\SignalSetTemplate;
use Platform\Signals\Tools\Concerns\HandlesSignals;

class RecordInferenceOutcomeTool implements ToolContract, ToolMetadataContract
{
    use HandlesSignals;

    public function getName(): string
    {
        return 'signals.inference.POST';
    }

    public function getDescription(): string
    {
        return 'POST /signals/inference - Ergebnis einer KI-Auswertung ohne Signal festhalten („nichts gefunden“). Pflicht: assignment_id, outcome=nothing. '
            . 'Optional note (Beobachtung, landet im Gedächtnis als prompt_experience oder – mit entity_id – als observation), confidence. Setzt die Zuordnung auf ausgewertet. '
            . 'Befunde stattdessen mit signals.signals.POST melden.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'assignment_id' => ['type' => 'integer', 'description' => 'ERFORDERLICH'],
                'outcome' => ['type' => 'string', 'enum' => ['nothing'], 'description' => 'ERFORDERLICH'],
                'note' => ['type' => 'string'],
                'entity_id' => ['type' => 'integer'],
                'confidence' => ['type' => 'number'],
                'team_id' => ['type' => 'integer'],
            ],
            'required' => ['assignment_id', 'outcome'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            ['team' => $team, 'error' => $error] = $this->resolveTeam($arguments, $context);
            if ($error) {
                return $error;
            }
            $a = SignalSetTemplate::query()->with(['template', 'set'])->find((int) ($arguments['assignment_id'] ?? 0));
            if (! $a || ! $a->set || (int) $a->set->team_id !== (int) $team->id) {
                return ToolResult::error('NOT_FOUND', 'Zuordnung nicht gefunden.');
            }
            $a->forceFill(['last_evaluated_at' => now(), 'last_error' => null])->save();

            $memoryId = null;
            if (trim((string) ($arguments['note'] ?? '')) !== '') {
                $memoryId = SignalMemoryEntry::create([
                    'team_id' => $team->id,
                    'entity_id' => $arguments['entity_id'] ?? null,
                    'signal_template_id' => $a->signal_template_id,
                    'memory_type' => ! empty($arguments['entity_id']) ? 'observation' : 'prompt_experience',
                    'content' => trim((string) $arguments['note']),
                    'confidence' => isset($arguments['confidence']) ? max(0, min(1, (float) $arguments['confidence'])) : 0.6,
                    'source_type' => 'inference',
                    'created_by_user_id' => $context->user?->id,
                ])->id;
            }

            return ToolResult::success(['assignment_id' => $a->id, 'memory_entry_id' => $memoryId, 'message' => "Auswertung '{$a->template?->name}' festgehalten: nichts gefunden."]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return $this->writeMeta(['inference', 'agent', 'do-nothing']);
    }
}
