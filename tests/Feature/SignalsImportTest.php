<?php

namespace Tests\Feature;

require_once __DIR__ . '/SignalsHarness.php';

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Umzug aus der Organisation: Gedächtnis sowie Kommentare (mit Antworten), Maßnahmen und Fokus der übernommenen
 * Signale – idempotent. Aufruf: tests/run-in-taiste.sh tests/Feature/SignalsImportTest.php
 */
class SignalsImportTest extends TestCase
{
    use SignalsHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootModule();
        $this->seedWorld();
    }

    public function test_imports_memory_and_signal_details_once(): void
    {
        $t = $this->s['teamId'];
        $u = $this->s['user']->id;
        $old = $this->row('organization_signals', ['team_id' => $t, 'entity_id' => $this->ids['a'], 'status' => 'open', 'severity' => 'warning', 'message' => 'alt', 'source' => 'rule', 'trigger_metrics' => '{}']);
        $oldOther = $this->row('organization_signals', ['team_id' => $t, 'entity_id' => $this->ids['a'], 'status' => 'open', 'severity' => 'warning', 'message' => 'nicht übernommen', 'source' => 'rule', 'trigger_metrics' => '{}']);
        $new = $this->row('signals', ['team_id' => $t, 'entity_id' => $this->ids['a'], 'status' => 'open', 'severity' => 'warning', 'message' => 'alt', 'source' => 'migration', 'legacy_organization_signal_id' => $old]);

        $c1 = $this->row('organization_signal_comments', ['signal_id' => $old, 'user_id' => $u, 'author_context' => 'user', 'content' => 'Frage', 'created_at' => '2026-10-01 10:00:00']);
        $this->row('organization_signal_comments', ['signal_id' => $old, 'parent_id' => $c1, 'user_id' => $u, 'author_context' => 'agent', 'content' => 'Antwort']);
        $this->row('organization_signal_comments', ['signal_id' => $oldOther, 'user_id' => $u, 'content' => 'bleibt draußen']);
        $this->row('organization_signal_actions', ['signal_id' => $old, 'position' => 1, 'title' => 'Team verstärken', 'status' => 'applied', 'decided_by' => $u, 'decided_at' => now()]);
        $this->row('organization_signal_focuses', ['signal_id' => $old, 'user_id' => $u, 'focused_at' => now()]);
        $this->row('organization_memory_entries', ['team_id' => $t, 'entity_id' => $this->ids['a'], 'memory_type' => 'baseline', 'content' => 'normal so', 'confidence' => 0.7, 'is_active' => true, 'source_type' => 'inference']);

        $this->artisan('signals:import-organization', ['--team' => $t, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, DB::table('signal_comments')->count(), 'Probelauf schreibt nichts');

        $this->artisan('signals:import-organization', ['--team' => $t])
            ->expectsOutputToContain('Gedächtnis: 1 neu (0 schon da) · Kommentare: 2 · Maßnahmen: 1 · Fokus: 1')->assertSuccessful();
        $this->artisan('signals:import-organization', ['--team' => $t])
            ->expectsOutputToContain('Gedächtnis: 0 neu (1 schon da) · Kommentare: 0 · Maßnahmen: 0 · Fokus: 0')->assertSuccessful();

        $comments = DB::table('signal_comments')->orderBy('id')->get();
        $this->assertSame([$new, $new], $comments->pluck('signal_id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame((int) $comments[0]->id, (int) $comments[1]->parent_id, 'Antwort hängt am übernommenen Elternkommentar');
        $this->assertSame('2026-10-01 10:00:00', (string) $comments[0]->created_at);
        $this->assertSame('applied', DB::table('signal_actions')->value('status'));
        $this->assertSame($u, (int) DB::table('signal_focuses')->where('signal_id', $new)->value('user_id'));
        $this->assertSame('normal so', DB::table('signal_memory_entries')->whereNotNull('legacy_organization_memory_id')->value('content'));
    }
}
