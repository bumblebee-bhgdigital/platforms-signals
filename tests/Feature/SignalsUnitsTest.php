<?php

namespace Tests\Feature;

require_once __DIR__ . '/SignalsHarness.php';

use Illuminate\Support\Facades\DB;
use Platform\Signals\Contracts\ResponsibilityLookup;
use Platform\Signals\Models\Signal;
use Platform\Signals\Models\SignalSet;
use Platform\Signals\Models\SignalSetTemplate;
use Platform\Signals\Services\SignalEvaluationService;
use Platform\Signals\Services\SignalSetService;
use Platform\Signals\Services\SignalTemplateService;
use Platform\Signals\Tools\EvaluateSignalsTool;
use Platform\Signals\Tools\UpsertSetTemplateTool;
use Tests\TestCase;

/**
 * Regeln je Einheit (units_only) und Fluss-Regeln: Filter auf Einheiten mit _cascaded-Werten, Beiträger in der Meldung,
 * Auflösung alter Projekt-Signale nach dem Umstellen, kombinierte Schwelle (all), Trend mit min_value, Probelauf ohne Schreiben.
 *
 * Baum: Gruppe (Träger) ─┬─ Einheit A (Träger) ─┬─ Projekt A1, Projekt A2 (beobachtet)
 *                        │                       └─ Labor (Träger) ── Projekt L1 (beobachtet)
 *                        └─ Einheit B (Träger)
 * Aufruf: tests/run-in-taiste.sh tests/Feature/SignalsUnitsTest.php
 */
class SignalsUnitsTest extends TestCase
{
    use SignalsHarness;

    private SignalSet $set;
    private FakeResponsibilityLookup $lookup;
    private array $types = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootModule();
        $this->withoutExceptionHandling();
        $this->seedWorld();
        \Illuminate\Support\Facades\Gate::before(fn () => true);

        foreach (['carrier' => 't_alpha', 'observed' => 't_gamma'] as $class => $code) {
            $this->types[$class] = $this->row('organization_entity_types', ['code' => $code, 'name' => $code, 'team_id' => $this->s['teamId'], 'is_active' => true, 'sort_order' => 9,
                'entity_type_group_id' => DB::table('organization_entity_type_groups')->value('id'), 'vsm_class' => $class]);
        }
        foreach (['group' => 'carrier', 'a' => 'carrier', 'b' => 'carrier', 'a1' => 'observed', 'a2' => 'observed'] as $k => $class) {
            DB::table('organization_entities')->where('id', $this->ids[$k])->update(['entity_type_id' => $this->types[$class]]);
        }
        $node = fn ($name, $parent, $class) => $this->row('organization_entities', ['name' => $name, 'team_id' => $this->s['teamId'], 'user_id' => $this->s['user']->id,
            'entity_type_id' => $this->types[$class], 'parent_entity_id' => $parent, 'is_active' => true]);
        $this->ids['lab'] = $node('Labor', $this->ids['a'], 'carrier');
        $this->ids['l1'] = $node('Projekt L1', $this->ids['lab'], 'observed');

        $this->lookup = new FakeResponsibilityLookup();
        $this->lookup->hold($this->ids['group'], $this->ids['ratchet'], $this->s['agent']->id, 'Ratchet', 'Führt an', 'agent');
        $this->app->instance(ResponsibilityLookup::class, $this->lookup);

