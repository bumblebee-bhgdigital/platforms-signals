<?php

namespace Platform\Signals\Livewire\Signal;

use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\Signals\Livewire\Concerns\ResolvesTeamFamily;
use Platform\Signals\Models\Signal;
use Platform\Signals\Models\SignalSet;

/** Alle ausgelösten Signale der eigenen Teams – Arbeitsliste (schwerste zuerst). */
class Index extends Component
{
    use ResolvesTeamFamily;

    #[Url]
    public string $status = 'actionable';
    #[Url]
    public string $severity = '';
    #[Url]
    public string $setId = '';
    #[Url]
    public bool $mine = false;

    public function render()
    {
        $teamIds = $this->relevantTeamIds(auth()->user());
        $q = Signal::query()->whereIn('team_id', $teamIds)->with(['entity:id,name', 'set:id,title', 'template:id,name,kind', 'responsible:id,name']);

        match ($this->status) {
            'actionable' => $q->actionable(),
            'snoozed' => $q->snoozed()->whereIn('status', ['open', 'acknowledged']),
            'closed' => $q->whereIn('status', ['resolved', 'dismissed']),
            default => $q->where('status', $this->status),
        };
        if ($this->severity !== '') {
            $q->where('severity', $this->severity);
        }
        if ($this->setId !== '') {
            $q->where('signal_set_id', (int) $this->setId);
        }
        if ($this->mine && auth()->id()) {
            $q->focusedBy(auth()->id());
        }

        $signals = $q->orderByRaw("CASE severity WHEN 'algedonic' THEN 0 WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')->limit(200)->get();

        $base = Signal::query()->whereIn('team_id', $teamIds);

        return view('signals::livewire.signal.index', [
            'signals' => $signals,
            'stats' => [
                'actionable' => (clone $base)->actionable()->count(),
                'critical' => (clone $base)->actionable()->whereIn('severity', ['critical', 'algedonic'])->count(),
                'escalated' => (clone $base)->actionable()->whereNotNull('escalated_at')->count(),
                'snoozed' => (clone $base)->snoozed()->whereIn('status', ['open', 'acknowledged'])->count(),
            ],
            'setOptions' => SignalSet::query()->whereIn('team_id', $teamIds)->orderBy('title')->get(['id', 'title'])
                ->map(fn ($s) => ['value' => (string) $s->id, 'label' => $s->title])->all(),
        ])->layout('platform::layouts.app');
    }
}
