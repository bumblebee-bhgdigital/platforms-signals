<?php

namespace Platform\Signals\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Signals\Models\SignalSet;
use Platform\Signals\Services\SignalEvaluationService;
use Platform\Signals\Tools\Concerns\HandlesSignals;

class EvaluateSignalsTool implements ToolContract, ToolMetadataContract
{
    use HandlesSignals;

    public function getName(): string
    {
        return 'signals.evaluate.POST';
    }

    public function getDescription(): string
    {
        return 'POST /signals/evaluate - Wertet die Regel-Templates eines aktiven Signal-Sets sofort an dessen Knoten aus (sonst automatisch nach jedem Snapshot, 08:16 und 18:16). '
            . 'Pflicht: signal_set_id. dry_run=true zeigt nur, was anschlagen würde (nichts wird geschrieben) – ideal zum Einstellen von Schwellen. Der Takt wird hier ignoriert.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'signal_set_id' => ['type' => 'integer', 'description' => 'ERFORDERLICH'],
                'dry_run' => ['type' => 'boolean', 'description' => 'Default true.'],
                'team_id' => ['type' => 'integer'],
            ],
            'required' => ['signal_set_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            ['team' => $team, 'error' => $error] = $this->resolveTeam($arguments, $context);
            if ($error) {
                return $error;
            }
            $set = SignalSet::query()->where('team_id', $team->id)->find((int) ($arguments['signal_set_id'] ?? 0));
            if (! $set) {
                return ToolResult::error('NOT_FOUND', 'Signal-Set nicht gefunden.');
            }
            $dryRun = ! array_key_exists('dry_run', $arguments) || (bool) $arguments['dry_run'];
            if (! $dryRun && ($denied = $this->authorizeSet($context, 'update', $set))) {
                return $denied;
            }
            if (! $set->isActive()) {
                return ToolResult::error('VALIDATION_ERROR', 'Das Set ist nicht aktiv. Erst mit signals.signal_sets.PUT status=active aktivieren.');
            }

            $report = app(SignalEvaluationService::class)->run($set->id, true, $dryRun);

            return ToolResult::success(array_merge($report, ['dry_run' => $dryRun, 'nodes_in_scope' => count(app(SignalEvaluationService::class)->scopeEntityIds($set))]));
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler bei der Auswertung: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return $this->writeMeta(['evaluate', 'rule', 'dry-run']);
    }
}
