<?php

/**
 * Dashboard Livewire Component — Übersicht aller Signal-Sets des Teams.
 *
 * Zeigt die für den User sichtbaren Sets der Team-Familie (Sichtbarkeit via SignalSet::visibleTo),
 * Kennzahlen-Kacheln und das Anlegen neuer Sets. Verknüpfung mit Knoten: Sidebar bzw. Detailseite.
 */

namespace Platform\Signals\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\Organization\Models\OrganizationEntity;
use Platform\Organization\Services\EntityDimensionBridge;
use Platform\Signals\Enums\SignalSetStatus;
use Platform\Signals\Exceptions\SignalSetException;
use Platform\Signals\Livewire\Concerns\ResolvesTeamFamily;
use Platform\Signals\Models\SignalSet;
use Platform\Signals\Models\SignalSetNode;
use Platform\Signals\Services\SignalSetService;

class Dashboard extends Component
{
    use ResolvesTeamFamily;

    public string $statusFilter = '';

    public bool $showCreate = false;
    public array $form = [
        'title' => '',
        'description' => '',
    ];

    /**
     * Dispatch comms-Event
     *
     * Wird nach dem Rendern aufgerufen (Kommunikation/Notifications).
     */
    public function rendered()
    {
        $this->dispatch('comms', [
            'model' => null,
            'modelId' => null,
            'subject' => 'Signal-Sets',
            'description' => 'Übersicht aller Signal-Sets',
            'url' => route('signals.dashboard'),
            'source' => 'signals.dashboard',
            'recipients' => [],
            'meta' => ['view_type' => 'dashboard'],
        ]);
    }

    /** Liste aktualisieren, wenn Sets (z. B. in der Sidebar) verknüpft/gelöst werden. */
    #[On('updateSidebar')]
    public function refreshList(): void
    {
    }

    public function openCreate(): void
    {
        Gate::authorize('create', SignalSet::class);
        $this->reset('form');
        $this->resetValidation();
        $this->showCreate = true;
    }

    public function create()
    {
        Gate::authorize('create', SignalSet::class);

        $this->validate([
            'form.title' => 'required|string|max:255',
            'form.description' => 'nullable|string',
        ]);

        try {
            $set = app(SignalSetService::class)->create(Auth::user(), Auth::user()->currentTeam, $this->form);
        } catch (SignalSetException $e) {
            $this->addError('form.title', $e->getMessage());

            return null;
        }

        $this->showCreate = false;
        $this->dispatch('updateSidebar');

        return $this->redirect(route('signals.sets.show', $set), navigate: true);
    }

    public function render()
    {
        $user = Auth::user();
        $teamIds = $this->relevantTeamIds($user);

        $all = SignalSet::query()
            ->with(['owner:id,name', 'responsibleAgent:id,name', 'team:id,name'])
            ->whereIn('team_id', $teamIds)
            ->visibleTo($user)
            ->orderBy('title')
            ->get();

        // Knoten je Set (eine Abfrage); inherit_down aus den Zuordnungs-Einstellungen
        $nodesBySet = [];
        $links = EntityDimensionBridge::linksForLinkables([SignalSet::MORPH_ALIAS], $all->pluck('id')->all(), false);
        $entityNames = OrganizationEntity::whereIn('id', $links->pluck('entity_id')->filter()->unique()->all())->pluck('name', 'id');
        $inherit = SignalSetNode::query()->whereIn('signal_set_id', $all->pluck('id')->all())->get()
            ->mapWithKeys(fn ($r) => [$r->signal_set_id . ':' . $r->entity_id => (bool) $r->inherit_down]);
        foreach ($links as $link) {
            if ($link->entity_id) {
                $nodesBySet[$link->linkable_id][] = [
                    'id' => (int) $link->entity_id,
                    'name' => $entityNames[$link->entity_id] ?? '—',
                    'inherit' => $inherit[$link->linkable_id . ':' . $link->entity_id] ?? false,
                ];
            }
        }

        $sets = $this->statusFilter !== ''
            ? $all->filter(fn ($s) => $s->status->value === $this->statusFilter)->values()
            : $all;

        $stats = [
            'total' => $all->count(),
            'active' => $all->where('status', SignalSetStatus::ACTIVE)->count(),
            'draft' => $all->where('status', SignalSetStatus::DRAFT)->count(),
            'unlinked' => $all->filter(fn ($s) => empty($nodesBySet[$s->id]))->count(),
        ];

        return view('signals::livewire.dashboard', [
            'sets' => $sets,
            'nodesBySet' => $nodesBySet,
            'stats' => $stats,
            'multiTeam' => count($teamIds) > 1,
            'statusOptions' => array_map(fn (SignalSetStatus $s) => ['value' => $s->value, 'label' => $s->label()], SignalSetStatus::cases()),
        ])->layout('platform::layouts.app');
    }
}
