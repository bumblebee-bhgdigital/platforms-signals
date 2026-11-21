<?php

namespace Platform\Signals\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Signals\Models\Signal;
use Platform\Signals\Services\SignalEvaluationService;
use Platform\Signals\Tools\Concerns\HandlesSignals;

class ListSignalsTool implements ToolContract, ToolMetadataContract
{
    use HandlesSignals;

    public function getName(): string
    {
        return 'signals.signals.GET';
    }

    public function getDescription(): string
    {
        return 'GET /signals/signals - Ausgelöste Signale (algedonische Alarme) an Knoten. Default: nur bearbeitbare (open/acknowledged, nicht zurückgestellt), schwerste zuerst. '
            . 'Mit id: ein Signal im Detail inkl. Maßnahmen und Kommentaren. Filter: entity_id (+ include_children), status, severity, signal_set_id, signal_template_id, '
            . 'responsible_user_id (Signale, für die ein Agent zuständig ist), assignee_entity_id, source, include_snoozed.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'entity_id' => ['type' => 'integer'],
                'include_children' => ['type' => 'boolean', 'description' => 'Mit entity_id: auch alle Unterknoten.'],
                'status' => ['type' => 'string', 'enum' => Signal::STATUSES],
                'severity' => ['type' => 'string', 'enum' => Signal::SEVERITIES],
                'source' => ['type' => 'string', 'enum' => ['rule', 'inference', 'human', 'migration']],
                'signal_set_id' => ['type' => 'integer'],
                'signal_template_id' => ['type' => 'integer'],
                'responsible_user_id' => ['type' => 'integer'],
                'assignee_entity_id' => ['type' => 'integer'],
                'include_snoozed' => ['type' => 'boolean'],
                'limit' => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
                'offset' => ['type' => 'integer'],
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
            $q = Signal::query()->where('team_id', $team->id)->with(['set:id,title', 'template:id,name,kind', 'responsible:id,name', 'actions']);

            if (! empty($arguments['id'])) {
                $s = $q->find((int) $arguments['id']);

                return $s ? ToolResult::success(['signal' => $this->signalToArray($s, true)]) : ToolResult::error('NOT_FOUND', 'Signal nicht gefunden.');
            }

            if (! empty($arguments['status'])) {
                $q->where('status', $arguments['status']);
            } elseif (empty($arguments['include_snoozed'])) {
                $q->actionable();
            } else {
                $q->whereIn('status', ['open', 'acknowledged']);
            }
            foreach (['severity', 'source', 'signal_set_id', 'signal_template_id', 'responsible_user_id', 'assignee_entity_id'] as $f) {
                if (! empty($arguments[$f])) {
                    $q->where($f, $arguments[$f]);
                }
            }
            if (! empty($arguments['entity_id'])) {
                $id = (int) $arguments['entity_id'];
                $ids = ! empty($arguments['include_children'])
                    ? array_merge([$id], app(SignalEvaluationService::class)->descendantIds($id))
                    : [$id];
                $q->whereIn('entity_id', $ids);
            }

            $total = (clone $q)->count();
            $limit = min(200, max(1, (int) ($arguments['limit'] ?? 50)));
            $offset = max(0, (int) ($arguments['offset'] ?? 0));
            $signals = $q->orderByRaw("CASE severity WHEN 'algedonic' THEN 0 WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
                ->orderByDesc('created_at')->skip($offset)->take($limit)->get();

            return ToolResult::success([
                'signals' => $signals->map(fn ($s) => $this->signalToArray($s))->values()->all(),
                'pagination' => ['limit' => $limit, 'offset' => $offset, 'total' => $total, 'returned' => $signals->count(), 'has_more' => $offset + $signals->count() < $total],
                'team_id' => $team->id,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Signale: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return $this->writeMeta(['list', 'algedonic'], true);
    }
}
