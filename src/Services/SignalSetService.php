<?php

namespace Platform\Signals\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\Signals\Enums\SignalSetStatus;
use Platform\Signals\Exceptions\SignalSetException;
use Platform\Signals\Models\SignalSet;

/**
 * Use-Cases rund ums Signal-Set (analog ProjectCreationService/LifecycleService im Planner).
 *
 * Der Service autorisiert NICHT — Rechte werden an der Grenze (Livewire, MCP-Tool) per
 * Gate/SignalSetPolicy geprüft, wie im Planner. Er validiert nur Fachregeln.
 */
class SignalSetService
{
    /** Felder, die create()/update() aus $data übernehmen. */
    private const FIELDS = ['title', 'description', 'owner_user_id', 'responsible_agent_user_id'];

    // ── Lesen ────────────────────────────────────────────────────

    public function find(int $id, bool $withTrashed = false): ?SignalSet
    {
        $query = SignalSet::query();
        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->find($id);
    }

    /**
     * Sets eines Teams (optional Status-Filter). Sichtbarkeit ($viewer) = Graph-Scope wie die UI.
     *
     * @param int|int[] $teamIds
     */
    public function listForTeams(int|array $teamIds, ?User $viewer = null, ?SignalSetStatus $status = null): Collection
    {
        $query = SignalSet::query()
            ->whereIn('team_id', (array) $teamIds)
            ->orderBy('title');

        if ($viewer) {
            $query->visibleTo($viewer);
        }
        if ($status) {
            $query->status($status);
        }

        return $query->get();
    }

    // ── Anlegen / Ändern ─────────────────────────────────────────

    /**
     * @param array<string,mixed> $data title (Pflicht) + Felder aus self::FIELDS, optional status (draft|active)
     */
    public function create(User $creator, Team|int $team, array $data): SignalSet
    {
        $teamId = $team instanceof Team ? $team->id : $team;
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new SignalSetException('Titel ist erforderlich.');
        }

        $status = SignalSetStatus::DRAFT;
        if (! empty($data['status'])) {
            $status = SignalSetStatus::tryFrom((string) $data['status'])
                ?? throw new SignalSetException('status muss einer von ' . implode(', ', SignalSetStatus::values()) . ' sein.');
            if (! in_array($status, [SignalSetStatus::DRAFT, SignalSetStatus::ACTIVE], true)) {
                throw new SignalSetException('Neue Sets starten als draft oder active.');
            }
        }

        $attrs = $this->validated($data, $teamId);
        $attrs['title'] = $title;
        $attrs['team_id'] = $teamId;
        $attrs['created_by_user_id'] = $creator->id;
        $attrs['owner_user_id'] = $attrs['owner_user_id'] ?? $creator->id;
        $attrs['status'] = $status;

        return SignalSet::create($attrs)->refresh();
    }

    /** @param array<string,mixed> $data nur gesetzte Schlüssel werden geändert (null leert, wo erlaubt) */
    public function update(SignalSet $set, array $data): SignalSet
    {
        if (array_key_exists('title', $data) && trim((string) $data['title']) === '') {
            throw new SignalSetException('Titel darf nicht leer sein.');
        }

        $attrs = $this->validated($data, $set->team_id);
        if (array_key_exists('title', $data)) {
            $attrs['title'] = trim((string) $data['title']);
        }

        $set->fill($attrs)->save();

        return $set->refresh();
    }

    // ── Statuswechsel ────────────────────────────────────────────

    public function activate(SignalSet $set): SignalSet
    {
        if ($set->trashed()) {
            throw new SignalSetException('Ein gelöschtes Set kann nicht aktiviert werden.', 'INVALID_STATE');
        }
        if ($set->status !== SignalSetStatus::ACTIVE) {
            $set->status = SignalSetStatus::ACTIVE;
            $set->save();
        }

        return $set->refresh();
    }

    public function archive(SignalSet $set): SignalSet
    {
        if ($set->status !== SignalSetStatus::ARCHIVED) {
            $set->status = SignalSetStatus::ARCHIVED;
            $set->save();
        }

        return $set->refresh();
    }

    /** Soft-Delete (wiederherstellbar). Die Knoten-Verknüpfungen bleiben bestehen, wie beim Planner-Projekt. */
    public function delete(SignalSet $set): void
    {
        DB::transaction(fn () => $set->delete());
    }

    // ── intern ───────────────────────────────────────────────────

    /**
     * Validiert/normalisiert die Eingabefelder. Nur vorhandene Schlüssel kommen zurück.
     *
     * @return array<string,mixed>
     */
    private function validated(array $data, int $teamId): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $data) && $field !== 'title') {
                $out[$field] = $data[$field] === '' ? null : $data[$field];
            }
        }

        if (! empty($out['owner_user_id'])) {
            $owner = User::find((int) $out['owner_user_id']);
            if (! $owner || ! $owner->teams()->where('teams.id', $teamId)->exists()) {
                throw new SignalSetException('owner_user_id ist kein Mitglied des Teams.');
            }
        }

        if (! empty($out['responsible_agent_user_id'])) {
            $agent = User::find((int) $out['responsible_agent_user_id']);
            if (! $agent || $agent->type !== 'ai_user') {
                throw new SignalSetException('responsible_agent_user_id muss ein Agent-User (type ai_user) sein.');
            }
        }

        return $out;
    }
}