        $this->set = app(SignalSetService::class)->create($this->s['user'], $this->s['team'], ['title' => 'Fluss', 'responsible_agent_user_id' => $this->s['agent']->id]);
        app(SignalSetService::class)->link($this->set, $this->ids['group'], true);
        app(SignalSetService::class)->activate($this->set);
    }

    private function template(array $conditions, string $pattern = 'threshold', string $name = 'Überfällige stauen sich'): int
    {
        $id = app(SignalTemplateService::class)->create($this->s['user'], $this->s['teamId'], [
            'name' => $name, 'pattern_type' => $pattern, 'severity' => 'warning', 'conditions' => $conditions,
        ])->id;
        $r = $this->tool(UpsertSetTemplateTool::class, ['signal_set_id' => $this->set->id, 'signal_template_id' => $id]);
        $this->assertTrue($r->success, json_encode($r->error ?? null));

        return $id;
    }

    /** Überfällige: einfach je Knoten und als Summe über den Teilbaum (wie der Snapshot sie schreibt). */
    private function overdueSnapshots(): void
    {
        $own = ['group' => 0, 'a' => 0, 'a1' => 12, 'a2' => 8, 'lab' => 0, 'l1' => 4, 'b' => 3];
        $cascaded = ['group' => 27, 'a' => 24, 'a1' => 12, 'a2' => 8, 'lab' => 4, 'l1' => 4, 'b' => 3];
        foreach ($own as $k => $v) {
            $this->snapshot($this->ids[$k], ['planner_items_overdue' => $v, 'planner_items_overdue_cascaded' => $cascaded[$k]]);
        }
    }

    public function test_units_only_evaluates_only_units_with_cascaded_values_and_names_the_contributors(): void
    {
        $this->overdueSnapshots();
        $this->template(['units_only' => true, 'metric' => 'planner_items_overdue', 'operator' => '>=', 'value' => 5]);

        app(SignalEvaluationService::class)->run();

        $open = Signal::open()->get()->keyBy('entity_id');
        $this->assertEqualsCanonicalizing([$this->ids['group'], $this->ids['a']], $open->keys()->all(), 'nur Einheiten, Labor (4) und B (3) unter der Schwelle, Projekte gar nicht');

        $a = $open[$this->ids['a']];
        $this->assertSame(24, $a->trigger_metrics['planner_items_overdue'], 'Summe über den Teilbaum');
        $this->assertStringContainsString('= 24 (>= 5)', $a->message);
        $this->assertStringContainsString('– vor allem: Projekt A1 (12), Projekt A2 (8), Labor (4)', $a->message);
        $this->assertSame([
            ['entity_id' => $this->ids['a1'], 'name' => 'Projekt A1', 'value' => 12],
            ['entity_id' => $this->ids['a2'], 'name' => 'Projekt A2', 'value' => 8],
            ['entity_id' => $this->ids['lab'], 'name' => 'Labor', 'value' => 4],
        ], $a->trigger_metrics['contributors']);

        // An der Gruppe: nicht in Einheit A hinein abgestiegen – A zählt als Ganzes, Projekt L1/A1 tauchen nicht auf
        $group = $open[$this->ids['group']];
        $this->assertSame(['Einheit A', 'Einheit B'], array_column($group->trigger_metrics['contributors'], 'name'));
        $this->assertSame(24, $group->trigger_metrics['contributors'][0]['value']);
    }

    public function test_project_level_signals_resolve_after_switching_to_units_only(): void
    {
        $this->overdueSnapshots();
        $templateId = $this->template(['metric' => 'planner_items_overdue', 'operator' => '>=', 'value' => 5]);

        app(SignalEvaluationService::class)->run();
        $this->assertEqualsCanonicalizing([$this->ids['a1'], $this->ids['a2']], Signal::open()->pluck('entity_id')->all(), 'bisher je Projekt');

        $r = $this->tool(UpsertSetTemplateTool::class, ['signal_set_id' => $this->set->id, 'signal_template_id' => $templateId, 'conditions_override' => ['units_only' => true]]);
        $this->assertTrue($r->success);
        app(SignalEvaluationService::class)->run(null, true);

        $this->assertEqualsCanonicalizing([$this->ids['group'], $this->ids['a']], Signal::open()->pluck('entity_id')->all());
        foreach (['a1', 'a2'] as $k) {
            $s = Signal::where('entity_id', $this->ids[$k])->first();
            $this->assertSame('resolved', $s->status);
            $this->assertSame('Automatisch aufgelöst: Regel zählt jetzt je Einheit – zusammengefasst an Einheit A.', $s->resolution_note);
        }
    }

    public function test_combined_threshold_needs_all_parts(): void
    {
        $this->snapshot($this->ids['a1'], ['planner_items_open' => 6, 'planner_items_done_7d' => 0]);
        $this->snapshot($this->ids['a2'], ['planner_items_open' => 9, 'planner_items_done_7d' => 2]);
        $this->snapshot($this->ids['b'], ['planner_items_done_7d' => 0]); // offene fehlen → kein Treffer
        $this->template(['all' => [
            ['metric' => 'planner_items_open', 'operator' => '>=', 'value' => 5],
            ['metric' => 'planner_items_done_7d', 'operator' => '==', 'value' => 0],
        ]], 'threshold', 'Stillstand');

        app(SignalEvaluationService::class)->run();

        $open = Signal::open()->get();
        $this->assertSame([$this->ids['a1']], $open->pluck('entity_id')->all());
        $this->assertSame('Projekt A1: planner_items_open = 6 (>= 5) und planner_items_done_7d = 0 (== 0)', $open[0]->message);
        $this->assertSame(['planner_items_open' => 6, 'planner_items_done_7d' => 0], $open[0]->trigger_metrics);
    }

    public function test_trend_respects_min_value(): void
    {
        foreach ([2 => 100, 1 => 150, 0 => 200] as $daysAgo => $v) {
            $this->snapshot($this->ids['a1'], ['time_total_minutes' => $v], $daysAgo);
            $this->snapshot($this->ids['a2'], ['time_total_minutes' => $v * 4], $daysAgo);
        }
        $this->template(['metric' => 'time_total_minutes', 'direction' => 'increasing', 'periods' => 3, 'min_change_percent' => 10, 'min_value' => 600], 'trend', 'Zeitaufwand steigt');

        app(SignalEvaluationService::class)->run();

        $this->assertSame([$this->ids['a2']], Signal::open()->pluck('entity_id')->all(), 'A1 steigt auch, liegt aber unter 600 Minuten');
    }

    public function test_dry_run_reports_hits_recipients_and_resolutions_without_writing(): void
    {
        $this->overdueSnapshots();
        $templateId = $this->template(['metric' => 'planner_items_overdue', 'operator' => '>=', 'value' => 5]);
        app(SignalEvaluationService::class)->run();
        $this->assertSame(2, Signal::count());
        DB::table('signal_set_templates')->update(['conditions_override' => json_encode(['units_only' => true]), 'last_evaluated_at' => null]);
        $state = fn () => json_encode([Signal::orderBy('id')->get()->toArray(), DB::table('signal_set_templates')->get()->toArray(), DB::table('signal_comments')->count()]);
        $before = $state();

        $r = $this->tool(EvaluateSignalsTool::class, ['set_id' => $this->set->id, 'template_id' => $templateId]);
        $this->assertTrue($r->success, json_encode($r->error ?? null));
        $this->assertTrue($r->data['dry_run']);

        $result = $r->data['results'][0];
        $hits = collect($result['hits'])->keyBy('entity_id');
        $this->assertEqualsCanonicalizing([$this->ids['group'], $this->ids['a']], $hits->keys()->all());
        $this->assertSame('new', $hits[$this->ids['a']]['action']);
        $this->assertSame('Einheit A', $hits[$this->ids['a']]['entity']);
        $this->assertSame('Projekt A1', $hits[$this->ids['a']]['contributors'][0]['name']);
        $this->assertSame(['found' => true, 'node' => 'Gruppe', 'holders' => ['Ratchet']], $hits[$this->ids['a']]['recipients']);
        $this->assertEqualsCanonicalizing([$this->ids['a1'], $this->ids['a2']], array_column($result['resolving'], 'entity_id'));
        $this->assertStringContainsString('je Einheit', $result['resolving'][0]['note']);
        $this->assertSame(2, $result['created']);
        $this->assertSame(2, $result['resolved']);

        $this->assertSame($before, $state(), 'Probelauf schreibt nichts');
        $this->assertNull(SignalSetTemplate::first()->last_evaluated_at);
    }
}
