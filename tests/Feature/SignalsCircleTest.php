<?php

namespace Tests\Feature;

require_once __DIR__ . '/SignalsHarness.php';

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Platform\Signals\Models\Signal;
use Platform\Signals\Services\CircleService;
use Platform\Signals\Support\CommitteeGateways;
use Platform\Signals\Tools\CreateCommitteeItemTool;
use Platform\Signals\Tools\CreateSignalTool;
use Platform\Signals\Tools\GetCommitteeSituationTool;
use Platform\Signals\Tools\UpdateSignalTool;
use Tests\TestCase;

/**
 * Regelkreis (Architektur v2, MGT-101) – nur gegen den Vertrag CommitteeGateway (Test-Double) und die Semantik der
 * Organisation (VSM-Klassen). Knotentypen heißen bewusst nichtssagend: Signals darf keine Typ-Codes kennen.
 *
 * Struktur: Gruppe (Träger) ─┬─ Governance (Ordner) ── Gruppen-LA
 *                            ├─ Einheit A (Träger, geführt von Martin) ── Projekt A1
 *                            └─ Einheit B (Träger)
 * Aufruf: tests/run-in-taiste.sh tests/Feature/SignalsCircleTest.php
 */
class SignalsCircleTest extends TestCase
{
    use SignalsHarness;

    private FakeCommitteeGateway $gw;
    private array $types = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootModule();
        $this->withoutExceptionHandling();
        $this->seedWorld();
        \Illuminate\Support\Facades\Gate::before(fn () => true);
        Relation::morphMap([FakeCommitteeGateway::REF => \Platform\Core\Models\User::class]);

        foreach (['carrier' => 't_alpha', 'actor' => 't_beta', 'observed' => 't_gamma'] as $class => $code) {
            $this->types[$class] = $this->row('organization_entity_types', ['code' => $code, 'name' => $code, 'team_id' => $this->s['teamId'], 'is_active' => true, 'sort_order' => 9,
                'entity_type_group_id' => DB::table('organization_entity_type_groups')->value('id'), 'vsm_class' => $class]);
        }
        foreach (['group', 'a', 'b'] as $k) {
            $this->retype($this->ids[$k], 'carrier');
        }
        foreach (['a1', 'a2'] as $k) {
            $this->retype($this->ids[$k], 'observed');
        }
        $this->ids['gov'] = $this->node('Governance', $this->ids['group'], 'observed');
        $this->ids['la'] = $this->node('Gruppe Lenkungsausschuss', $this->ids['gov'], 'actor');
        $this->ids['martin'] = $this->node('Martin', null, 'actor');
        DB::table('organization_entities')->where('id', $this->ids['martin'])->update(['linked_user_id' => $this->s['user']->id]);
        $this->relate($this->ids['martin'], $this->ids['a'], 'manages', 'Führt an');

