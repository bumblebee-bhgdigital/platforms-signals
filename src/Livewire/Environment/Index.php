<?php

namespace Platform\Signals\Livewire\Environment;

use Livewire\Component;
use Platform\Signals\Livewire\Concerns\ResolvesTeamFamily;
use Platform\Signals\Models\SignalEnvironmentSnapshot;
use Platform\Signals\Models\SignalEnvironmentSource;
use Platform\Signals\Services\EnvironmentPullService;

/** Umwelt (VSM S4): Quellen mit letztem Stand und Bewegung; abrufen, pausieren. Anlegen/Bewerten per MCP. */
class Index extends Component
{
    use ResolvesTeamFamily;

    public ?int $openSourceId = null;
    public ?string $message = null;

    private function sources()
    {
        return SignalEnvironmentSource::query()->whereIn('team_id', $this->relevantTeamIds(auth()->user()));
    }

    public function toggle(int $id): void
    {
        $s = $this->sources()->findOrFail($id);
        $s->update(['is_active' => ! $s->is_active]);
    }

    public function pullNow(int $id): void
    {
        $s = $this->sources()->findOrFail($id);
        $snap = app(EnvironmentPullService::class)->pullSource($s);
        $this->message = $snap ? "„{$s->name}“: neuer Stand ({$snap->metrics['new_items_count']} Einträge)." : "„{$s->name}“: nichts Neues.";
        $this->openSourceId = $s->id;
    }

    public function open(int $id): void
    {
        $this->openSourceId = $this->openSourceId === $id ? null : $id;
    }

    public function render()
    {
        $sources = $this->sources()->orderBy('cluster')->orderBy('name')->get();
        $latest = SignalEnvironmentSnapshot::query()->whereIn('source_id', $sources->pluck('id'))
            ->whereIn('id', fn ($q) => $q->selectRaw('MAX(id)')->from('signal_environment_snapshots')->groupBy('source_id'))
            ->get()->keyBy('source_id');
        $history = $this->openSourceId
            ? SignalEnvironmentSnapshot::query()->where('source_id', $this->openSourceId)->orderByDesc('snapshot_date')->limit(10)->get()
            : collect();

        return view('signals::livewire.environment.index', compact('sources', 'latest', 'history'))->layout('platform::layouts.app');
    }
}
