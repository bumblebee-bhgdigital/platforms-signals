<?php

namespace Platform\Signals\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Signals\Models\SignalTemplate;
use Platform\Signals\Tools\Concerns\HandlesSignals;

class DeleteSignalTemplateTool implements ToolContract, ToolMetadataContract
{
    use HandlesSignals;

    public function getName(): string
    {
        return 'signals.templates.DELETE';
    }

    public function getDescription(): string
    {
        return 'DELETE /signals/templates/{id} - Löscht ein Template (Soft-Delete) und nimmt es aus allen Sets. Ausgelöste Signale bleiben erhalten.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'description' => 'ERFORDERLICH'], 'team_id' => ['type' => 'integer']], 'required' => ['id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            ['team' => $team, 'error' => $error] = $this->resolveTeam($arguments, $context);
            if ($error) {
                return $error;
            }
            $template = SignalTemplate::query()->where('team_id', $team->id)->find((int) ($arguments['id'] ?? 0));
            if (! $template) {
                return ToolResult::error('NOT_FOUND', 'Template nicht gefunden.');
            }
            $sets = $template->assignments()->count();
            $template->assignments()->delete();
            $template->delete();

            return ToolResult::success(['id' => $template->id, 'message' => "Template '{$template->name}' gelöscht (aus {$sets} Set(s) entfernt)."]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Löschen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return $this->writeMeta(['templates', 'delete']);
    }
}
