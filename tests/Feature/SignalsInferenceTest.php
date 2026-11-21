<?php

namespace Tests\Feature;

require_once __DIR__ . '/SignalsHarness.php';

use Platform\Signals\Models\Signal;
use Platform\Signals\Models\SignalMemoryEntry;
use Platform\Signals\Models\SignalSetTemplate;
use Platform\Signals\Services\SignalSetService;
use Platform\Signals\Services\SignalTemplateService;
use Platform\Signals\Tools\CreateMemoryTool;
use Platform\Signals\Tools\CreateSignalTool;
use Platform\Signals\Tools\GetInferenceWorkTool;
use Platform\Signals\Tools\ListMemoryTool;
use Platform\Signals\Tools\RecordInferenceOutcomeTool;
use Platform\Signals\Tools\UpdateSignalTool;
use Tests\TestCase;

/**
 * KI-Templates werden vom verantwortlichen Agenten per MCP ausgewertet: Arbeitsliste, Kontext (Knoten des Sets,
 * Snapshots, offene Signale, Gedächtnis), „nichts gefunden“, Lernschleife über Rückmeldungen.
 * Aufruf: tests/run-in-taiste.sh tests/Feature/SignalsInferenceTest.php
 */
class SignalsInferenceTest extends TestCase
{
    use SignalsHarness;

    private $set;
    private $template;
    private SignalSetTemplate $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootModule();
        $this->withoutExceptionHandling();
        $this->seedWorld();

        $sets = app(SignalSetService::class);
        $this->set = $sets->create($this->s['user'], $this->s['team'], ['title' => 'Lenkungsausschuss', 'responsible_agent_user_id' => $this->s['agent']->id]);
        $sets->link($this->set, $this->ids['a'], true);
        $sets->activate($this->set);
        $this->template = app(SignalTemplateService::class)->create($this->s['user'], $this->s['teamId'], [
            'name' => 'Stille Einheiten', 'kind' => 'inference', 'vsm_system' => 's3_star', 'severity' => 'warning',
            'prompt' => 'Welche Einheiten zeigen keine Bewegung?', 'schedule_interval_hours' => 72,
        ]);
        $this->assignment = app(SignalTemplateService::class)->attach($this->set, $this->template);
        $this->snapshot($this->ids['a1'], ['items_open' => 3]);
    }

    public function test_agent_sees_due_work_gets_context_and_records_nothing(): void
    {
        $work = $this->tool(GetInferenceWorkTool::class, [], $this->s['agent']);
        $this->assertTrue($work->success, json_encode($work->error ?? null));
        $this->assertSame(1, $work->data['count']);
        $this->assertSame($this->assignment->id, $work->data['work'][0]['assignment_id']);

        $ctx = $this->tool(GetInferenceWorkTool::class, ['assignment_id' => $this->assignment->id], $this->s['agent']);
        $this->assertTrue($ctx->success, json_encode($ctx->error ?? null));
        $c = $ctx->data['context'];
        $this->assertSame('Welche Einheiten zeigen keine Bewegung?', $c['assignment']['prompt']);
        $this->assertEqualsCanonicalizing([$this->ids['a'], $this->ids['a1'], $this->ids['a2']], array_column($c['entities'], 'id'), 'nur die Knoten des Sets');
        $a1 = collect($c['entities'])->firstWhere('id', $this->ids['a1']);
        $this->assertArrayNotHasKey('snapshot_latest', $a1, 'Daten liest der Agent selbst – Signals sammelt nichts vor');
        $this->assertStringContainsString('tool_registry.SEARCH', $c['how_to_answer']);

        $this->assertSame(0, $this->tool(GetInferenceWorkTool::class, [], $this->s['agent'])->data['count'], 'nach dem Auswerten nicht mehr fällig');

        $r = $this->tool(RecordInferenceOutcomeTool::class, ['assignment_id' => $this->assignment->id, 'outcome' => 'nothing', 'note' => 'A ist stabil, keine Auffälligkeit', 'entity_id' => $this->ids['a']], $this->s['agent']);
        $this->assertTrue($r->success);
        $this->assertSame('observation', SignalMemoryEntry::find($r->data['memory_entry_id'])->memory_type);
    }

    public function test_feedback_on_inference_signals_feeds_memory_and_comes_back_as_context(): void
    {
        $r = $this->tool(CreateSignalTool::class, ['signal_set_id' => $this->set->id, 'signal_template_id' => $this->template->id, 'entity_id' => $this->ids['a2'], 'message' => 'A2 ist still'], $this->s['agent']);
        $signal = Signal::find($r->data['signal']['id']);
        $this->tool(UpdateSignalTool::class, ['id' => $signal->id, 'action' => 'dismiss', 'reason' => 'A2 ruht bewusst bis Q1']);

        $mem = SignalMemoryEntry::where('signal_id', $signal->id)->first();
        $this->assertSame('suppression', $mem->memory_type);
        $this->assertStringContainsString('ruht bewusst', $mem->content);

        $ctx = $this->tool(GetInferenceWorkTool::class, ['assignment_id' => $this->assignment->id, 'dry_run' => true], $this->s['agent'])->data['context'];
        $this->assertTrue(collect($ctx['memory']['entity_memory'])->contains(fn ($m) => $m['type'] === 'suppression' && $m['entity_id'] === $this->ids['a2']));
        $this->assertTrue(collect($ctx['memory']['template_experience'])->contains(fn ($m) => $m['type'] === 'suppression'));
        $this->assertNull($this->assignment->fresh()->last_evaluated_at, 'dry_run markiert nicht');
    }

    public function test_memory_tools_and_idempotent_migration(): void
    {
        $args = ['memory_type' => 'relationship', 'content' => 'Doppelte Kundenzuordnung', 'entity_id' => $this->ids['b'], 'legacy_organization_memory_id' => 127, 'confidence' => 0.6, 'created_at' => '2026-10-31 08:19:40', 'source_type' => 'migration'];
        $this->assertTrue($this->tool(CreateMemoryTool::class, $args)->data['created']);
        $this->assertFalse($this->tool(CreateMemoryTool::class, $args)->data['created']);
        $list = $this->tool(ListMemoryTool::class, ['entity_id' => $this->ids['b']]);
        $this->assertSame(1, $list->data['pagination']['total']);
        $this->assertSame('2026-10-31', substr($list->data['entries'][0]['created_at'], 0, 10));
    }
}
