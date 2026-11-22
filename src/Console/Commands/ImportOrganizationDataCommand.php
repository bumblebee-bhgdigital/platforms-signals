<?php

namespace Platform\Signals\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Signals\Models\SignalMemoryEntry;
use Platform\Signals\Models\SignalTemplate;

/**
 * Einmaliger Umzug der Verlaufsdaten aus der Organisation (#1046 und Gedächtnis), idempotent über legacy_*-IDs:
 *  - Umweltquellen + Snapshots (IDs werden umgeschlüsselt), alte Quellen werden deaktiviert (kein doppelter Abruf)
 *  - Gedächtnis-Einträge; inference_prompt_id → Template gleichen Namens, source_relevance.source_id → neue Quelle
 *
 * Die Konfiguration (Regeln, Prompts → Templates, Sets) wird bewusst per MCP umgezogen, nicht hier.
 * Liest die Tabellen der Organisation direkt (deren Models sind beim Rückbau ggf. schon weg).
 */
class ImportOrganizationDataCommand extends Command
{
    protected $signature = 'signals:import-organization {--team= : Team-ID (Root-Team)} {--dry-run : nur zählen}';

    protected $description = 'Übernimmt das Gedächtnis aus der Organisation ins Signals-Modul (idempotent). Umweltquellen nicht – externe Daten liest der Agent selbst.';

    public function handle(): int
    {
        $teamId = (int) $this->option('team');
        if ($teamId < 1) {
            $this->error('--team ist erforderlich.');

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');

        $report = ['memory' => 0, 'memory_skipped' => 0];

        if (Schema::hasTable('organization_memory_entries')) {
            $promptNames = Schema::hasTable('organization_signal_inference_prompts')
                ? DB::table('organization_signal_inference_prompts')->where('team_id', $teamId)->pluck('name', 'id')->all()
                : [];
            $templateByName = SignalTemplate::query()->where('team_id', $teamId)->where('kind', 'inference')->pluck('id', 'name')->all();

            // source_relevance hing an Umweltquellen – die gibt es in Signals nicht
            foreach (DB::table('organization_memory_entries')->where('team_id', $teamId)->whereNull('deleted_at')->where('memory_type', '!=', 'source_relevance')->orderBy('id')->get() as $old) {
                if (SignalMemoryEntry::withTrashed()->where('legacy_organization_memory_id', $old->id)->exists()) {
                    $report['memory_skipped']++;
                    continue;
                }
                $report['memory']++;
                if ($dry) {
                    continue;
                }
                $data = $old->structured_data ? (json_decode($old->structured_data, true) ?: []) : [];
                $templateId = null;
                if ($old->inference_prompt_id && isset($promptNames[$old->inference_prompt_id])) {
                    $templateId = $templateByName[$promptNames[$old->inference_prompt_id]] ?? null;
                    $data['legacy_inference_prompt_id'] = $old->inference_prompt_id;
                }
                SignalMemoryEntry::create([
                    'team_id' => $old->team_id, 'entity_id' => $old->entity_id, 'signal_template_id' => $templateId,
                    'memory_type' => $old->memory_type, 'content' => (string) $old->content, 'structured_data' => $data ?: null,
                    'confidence' => (float) $old->confidence, 'source_type' => $old->source_type ?: 'migration',
                    'valid_until' => $old->valid_until, 'is_active' => (bool) $old->is_active,
                    'reinforcement_count' => (int) ($old->reinforcement_count ?? 0), 'legacy_organization_memory_id' => $old->id,
                    'created_at' => $old->created_at,
                ]);
            }
        }

        $this->info(($dry ? '[Probelauf] ' : '') . sprintf(
            'Gedächtnis: %d neu (%d schon da)',
            $report['memory'], $report['memory_skipped'],
        ));

        return self::SUCCESS;
    }
}
