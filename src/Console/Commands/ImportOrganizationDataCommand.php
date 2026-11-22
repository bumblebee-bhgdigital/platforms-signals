<?php

namespace Platform\Signals\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Signals\Models\SignalMemoryEntry;
use Platform\Signals\Models\SignalTemplate;

/**
 * Einmaliger Umzug der Verlaufsdaten aus der Organisation (#1046 und Gedächtnis), idempotent über legacy_*-IDs:
 *  - Gedächtnis-Einträge; inference_prompt_id → Template gleichen Namens (source_relevance entfällt mit den Umweltquellen)
 *  - Kommentare, Maßnahmen und Fokus der bereits übernommenen Signale (legacy_organization_signal_id); Antworten behalten ihren Elternkommentar
 *
 * Die Konfiguration (Regeln, Prompts → Templates, Sets) wird bewusst per MCP umgezogen, nicht hier.
 * Liest die Tabellen der Organisation direkt (deren Models sind beim Rückbau ggf. schon weg).
 */
class ImportOrganizationDataCommand extends Command
{
    protected $signature = 'signals:import-organization {--team= : Team-ID (Root-Team)} {--dry-run : nur zählen}';

    protected $description = 'Übernimmt Gedächtnis sowie Kommentare, Maßnahmen und Fokus der übernommenen Signale aus der Organisation (idempotent).';

    public function handle(): int
    {
        $teamId = (int) $this->option('team');
        if ($teamId < 1) {
            $this->error('--team ist erforderlich.');

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');

        $report = ['memory' => 0, 'memory_skipped' => 0, 'comments' => 0, 'actions' => 0, 'focuses' => 0];

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

        $this->importSignalDetails($teamId, $dry, $report);

        $this->info(($dry ? '[Probelauf] ' : '') . sprintf(
            'Gedächtnis: %d neu (%d schon da) · Kommentare: %d · Maßnahmen: %d · Fokus: %d',
            $report['memory'], $report['memory_skipped'], $report['comments'], $report['actions'], $report['focuses'],
        ));

        return self::SUCCESS;
    }

    /** Kommentare, Maßnahmen, Fokus der Signale, die schon mit legacy_organization_signal_id übernommen sind. */
    private function importSignalDetails(int $teamId, bool $dry, array &$report): void
    {
        $map = DB::table('signals')->where('team_id', $teamId)->whereNotNull('legacy_organization_signal_id')
            ->pluck('id', 'legacy_organization_signal_id')->all();
        if (! $map) {
            return;
        }
        $oldIds = array_keys($map);

        if (Schema::hasTable('organization_signal_comments')) {
            $known = DB::table('signal_comments')->whereNotNull('legacy_organization_comment_id')->pluck('id', 'legacy_organization_comment_id')->all();
            // nach id: Elternkommentare kommen vor ihren Antworten
            foreach (DB::table('organization_signal_comments')->whereIn('signal_id', $oldIds)->orderBy('id')->get() as $c) {
                if (isset($known[$c->id])) {
                    continue;
                }
                $report['comments']++;
                if ($dry) {
                    continue;
                }
                $known[$c->id] = DB::table('signal_comments')->insertGetId([
                    'signal_id' => $map[$c->signal_id], 'parent_id' => $c->parent_id ? ($known[$c->parent_id] ?? null) : null,
                    'user_id' => $c->user_id, 'author_context' => mb_substr((string) $c->author_context, 0, 30) ?: 'user',
                    'content' => (string) $c->content, 'legacy_organization_comment_id' => $c->id,
                    'created_at' => $c->created_at, 'updated_at' => $c->updated_at,
                ]);
            }
        }

        if (Schema::hasTable('organization_signal_actions')) {
            $known = DB::table('signal_actions')->whereNotNull('legacy_organization_action_id')->pluck('legacy_organization_action_id')->flip()->all();
            foreach (DB::table('organization_signal_actions')->whereIn('signal_id', $oldIds)->orderBy('id')->get() as $a) {
                if (isset($known[$a->id])) {
                    continue;
                }
                $report['actions']++;
                if ($dry) {
                    continue;
                }
                DB::table('signal_actions')->insert([
                    'signal_id' => $map[$a->signal_id], 'position' => (int) $a->position, 'title' => (string) $a->title,
                    'description' => $a->description, 'status' => $a->status ?: 'pending', 'decision_reason' => $a->decision_reason,
                    'decided_by' => $a->decided_by, 'decided_at' => $a->decided_at, 'legacy_organization_action_id' => $a->id,
                    'created_at' => $a->created_at, 'updated_at' => $a->updated_at, 'deleted_at' => $a->deleted_at,
                ]);
            }
        }

        if (Schema::hasTable('organization_signal_focuses')) {
            foreach (DB::table('organization_signal_focuses')->whereIn('signal_id', $oldIds)->orderBy('id')->get() as $f) {
                $exists = DB::table('signal_focuses')->where('signal_id', $map[$f->signal_id])->where('user_id', $f->user_id)->exists();
                if ($exists) {
                    continue;
                }
                $report['focuses']++;
                if ($dry) {
                    continue;
                }
                DB::table('signal_focuses')->insert([
                    'signal_id' => $map[$f->signal_id], 'user_id' => $f->user_id, 'focused_at' => $f->focused_at,
                    'created_at' => $f->created_at, 'updated_at' => $f->updated_at,
                ]);
            }
        }
    }
}
