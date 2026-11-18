<?php

namespace Platform\Signals\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Signals\Enums\SignalSetStatus;
use Platform\Signals\Models\SignalSet;
use Platform\Signals\Services\SignalSetService;
use Platform\Signals\Tools\Concerns\HandlesSignalSets;

class ListSignalSetsTool implements ToolContract, ToolMetadataContract
{
    use HandlesSignalSets;

    public function getName(): string
    {
        return 'signal_sets.GET';
    }

    public function getDescription(): string
    {
        return 'GET /signal-sets - Listet Signal-Sets des Teams (nur für den User sichtbare). Einzelnes Set: id=<set_id>. '
            . ''
            . '"Sets eines Knotens": entity_id=<Entity-ID> liefert direkt verknüpfte Sets (source=direct) und, mit include_inherited=true (Default), von Vorfahren nach unten vererbte (source=inherited, via_entity_id). '
            . 'Weitere Filter: status, unlinked=true (Sets ohne Knoten), search, limit/offset.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Optional: einzelnes Set anhand der ID.'],
                'team_id' => ['type' => 'integer', 'description' => 'Optional: Team-ID (Default: aktuelles Team).'],
                'entity_id' => ['type' => 'integer', 'description' => 'Optional: nur Sets, die an diesem Knoten wirken (direkt und optional vererbt).'],
                'include_inherited' => ['type' => 'boolean', 'description' => 'Nur mit entity_id: auch vererbte Sets einbeziehen (Default true).'],
                'unlinked' => ['type' => 'boolean', 'description' => 'Optional: nur Sets ohne Knoten-Zuordnung.'],
                'status' => ['type' => 'string', 'enum' => SignalSetStatus::values(), 'description' => 'Optional: Status-Filter.'],
                'search' => ['type' => 'string', 'description' => 'Optional: Suche in Titel/Beschreibung.'],
                'limit' => ['type' => 'integer', 'description' => 'Optional: max. Anzahl (Default 50, max 200).'],
                'offset' => ['type' => 'integer', 'description' => 'Optional: Offset.'],
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

            if (! empty($arguments['id'])) {
                $set = SignalSet::query()->where('team_id', $team->id)->find((int) $arguments['id']);
                if (! $set) {
                    return ToolResult::error('NOT_FOUND', 'Signal-Set nicht gefunden.');
                }
                if ($denied = $this->authorizeSet($context, 'view', $set)) {
                    return $denied;
                }

                return ToolResult::success(['signal_set' => $this->setToArray($set)]);
            }

            $query = SignalSet::query()->where('team_id', $team->id)->visibleTo($context->user);

            if (! empty($arguments['status'])) {
                $status = SignalSetStatus::tryFrom((string) $arguments['status']);
                if (! $status) {
                    return ToolResult::error('VALIDATION_ERROR', 'status muss einer von ' . implode(', ', SignalSetStatus::values()) . ' sein.');
                }
                $query->status($status);
            }

            if (! empty($arguments['search'])) {
                $term = '%' . $arguments['search'] . '%';
                $query->where(fn ($q) => $q->where('title', 'like', $term)->orWhere('description', 'like', $term));
            }

            $sourceBySet = [];
            if (! empty($arguments['entity_id'])) {
                $entries = app(SignalSetService::class)->setsForNode((int) $arguments['entity_id'], $arguments['include_inherited'] ?? true);
                foreach ($entries as $entry) {
                    $sourceBySet[$entry['set']->id] = ['source' => $entry['source'], 'via_entity_id' => $entry['via_entity_id']];
                }
                $query->whereIn('id', array_keys($sourceBySet) ?: [0]);
            }

            $sets = $query->orderBy('title')->get();

            if (! empty($arguments['unlinked'])) {
                $linked = \Platform\Organization\Services\EntityDimensionBridge::linksForLinkables(
                    [SignalSet::MORPH_ALIAS], $sets->pluck('id')->all(), false
                )->pluck('linkable_id')->map(fn ($id) => (int) $id)->all();
                $sets = $sets->reject(fn ($s) => in_array($s->id, $linked, true))->values();
            }

            $limit = min(max((int) ($arguments['limit'] ?? 50), 1), 200);
            $offset = max((int) ($arguments['offset'] ?? 0), 0);

            return ToolResult::success([
                'signal_sets' => $sets->slice($offset, $limit)->values()->map(fn ($s) => $this->setToArray($s) + ($sourceBySet[$s->id] ?? []))->all(),
                'total' => $sets->count(),
                'team_id' => $team->id,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Signal-Sets: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['signals', 'signal-sets', 'list'],
            'read_only' => true,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'safe',
            'idempotent' => true,
        ];
    }
}
