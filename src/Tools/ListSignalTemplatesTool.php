<?php

namespace Platform\Signals\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Signals\Models\SignalTemplate;
use Platform\Signals\Tools\Concerns\HandlesSignals;

class ListSignalTemplatesTool implements ToolContract, ToolMetadataContract
{
    use HandlesSignals;

    public function getName(): string
    {
        return 'signals.templates.GET';
    }

    public function getDescription(): string
    {
        return 'GET /signals/templates - Listet Signal-Templates (wiederverwendbare Signaldefinitionen) des Teams. '
            . 'kind=rule: Muster (threshold, trend, ratio, cross_dimension) mit conditions auf Kennzahlen der Knoten. '
            . 'kind=inference: Prompt + VSM-System + Kontextumfang, ausgewertet vom verantwortlichen Agenten des Sets. '
            . 'Mit id: ein Template inkl. der Sets, in denen es liegt (mit Overrides). Templates wirken erst, wenn sie in einem aktiven Set an einem Knoten liegen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Optional: ein Template im Detail.'],
                'kind' => ['type' => 'string', 'enum' => SignalTemplate::KINDS, 'description' => 'Optional: nur rule oder inference.'],
                'include_inactive' => ['type' => 'boolean', 'description' => 'Optional: auch deaktivierte Templates (Default false).'],
                'team_id' => ['type' => 'integer', 'description' => 'Optional: Team-ID (Default: aktuelles Team).'],
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
            $q = SignalTemplate::query()->where('team_id', $team->id);
            if (! empty($arguments['id'])) {
                $t = $q->find((int) $arguments['id']);

                return $t ? ToolResult::success(['template' => $this->templateToArray($t, true)]) : ToolResult::error('NOT_FOUND', 'Template nicht gefunden.');
            }
            if (! empty($arguments['kind'])) {
                $q->where('kind', $arguments['kind']);
            }
            if (empty($arguments['include_inactive'])) {
                $q->where('is_active', true);
            }
            $templates = $q->orderBy('kind')->orderBy('name')->get();

            return ToolResult::success([
                'templates' => $templates->map(fn ($t) => $this->templateToArray($t, true))->values()->all(),
                'count' => $templates->count(),
                'team_id' => $team->id,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Templates: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return $this->writeMeta(['templates', 'list'], true);
    }
}
