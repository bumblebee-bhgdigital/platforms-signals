<?php

namespace Platform\Signals\Console\Commands;

use Illuminate\Console\Command;
use Platform\Signals\Services\SignalEscalationService;

class EscalateSignalsCommand extends Command
{
    protected $signature = 'signals:escalate';

    protected $description = 'Eskaliert überfällige Signale entlang der VSM-Ebenen und aggregiert unabsorbierte S5-Signale.';

    public function handle(SignalEscalationService $service): int
    {
        $r = $service->run();
        $this->info("Eskaliert: {$r['escalated']} · aggregiert: {$r['aggregated']}");

        return self::SUCCESS;
    }
}
