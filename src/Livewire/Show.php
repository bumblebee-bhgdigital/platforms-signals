<?php

/**
 * Show Livewire Component — Detailseite eines Signal-Sets.
 *
 * Eigenschaften (Knoten mit Vererbung, Owner, Agent) plus Platzhalter-Bereiche für Definitionen,
 * Kennzahlen, ausgelöste Signale und Agent (Messlogik folgt in eigenen Boards).
 */

namespace Platform\Signals\Livewire;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\Core\Livewire\Concerns\HasPageContext;
use Platform\Organization\Models\OrganizationEntity;
use Platform\Signals\Enums\SignalSetStatus;
use Platform\Signals\Exceptions\SignalSetException;
use Platform\Signals\Livewire\Concerns\LinksSetsToNodes;
use Platform\Signals\Models\SignalSet;
use Platform\Signals\Services\SignalSetService;

class Show extends Component
{
    use HasPageContext, LinksSetsToNodes;

    public SignalSet $signalSet;

    public bool $showEdit = false;
    public array $form = [];

    public function mount(SignalSet $signalSet): void
    {
        Gate::authorize('view', $signalSet);
        $this->signalSet = $signalSet;
    }

    protected function pageContext(): ?Model
    {
        return $this->signalSet;
    }

    protected function pageContextOptions(): array
    {
        return [
            'subject' => $this->signalSet->title,
            'url' => route('signals.sets.show', $this->signalSet),
            'source' => 'signals.set.view',
            'capabilities' => ['manage_channels' => true, 'threads' => false],
            'meta' => [
                'status' => $this->signalSet->status?->value,
                'created_at' => $this->signalSet->created_at,
            ],
        ];
    }

    #[On('updateSidebar')]
    public function refreshSet(): void
    {
        $this->signalSet->refresh();
    }

    public function openEdit(): void
    {
        Gate::authorize('update', $this->signalSet);
        $this->form = [
            'title' => $this->signalSet->title,
            'description' => $this->signalSet->description,
        ];
        $this->resetValidation();
        $this->showEdit = true;
    }

    public function save(): void
    {
        Gate::authorize('update', $this->signalSet);

        $this->validate([
            'form.title' => 'required|string|max:255',
            'form.description' => 'nullable|string',
        ]);

        try {
            $this->signalSet = app(SignalSetService::class)->update($this->signalSet, $this->form);
        } catch (SignalSetException $e) {
            $this->addError('form.title', $e->getMessage());

            return;
        }

        $this->showEdit = false;
        $this->dispatch('updateSidebar');
    }

    public function activate(): void
    {
        Gate::authorize('update', $this->signalSet);

        try {
            $this->signalSet = app(SignalSetService::class)->activate($this->signalSet);
        } catch (SignalSetException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->dispatch('updateSidebar');
    }

    public function archive(): void
    {
        Gate::authorize('update', $this->signalSet);

        $this->signalSet = app(SignalSetService::class)->archive($this->signalSet);
        $this->dispatch('updateSidebar');
    }

    public function deleteSet()
    {
        Gate::authorize('delete', $this->signalSet);

        app(SignalSetService::class)->delete($this->signalSet);
        $this->dispatch('updateSidebar');

        return $this->redirect(route('signals.dashboard'), navigate: true);
    }

    protected function afterLinkChange(): void
    {
        $this->cancelLinking();
        $this->signalSet->refresh();
        $this->dispatch('updateSidebar');
    }

    public function render()
    {
        $set = $this->signalSet;
        $assignments = app(SignalSetService::class)->nodeAssignments($set);
        $entities = $assignments === []
            ? collect()
            : OrganizationEntity::with('type:id,name')->whereIn('id', array_keys($assignments))->get()->keyBy('id');

        $nodes = collect($assignments)->map(fn ($inherit, $entityId) => [
            'entity' => $entities->get($entityId),
            'inherit' => $inherit,
        ])->filter(fn ($n) => $n['entity'])->values();

        return view('signals::livewire.show', [
            'set' => $set,
            'nodes' => $nodes,
            'linkCandidates' => $this->linkCandidates(),
        ])->layout('platform::layouts.app');
    }
}
