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
            . 'Pflicht: signal_set_id (Alias set_id). Optional signal_template_id (Alias template_id): nur dieses Template. '
            . 'dry_run (Default true) zeigt nur, was anschlagen würde – nichts wird geschrieben, ideal zum Einstellen von Schwellen. '
            . 'Je Template in results[]: hits[] (entity, message, contributors, action new|open|reopen, im Probelauf recipients = wer es bekäme) '
            . 'und resolving[] (offene Signale, die sich auflösen würden, mit Notiz). force (Default true) ignoriert den Takt.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'signal_set_id' => ['type' => 'integer', 'description' => 'ERFORDERLICH (Alias set_id)'],
                'signal_template_id' => ['type' => 'integer', 'description' => 'Optional: nur dieses Template auswerten (Alias template_id).'],
                'dry_run' => ['type' => 'boolean', 'description' => 'Default true: nichts schreiben, nur berichten.'],
                'force' => ['type' => 'boolean', 'description' => 'Default true: Takt (daily/weekly) ignorieren.'],
                'team_id' => ['type' => 'integer'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            ['team' => $team, 'error' => $error] = $this->resolveTeam($arguments, $context);
            if ($error) {
                return $error;
            }
            $set = SignalSet::query()->where('team_id', $team->id)->find((int) ($arguments['signal_set_id'] ?? $arguments['set_id'] ?? 0));
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

            $templateId = (int) ($arguments['signal_template_id'] ?? $arguments['template_id'] ?? 0) ?: null;
            $force = ! array_key_exists('force', $arguments) || (bool) $arguments['force'];

            $report = app(SignalEvaluationService::class)->run($set->id, $force, $dryRun, null, $templateId);

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
