<?php

namespace Platform\Signals\Livewire\Template;

use Livewire\Component;
use Platform\Signals\Exceptions\SignalSetException;
use Platform\Signals\Livewire\Concerns\ResolvesTeamFamily;
use Platform\Signals\Models\SignalTemplate;
use Platform\Signals\Services\SignalTemplateService;

/** Templates des Teams: anlegen, bearbeiten, (de)aktivieren. In Sets gelegt werden sie auf der Set-Seite. */
class Index extends Component
{
    use ResolvesTeamFamily;

    public bool $showForm = false;
    public ?int $editId = null;
    public array $form = [];
    public string $conditionsJson = '';
    public string $dataSources = '';
    public ?string $error = null;

    private function teamId(): int
    {
        return (int) auth()->user()->currentTeam->getRootTeam()->id;
    }

    public function openCreate(string $kind = 'rule'): void
    {
        $this->editId = null;
        $this->form = ['kind' => $kind, 'name' => '', 'description' => '', 'severity' => 'warning', 'pattern_type' => 'threshold', 'frequency' => 'every_snapshot', 'prompt' => '', 'vsm_system' => 's3_star', 'dimension' => '', 'schedule_interval_hours' => 168, 'is_active' => true];
        $this->conditionsJson = json_encode(['metric' => 'items_open', 'operator' => '>', 'value' => 50], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $this->dataSources = 'snapshots, movement';
        $this->error = null;
        $this->showForm = true;
    }

    public function openEdit(int $id): void
    {
        $t = SignalTemplate::query()->whereIn('team_id', $this->relevantTeamIds(auth()->user()))->findOrFail($id);
        $this->editId = $t->id;
        $this->form = $t->only(['kind', 'name', 'description', 'severity', 'pattern_type', 'frequency', 'prompt', 'vsm_system', 'dimension', 'schedule_interval_hours', 'is_active']);
        $this->conditionsJson = json_encode($t->conditions ?? new \stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $this->dataSources = implode(', ', (array) $t->data_sources);
        $this->error = null;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->error = null;
        $data = $this->form;
        if (($data['kind'] ?? 'rule') === 'rule') {
            $conditions = json_decode($this->conditionsJson, true);
            if (! is_array($conditions)) {
                $this->error = 'Bedingungen sind kein gültiges JSON-Objekt.';

                return;
            }
            $data['conditions'] = $conditions;
            unset($data['prompt'], $data['vsm_system'], $data['dimension'], $data['schedule_interval_hours']);
        } else {
            $data['data_sources'] = array_values(array_filter(array_map('trim', explode(',', $this->dataSources))));
            unset($data['pattern_type'], $data['frequency']);
        }

        $service = app(SignalTemplateService::class);
        try {
            if ($this->editId) {
                unset($data['kind']);
                $service->update(SignalTemplate::query()->whereIn('team_id', $this->relevantTeamIds(auth()->user()))->findOrFail($this->editId), $data);
            } else {
                $service->create(auth()->user(), $this->teamId(), $data);
            }
        } catch (SignalSetException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $this->showForm = false;
    }

    public function toggle(int $id): void
    {
        $t = SignalTemplate::query()->whereIn('team_id', $this->relevantTeamIds(auth()->user()))->findOrFail($id);
        $t->update(['is_active' => ! $t->is_active]);
    }

    public function render()
    {
        $templates = SignalTemplate::query()->whereIn('team_id', $this->relevantTeamIds(auth()->user()))
            ->withCount(['assignments', 'signals as open_signals_count' => fn ($q) => $q->whereIn('status', ['open', 'acknowledged'])])
            ->orderBy('kind')->orderBy('name')->get();

        return view('signals::livewire.template.index', ['templates' => $templates])->layout('platform::layouts.app');
    }
}
