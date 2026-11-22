<?php

namespace Platform\Signals\Console\Commands;

use Illuminate\Console\Command;
use Platform\Signals\Services\SignalEscalationService;

/** Stündlich: Eskalation nur, wenn nichts passiert (Z3) – ignoriert, dauerhaft, wiederkehrend; eine Stufe je Auslöser. */
class EscalateSignalsCommand extends Command
{
    protected $signature = 'signals:escalate';

    protected $description = 'Eskaliert Signale, auf die niemand reagiert (ignoriert), die zu lange offen sind (dauerhaft) oder wiederkehren – eine Stufe.';

    public function handle(SignalEscalationService $service): int
    {
        $r = $service->run();
        $this->info("Geprüft: {$r['checked']} · weitergereicht: {$r['escalated']} · ans Gremium: {$r['committee']} · markiert: {$r['marked']}");

        return self::SUCCESS;
    }
}
