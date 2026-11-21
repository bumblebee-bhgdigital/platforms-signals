<?php

namespace Platform\Signals\Listeners;

use Platform\Signals\Models\Signal;
use Platform\Signals\Support\SignalFeedback;

/**
 * Hört auf die allgemeinen Gremien-Ereignisse des Management Books und reagiert nur, wenn der Agendapunkt
 * mit einem Signal verknüpft ist (lose Kopplung: das Book kennt Signals nicht).
 */
class HandleCommitteeEvents
{
    public function decided(object $event): void
    {
        $item = $event->agendaItem ?? null;
        $decision = $event->decision ?? null;
        if (! $item || ! $decision || $item->linked_object_type !== Signal::MORPH_ALIAS || ! $item->linked_object_id) {
            return;
        }
        SignalFeedback::decision((int) $item->linked_object_id, (string) ($decision->kind ?: 'measure'), 'management_book_decision',
            (int) $decision->id, (string) $decision->text, $decision->created_by ? (int) $decision->created_by : null);
    }

    public function proposalRejected(object $event): void
    {
        $item = $event->agendaItem ?? null;
        if (! $item || $item->linked_object_type !== Signal::MORPH_ALIAS || ! $item->linked_object_id) {
            return;
        }
        SignalFeedback::decision((int) $item->linked_object_id, 'return_to_unit', 'management_book_agenda_item', (int) $item->id,
            'Vom Vorsitz nicht auf die Agenda genommen – lokal lösen.', $event->byUserId ?? null);
    }
}
