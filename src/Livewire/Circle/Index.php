<?php

namespace Platform\Signals\Livewire\Circle;

use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\Signals\Exceptions\SignalSetException;
use Platform\Signals\Livewire\Concerns\ResolvesTeamFamily;
use Platform\Signals\Models\Signal;
use Platform\Signals\Services\CircleService;

/**
 * Regelkreis für Stewards (Menschen – Agenten arbeiten dieselbe Logik per MCP):
 * meine Gremien, Kandidaten aus den Einheiten, Eskaliertes von unten, was im LA liegt;
 * lokal lösen oder nach der Faustregel mit den vier Fragen in den LA heben.
 */
class Index extends Component
{
    use ResolvesTeamFamily;

    #[Url]
    public ?int $committee = null;

    public ?int $liftId = null;
    public array $q = [];
    public ?int $solveId = null;
    public string $note = '';
    public ?string $error = null;
    public ?string $message = null;

    private function service(): CircleService
    {
        return app(CircleService::class);
    }

    private function signal(int $id): Signal
    {
        return Signal::query()->whereIn('team_id', $this->relevantTeamIds(auth()->user()))->findOrFail($id);
    }

    public function openLift(int $id): void
    {
        $s = $this->signal($id);
        $this->liftId = $s->id;
        $this->q = ['title' => mb_strimwidth(strip_tags((string) $s->message), 0, 120, '…'), 'goal' => '', 'observation' => strip_tags((string) $s->message), 'obstacle' => '', 'local_solution' => '', 'system_support' => '', 'local_solution_confirmed' => false];
        $this->error = null;
    }

    public function lift(): void
    {
        $this->error = null;
        try {
            $r = $this->service()->liftToCommittee($this->signal((int) $this->liftId), $this->q, auth()->user());
            $this->message = "Als Entwurf in „{$r['meeting']['title']}“ gelegt.";
            $this->liftId = null;
        } catch (SignalSetException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function openSolve(int $id): void
    {
        $this->solveId = $id;
        $this->note = '';
        $this->error = null;
    }

    public function solve(): void
    {
        $this->error = null;
        try {
            $this->service()->solveLocally($this->signal((int) $this->solveId), $this->note, auth()->user());
            $this->message = 'Lokal gelöst.';
            $this->solveId = null;
        } catch (SignalSetException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render()
    {
        $teamIds = $this->relevantTeamIds(auth()->user());
        $mine = $this->service()->committeesFor((int) auth()->id());
        $ids = array_map(fn ($c) => (int) $c['entity_id'], $mine);
        $current = $this->committee && in_array($this->committee, $ids, true) ? $this->committee : ($ids[0] ?? null);

        return view('signals::livewire.circle.index', [
            'mine' => $mine,
            'current' => $current,
            'situation' => $current ? $this->service()->committeeSituation($current, $teamIds) : null,
        ])->layout('platform::layouts.app');
    }
}
