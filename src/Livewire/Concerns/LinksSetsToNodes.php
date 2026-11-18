<?php

namespace Platform\Signals\Livewire\Concerns;

use Illuminate\Support\Facades\Gate;
use Platform\Organization\Authz\DimensionLinkAuthorizer;
use Platform\Organization\Models\OrganizationEntity;
use Platform\Signals\Models\SignalSet;
use Platform\Signals\Services\SignalSetService;

/**
 * Verknüpfen / Umhängen / Lösen eines Signal-Sets an Knoten aus der UI (Sidebar, Detailseite).
 *
 * Nutzt die Dimension Bridge über SignalSetService (analog ProjectsCleanup::replaceLinks im Planner),
 * prüft Schreibrecht auf das Set (Policy) UND auf den Zielknoten (Core-Graph, writableEntityIds) und
 * dispatcht anschließend das UI-Event `updateSidebar`, auf das die Sidebar hört.
 *
 * Unterschied zum Planner: ein Set hängt an mehreren Knoten. "Verknüpfen" fügt einen Knoten hinzu
 * (linkingFromEntityId = null), "Umhängen" ersetzt genau die Zuordnung zu linkingFromEntityId.
 */
trait LinksSetsToNodes
{
    public ?int $linkingSetId = null;
    public ?int $linkingFromEntityId = null;
    /** Zeile (Knoten-ID, 0 = Unverknüpft-Liste), an der der Picker geöffnet wurde. */
    public ?int $linkingAnchor = null;
    public bool $linkInherit = false;
    public string $linkSearch = '';

    /** @param int|null $fromEntityId null = zusätzlichen Knoten hinzufügen, sonst von diesem Knoten umhängen */
    public function startLinking(int $setId, ?int $fromEntityId = null, ?int $anchor = null): void
    {
        Gate::authorize('update', SignalSet::findOrFail($setId));
        $this->linkingSetId = $setId;
        $this->linkingFromEntityId = $fromEntityId;
        $this->linkingAnchor = $anchor ?? $fromEntityId ?? 0;
        $this->linkInherit = false;
        $this->linkSearch = '';
    }

    public function cancelLinking(): void
    {
        $this->linkingSetId = null;
        $this->linkingFromEntityId = null;
        $this->linkingAnchor = null;
        $this->linkInherit = false;
        $this->linkSearch = '';
    }

    public function linkTo(int $entityId): void
    {
        $set = SignalSet::findOrFail((int) $this->linkingSetId);
        Gate::authorize('update', $set);
        $this->authorizeNode($entityId);

        $service = app(SignalSetService::class);
        if ($this->linkingFromEntityId) {
            $this->authorizeNode($this->linkingFromEntityId);
            $service->relink($set, $this->linkingFromEntityId, $entityId);
        } else {
            $service->link($set, $entityId, $this->linkInherit);
        }

        $this->afterLinkChange();
    }

    /** Zuordnung zu einem Knoten lösen. */
    public function unlinkNode(int $setId, int $entityId): void
    {
        $set = SignalSet::findOrFail($setId);
        Gate::authorize('update', $set);
        $this->authorizeNode($entityId);

        app(SignalSetService::class)->unlink($set, $entityId);

        $this->afterLinkChange();
    }

    /** Vererbung nach unten für die Zuordnung Set <-> Knoten umschalten. */
    public function toggleInherit(int $setId, int $entityId): void
    {
        $set = SignalSet::findOrFail($setId);
        Gate::authorize('update', $set);
        $this->authorizeNode($entityId);

        $service = app(SignalSetService::class);
        $service->setInherit($set, $entityId, ! ($service->nodeAssignments($set)[$entityId] ?? false));

        $this->afterLinkChange();
    }

    protected function authorizeNode(int $entityId): void
    {
        if (! in_array($entityId, (new DimensionLinkAuthorizer())->writableEntityIds(auth()->user()), true)) {
            abort(403, 'Kein Schreibrecht auf diesen Knoten.');
        }
    }

    protected function afterLinkChange(): void
    {
        $this->cancelLinking();
        $this->dispatch('updateSidebar');
    }

    /**
     * Kandidaten für den Knoten-Picker: Entities, auf die der User schreiben darf, optional gefiltert.
     * Knoten, an denen das Set bereits hängt, werden ausgelassen.
     *
     * @return array<int, array{id:int,name:string,type:?string}>
     */
    protected function linkCandidates(): array
    {
        $user = auth()->user();
        if (! $user || ! $this->linkingSetId) {
            return [];
        }

        $writable = (new DimensionLinkAuthorizer())->writableEntityIds($user);
        if ($writable === []) {
            return [];
        }

        $set = SignalSet::find($this->linkingSetId);
        $taken = $set ? array_keys(app(SignalSetService::class)->nodeAssignments($set)) : [];
        $search = trim($this->linkSearch);

        return OrganizationEntity::query()
            ->with('type:id,name')
            ->whereIn('id', array_values(array_diff($writable, $taken)))
            ->where('is_active', true)
            ->when($search !== '', fn ($q) => $q->where(fn ($s) => $s->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
            ->orderBy('name')
            ->limit($search !== '' ? 20 : 10)
            ->get(['id', 'name', 'entity_type_id'])
            ->map(fn ($e) => ['id' => (int) $e->id, 'name' => $e->name, 'type' => $e->type?->name])
            ->all();
    }
}
