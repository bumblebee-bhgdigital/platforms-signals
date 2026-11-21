<?php

namespace Platform\Signals\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Signals\Models\SignalEnvironmentSnapshot;
use Platform\Signals\Models\SignalEnvironmentSource;
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
    protected $signature = 'signals:import-organization {--team= : Team-ID (Root-Team)} {--dry-run : nur zählen} {--keep-sources-active : alte Quellen nicht deaktivieren}';

    protected $description = 'Übernimmt Umweltquellen, Snapshots und Gedächtnis aus der Organisation ins Signals-Modul (idempotent).';

    public function handle(): int
    {
        $teamId = (int) $this->option('team');
        if ($teamId < 1) {
            $this->error('--team ist erforderlich.');

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');

        $report = ['sources' => 0, 'sources_skipped' => 0, 'snapshots' => 0, 'memory' => 0, 'memory_skipped' => 0, 'deactivated' => 0];
        $sourceMap = [];

        if (Schema::hasTable('organization_environment_sources')) {
            foreach (DB::table('organization_environment_sources')->where('team_id', $teamId)->whereNull('deleted_at')->orderBy('id')->get() as $old) {
                $new = SignalEnvironmentSource::withTrashed()->where('legacy_organization_source_id', $old->id)->first();
                if ($new) {
                    $report['sources_skipped']++;
                } else {
                    $report['sources']++;
                    if ($dry) {
                        continue;
                    }
                }
                $new ??= SignalEnvironmentSource::create([
                    'team_id' => $old->team_id, 'user_id' => $old->user_id, 'name' => $old->name, 'source_type' => $old->source_type,
                    'category' => $old->category, 'cluster' => $old->cluster ?? null, 'config' => json_decode($old->config, true) ?: [],
                    'pull_interval_hours' => $old->pull_interval_hours, 'is_active' => (bool) $old->is_active,
                    'last_pulled_at' => $old->last_pulled_at, 'legacy_organization_source_id' => $old->id,
                ]);
                $sourceMap[$old->id] = $new->id;
                if ($dry) {
                    continue;
                }

                foreach (DB::table('organization_environment_snapshots')->where('source_id', $old->id)->orderBy('id')->get() as $snap) {
                    if (SignalEnvironmentSnapshot::query()->where('legacy_organization_snapshot_id', $snap->id)->exists()) {
                        continue;
                    }
                    SignalEnvironmentSnapshot::create([
                        'team_id' => $snap->team_id, 'source_id' => $new->id, 'snapshot_date' => $snap->snapshot_date,
                        'metrics' => json_decode($snap->metrics, true) ?: [], 'summary' => (string) $snap->summary,
                        'raw_items' => $snap->raw_items ? (json_decode($snap->raw_items, true) ?: []) : [],
                        'created_at' => $snap->created_at, 'legacy_organization_snapshot_id' => $snap->id,
                    ]);
                    $report['snapshots']++;
                }
            }

            if (! $dry && ! $this->option('keep-sources-active') && $sourceMap !== []) {
                $report['deactivated'] = DB::table('organization_environment_sources')->whereIn('id', array_keys($sourceMap))
                    ->where('is_active', true)->update(['is_active' => false, 'updated_at' => now()]);
            }
        }

        if (Schema::hasTable('organization_memory_entries')) {
            $promptNames = Schema::hasTable('organization_signal_inference_prompts')
                ? DB::table('organization_signal_inference_prompts')->where('team_id', $teamId)->pluck('name', 'id')->all()
                : [];
            $templateByName = SignalTemplate::query()->where('team_id', $teamId)->where('kind', 'inference')->pluck('id', 'name')->all();

            foreach (DB::table('organization_memory_entries')->where('team_id', $teamId)->whereNull('deleted_at')->orderBy('id')->get() as $old) {
                if (SignalMemoryEntry::withTrashed()->where('legacy_organization_memory_id', $old->id)->exists()) {
                    $report['memory_skipped']++;
                    continue;
                }
                $report['memory']++;
                if ($dry) {
                    continue;
                }
                $data = $old->structured_data ? (json_decode($old->structured_data, true) ?: []) : [];
                if ($old->memory_type === 'source_relevance' && isset($data['source_id']) && isset($sourceMap[$data['source_id']])) {
                    $data['legacy_source_id'] = $data['source_id'];
                    $data['source_id'] = $sourceMap[$data['source_id']];
                }
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
            'Quellen: %d neu (%d schon da) · Snapshots: %d · Gedächtnis: %d neu (%d schon da) · alte Quellen deaktiviert: %d',
            $report['sources'], $report['sources_skipped'], $report['snapshots'], $report['memory'], $report['memory_skipped'], $report['deactivated'],
        ));

        return self::SUCCESS;
    }
}
