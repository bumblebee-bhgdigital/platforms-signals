<?php

namespace Platform\Signals\Organization;

use Illuminate\Support\Carbon;
use Platform\Organization\Contracts\AgentWorkSource;
use Platform\Organization\Models\OrganizationEntity;
use Platform\Signals\Services\SignalDeliveryQueue;

/**
 * Organization-Bridge: legt dem Zustell-Agenten eines Sets seine offenen Parlan-Aufträge in den Arbeitseingang
 * (GET /api/org/agent/work). Ohne das sieht der Daemon die Aufträge nicht – sie wecken ihn nicht und stehen nicht
 * in seinem Takt. Eine Zeile je Agent: wie viele Aufträge jetzt ausführbar sind und mit welchem Tool.
 */
class SignalsAgentWork implements AgentWorkSource
{
    public function key(): string
    {
        return 'signals';
    }

    public function workFor(OrganizationEntity $agent): array
    {
        if (! $agent->linked_user_id) {
            return [];
        }
        $rows = array_values(array_filter(
            app(SignalDeliveryQueue::class)->pendingFor((int) $agent->linked_user_id, null, 500),
            fn (array $r) => $r['ready'],
        ));
        if ($rows === []) {
            return [];
        }

        $ops = array_count_values(array_column($rows, 'op'));
        $labels = ['create' => 'anlegen', 'handover' => 'übergeben', 'comment' => 'kommentieren', 'reopen' => 'wieder öffnen', 'close' => 'schließen'];
        $parts = [];
        foreach ($labels as $op => $label) {
            if (! empty($ops[$op])) {
                $parts[] = "{$ops[$op]}× {$label}";
            }
        }
        $oldest = min(array_column($rows, 'created_at'));
        $critical = in_array('critical', array_map(fn ($r) => $r['signal']['severity'] ?? null, $rows), true);
        $waitingHours = $oldest ? now()->diffInHours(Carbon::parse($oldest)->toImmutable(), true) : 0;
        $count = count($rows);

        return [[
            'key' => 'signals.deliveries',
            'text' => $count . ($count === 1 ? ' Zustell-Auftrag' : ' Zustell-Aufträge') . ' für Signale bereit (' . implode(', ', $parts) . ')'
                . ($oldest ? ', ältester seit ' . Carbon::parse($oldest)->timezone(config('app.timezone'))->format('d.m. H:i') : '') . '.',
            'count' => $count,
            // offen = 0.6; wartet schon länger als einen halben Tag oder ist kritisch → dringender
            'weight' => min(1.0, 0.6 + ($critical ? 0.2 : 0) + ($waitingHours >= 12 ? 0.2 : 0)),
            'tool' => 'signals.deliveries.GET',
            'how' => 'Aufträge holen, je ready-Auftrag in Parlan ausführen (create: Vorgang im Kontext-Raum anlegen; handover/comment/reopen/close: act_on_thread) und jeden mit signals.deliveries.POST {op_id, ok, thread} zurückmelden.',
            'oldest_at' => $oldest,
        ]];
    }
}
