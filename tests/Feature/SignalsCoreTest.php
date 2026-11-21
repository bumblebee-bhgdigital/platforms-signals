<?php

namespace Tests\Feature;

require_once __DIR__ . '/SignalsHarness.php';

use Platform\Signals\Models\Signal;
use Platform\Signals\Models\SignalSet;
use Platform\Signals\Services\SignalEvaluationService;
use Platform\Signals\Services\SignalSetService;
use Platform\Signals\Tools\CreateSignalTemplateTool;
use Platform\Signals\Tools\CreateSignalTool;
use Platform\Signals\Tools\EvaluateSignalsTool;
use Platform\Signals\Tools\ListSignalsTool;
use Platform\Signals\Tools\ListSignalTemplatesTool;
use Platform\Signals\Tools\UpdateSignalTool;
use Platform\Signals\Tools\UpsertSetTemplateTool;
use Tests\TestCase;

/**
 * Kern des Signals-Moduls: Templates, Sets an Knoten (mit Vererbung), Auswertung nur im Geltungsbereich,
 * Overrides, Auto-Auflösung, Lebenszyklus mit Auflösung (Notiz + Verweis), Agent-Signale ohne Duplikate.
 * Aufruf: tests/run-in-taiste.sh tests/Feature/SignalsCoreTest.php
 */
class SignalsCoreTest extends TestCase
{
    use SignalsHarness;

    private SignalSet $set;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootModule();
        $this->withoutExceptionHandling();
        $this->seedWorld();

        $this->set = app(SignalSetService::class)->create($this->s['user'], $this->s['team'], [
            'title' => 'Basis', 'responsible_agent_user_id' => $this->s['agent']->id,
        ]);
        // Set hängt an Einheit A und vererbt nach unten (A, A1, A2) – Einheit B liegt außerhalb
        app(SignalSetService::class)->link($this->set, $this->ids['a'], true);
        app(SignalSetService::class)->activate($this->set);

