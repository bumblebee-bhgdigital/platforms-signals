<?php

namespace Platform\Signals\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Signals\Models\SignalSet;
use Platform\Signals\Services\SignalSetService;
use Platform\Signals\Tools\Concerns\HandlesSignalSets;

class DeleteSignalSetTool implements ToolContract, ToolMetadataContract
{
    use HandlesSignalSets;

    public function getName(): string
    {
        return 'signal_sets.DELETE';
    }

    public function getDescription(): string
    {
        return 'DELETE /signal-sets/{id} - Löscht ein Signal-Set (Soft-Delete, wiederherstellbar). Pflicht: id. '
            . 'Zum Beenden eines Sets ohne Löschen stattdessen signal_sets.PUT mit status=archived nutzen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'ID des Sets (ERFORDERLICH).'],
                'team_id' => ['type' => 'integer', 'description' => 'Optional: Team-ID (Default: aktuelles Team).'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            ['team' => $team, 'error' => $error] = $this->resolveTeam($arguments, $context);
            if ($error) {
                return $error;
            }
            $set = SignalSet::query()->where('team_id', $team->id)->find((int) ($arguments['id'] ?? 0));
            if (! $set) {
                return ToolResult::error('NOT_FOUND', 'Signal-Set nicht gefunden.');
            }
            if ($denied = $this->authorizeSet($context, 'delete', $set)) {
                return $denied;
            }

            $title = $set->title;
            $id = $set->id;
            app(SignalSetService::class)->delete($set);

            return ToolResult::success([
                'id' => $id,
                'title' => $title,
                'message' => "Signal-Set '{$title}' (#{$id}) gelöscht (Soft-Delete).",
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Löschen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['signals', 'signal-sets', 'delete'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'destructive',
            'idempotent' => false,
        ];
    }
}
