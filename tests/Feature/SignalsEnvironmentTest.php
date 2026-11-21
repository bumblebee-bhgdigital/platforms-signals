<?php

namespace Tests\Feature;

require_once __DIR__ . '/SignalsHarness.php';

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Platform\Signals\Models\SignalEnvironmentSnapshot;
use Platform\Signals\Models\SignalEnvironmentSource;
use Platform\Signals\Models\SignalMemoryEntry;
use Platform\Signals\Services\EnvironmentContextService;
use Platform\Signals\Services\EnvironmentPullService;
use Platform\Signals\Tools\CreateEnvironmentSourceTool;
use Platform\Signals\Tools\ListEnvironmentSourcesTool;
use Platform\Signals\Tools\RateEnvironmentSourceTool;
use Tests\TestCase;

/**
 * Umwelt (S4, #1046): Quellen-Tools, RSS-Abruf (ohne LLM-Key: Fallback), Kontext mit Bewegung und gelernter Relevanz,
 * Import aus der Organisation (Quellen, Snapshots, Gedächtnis, idempotent, alte Quellen deaktiviert).
 * Aufruf: tests/run-in-taiste.sh tests/Feature/SignalsEnvironmentTest.php
 */
class SignalsEnvironmentTest extends TestCase
{
    use SignalsHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootModule();
        $this->withoutExceptionHandling();
        $this->seedWorld();
        config(['ai.anthropic.api_key' => null]);
    }

    public function test_source_tools_pull_and_context_with_movement(): void
    {
        $r = $this->tool(CreateEnvironmentSourceTool::class, ['name' => 'foodservice.de', 'source_type' => 'rss', 'category' => 'gastronomy', 'cluster' => 'society', 'config' => ['url' => 'https://example.test/rss'], 'pull_interval_hours' => 24]);
        $this->assertTrue($r->success, json_encode($r->error ?? null));
        $source = SignalEnvironmentSource::first();

        $rss = '<?xml version="1.0"?><rss><channel><item><title>Catering boomt</title><link>https://x</link><pubDate>' . now()->toRfc2822String() . '</pubDate><description>Mehr Events</description></item></channel></rss>';
        Http::fake(['example.test/*' => Http::response($rss, 200)]);
        $this->assertNotNull(app(EnvironmentPullService::class)->pullSource($source));
        $this->assertSame(1, SignalEnvironmentSnapshot::count());
        $this->assertSame(1, SignalEnvironmentSource::query()->due()->count() === 0 ? 1 : 0, 'nach dem Abruf nicht fällig');

        $ctx = app(EnvironmentContextService::class)->buildInferenceContext($this->s['teamId']);
        $this->assertSame('foodservice.de', $ctx[0]['source']);
        $this->assertStringContainsString('Catering boomt', $ctx[0]['summary']);

        $list = $this->tool(ListEnvironmentSourcesTool::class, []);
        $this->assertTrue($list->success);

        $rate = $this->tool(RateEnvironmentSourceTool::class, ['source_name' => 'foodservice.de', 'relevance_rating' => 0.1, 'reasoning' => 'Für uns irrelevant']);
        $this->assertTrue($rate->success, json_encode($rate->error ?? null));
        $this->assertSame(1, SignalMemoryEntry::where('memory_type', 'source_relevance')->count());
    }

    public function test_import_from_organization_is_idempotent_and_remaps_ids(): void
    {
        $t = $this->s['teamId'];
        $oldSource = DB::table('organization_environment_sources')->insertGetId(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => $t, 'name' => 'HDE', 'source_type' => 'rss', 'category' => 'market', 'config' => json_encode(['url' => 'https://hde.test']), 'pull_interval_hours' => 168, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([2, 1] as $d) {
            DB::table('organization_environment_snapshots')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => $t, 'source_id' => $oldSource, 'snapshot_date' => now()->subDays($d)->toDateString(), 'metrics' => json_encode(['sentiment_score' => 0.1 * $d, 'relevance_score' => 0.5]), 'summary' => "Stand {$d}", 'created_at' => now()]);
        }
        $memRow = ['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => $t, 'entity_id' => $this->ids['a'], 'memory_type' => 'source_relevance', 'content' => 'HDE nützlich', 'structured_data' => json_encode(['source_id' => $oldSource, 'relevance_rating' => 0.8]), 'confidence' => 0.7, 'source_type' => 'manual', 'is_active' => true, 'created_at' => now()->subMonth(), 'updated_at' => now()];
        DB::table('organization_memory_entries')->insert(array_intersect_key($memRow, array_flip(\Illuminate\Support\Facades\Schema::getColumnListing('organization_memory_entries'))));

        Artisan::call('signals:import-organization', ['--team' => $t, '--dry-run' => true]);
        $this->assertSame(0, SignalEnvironmentSource::count(), 'Probelauf schreibt nichts');

        Artisan::call('signals:import-organization', ['--team' => $t]);
        Artisan::call('signals:import-organization', ['--team' => $t]);
        $this->assertSame(1, SignalEnvironmentSource::count());
        $this->assertSame(2, SignalEnvironmentSnapshot::count());
        $this->assertSame(1, SignalMemoryEntry::count());
        $new = SignalEnvironmentSource::first();
        $this->assertSame($new->id, SignalMemoryEntry::first()->structured_data['source_id'], 'source_id umgeschlüsselt');
        $this->assertFalse((bool) DB::table('organization_environment_sources')->where('id', $oldSource)->value('is_active'), 'alte Quelle deaktiviert');

        $ctx = app(EnvironmentContextService::class)->buildInferenceContext($t);
        $this->assertSame('Stand 1', $ctx[0]['summary']);
        $this->assertEqualsWithDelta(0.8, $ctx[0]['learned_relevance'], 0.001);
        $this->assertArrayHasKey('delta', $ctx[0]);
    }
}
