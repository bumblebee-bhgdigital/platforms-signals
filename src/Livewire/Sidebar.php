<?php

/**
 * Sidebar Livewire Component
 *
 * Signal-Sets nach Knoten gruppiert (Entity-Typ -> Baum -> Sets), nicht verknüpfte Sets separat.
 * Struktur wie die Planner-Sidebar; ein Set kann unter mehreren Knoten erscheinen.
 *
 * Sichtbarkeit: SignalSet::visibleTo (Core-Graph). Verknüpfen/Umhängen/Lösen/Vererbung direkt hier
 * (LinksSetsToNodes); das UI-Event `updateSidebar` hält die Liste aktuell.
 */

namespace Platform\Signals\Livewire;

use Livewire\Attributes\On;
use Livewire\Component;
use Platform\Organization\Models\OrganizationEntity;
use Platform\Organization\Services\EntityAncestorService;
use Platform\Organization\Services\EntityDimensionBridge;
use Platform\Signals\Enums\SignalSetStatus;
use Platform\Signals\Livewire\Concerns\LinksSetsToNodes;
use Platform\Signals\Livewire\Concerns\ResolvesTeamFamily;
use Platform\Signals\Models\SignalSet;
use Platform\Signals\Models\SignalSetNode;

class Sidebar extends Component
{
    use ResolvesTeamFamily, LinksSetsToNodes;

    /** Neu rendern, wenn Sets angelegt/verknüpft/gelöst werden (auch von anderen Komponenten). */
    #[On('updateSidebar')]
    public function updateSidebar(): void
    {
    }

    public function render()
    {
        $user = auth()->user();
        $teamId = $user?->currentTeam?->id;

        $empty = [
            'entityTypeGroups' => collect(),
            'unlinkedSets' => collect(),
            'linkCandidates' => [],
            'openSignals' => 0,
            'mySignals' => 0,
        ];

        if (! $user || ! $teamId) {
            return view('signals::livewire.sidebar', $empty);
        }

        // Archivierte Sets bleiben als Historie über Übersicht/Detail erreichbar, nicht in der Navigation.
        $sets = SignalSet::query()
            ->whereIn('team_id', $this->relevantTeamIds($user))
            ->visibleTo($user)
            ->where('status', '!=', SignalSetStatus::ARCHIVED->value)
            ->orderBy('title')
            ->get();

        // Knoten-Verknüpfungen (DimensionLink, Alias 'signal_set'); ein Set kann an mehreren Knoten hängen
        $entitySetMap = []; // entity_id => [set_id]
        $linkedSetIds = [];
        $links = EntityDimensionBridge::linksForLinkables([SignalSet::MORPH_ALIAS], $sets->pluck('id')->all());
        foreach ($links as $link) {
            if (! $link->entity_id) {
                continue;
            }
            $entitySetMap[$link->entity_id][] = $link->linkable_id;
            $linkedSetIds[] = $link->linkable_id;
        }
        foreach ($entitySetMap as $entityId => $ids) {
            $entitySetMap[$entityId] = array_values(array_unique($ids));
        }
        $linkedSetIds = array_values(array_unique($linkedSetIds));

        // Vererbungs-Option je Zuordnung (set_id:entity_id => bool)
        $inheritMap = [];
        if ($linkedSetIds !== []) {
            foreach (SignalSetNode::query()->whereIn('signal_set_id', $linkedSetIds)->get(['signal_set_id', 'entity_id', 'inherit_down']) as $row) {
                $inheritMap[$row->signal_set_id . ':' . $row->entity_id] = (bool) $row->inherit_down;
            }
        }

        // Aufwärts-Traversierung: Tree-Parents (nur struktureller Baum, kein engagement_with-Channel)
        $ancestorService = new EntityAncestorService();
        $channels = [];
        foreach ($ancestorService->expandEntitiesWithAncestors(array_keys($entitySetMap), $channels) as $entityId) {
            $entitySetMap[$entityId] ??= [];
        }

        $entityTypeGroups = collect();
        if ($entitySetMap !== []) {
            $entities = OrganizationEntity::with('type')->whereIn('id', array_keys($entitySetMap))->get()->keyBy('id');
            $hierarchy = $ancestorService->buildParentChildrenMap($entities, $channels);
            $childrenMap = $hierarchy['parent_to_children'];

            $buildTree = function (int $entityId) use (&$buildTree, $entities, $childrenMap, $entitySetMap, $sets, $inheritMap): ?array {
                $entity = $entities->get($entityId);
                if (! $entity) {
                    return null;
                }

                $childNodes = collect($childrenMap[$entityId] ?? [])->map(fn ($id) => $buildTree($id))->filter();
                $childrenByType = $childNodes
                    ->groupBy(fn ($c) => $c['type_id'])
                    ->map(function ($group) use ($entities) {
                        $first = $group->first();
                        $type = $entities->get($first['entity_id'])?->type;

                        return [
                            'type_id' => $first['type_id'],
                            'type_name' => $type?->name ?? 'Sonstige',
                            'type_icon' => $type?->icon ?? null,
                            'sort_order' => $type?->sort_order ?? 999,
                            'children' => $group->sortBy('entity_name')->values(),
                        ];
                    })
                    ->sortBy('sort_order')->values();

                // aktive Sets zuerst, dann Entwürfe
                $nodeSets = collect($entitySetMap[$entityId] ?? [])
                    ->map(fn ($id) => $sets->firstWhere('id', $id))->filter()
                    ->sortBy(fn ($s) => [$s->status === SignalSetStatus::ACTIVE ? 0 : 1, $s->title])
                    ->values();

                $total = $nodeSets->count() + $childNodes->sum('total_sets');
                if ($total === 0) {
                    return null;
                }

                return [
                    'entity_id' => $entityId,
                    'entity_name' => $entity->name,
                    'type_id' => $entity->type?->id,
                    'sets' => $nodeSets,
                    'inherit' => $nodeSets->mapWithKeys(fn ($s) => [$s->id => $inheritMap[$s->id . ':' . $entityId] ?? false])->all(),
                    'children_by_type' => $childrenByType,
                    'total_sets' => $total,
                ];
            };

            $grouped = [];
            foreach ($hierarchy['roots'] as $entityId) {
                $entity = $entities->get($entityId);
                if (! $entity || ! $entity->type || ! ($tree = $buildTree($entityId))) {
                    continue;
                }
                $typeId = $entity->type->id;
                $grouped[$typeId] ??= [
                    'type_id' => $typeId,
                    'type_name' => $entity->type->name,
                    'type_icon' => $entity->type->icon,
                    'sort_order' => $entity->type->sort_order ?? 999,
                    'entities' => [],
                ];
                $grouped[$typeId]['entities'][] = $tree;
            }

            $entityTypeGroups = collect($grouped)->sortBy('sort_order')
                ->map(function ($g) {
                    $g['entities'] = collect($g['entities'])->sortBy('entity_name')->values();

                    return $g;
                })->values();
        }

        return view('signals::livewire.sidebar', [
            'entityTypeGroups' => $entityTypeGroups,
            'unlinkedSets' => $sets->reject(fn ($s) => in_array($s->id, $linkedSetIds, true))->values(),
            'linkCandidates' => $this->linkCandidates(),
            'openSignals' => \Platform\Signals\Models\Signal::query()->whereIn('team_id', $this->relevantTeamIds($user))->actionable()->count(),
            'mySignals' => \Platform\Signals\Models\Signal::query()->whereIn('team_id', $this->relevantTeamIds($user))->actionable()->forRecipient((int) $user->id)->count(),
        ]);
    }
}
