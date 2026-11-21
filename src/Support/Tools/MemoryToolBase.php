<?php

namespace Platform\Signals\Support\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Signals\Models\SignalMemoryEntry;
use Platform\Signals\Tools\Concerns\HandlesSignals;

/**
 * signals.memory.GET | POST | PUT | DELETE – Gedächtnis der Wahrnehmung. Die konkreten Tools setzen nur das Verb
 * (der ToolLoader instanziiert ohne Argumente).
 */
abstract class MemoryToolBase implements ToolContract, ToolMetadataContract
{
    use HandlesSignals;

    protected string $verb = 'GET';

    public function getName(): string
    {
        return 'signals.memory.' . $this->verb;
    }

    public function getDescription(): string
    {
        return match ($this->verb) {
            'GET' => 'GET /signals/memory - Gedächtnis der Wahrnehmung: Rückmeldungen zu Signalen (baseline, suppression) und Beobachtungen der Agenten. Filter: entity_id, signal_template_id, memory_type, include_inactive. Mit id: ein Eintrag.',
            'POST' => 'POST /signals/memory - Eintrag anlegen. Pflicht: memory_type (' . implode(', ', SignalMemoryEntry::TYPES) . '), content. Optional entity_id, signal_template_id, signal_id, structured_data, confidence (0–1), valid_until. Umzug: legacy_organization_memory_id, created_at, source_type.',
            'PUT' => 'PUT /signals/memory/{id} - Eintrag ändern (content, structured_data, confidence, valid_until, is_active). reinforce=true erhöht reinforcement_count und confidence (+0.1, max 1).',
            'DELETE' => 'DELETE /signals/memory/{id} - Eintrag löschen (Soft-Delete).',
        };
    }

    public function getSchema(): array
    {
        $fields = [
            'memory_type' => ['type' => 'string', 'enum' => SignalMemoryEntry::TYPES],
            'content' => ['type' => 'string'],
            'entity_id' => ['type' => 'integer'],
            'signal_template_id' => ['type' => 'integer'],
            'signal_id' => ['type' => 'integer'],
            'structured_data' => ['type' => 'object'],
            'confidence' => ['type' => 'number'],
            'valid_until' => ['type' => 'string'],
            'team_id' => ['type' => 'integer'],
        ];

        return match ($this->verb) {
            'GET' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'entity_id' => ['type' => 'integer'], 'signal_template_id' => ['type' => 'integer'], 'memory_type' => $fields['memory_type'], 'include_inactive' => ['type' => 'boolean'], 'limit' => ['type' => 'integer'], 'offset' => ['type' => 'integer'], 'team_id' => ['type' => 'integer']]],
            'POST' => ['type' => 'object', 'properties' => $fields + ['legacy_organization_memory_id' => ['type' => 'integer'], 'created_at' => ['type' => 'string'], 'source_type' => ['type' => 'string'], 'is_active' => ['type' => 'boolean'], 'reinforcement_count' => ['type' => 'integer']], 'required' => ['memory_type', 'content']],
            'PUT' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'is_active' => ['type' => 'boolean'], 'reinforce' => ['type' => 'boolean']] + $fields, 'required' => ['id']],
            'DELETE' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'team_id' => ['type' => 'integer']], 'required' => ['id']],
        };
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            ['team' => $team, 'error' => $error] = $this->resolveTeam($arguments, $context);
            if ($error) {
                return $error;
            }
            $q = SignalMemoryEntry::query()->where('team_id', $team->id);

            if ($this->verb === 'GET') {
                if (! empty($arguments['id'])) {
                    $m = $q->find((int) $arguments['id']);

                    return $m ? ToolResult::success(['entry' => $this->memoryToArray($m)]) : ToolResult::error('NOT_FOUND', 'Eintrag nicht gefunden.');
                }
                foreach (['entity_id', 'signal_template_id', 'memory_type'] as $f) {
                    if (! empty($arguments[$f])) {
                        $q->where($f, $arguments[$f]);
                    }
                }
                if (empty($arguments['include_inactive'])) {
                    $q->active()->valid();
                }
                $total = (clone $q)->count();
                $limit = min(200, max(1, (int) ($arguments['limit'] ?? 50)));
                $offset = max(0, (int) ($arguments['offset'] ?? 0));
                $rows = $q->orderByDesc('created_at')->skip($offset)->take($limit)->get();

                return ToolResult::success(['entries' => $rows->map(fn ($m) => $this->memoryToArray($m))->all(), 'pagination' => ['limit' => $limit, 'offset' => $offset, 'total' => $total, 'has_more' => $offset + $rows->count() < $total]]);
            }

            if ($this->verb === 'POST') {
                if (! in_array($arguments['memory_type'] ?? null, SignalMemoryEntry::TYPES, true) || trim((string) ($arguments['content'] ?? '')) === '') {
                    return ToolResult::error('VALIDATION_ERROR', 'memory_type (gültig) und content sind erforderlich.');
                }
                if (! empty($arguments['legacy_organization_memory_id'])) {
                    $existing = SignalMemoryEntry::withTrashed()->where('legacy_organization_memory_id', (int) $arguments['legacy_organization_memory_id'])->first();
                    if ($existing) {
                        return ToolResult::success(['entry' => $this->memoryToArray($existing), 'created' => false, 'message' => "Bereits übernommen als #{$existing->id}."]);
                    }
                }
                $data = array_intersect_key($arguments, array_flip(['memory_type', 'content', 'entity_id', 'signal_template_id', 'signal_id', 'structured_data', 'confidence', 'valid_until', 'legacy_organization_memory_id', 'created_at', 'source_type', 'is_active', 'reinforcement_count']));
                $m = SignalMemoryEntry::create($data + ['team_id' => $team->id, 'source_type' => 'manual', 'created_by_user_id' => $context->user?->id]);

                return ToolResult::success(['entry' => $this->memoryToArray($m), 'created' => true]);
            }

            $m = $q->find((int) ($arguments['id'] ?? 0));
            if (! $m) {
                return ToolResult::error('NOT_FOUND', 'Eintrag nicht gefunden.');
            }
            if ($this->verb === 'DELETE') {
                $m->delete();

                return ToolResult::success(['id' => $m->id, 'message' => 'Eintrag gelöscht.']);
            }

            $data = array_intersect_key($arguments, array_flip(['content', 'structured_data', 'confidence', 'valid_until', 'is_active', 'memory_type', 'entity_id']));
            if (! empty($arguments['reinforce'])) {
                $data['reinforcement_count'] = $m->reinforcement_count + 1;
                $data['confidence'] = min(1.0, (float) ($data['confidence'] ?? $m->confidence) + 0.1);
            }
            $m->update($data);

            return ToolResult::success(['entry' => $this->memoryToArray($m->fresh())]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    private function memoryToArray(SignalMemoryEntry $m): array
    {
        return [
            'id' => $m->id, 'entity_id' => $m->entity_id, 'entity_name' => $m->entity?->name, 'signal_template_id' => $m->signal_template_id,
            'signal_id' => $m->signal_id, 'memory_type' => $m->memory_type, 'content' => $m->content, 'structured_data' => $m->structured_data,
            'confidence' => $m->confidence, 'source_type' => $m->source_type, 'is_active' => $m->is_active, 'reinforcement_count' => $m->reinforcement_count,
            'valid_until' => $m->valid_until?->toIso8601String(), 'legacy_organization_memory_id' => $m->legacy_organization_memory_id,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }

    public function getMetadata(): array
    {
        return $this->writeMeta(['memory', 'learning', strtolower($this->verb)], $this->verb === 'GET');
    }
}
