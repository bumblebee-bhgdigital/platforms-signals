<?php

namespace Platform\Signals\Livewire;

use Livewire\Component;
use Platform\Signals\Exceptions\SignalSetException;
use Platform\Signals\Livewire\Concerns\ResolvesTeamFamily;
use Platform\Signals\Models\Signal;
use Platform\Signals\Services\MySignalsService;
use Platform\Signals\Services\SignalService;

/**
 * Z9 „Meine Signale“: Bei mir · Zu mir hochgekommen · Erledigt. Drei Knöpfe je Signal:
 * Gesehen (acknowledge), Gelöst (resolve mit Notiz), Gehört nicht zu mir (reject mit Begründung).
 */
class Mine extends Component
{
    use ResolvesTeamFamily;

    /** @var array<int,string> Notiz je Signal (Gelöst) */
    public array $notes = [];
    /** @var array<int,string> Begründung je Signal (Gehört nicht zu mir) */
    public array $reasons = [];
    public ?string $error = null;
    public ?string $flash = null;

    private function signal(int $id): Signal
    {
        $signal = Signal::query()->whereIn('team_id', $this->relevantTeamIds(auth()->user()))->find($id);
        abort_unless($signal, 404);

        return $signal;
    }

    private function attempt(callable $fn, string $done): void
    {
        $this->error = null;
        $this->flash = null;
        try {
            $fn();
            $this->flash = $done;
        } catch (SignalSetException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function acknowledge(int $id): void
    {
        $this->attempt(fn () => app(SignalService::class)->transition($this->signal($id), 'acknowledge', auth()->user()), 'Als gesehen markiert.');
    }

    public function resolve(int $id): void
    {
        $this->attempt(function () use ($id) {
            $note = trim((string) ($this->notes[$id] ?? ''));
            if ($note === '') {
                throw new SignalSetException('Wodurch gelöst? Bitte kurz notieren.');
            }
            app(SignalService::class)->transition($this->signal($id), 'resolve', auth()->user(), ['resolution_note' => $note]);
            unset($this->notes[$id]);
        }, 'Gelöst.');
    }

    public function reject(int $id): void
    {
        $this->attempt(function () use ($id) {
            app(SignalService::class)->transition($this->signal($id), 'reject', auth()->user(), ['reason' => (string) ($this->reasons[$id] ?? '')]);
            unset($this->reasons[$id]);
        }, 'Weitergegeben: gehört nicht zu dir.');
    }

    public function render()
    {
        $user = auth()->user();
        $groups = $user ? app(MySignalsService::class)->groups((int) $user->id, $this->relevantTeamIds($user)) : ['with_me' => collect(), 'came_up' => collect(), 'done' => collect()];

        return view('signals::livewire.mine', $groups)->layout('platform::layouts.app');
    }
}
