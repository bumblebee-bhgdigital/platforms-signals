<?php

namespace Platform\Signals\Tools\Concerns;

use Illuminate\Support\Facades\Gate;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\Signals\Models\SignalSet;

/** Gemeinsame Helfer der signal_sets.*-Tools (Team-Auflösung, Serialisierung, Autorisierung). */
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

    protected function authorizeSet(ToolContext $context, string $ability, SignalSet $set): ?ToolResult
    {
        return Gate::forUser($context->user)->allows($ability, $set)
            ? null
            : ToolResult::error('ACCESS_DENIED', "Keine Berechtigung ({$ability}) für Signal-Set #{$set->id}.");
    }

    /** @return array<string,mixed> */
    protected function setToArray(SignalSet $set): array
    {
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
            'responsible_agent_user_id' => ['type' => 'integer', 'description' => 'Verantwortlicher Agent (User-ID eines Agent-Users, type ai_user).'],
        ];
    }
}
