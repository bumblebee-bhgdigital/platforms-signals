<?php

namespace Platform\Signals\Livewire\Signal;

use Livewire\Component;
use Platform\Signals\Exceptions\SignalSetException;
use Platform\Signals\Livewire\Concerns\ResolvesTeamFamily;
use Platform\Signals\Models\Signal;
use Platform\Signals\Services\SignalService;

/** Detailseite eines Signals: bestätigen, erledigen (Notiz + Verweis), verwerfen, zurückstellen, Maßnahmen, Kommentare. */
class Show extends Component
{
    use ResolvesTeamFamily;

    public Signal $signal;

    public string $note = '';
    public string $linkType = '';
    public string $linkId = '';
    public string $reason = '';
    public string $comment = '';
    public string $snoozeDays = '7';
    public ?string $error = null;

    public function mount(Signal $signal): void
    {
        abort_unless(in_array((int) $signal->team_id, $this->relevantTeamIds(auth()->user()), true), 403);
        $this->signal = $signal;
    }

    private function service(): SignalService
    {
        return app(SignalService::class);
    }

    private function attempt(callable $fn): void
    {
        $this->error = null;
        try {
            $fn();
            $this->signal->refresh();
        } catch (SignalSetException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function acknowledge(): void
    {
        $this->attempt(fn () => $this->service()->transition($this->signal, 'acknowledge', auth()->user()));
    }

    public function resolve(): void
    {
        $this->attempt(function () {
            $this->service()->transition($this->signal, 'resolve', auth()->user(), [
                'resolution_note' => $this->note,
                'link_type' => $this->linkType ?: null,
                'link_id' => $this->linkId !== '' ? (int) $this->linkId : null,
            ]);
            $this->reset('note', 'linkType', 'linkId');
        });
    }

    public function dismiss(): void
    {
        $this->attempt(function () {
            $this->service()->transition($this->signal, 'dismiss', auth()->user(), ['reason' => $this->reason, 'resolution_note' => $this->note]);
            $this->reset('reason', 'note');
        });
    }

    public function reopen(): void
    {
        $this->attempt(fn () => $this->service()->transition($this->signal, 'reopen', auth()->user()));
    }

    public function snooze(): void
    {
        $days = max(1, (int) $this->snoozeDays);
        $this->attempt(fn () => $this->service()->snooze($this->signal, now()->addDays($days)));
    }

    public function unsnooze(): void
    {
        $this->attempt(fn () => $this->service()->snooze($this->signal, null));
    }

    public function toggleFocus(): void
    {
        $this->attempt(fn () => $this->service()->toggleFocus($this->signal, auth()->user()));
    }

    public function decide(int $actionId, string $status): void
    {
        $this->attempt(fn () => $this->service()->decideAction($this->signal, $actionId, $status, auth()->user()));
    }

    public function addComment(): void
    {
        $this->attempt(function () {
            $this->service()->comment($this->signal, $this->comment, auth()->user());
            $this->comment = '';
        });
    }

    public function render()
    {
        $s = $this->signal->load(['entity:id,name', 'set:id,title', 'template', 'responsible:id,name', 'resolvedBy:id,name', 'actions', 'assignee:id,name', 'currentOwner:id,name', 'perspectiveEntity:id,name', 'createdByAgent:id,name']);
        $link = null;
        if ($s->resolution_link_type) {
            try {
                $obj = $s->resolutionLink;
                $link = ['type' => $s->resolution_link_type, 'id' => $s->resolution_link_id, 'label' => $obj ? (method_exists($obj, 'getDisplayName') ? $obj->getDisplayName() : ($obj->title ?? $obj->text ?? $obj->name ?? null)) : null];
            } catch (\Throwable) {
                $link = ['type' => $s->resolution_link_type, 'id' => $s->resolution_link_id, 'label' => null];
            }
        }

        return view('signals::livewire.signal.show', [
            's' => $s,
            'link' => $link,
            'comments' => $s->comments()->with('user:id,name')->orderBy('created_at')->get(),
            'focused' => $s->isFocusedBy(auth()->user()),
            'linkTypes' => [
                ['value' => 'management_book_decision', 'label' => 'Beschluss (Management Book)'],
                ['value' => 'management_book_agenda_item', 'label' => 'Agendapunkt (Management Book)'],
                ['value' => 'management_book_meeting', 'label' => 'Sitzung (Management Book)'],
                ['value' => 'management_book_risk', 'label' => 'Risiko (Management Book)'],
                ['value' => 'planner_task', 'label' => 'Aufgabe (Planner)'],
                ['value' => 'helpdesk_ticket', 'label' => 'Ticket (Helpdesk)'],
            ],
        ])->layout('platform::layouts.app');
    }
}