        foreach (['a' => 60, 'a1' => 70, 'a2' => 10, 'b' => 99] as $k => $open) {
            $this->snapshot($this->ids[$k], ['items_open' => $open, 'items_done' => 1, 'items_total' => $open + 1]);
        }
    }

    private function ruleTemplate(array $extra = []): int
    {
        $r = $this->tool(CreateSignalTemplateTool::class, $extra + [
            'name' => 'Überlast', 'pattern_type' => 'threshold', 'severity' => 'warning',
            'conditions' => ['metric' => 'items_open', 'operator' => '>', 'value' => 50],
            'signal_set_id' => $this->set->id,
        ]);
        $this->assertTrue($r->success, json_encode($r->error ?? null));

        return (int) $r->data['template']['id'];
    }

    public function test_rule_fires_only_inside_the_sets_scope_and_lands_at_the_agent(): void
    {
        $this->ruleTemplate();

        $report = app(SignalEvaluationService::class)->run();
        $this->assertSame(2, $report['created']);

        $signals = Signal::all();
        $this->assertEqualsCanonicalizing([$this->ids['a'], $this->ids['a1']], $signals->pluck('entity_id')->all());
        $this->assertNotContains($this->ids['b'], $signals->pluck('entity_id')->all(), 'Einheit B liegt außerhalb des Sets');
        $this->assertTrue($signals->every(fn ($s) => $s->responsible_user_id === $this->s['agent']->id && $s->source === 'rule'));

        // zweiter Lauf: keine Duplikate
        app(SignalEvaluationService::class)->run(null, true);
        $this->assertSame(2, Signal::count());
    }

    public function test_override_changes_the_threshold_per_set_and_stale_signals_resolve_themselves(): void
    {
        $templateId = $this->ruleTemplate();
        app(SignalEvaluationService::class)->run();
        $this->assertSame(2, Signal::open()->count());

        $r = $this->tool(UpsertSetTemplateTool::class, ['signal_set_id' => $this->set->id, 'signal_template_id' => $templateId, 'conditions_override' => ['value' => 65], 'severity_override' => 'critical']);
        $this->assertTrue($r->success);
        $this->assertSame(65, $r->data['assignment']['effective']['conditions']['value']);

        app(SignalEvaluationService::class)->run(null, true);
        $open = Signal::open()->get();
        $this->assertSame([$this->ids['a1']], $open->pluck('entity_id')->all(), 'nur A1 liegt über 65');
        $resolved = Signal::where('entity_id', $this->ids['a'])->first();
        $this->assertSame('resolved', $resolved->status);
        $this->assertStringContainsString('Automatisch', $resolved->resolution_note);
    }

    public function test_dry_run_reports_without_writing_and_frequency_is_respected(): void
    {
        $this->ruleTemplate(['frequency' => 'daily']);
        $r = $this->tool(EvaluateSignalsTool::class, ['signal_set_id' => $this->set->id]);
        $this->assertTrue($r->success);
        $this->assertTrue($r->data['dry_run']);
        $this->assertSame(2, $r->data['created']);
        $this->assertSame(0, Signal::count());

        app(SignalEvaluationService::class)->run();
        $this->assertSame(2, Signal::count());
        $again = app(SignalEvaluationService::class)->run();
        $this->assertSame(1, $again['skipped_not_due'], 'daily: am selben Tag nicht noch einmal');
    }

    public function test_inactive_set_and_non_inheriting_node_limit_the_scope(): void
    {
        $this->ruleTemplate();
        app(SignalSetService::class)->setInherit($this->set, $this->ids['a'], false);
        app(SignalEvaluationService::class)->run();
        $this->assertSame([$this->ids['a']], Signal::pluck('entity_id')->all());

        app(SignalSetService::class)->archive($this->set);
        Signal::query()->delete();
        $this->assertSame(0, app(SignalEvaluationService::class)->run()['sets']);
    }

    public function test_all_rule_patterns_behave_like_before(): void
    {
        $this->snapshot($this->ids['a1'], ['time_total_minutes' => 100], 2, 'evening');
        $this->snapshot($this->ids['a1'], ['time_total_minutes' => 150], 1, 'evening');
        $this->snapshot($this->ids['a1'], ['time_total_minutes' => 200], 0, 'evening');
        $this->ruleTemplate(['name' => 'Trend', 'pattern_type' => 'trend', 'conditions' => ['metric' => 'time_total_minutes', 'direction' => 'increasing', 'periods' => 3, 'min_change_percent' => 15]]);
        $this->ruleTemplate(['name' => 'Quote', 'pattern_type' => 'ratio', 'conditions' => ['numerator' => 'items_done', 'denominator' => 'items_total', 'operator' => '<', 'value' => 0.3, 'min_denominator' => 20]]);

        app(SignalEvaluationService::class)->run();
        $byTemplate = Signal::with('template')->get()->groupBy(fn ($s) => $s->template->name)->map(fn ($g) => $g->pluck('entity_id')->sort()->values()->all());

        $this->assertSame([$this->ids['a1']], $byTemplate['Trend']);
        // Quote: A (1/61) und A1 (1/71) unter 30 %, A2 hat nur 11 im Nenner (< min_denominator)
        $this->assertEqualsCanonicalizing([$this->ids['a'], $this->ids['a1']], $byTemplate['Quote']);
    }

    public function test_validation_rejects_incomplete_rules(): void
    {
        $r = $this->tool(CreateSignalTemplateTool::class, ['name' => 'Kaputt', 'pattern_type' => 'ratio', 'conditions' => ['numerator' => 'items_done']]);
        $this->assertFalse($r->success);
        $this->assertStringContainsString('denominator', $r->error['message'] ?? json_encode($r->error));

        $r = $this->tool(CreateSignalTemplateTool::class, ['name' => 'KI ohne Prompt', 'kind' => 'inference']);
        $this->assertFalse($r->success);
    }

    public function test_lifecycle_with_resolution_note_and_link(): void
    {
        $this->ruleTemplate();
        app(SignalEvaluationService::class)->run();
        $signal = Signal::where('entity_id', $this->ids['a1'])->first();

        $r = $this->tool(UpdateSignalTool::class, ['id' => $signal->id, 'action' => 'acknowledge', 'comment' => 'Schaue ich mir an']);
        $this->assertTrue($r->success);
        $this->assertSame('acknowledged', $r->data['signal']['status']);
        $this->assertCount(1, $r->data['signal']['comments']);

        $r = $this->tool(UpdateSignalTool::class, ['id' => $signal->id, 'assignee_entity_id' => $this->ids['a'], 'snooze_until' => now()->addDay()->toIso8601String(), 'focus' => true]);
        $this->assertTrue($r->success);
        $this->assertSame($this->ids['a'], $r->data['signal']['assignee_entity_id']);
        $this->assertNotNull($r->data['signal']['snooze_until']);
        $this->assertSame(0, $this->tool(ListSignalsTool::class, ['entity_id' => $this->ids['a1']])->data['pagination']['total'], 'zurückgestellt = nicht bearbeitbar');

        // Auflösen mit Notiz und Verweis auf ein anderes Signal (Morph-Alias „signals_signal“ als Stellvertreter für Beschluss/Aufgabe)
        $other = Signal::where('entity_id', $this->ids['a'])->first();
        $r = $this->tool(UpdateSignalTool::class, ['id' => $signal->id, 'action' => 'resolve', 'resolution_note' => 'Im LA entschieden: zweites Team', 'link_type' => 'signals_signal', 'link_id' => $other->id]);
        $this->assertTrue($r->success, json_encode($r->error ?? null));
        $this->assertSame('resolved', $r->data['signal']['status']);
        $this->assertSame('Im LA entschieden: zweites Team', $r->data['signal']['resolution_note']);
        $this->assertSame(['type' => 'signals_signal', 'id' => $other->id], $r->data['signal']['resolution_link']);
        $this->assertSame($this->s['user']->id, $r->data['signal']['resolved_by_user_id']);
        $this->assertSame($other->id, $signal->fresh()->resolutionLink->id);

        $bad = $this->tool(UpdateSignalTool::class, ['id' => $other->id, 'action' => 'resolve', 'link_type' => 'gibt_es_nicht', 'link_id' => 1]);
        $this->assertFalse($bad->success);
    }

    public function test_agent_creates_inference_signal_once_and_actions_drive_status(): void
    {
        $r = $this->tool(CreateSignalTemplateTool::class, [
            'name' => 'Stille Einheiten', 'kind' => 'inference', 'vsm_system' => 's3_star', 'severity' => 'warning',
            'prompt' => 'Welche Einheiten zeigen keine Bewegung?', 'signal_set_id' => $this->set->id,
        ]);
        $this->assertTrue($r->success, json_encode($r->error ?? null));
        $templateId = $r->data['template']['id'];

        $args = ['signal_set_id' => $this->set->id, 'signal_template_id' => $templateId, 'entity_id' => $this->ids['a2'], 'message' => 'A2 ist still', 'actions' => [['title' => 'Nachfragen'], ['title' => 'Daten prüfen']]];
        $first = $this->tool(CreateSignalTool::class, $args, $this->s['agent']);
        $this->assertTrue($first->success, json_encode($first->error ?? null));
        $this->assertTrue($first->data['created']);
        $this->assertSame('inference', $first->data['signal']['source_type']);
        $this->assertSame('s3_star', $first->data['signal']['vsm_level']);
        $this->assertSame($this->ids['ratchet'], $first->data['signal']['created_by_agent_entity_id']);

        $second = $this->tool(CreateSignalTool::class, array_merge($args, ['message' => 'A2 weiterhin still', 'severity' => 'critical']), $this->s['agent']);
        $this->assertFalse($second->data['created']);
        $this->assertSame(1, Signal::count());
        $this->assertSame('critical', Signal::first()->severity, 'Schwere steigt');

        $signal = Signal::first();
        [$a1, $a2] = $signal->actions->pluck('id')->all();
        $this->tool(UpdateSignalTool::class, ['id' => $signal->id, 'action_id' => $a1, 'action_status' => 'applied']);
        $this->assertSame('open', $signal->fresh()->status, 'eine Maßnahme noch offen');
        $this->tool(UpdateSignalTool::class, ['id' => $signal->id, 'action_id' => $a2, 'action_status' => 'applied']);
        $this->assertSame('resolved', $signal->fresh()->status);
    }

    public function test_migration_is_idempotent_and_keeps_history(): void
    {
        $args = ['entity_id' => $this->ids['b'], 'message' => 'Altes Signal', 'legacy_organization_signal_id' => 339, 'status' => 'acknowledged', 'severity' => 'warning', 'created_at' => '2026-11-08 08:13:00'];
        $r = $this->tool(CreateSignalTool::class, $args);
        $this->assertTrue($r->data['created']);
        $this->assertSame('migration', Signal::first()->source);
        $this->assertSame('2026-11-08', Signal::first()->created_at->toDateString());
        $this->assertFalse($this->tool(CreateSignalTool::class, $args)->data['created']);
        $this->assertSame(1, Signal::count());
    }

    public function test_templates_listing_shows_sets_and_effective_settings(): void
    {
        $id = $this->ruleTemplate();
        $r = $this->tool(ListSignalTemplatesTool::class, ['id' => $id]);
        $this->assertTrue($r->success);
        $this->assertSame('Basis', $r->data['template']['sets'][0]['signal_set']);
        $this->assertSame(50, $r->data['template']['sets'][0]['effective']['conditions']['value']);
    }
    public function test_overdue_signals_escalate_up_the_vsm_ladder(): void
    {
        $signal = Signal::create([
            'team_id' => $this->s['teamId'], 'entity_id' => $this->ids['a'], 'message' => 'überfällig', 'severity' => 'warning',
            'status' => 'open', 'vsm_level' => 's3', 'deadline_at' => now()->subHour(),
        ]);
        $r = app(\Platform\Signals\Services\SignalEscalationService::class)->run();
        $this->assertSame(1, $r['escalated']);
        $signal->refresh();
        $this->assertSame('s3_star', $signal->vsm_level);
        $this->assertSame('critical', $signal->severity);
        $this->assertTrue($signal->deadline_at->isFuture());

        // bestätigt = keine Eskalation mehr
        $signal->update(['deadline_at' => now()->subHour(), 'acknowledged_at' => now()]);
        $this->assertSame(0, app(\Platform\Signals\Services\SignalEscalationService::class)->run()['escalated']);
    }
    public function test_new_responsible_agent_takes_over_open_signals(): void
    {
        $this->ruleTemplate();
        app(\Platform\Signals\Services\SignalSetService::class)->update($this->set, ['responsible_agent_user_id' => null]);
        app(SignalEvaluationService::class)->run();
        $this->assertTrue(Signal::all()->every(fn ($s) => $s->responsible_user_id === null));

        app(\Platform\Signals\Services\SignalSetService::class)->update($this->set->fresh(), ['responsible_agent_user_id' => $this->s['agent']->id]);
        $this->assertTrue(Signal::all()->every(fn ($s) => $s->responsible_user_id === $this->s['agent']->id));
    }
    public function test_entity_type_filter_limits_where_a_rule_fires(): void
    {
        $templateId = $this->ruleTemplate();
        $this->tool(UpsertSetTemplateTool::class, ['signal_set_id' => $this->set->id, 'signal_template_id' => $templateId, 'conditions_override' => ['exclude_entity_types' => ['business_unit']]]);
        app(SignalEvaluationService::class)->run();
        $this->assertSame(0, Signal::count(), 'alle Knoten im Test sind business_unit');

        $this->tool(UpsertSetTemplateTool::class, ['signal_set_id' => $this->set->id, 'signal_template_id' => $templateId, 'conditions_override' => ['entity_types' => ['business_unit']]]);
        app(SignalEvaluationService::class)->run(null, true);
        $this->assertSame(2, Signal::count());
    }
}