        $this->gw = new FakeCommitteeGateway();
        $this->gw->addCommittee($this->ids['la'], 'Gruppen-LA');
        CommitteeGateways::register($this->gw);
    }

    protected function tearDown(): void
    {
        CommitteeGateways::forget();
        parent::tearDown();
    }

    private function node(string $name, ?int $parent, string $class): int
    {
        return $this->row('organization_entities', ['name' => $name, 'team_id' => $this->s['teamId'], 'user_id' => $this->s['user']->id,
            'entity_type_id' => $this->types[$class], 'parent_entity_id' => $parent, 'is_active' => true]);
    }

    private function retype(int $id, string $class): void
    {
        DB::table('organization_entities')->where('id', $id)->update(['entity_type_id' => $this->types[$class]]);
    }

    private function signalAt(int $entityId): Signal
    {
        $r = $this->tool(CreateSignalTool::class, ['entity_id' => $entityId, 'message' => 'Projekt hängt'], $this->s['agent']);
        $this->assertTrue($r->success, json_encode($r->error ?? null));

        return Signal::find($r->data['signal']['id']);
    }

    private function lift(Signal $signal, array $override = [])
    {
        return $this->tool(CreateCommitteeItemTool::class, $override + [
            'signal_id' => $signal->id, 'goal' => 'Projekt bis Q1 live', 'observation' => 'seit 6 Wochen kein Fortschritt',
            'obstacle' => 'Entwicklerin auch in Einheit B gebunden', 'local_solution' => 'Scope verkleinern',
            'system_support' => 'Priorität zwischen A und B klären', 'local_solution_confirmed' => true,
        ], $this->s['agent']);
    }

    /** Venture V (Träger) unter der Gruppe, darin ein Projekt; optional mit eigenem LA. */
    private function venture(bool $withLa): array
    {
        $v = $this->node('FOOD.WORKS', $this->ids['group'], 'carrier');
        $p = $this->node('Lead-Projekt', $v, 'observed');
        $vLa = $this->node('FOOD.WORKS LA', $this->node('Governance V', $v, 'observed'), 'actor');
        if ($withLa) {
            $this->gw->addCommittee($vLa, 'Venture-LA');
        }

        return compact('v', 'p', 'vLa');
    }

    public function test_signal_belongs_to_the_nearest_carrier(): void
    {
        $signal = $this->signalAt($this->ids['a1']);
        $this->assertSame($this->ids['a'], $signal->unit_entity_id);
        $this->assertSame('unit', $signal->stage);
        $this->assertSame('Martin', app(CircleService::class)->leaderOf($this->ids['a'])['name']);
        $this->assertSame($this->ids['la'], app(CircleService::class)->committeeAbove($this->ids['a'])['entity_id']);
    }

    public function test_solve_locally_with_a_frame_from_the_gateway(): void
    {
        $signal = $this->signalAt($this->ids['a1']);
        $this->gw->frames[$this->ids['a']] = [['type' => FakeCommitteeGateway::REF, 'decision_id' => $this->s['user']->id, 'text' => 'A plant Personal selbst', 'since' => null, 'committee' => 'Gruppen-LA']];
        $r = $this->tool(UpdateSignalTool::class, ['id' => $signal->id, 'action' => 'solve_locally', 'resolution_note' => 'Scope verkleinert', 'frame_id' => $this->s['user']->id], $this->s['agent']);
        $this->assertTrue($r->success, json_encode($r->error ?? null));
        $this->assertSame('solved_locally', $r->data['signal']['outcome']);
        $this->assertSame(FakeCommitteeGateway::REF, Signal::find($signal->id)->resolution_link_type);
    }

    public function test_lift_requires_4b_and_4a(): void
    {
        $signal = $this->signalAt($this->ids['a1']);
        $this->assertStringContainsString('4b', json_encode($this->lift($signal, ['system_support' => ''])->error));
        $this->assertFalse($this->lift($signal, ['local_solution' => ''])->success);
        $this->assertSame([], $this->gw->items);
    }

    public function test_lift_drafts_and_outcomes_flow_back(): void
    {
        $signal = $this->signalAt($this->ids['a1']);
        $r = $this->lift($signal);
        $this->assertTrue($r->success, json_encode($r->error ?? null));
        $this->assertSame('committee', $r->data['signal']['stage']);
        $this->assertEquals(['link_type' => Signal::MORPH_ALIAS, 'link_id' => $signal->id, 'unit_entity_id' => $this->ids['a']],
            array_intersect_key($this->gw->items[1], array_flip(['link_type', 'link_id', 'unit_entity_id'])));

        $this->gw->decide(1, 'return_to_unit', 'A und B klären das bilateral', $this->s['user']->id);
        $signal->refresh();
        $this->assertSame(['unit', 'returned_to_unit', false], [$signal->stage, $signal->outcome, $signal->isClosed()]);

        $this->lift($signal);
        $this->gw->decide(2, 'measure', 'Entwicklerin 2 Tage/Woche bei A', $this->s['user']->id);
        $signal->refresh();
        $this->assertSame(['resolved', 'measure', FakeCommitteeGateway::REF], [$signal->status, $signal->outcome, $signal->resolution_link_type]);
    }

    public function test_outcomes_for_foreign_objects_are_ignored(): void
    {
        $signal = $this->signalAt($this->ids['a1']);
        $this->lift($signal);
        $this->gw->items[1]['link_type'] = 'something_else';
        $this->gw->decide(1, 'measure', 'gilt nicht dem Signal', $this->s['user']->id);
        $this->assertSame('committee', $signal->fresh()->stage);
    }

    public function test_group_la_works_as_proxy_for_a_venture_without_own_la(): void
    {
        $x = $this->venture(false);
        $above = app(CircleService::class)->committeeAbove($x['p']);
        $this->assertSame($this->ids['la'], $above['entity_id']);
        $this->assertSame($x['v'], $above['proxy_for_entity_id']);
        $this->assertSame('unit', $above['level'], 'ohne LA darunter ist auch die Gruppe (noch) eine Ebene');
    }

    public function test_venture_la_takes_its_own_signals_and_escalates_one_level_up(): void
    {
        $x = $this->venture(true);
        $circle = app(CircleService::class);
        $this->assertSame($x['vLa'], $circle->committeeAbove($x['p'])['entity_id']);
        $this->assertSame(['group', [$x['vLa']]], [$circle->committeeInfo($this->ids['la'])['level'], $circle->committeeInfo($this->ids['la'])['sub_committees']]);
        $this->assertSame('unit', $circle->committeeInfo($x['vLa'])['level']);

        $signal = $this->signalAt($x['p']);
        $this->assertSame($x['v'], $signal->unit_entity_id);
        $this->assertNotContains($signal->id, $circle->committeeSituation($this->ids['la'], $this->s['teamId'])['candidates']->pluck('id')->all());

        $r = $this->lift($signal);
        $this->assertSame($this->gw->meetings[$x['vLa']]['meeting_id'], $r->data['meeting']['meeting_id']);
        $this->gw->decide(1, 'escalation', 'Braucht Entscheidung der Gruppe', $this->s['user']->id);
        $signal->refresh();
        $this->assertSame(['escalated', $this->ids['la'], $x['vLa']], [$signal->stage, $signal->committee_entity_id, $signal->origin_committee_entity_id]);
        $this->assertSame([$signal->id], $circle->committeeSituation($this->ids['la'], $this->s['teamId'])['escalated_here']->pluck('id')->all());

        $r2 = $this->lift($signal->fresh());
        $this->assertTrue($r2->success, json_encode($r2->error ?? null));
        $this->assertSame($this->gw->meetings[$this->ids['la']]['meeting_id'], $r2->data['meeting']['meeting_id']);
    }

    public function test_stewards_can_be_humans_or_agents(): void
    {
        $x = $this->venture(true);
        $this->relate($this->ids['martin'], $this->ids['la'], 'stewards', 'betreut (Regelkreis)');
        $this->relate($this->ids['ratchet'], $x['vLa'], 'stewards', 'betreut (Regelkreis)');
        $circle = app(CircleService::class);

        $this->assertSame([$this->ids['la']], array_column($circle->committeesFor($this->s['user']->id), 'entity_id'));
        $this->assertSame([$x['vLa']], array_column($circle->committeesFor($this->s['agent']->id), 'entity_id'));
        $this->assertTrue($circle->stewards($x['vLa'])[0]['is_agent']);
        $this->assertFalse($circle->stewards($this->ids['la'])[0]['is_agent']);

        $r = $this->tool(GetCommitteeSituationTool::class, [], $this->s['agent']);
        $this->assertSame([$x['vLa']], array_map(fn ($c) => $c['committee']['entity_id'], $r->data['circles']));

        $this->bootUi();
        $this->get(route('signals.circle.index'))->assertOk()->assertSee('Gruppen-LA')->assertSee('Gruppe');
    }

    public function test_responsible_users_named_by_the_gateway_are_stewards_too(): void
    {
        $this->gw->committees[0]['responsible_user_ids'] = [$this->s['agent']->id];
        $this->assertSame([$this->ids['la']], array_column(app(CircleService::class)->committeesFor($this->s['agent']->id), 'entity_id'));
    }

    public function test_legacy_signals_are_delivered_when_the_situation_is_read(): void
    {
        $signal = $this->signalAt($this->ids['a1']);
        $signal->forceFill(['unit_entity_id' => null])->save();
        $sit = $this->tool(GetCommitteeSituationTool::class, ['committee_entity_id' => $this->ids['la']], $this->s['agent']);
        $this->assertSame([$signal->id], array_column($sit->data['circles'][0]['candidates'], 'id'));
        $this->assertSame('Martin', $sit->data['circles'][0]['units'][$this->ids['a']]['leader']['name']);
    }

    /** Lose Kopplung als Regel: im Signals-Code keine fremden Module und keine Knotentyp-Codes der Organisation. */
    public function test_signals_knows_no_foreign_modules_or_type_codes(): void
    {
        $root = getenv('SIGNALS_MODULE_PATH');
        $files = array_merge(glob("$root/src/*/*.php"), glob("$root/src/*/*/*.php"), glob("$root/src/*.php"));
        $offenders = [];
        foreach ($files as $f) {
            $src = file_get_contents($f);
            foreach (['ManagementBook', 'management_book', 'Planner\\', 'Helpdesk', "'container'", "'board'", "'venture", "'holding'", "->where('code', 'agent')"] as $needle) {
                if (str_contains($src, $needle)) {
                    $offenders[] = basename($f) . ": {$needle}";
                }
            }
        }
        $this->assertSame([], $offenders);
    }
}
