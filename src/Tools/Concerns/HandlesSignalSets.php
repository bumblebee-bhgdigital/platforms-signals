<?php

namespace Platform\Signals\Tools\Concerns;

use Illuminate\Support\Facades\Gate;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\Organization\Models\OrganizationEntity;
use Platform\Signals\Models\SignalSet;
use Platform\Signals\Services\SignalSetService;

/** Gemeinsame Helfer der signals.signal_sets.*-Tools (Team-Auflösung, Serialisierung, Autorisierung). */
trait HandlesSignalSets
{
    /** @return array{team: Team|null, error: ToolResult|null} */
    protected function resolveTeam(array $arguments, ToolContext $context): array
    {
        if (! $context->user) {
            return ['team' => null, 'error' => ToolResult::error('AUTH_ERROR', 'Kein User im Kontext gefunden.')];
        }

        $teamId = $arguments['team_id'] ?? null;
        if ($teamId === 0 || $teamId === '0') {
            $teamId = null;
        }
        $teamId = $teamId ?: $context->team?->id;
        if (! $teamId) {
            return ['team' => null, 'error' => ToolResult::error('MISSING_TEAM', 'Kein Team angegeben und kein Team im Kontext. Nutze "core.teams.GET".')];
        }

        $team = $context->user->teams()->where('teams.id', (int) $teamId)->first();
        if (! $team) {
            return ['team' => null, 'error' => ToolResult::error('ACCESS_DENIED', "Kein Zugriff auf Team {$teamId}. Nutze 'core.teams.GET'.")];
        }

        return ['team' => $team, 'error' => null];
    }

    /**
     * Darf der User an diesen Knoten verknüpfen? Gleiche Regel wie die Terminal-App "Verknüpfungen":
     * Entity muss im Schreib-Reichweitenbereich des Users liegen (Core-Graph, kein eigenes Rollensystem).
     */
    protected function canWriteEntity(ToolContext $context, int $entityId): bool
    {
        if (! class_exists(\Platform\Organization\Authz\DimensionLinkAuthorizer::class)) {
            return false;
        }

        return in_array($entityId, (new \Platform\Organization\Authz\DimensionLinkAuthorizer())->writableEntityIds($context->user), true)
            && OrganizationEntity::whereKey($entityId)->exists();
    }

    protected function authorizeSet(ToolContext $context, string $ability, SignalSet $set): ?ToolResult
    {
        return Gate::forUser($context->user)->allows($ability, $set)
            ? null
            : ToolResult::error('ACCESS_DENIED', "Keine Berechtigung ({$ability}) für Signal-Set #{$set->id}.");
    }

    /** @return array<string,mixed> */
    protected function setToArray(SignalSet $set): array
    {
        $assignments = app(SignalSetService::class)->nodeAssignments($set);
        $names = $assignments === [] ? collect() : OrganizationEntity::whereIn('id', array_keys($assignments))->pluck('name', 'id');

        return [
            'id' => $set->id,
            'uuid' => $set->uuid,
            'team_id' => $set->team_id,
            'title' => $set->title,
            'description' => $set->description,
            'status' => $set->status?->value,
            'status_label' => $set->status?->label(),
            'owner_user_id' => $set->owner_user_id,
            'responsible_agent_user_id' => $set->responsible_agent_user_id,
            'created_by_user_id' => $set->created_by_user_id,
            'nodes' => array_map(
                fn ($id, $inherit) => ['entity_id' => $id, 'entity_name' => $names[$id] ?? null, 'inherit_down' => $inherit],
                array_keys($assignments),
                array_values($assignments)
            ),
            'templates' => $set->templateAssignments()->with('template')->get()->map(fn ($a) => [
                'signal_template_id' => $a->signal_template_id,
                'name' => $a->template?->name,
                'kind' => $a->template?->kind,
                'is_active' => $a->is_active,
                'severity' => $a->effectiveSeverity(),
                'has_override' => (bool) ($a->conditions_override || $a->severity_override || $a->frequency_override || $a->schedule_interval_hours_override),
                'last_evaluated_at' => $a->last_evaluated_at?->toIso8601String(),
            ])->values()->all(),
            'open_signals' => $set->signals()->whereIn('status', ['open', 'acknowledged'])->count(),
            'created_at' => $set->created_at?->toIso8601String(),
            'updated_at' => $set->updated_at?->toIso8601String(),
        ];
    }

    /** Gemeinsame Property-Definitionen für POST/PUT. */
    protected function setFieldSchema(): array
    {
        return [
            'title' => ['type' => 'string', 'description' => 'Titel des Signal-Sets.'],
            'description' => ['type' => 'string', 'description' => 'Beschreibung / Zweck des Sets.'],
            'owner_user_id' => ['type' => 'integer', 'description' => 'Owner (User-ID, Mitglied des Teams). Default beim Anlegen: aufrufender User.'],
            'responsible_agent_user_id' => ['type' => 'integer', 'description' => 'Verantwortlicher Agent (User-ID eines Agenten: Konto am Agent-Knoten der Organisation oder type ai_user).'],
        ];
    }
}
