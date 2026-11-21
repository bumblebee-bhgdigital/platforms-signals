<?php

/**
 * Show Livewire Component — Detailseite eines Signal-Sets.
 *
 * Eigenschaften (Knoten mit Vererbung, Owner, Agent), Templates im Set (mit wirksamen Einstellungen,
 * Probelauf) und die aus dem Set ausgelösten Signale.
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

    public ?int $addTemplateId = null;
    public ?array $dryRun = null;

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

    public function addTemplate(): void
    {
        Gate::authorize('update', $this->signalSet);
        $template = \Platform\Signals\Models\SignalTemplate::query()->where('team_id', $this->signalSet->team_id)->find((int) $this->addTemplateId);
        if (! $template) {
            return;
        }
        app(\Platform\Signals\Services\SignalTemplateService::class)->attach($this->signalSet, $template, [
            'position' => (int) $this->signalSet->templateAssignments()->max('position') + 1,
        ]);
        $this->addTemplateId = null;
        $this->dryRun = null;
    }

    public function toggleTemplate(int $assignmentId): void
    {
        Gate::authorize('update', $this->signalSet);
        $a = $this->signalSet->templateAssignments()->whereKey($assignmentId)->first();
        $a?->update(['is_active' => ! $a->is_active]);
        $this->dryRun = null;
    }

    public function removeTemplate(int $assignmentId): void
    {
        Gate::authorize('update', $this->signalSet);
        $this->signalSet->templateAssignments()->whereKey($assignmentId)->delete();
        $this->dryRun = null;
    }

    /** Probelauf: was würde jetzt anschlagen? Schreibt nichts. */
    public function runDryRun(): void
    {
        Gate::authorize('view', $this->signalSet);
        if (! $this->signalSet->isActive()) {
            $this->dryRun = ['error' => 'Das Set ist nicht aktiv – ausgewertet wird erst nach dem Aktivieren.'];

            return;
        }
        $report = app(\Platform\Signals\Services\SignalEvaluationService::class)->run($this->signalSet->id, true, true);
        $names = OrganizationEntity::whereIn('id', collect($report['results'])->pluck('triggered')->flatten(1)->pluck('entity_id')->filter()->all())->pluck('name', 'id');
        $this->dryRun = [
            'created' => $report['created'],
            'resolved' => $report['resolved'],
            'nodes' => count(app(\Platform\Signals\Services\SignalEvaluationService::class)->scopeEntityIds($this->signalSet)),
            'rows' => collect($report['results'])->map(fn ($r) => [
                'template' => $r['template'] ?? ('#' . ($r['template_id'] ?? '?')),
                'error' => $r['error'] ?? null,
                'hits' => collect($r['triggered'] ?? [])->map(fn ($t) => ($names[$t['entity_id']] ?? '#' . $t['entity_id']) . ' – ' . $t['message'])->all(),
            ])->all(),
        ];
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

        $assignments = $set->templateAssignments()->with('template')->get()->filter(fn ($a) => $a->template)->values();
        $available = \Platform\Signals\Models\SignalTemplate::query()->where('team_id', $set->team_id)->where('is_active', true)
            ->whereNotIn('id', $assignments->pluck('signal_template_id'))->orderBy('kind')->orderBy('name')->get(['id', 'name', 'kind']);

        $signals = $set->signals()->whereIn('status', ['open', 'acknowledged'])->with('entity:id,name')
            ->orderByRaw("CASE severity WHEN 'algedonic' THEN 0 WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')->limit(50)->get();

        return view('signals::livewire.show', [
            'set' => $set,
            'nodes' => $nodes,
            'linkCandidates' => $this->linkCandidates(),
            'assignments' => $assignments,
            'availableTemplates' => $available,
            'signals' => $signals,
            'closedCount' => $set->signals()->whereIn('status', ['resolved', 'dismissed'])->count(),
        ])->layout('platform::layouts.app');
    }
}
