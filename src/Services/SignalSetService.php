<?php

namespace Platform\Signals\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\Signals\Enums\SignalSetStatus;
use Platform\Organization\Models\OrganizationEntity;
use Platform\Organization\Services\EntityDimensionBridge;
use Platform\Signals\Events\SignalSetLinkChanged;
use Platform\Signals\Exceptions\SignalSetException;
use Platform\Signals\Models\SignalSet;
use Platform\Signals\Models\SignalSetNode;

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

    // ── Knoten-Zuordnung (Dimension Bridge) ──────────────────────

    /** Knoten (Entity-IDs), an denen das Set hängt. @return int[] */
    public function nodeIds(SignalSet $set): array
    {
        return EntityDimensionBridge::linksForLinkables([SignalSet::MORPH_ALIAS], [$set->id], false)
            ->pluck('entity_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * Zuordnungen des Sets: entity_id => inherit_down. Knoten ohne Settings-Zeile (z. B. extern per
     * organization.entity.link.POST verknüpft) gelten als nicht vererbend.
     *
     * @return array<int,bool>
     */
    public function nodeAssignments(SignalSet $set): array
    {
        $inherit = SignalSetNode::query()->where('signal_set_id', $set->id)->pluck('inherit_down', 'entity_id');

        $out = [];
        foreach ($this->nodeIds($set) as $entityId) {
            $out[$entityId] = (bool) ($inherit[$entityId] ?? false);
        }

        return $out;
    }

    /*
     * Hinweis zu Events: Die Bridge löscht per Query (keine Model-Events). LINKED kommt daher beim
     * Anlegen aus dem DimensionLink-Hook (SignalsServiceProvider, greift auch bei externen
     * Verknüpfungen), UNLINKED/RELINKED/INHERIT_CHANGED dispatcht dieser Service.
     */

    /** Set an einen weiteren Knoten hängen (zusätzlich zu bestehenden Knoten). */
    public function link(SignalSet $set, int $entityId, bool $inheritDown = false): void
    {
        DB::transaction(function () use ($set, $entityId, $inheritDown) {
            EntityDimensionBridge::createLink($entityId, SignalSet::MORPH_ALIAS, $set->id, ['team_id' => $set->team_id]);
            EntityDimensionBridge::flush();

            SignalSetNode::query()->updateOrCreate(
                ['signal_set_id' => $set->id, 'entity_id' => $entityId],
                ['inherit_down' => $inheritDown],
            );
        });

    }

    /** Vererbung nach unten für eine bestehende Zuordnung ein-/ausschalten. */
    public function setInherit(SignalSet $set, int $entityId, bool $inheritDown): void
    {
        if (! in_array($entityId, $this->nodeIds($set), true)) {
            throw new SignalSetException("Das Set hängt nicht an Knoten {$entityId}.", 'NOT_LINKED');
        }

        SignalSetNode::query()->updateOrCreate(
            ['signal_set_id' => $set->id, 'entity_id' => $entityId],
            ['inherit_down' => $inheritDown],
        );

        SignalSetLinkChanged::dispatch($set, SignalSetLinkChanged::INHERIT_CHANGED, $entityId, null, $inheritDown);
    }

    /** Zuordnung zu einem Knoten lösen; ohne $entityId alle Knoten (Set wird "unverknüpft"). */
    public function unlink(SignalSet $set, ?int $entityId = null): void
    {
        $nodes = $entityId ? [$entityId] : $this->nodeIds($set);

        DB::transaction(function () use ($set, $nodes) {
            foreach ($nodes as $id) {
                EntityDimensionBridge::deleteLink($id, SignalSet::MORPH_ALIAS, $set->id);
            }
            SignalSetNode::query()->where('signal_set_id', $set->id)->whereIn('entity_id', $nodes)->delete();
        });
        EntityDimensionBridge::flush();

        foreach ($nodes as $id) {
            SignalSetLinkChanged::dispatch($set, SignalSetLinkChanged::UNLINKED, $id);
        }
    }

    /**
     * Umhängen: ersetzt die Zuordnung zu $fromEntityId durch $toEntityId. Die übrigen Knoten bleiben
     * unberührt (ein Set hängt an mehreren Knoten). Die Vererbungs-Option wandert mit.
     */
    public function relink(SignalSet $set, int $fromEntityId, int $toEntityId): void
    {
        $assignments = $this->nodeAssignments($set);
        if (! array_key_exists($fromEntityId, $assignments)) {
            throw new SignalSetException("Das Set hängt nicht an Knoten {$fromEntityId}.", 'NOT_LINKED');
        }
        if ($fromEntityId === $toEntityId) {
            return;
        }

        $inherit = $assignments[$fromEntityId];

        DB::transaction(function () use ($set, $fromEntityId, $toEntityId, $inherit) {
            EntityDimensionBridge::deleteLink($fromEntityId, SignalSet::MORPH_ALIAS, $set->id);
            SignalSetNode::query()->where('signal_set_id', $set->id)->where('entity_id', $fromEntityId)->delete();
            EntityDimensionBridge::createLink($toEntityId, SignalSet::MORPH_ALIAS, $set->id, ['team_id' => $set->team_id]);
            SignalSetNode::query()->updateOrCreate(
                ['signal_set_id' => $set->id, 'entity_id' => $toEntityId],
                ['inherit_down' => $inherit],
            );
        });
        EntityDimensionBridge::flush();

        SignalSetLinkChanged::dispatch($set, SignalSetLinkChanged::RELINKED, $toEntityId, $fromEntityId, $inherit);
    }

    /**
     * Sets, die an einem Knoten wirken: direkt verknüpfte und (optional) von Vorfahren vererbte.
     * Direkt hat Vorrang: hängt ein Set direkt UND vererbt am Knoten, erscheint es nur als "direct".
     *
     * @return SupportCollection<int, array{set: SignalSet, source: 'direct'|'inherited', via_entity_id: int}>
     */
    public function setsForNode(int $entityId, bool $includeInherited = true): SupportCollection
    {
        $result = collect();

        $direct = $this->setIdsAtNodes([$entityId]);
        foreach (SignalSet::query()->whereIn('id', $direct)->orderBy('title')->get() as $set) {
            $result->put($set->id, ['set' => $set, 'source' => 'direct', 'via_entity_id' => $entityId]);
        }

        if ($includeInherited) {
            foreach ($this->ancestorIds($entityId) as $ancestorId) {
                $inheritingIds = SignalSetNode::query()
                    ->where('entity_id', $ancestorId)->where('inherit_down', true)
                    ->pluck('signal_set_id')->map(fn ($id) => (int) $id)->all();
                $inheritingIds = array_values(array_diff(
                    array_intersect($inheritingIds, $this->setIdsAtNodes([$ancestorId])),
                    $result->keys()->all()
                ));
                if ($inheritingIds === []) {
                    continue;
                }
                foreach (SignalSet::query()->whereIn('id', $inheritingIds)->orderBy('title')->get() as $set) {
                    $result->put($set->id, ['set' => $set, 'source' => 'inherited', 'via_entity_id' => $ancestorId]);
                }
            }
        }

        return $result->values();
    }

    /** Vorfahren eines Knotens (nächster Elternknoten zuerst, strukturell über parent_entity_id). @return int[] */
    public function ancestorIds(int $entityId): array
    {
        $ids = [];
        $current = $entityId;
        for ($depth = 0; $depth < 50; $depth++) {
            $parent = OrganizationEntity::query()->whereKey($current)->value('parent_entity_id');
            if (! $parent || in_array((int) $parent, $ids, true)) {
                break;
            }
            $ids[] = (int) $parent;
            $current = (int) $parent;
        }

        return $ids;
    }

    /** @param int[] $entityIds @return int[] Set-IDs, die direkt an den Knoten hängen */
    private function setIdsAtNodes(array $entityIds): array
    {
        return EntityDimensionBridge::linksForEntities($entityIds)
            ->where('linkable_type', SignalSet::MORPH_ALIAS)
            ->pluck('linkable_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
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
