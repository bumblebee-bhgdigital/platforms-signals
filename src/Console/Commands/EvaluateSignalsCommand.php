<?php

namespace Platform\Signals\Console\Commands;

use Illuminate\Console\Command;
use Platform\Signals\Services\SignalEvaluationService;

class EvaluateSignalsCommand extends Command
{
    protected $signature = 'signals:evaluate {--set= : nur dieses Signal-Set} {--force : Takt ignorieren} {--dry-run : nichts schreiben}';

    protected $description = 'Wertet die Regel-Templates aktiver Signal-Sets an deren Knoten aus (nach dem Snapshot der Organisation).';

    public function handle(SignalEvaluationService $service): int
    {
        $report = $service->run(
            $this->option('set') ? (int) $this->option('set') : null,
            (bool) $this->option('force'),
            (bool) $this->option('dry-run'),
        );

        $this->info(sprintf(
            'Sets: %d · ausgewertet: %d · neu: %d · aufgelöst: %d · nicht dran: %d',
            $report['sets'], $report['evaluated'], $report['created'], $report['resolved'], $report['skipped_not_due'],
        ));
        foreach ($report['results'] as $r) {
            if (isset($r['error'])) {
                $this->error("Set #{$r['set_id']} / Template #{$r['template_id']}: {$r['error']}");
            }
        }

        return self::SUCCESS;
    }
}
