<?php

namespace Tests\Feature;

require_once __DIR__ . '/SignalsHarness.php';

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Platform\Signals\Contracts\ResponsibilityLookup;
use Platform\Signals\Models\Signal;
use Platform\Signals\Models\SignalSet;
use Platform\Signals\Services\CircleService;
use Platform\Signals\Services\SignalEscalationService;
use Platform\Signals\Services\SignalEvaluationService;
use Platform\Signals\Services\SignalService;
use Platform\Signals\Services\SignalSetService;
use Platform\Signals\Services\SignalTemplateService;
use Platform\Signals\Support\CommitteeGateways;
use Platform\Signals\Tools\GetMySignalsTool;
use Platform\Signals\Tools\UpdateSignalTool;
use Platform\Signals\Tools\UpsertSetTemplateTool;
use Tests\TestCase;

/**
 * Zustellung und Eskalation (Architektur v3, Z1–Z9) – nur gegen Test-Doubles der Verträge (ResponsibilityLookup,
 * CommitteeGateway) und mit nichtssagenden Typ-Codes.
 *
 * Struktur: Gruppe (Träger, Clara „Führt an“) ─┬─ Einheit A (Träger, Bernd „Venture Owner“) ─┬─ Projekt A1 (Anna „Projektleitung“)
 *                                              │                                             └─ Projekt A2 (niemand)
 *                                              └─ Einheit B (Träger, niemand)
 * Allgemeines Set an der Gruppe, vererbt; Template „Überlast“ items_open > 50.
 * Aufruf: tests/run-in-taiste.sh tests/Feature/SignalsDeliveryTest.php
 */
class SignalsDeliveryTest extends TestCase
{
    use SignalsHarness;

    private FakeResponsibilityLookup $lookup;
    private SignalSet $general;
    private int $templateId;
    private array $types = [];
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootModule();
        $this->withoutExceptionHandling();
        $this->seedWorld();
        \Illuminate\Support\Facades\Gate::before(fn () => true);

        foreach (['carrier' => 't_alpha', 'actor' => 't_beta', 'observed' => 't_gamma'] as $class => $code) {
            $this->types[$class] = $this->row('organization_entity_types', ['code' => $code, 'name' => $code, 'team_id' => $this->s['teamId'], 'is_active' => true, 'sort_order' => 9,
                'entity_type_group_id' => DB::table('organization_entity_type_groups')->value('id'), 'vsm_class' => $class]);
        }
        foreach (['group' => 'carrier', 'a' => 'carrier', 'b' => 'carrier', 'a1' => 'observed', 'a2' => 'observed'] as $k => $class) {
            DB::table('organization_entities')->where('id', $this->ids[$k])->update(['entity_type_id' => $this->types[$class]]);
        }
        $this->ids['anna'] = $this->person('Anna');
        $this->ids['bernd'] = $this->person('Bernd', $this->ids['a']); // Person im Baum (für „befangen“)
        $this->ids['clara'] = $this->person('Clara');

        $this->lookup = new FakeResponsibilityLookup();
        $this->lookup->hold($this->ids['a1'], $this->ids['anna'], $this->users['Anna']->id, 'Anna', 'Projektleitung');
        $this->lookup->hold($this->ids['a'], $this->ids['bernd'], $this->users['Bernd']->id, 'Bernd', 'Venture Owner');
        $this->lookup->hold($this->ids['group'], $this->ids['clara'], $this->users['Clara']->id, 'Clara', 'Führt an');
        $this->app->instance(ResponsibilityLookup::class, $this->lookup);

        $this->general = $this->set('Allgemein', $this->ids['group'], true);
        $this->templateId = app(SignalTemplateService::class)->create($this->s['user'], $this->s['teamId'], [
            'name' => 'Überlast', 'pattern_type' => 'threshold', 'severity' => 'warning', 'conditions' => ['metric' => 'items_open', 'operator' => '>', 'value' => 50],
        ])->id;
        $this->assign($this->general);

        foreach (['a' => 60, 'a1' => 70, 'a2' => 10, 'b' => 99] as $k => $open) {
            $this->snapshot($this->ids[$k], ['items_open' => $open]);
        }
    }

    protected function tearDown(): void
    {
        CommitteeGateways::forget();
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Hilfen ───────────────────────────────────────────────────

    private function person(string $name, ?int $parent = null): int
    {
        $user = \Platform\Core\Models\User::create(['name' => $name, 'email' => strtolower($name) . '@x.test', 'password' => 'x']);
        DB::table('team_user')->insert(['team_id' => $this->s['teamId'], 'user_id' => $user->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
        $user->forceFill(['current_team_id' => $this->s['teamId']])->save();
        $this->users[$name] = $user->fresh();

        return $this->row('organization_entities', ['name' => $name, 'team_id' => $this->s['teamId'], 'user_id' => $this->s['user']->id,
            'entity_type_id' => $this->types['actor'], 'parent_entity_id' => $parent, 'is_active' => true, 'linked_user_id' => $user->id]);
    }

    private function set(string $title, int $entityId, bool $inherit): SignalSet
    {
        $set = app(SignalSetService::class)->create($this->s['user'], $this->s['team'], ['title' => $title, 'responsible_agent_user_id' => $this->s['agent']->id]);
        app(SignalSetService::class)->link($set, $entityId, $inherit);

        return app(SignalSetService::class)->activate($set);
    }

    private function assign(SignalSet $set, array $args = [], ?int $templateId = null): array
    {
        $r = $this->tool(UpsertSetTemplateTool::class, $args + ['signal_set_id' => $set->id, 'signal_template_id' => $templateId ?? $this->templateId]);
        $this->assertTrue($r->success, json_encode($r->error ?? null));

        return $r->data['assignment'];
    }

    private function setOpen(string $key, int $open): void
    {
        DB::table('organization_entity_snapshots')->where('entity_id', $this->ids[$key])->update(['metrics' => json_encode(['items_open' => $open])]);
    }

    private function evaluate(): array
    {
        return app(SignalEvaluationService::class)->run(null, true);
    }

    private function at(string $key): ?Signal
    {
        return Signal::where('entity_id', $this->ids[$key])->where('signal_template_id', $this->templateId)->first();
    }

    private function openAt(string $key): array
    {
        return Signal::where('entity_id', $this->ids[$key])->whereIn('status', ['open', 'acknowledged'])->get()->all();
    }

    // ── Z1: das nächste gewinnt ──────────────────────────────────

    public function test_nearest_assignment_wins(): void
    {
        $aSet = $this->set('Einheit A', $this->ids['a'], true);
        $aAssignment = $this->assign($aSet, ['conditions_override' => ['value' => 65], 'severity_override' => 'critical']);

        $this->evaluate();
        $this->assertSame([], $this->openAt('a'), 'an A gilt das A-Set (65) – 60 liegt darunter');
        $this->assertSame([], $this->openAt('a2'));
        $a1 = $this->at('a1');
        $this->assertSame([$aAssignment['id'], $aSet->id, 'critical'], [$a1->signal_set_template_id, $a1->signal_set_id, $a1->severity]);
        $this->assertNotSame($aAssignment['id'], $this->at('b')->signal_set_template_id, 'an B gilt das allgemeine Set');
        $this->assertSame($this->general->id, $this->at('b')->signal_set_id);
    }

    public function test_opt_out_switches_the_template_off_unless_binding_above(): void
    {
        $aSet = $this->set('Einheit A', $this->ids['a'], true);
        $this->assign($aSet, ['opts_out' => true]);

        $this->evaluate();
        $this->assertSame([[], [], []], [$this->openAt('a'), $this->openAt('a1'), $this->openAt('a2')]);
        $this->assertCount(1, $this->openAt('b'));

        $general = $this->assign($this->general, ['is_binding' => true]);
        $this->evaluate();
        $this->assertCount(1, $this->openAt('a'), 'verbindlich oben: Abwahl zählt nicht');
        $this->assertSame($general['id'], $this->at('a1')->signal_set_template_id);

        // nicht mehr verbindlich → die Abwahl greift wieder, offene Signale schließen sich selbst
        $this->assign($this->general, ['is_binding' => false]);
        $this->evaluate();
        $this->assertSame('resolved', $this->at('a1')->status);
        $this->assertStringContainsString('gilt an diesem Knoten nicht mehr', $this->at('a1')->resolution_note);
    }

    public function test_one_open_signal_per_node_and_template_across_sets(): void
    {
        $second = $this->set('Zweites allgemeines Set', $this->ids['group'], true);
        $this->assign($second);
        $direct = $this->set('Direkt an A1', $this->ids['a1'], false);
        $directAssignment = $this->assign($direct);

        $this->evaluate();
        $this->evaluate();
        foreach (['a', 'a1', 'b'] as $k) {
            $this->assertCount(1, $this->openAt($k), "genau ein offenes Signal an {$k}");
        }
        $this->assertSame($directAssignment['id'], $this->at('a1')->signal_set_template_id, 'das Set direkt am Knoten ist das nächste');
        $this->assertSame(3, Signal::count());
    }

    // ── Z2: Empfänger ────────────────────────────────────────────

    public function test_recipient_is_found_from_below_with_reason_and_the_agent_stays_companion(): void
    {
        $this->setOpen('a2', 80);
        $this->evaluate();

        $a1 = $this->at('a1');
        $this->assertSame([[$this->ids['anna']], [$this->users['Anna']->id], $this->ids['a1']], [$a1->recipient_entity_ids, $a1->recipient_user_ids, $a1->recipient_node_entity_id]);
        $this->assertSame('verantwortlich an Projekt A1 (Projektleitung)', $a1->recipient_reason);
        $this->assertNotNull($a1->delivered_at);
        $this->assertSame($this->s['agent']->id, $a1->responsible_user_id, 'der Agent des Sets ist Absender und Begleiter');

        $a2 = $this->at('a2');
        $this->assertSame([$this->ids['bernd']], $a2->recipient_entity_ids);
        $this->assertSame('Projekt A2: niemand verantwortlich → nächster Verantwortlicher an Einheit A (Venture Owner)', $a2->recipient_reason);
        $this->assertSame('manage', collect($this->lookup->calls)->firstWhere(1, $this->ids['a2'])[2]);
    }

    public function test_the_subject_of_a_signal_is_skipped(): void
    {
        // Überlast der Person Bernd: Bernd selbst ist befangen – es geht an die Nächste darüber
        $signal = app(SignalService::class)->create(['team_id' => $this->s['teamId'], 'entity_id' => $this->ids['bernd'], 'message' => 'Bernd ist überlastet', 'severity' => 'warning']);
        $this->assertSame([$this->ids['clara']], $signal->recipient_entity_ids);
        $this->assertNotContains($this->users['Bernd']->id, $signal->recipient_user_ids);
        $this->assertStringContainsString('Gruppe (Führt an)', $signal->recipient_reason);
    }

    // ── Z4/Z3: wiederkehrend ─────────────────────────────────────

    public function test_recurrence_reopens_the_old_signal_and_escalates_at_the_threshold(): void
    {
        $this->evaluate();
        $first = $this->at('a1');
        app(SignalService::class)->transition($first, 'resolve', $this->users['Anna'], ['resolution_note' => 'Aufgaben bereinigt']);

        $this->evaluate();
        $again = $this->at('a1');
        $this->assertSame([$first->id, 'open', 2], [$again->id, $again->status, $again->recurrence_count], 'derselbe Vorgang, wieder geöffnet');
        $this->assertNotNull($again->reopened_at);
        $this->assertNull($again->reacted_at);
        $this->assertSame(1, Signal::where('entity_id', $this->ids['a1'])->count());
        $this->assertStringContainsString('zum 2. Mal in 1 Tag', $again->comments()->latest('id')->value('content'));
        $this->assertSame(0, app(SignalEscalationService::class)->run()['escalated'], '2× liegt unter der Schwelle 3');

        app(SignalService::class)->transition($again, 'resolve', $this->users['Anna'], ['resolution_note' => 'wieder bereinigt']);
        $this->evaluate();
        $third = $this->at('a1');
        $this->assertSame(3, $third->recurrence_count);

        $this->assertSame(1, app(SignalEscalationService::class)->run()['escalated']);
        $third->refresh();
        $this->assertSame([1, [$this->ids['bernd']], $this->ids['a']], [$third->escalation_level, $third->recipient_entity_ids, $third->recipient_node_entity_id]);
        $this->assertStringContainsString('wiederkehrend', $third->escalation_reason);
        $this->assertStringContainsString('bei Anna (Projekt A1)', $third->comments()->latest('id')->value('content'));
        $this->assertSame(0, app(SignalEscalationService::class)->run()['escalated'], 'nur einmal je Auslöser');
    }

    // ── Z3: ignoriert, in Arbeitstagen ───────────────────────────

    public function test_ignored_counts_working_days_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-27 10:00')); // Freitag
        $this->snapshot($this->ids['a1'], ['items_open' => 70]);
        $this->evaluate();
        $signal = $this->at('a1');
        $this->assertTrue($signal->delivered_at->isFriday());

        Carbon::setTestNow(Carbon::parse('2026-11-30 11:00')); // Montag: 3 Kalendertage, aber erst 1 Arbeitstag
        app(SignalEscalationService::class)->run();
        $this->assertSame(0, $signal->fresh()->escalation_level);

        Carbon::setTestNow(Carbon::parse('2026-12-01 11:00')); // Dienstag
        app(SignalEscalationService::class)->run();
        $this->assertSame(0, $signal->fresh()->escalation_level);

        Carbon::setTestNow(Carbon::parse('2026-12-02 10:00')); // Mittwoch: 3 Arbeitstage
        app(SignalEscalationService::class)->run();
        $signal->refresh();
        $this->assertSame([1, [$this->ids['bernd']]], [$signal->escalation_level, $signal->recipient_entity_ids]);
        $this->assertStringContainsString('ignoriert', $signal->escalation_reason);
        $this->assertStringContainsString('lag 3 Arbeitstage bei Anna', $signal->comments()->latest('id')->value('content'));

        app(SignalEscalationService::class)->run();
        $this->assertSame(1, $signal->fresh()->escalation_level, 'kein stündliches Wiederholen');

        // gesehen stoppt die Uhr: auch nach weiteren Arbeitstagen keine Eskalation wegen „ignoriert“
        app(SignalService::class)->transition($signal->fresh(), 'acknowledge', $this->users['Bernd']);
        Carbon::setTestNow(Carbon::parse('2026-12-08 10:00'));
        app(SignalEscalationService::class)->run();
        $this->assertSame(1, $signal->fresh()->escalation_level);
    }

    // ── Z3: abgelehnt ────────────────────────────────────────────

    public function test_reject_escalates_to_the_line_immediately(): void
    {
        $this->evaluate();
        $signal = $this->at('a1');

        $bad = $this->tool(UpdateSignalTool::class, ['id' => $signal->id, 'action' => 'reject'], $this->users['Anna']);
        $this->assertFalse($bad->success, 'Begründung ist Pflicht');

        $r = $this->tool(UpdateSignalTool::class, ['id' => $signal->id, 'action' => 'reject', 'reason' => 'Das Projekt gehört zu Einheit A'], $this->users['Anna']);
        $this->assertTrue($r->success, json_encode($r->error ?? null));
        $this->assertSame(1, $r->data['signal']['escalation_level']);
        $this->assertSame([['entity_id' => $this->ids['bernd'], 'name' => 'Bernd']], $r->data['signal']['recipients']);
        $this->assertStringContainsString('abgelehnt', $r->data['signal']['escalation_reason']);
        $this->assertNull($r->data['signal']['reacted_at'], 'die Uhr läuft für den Neuen');
        $this->assertSame('open', $r->data['signal']['status']);
    }

    public function test_line_with_nobody_above_keeps_the_recipients_and_marks(): void
    {
        unset($this->lookup->holders[$this->ids['a']], $this->lookup->holders[$this->ids['group']]);
        $this->evaluate();
        $signal = $this->at('a1');

        app(SignalService::class)->transition($signal, 'reject', $this->users['Anna'], ['reason' => 'nicht meins']);
        $signal->refresh();
        $this->assertSame([0, [$this->ids['anna']]], [$signal->escalation_level, $signal->recipient_entity_ids]);
        $this->assertNotNull($signal->escalation_reason);
        $this->assertStringContainsString('niemand verantwortlich', $signal->comments()->latest('id')->value('content'));
    }

    // ── Z3/Z5: Gremium ───────────────────────────────────────────

    public function test_committee_target_and_unit_top_hand_over_to_the_circle(): void
    {
        $gw = new FakeCommitteeGateway();
        $node = fn ($name, $parent, $class) => $this->row('organization_entities', ['name' => $name, 'team_id' => $this->s['teamId'], 'user_id' => $this->s['user']->id,
            'entity_type_id' => $this->types[$class], 'parent_entity_id' => $parent, 'is_active' => true]);
        $groupLa = $node('Gruppen-LA', $node('Governance', $this->ids['group'], 'observed'), 'actor');
        $gw->addCommittee($groupLa, 'Gruppen-LA');
        CommitteeGateways::register($gw);

        $this->assign($this->general, ['escalation_target' => 'committee']);
        $this->evaluate();
        $a1 = $this->at('a1');
        app(SignalService::class)->transition($a1, 'reject', $this->users['Anna'], ['reason' => 'braucht eine Entscheidung über A hinaus']);
        $a1->refresh();
        $this->assertSame(['escalated', $groupLa, 1], [$a1->stage, $a1->committee_entity_id, $a1->escalation_level]);
        $this->assertSame([$a1->id], app(CircleService::class)->committeeSituation($groupLa, $this->s['teamId'])['escalated_here']->pluck('id')->all());
        $this->assertStringContainsString('Gruppen-LA übergeben', $a1->comments()->latest('id')->value('content'));
        app(SignalService::class)->transition($this->at('b'), 'acknowledge', $this->users['Clara']);
        Carbon::setTestNow(now()->addWeeks(4));
        app(SignalEscalationService::class)->run();
        $this->assertSame(['escalated', $groupLa, 1], [$a1->fresh()->stage, $a1->fresh()->committee_entity_id, $a1->fresh()->escalation_level], 'im Gremium läuft keine Linien-Eskalation mehr');
        Carbon::setTestNow();

        // Linie an der Spitze einer Einheit mit eigenem Gremium: auf die Linie folgt das Gremium der Einheit
        $aLa = $node('A-LA', $node('Governance A', $this->ids['a'], 'observed'), 'actor');
        $gw->addCommittee($aLa, 'A-LA');
        $this->assign($this->general, ['escalation_target' => 'line']);
        $a = $this->at('a');
        $this->assertSame($this->ids['a'], $a->recipient_node_entity_id);
        app(SignalService::class)->transition($a, 'reject', $this->users['Bernd'], ['reason' => 'betrifft die Ressourcen der ganzen Einheit']);
        $a->refresh();
        $this->assertSame(['escalated', $aLa], [$a->stage, $a->committee_entity_id]);
    }

    // ── Z7: Einheit ohne Verantwortlichen ────────────────────────

    public function test_unit_without_responsible_hits_and_resolves_when_someone_is_entered(): void
    {
        $t = app(SignalTemplateService::class)->create($this->s['user'], $this->s['teamId'], ['name' => 'Einheit ohne Verantwortlichen', 'pattern_type' => 'no_responsible', 'conditions' => []]);
        $this->assign($this->general, ['is_binding' => true], $t->id);

        $this->evaluate();
        $hits = Signal::where('signal_template_id', $t->id)->get();
        $this->assertSame([$this->ids['b']], $hits->pluck('entity_id')->all(), 'nur Träger ohne Verantwortlichen (A1/A2 sind keine Träger)');
        $signal = $hits->first();
        $this->assertSame('critical', $signal->severity);
        $this->assertStringContainsString('Verantwortlichen eintragen', $signal->message);
        $this->assertSame([$this->ids['clara']], $signal->recipient_entity_ids, 'der Verantwortliche darüber muss benennen');
        $this->assertSame('task', app(\Platform\Signals\Services\SignalDelivery::class)->policy($signal)['delivery']);

        $this->lookup->hold($this->ids['b'], $this->ids['anna'], $this->users['Anna']->id, 'Anna', 'Führt an');
        $this->evaluate();
        $this->assertSame('resolved', $signal->fresh()->status);
    }

    // ── Z9: Meine Signale ────────────────────────────────────────

    public function test_my_signals_groups(): void
    {
        $this->setOpen('a2', 80);
        $this->evaluate();
        // Bernd: A (bei mir), A2 (bei mir, von unten gesucht), A1 nach Ablehnung durch Anna (hochgekommen)
        app(SignalService::class)->transition($this->at('a1'), 'reject', $this->users['Anna'], ['reason' => 'nicht meins']);
        app(SignalService::class)->transition($this->at('a2'), 'resolve', $this->users['Bernd'], ['resolution_note' => 'erledigt']);

        $r = $this->tool(GetMySignalsTool::class, [], $this->users['Bernd']);
        $this->assertTrue($r->success, json_encode($r->error ?? null));
        $this->assertSame([$this->at('a')->id], array_column($r->data['with_me'], 'id'));
        $this->assertSame([$this->at('a1')->id], array_column($r->data['came_up'], 'id'));
        $this->assertStringContainsString('abgelehnt', $r->data['came_up'][0]['escalation_reason']);
        $this->assertSame([$this->at('a2')->id], array_column($r->data['done'], 'id'));
        $this->assertSame(0, $this->tool(GetMySignalsTool::class, [], $this->users['Anna'])->data['counts']['with_me']);

        $this->assertFalse($this->tool(GetMySignalsTool::class, ['user_id' => $this->users['Bernd']->id], $this->users['Anna'])->success, 'Menschen nur für sich');
        $forBernd = $this->tool(GetMySignalsTool::class, ['user_id' => $this->users['Bernd']->id], $this->s['agent']);
        $this->assertSame(1, $forBernd->data['counts']['came_up'], 'Agenten dürfen für die Person lesen, die sie unterstützen');

        $this->bootUi();
        $this->actingAs($this->users['Bernd']);
        $this->get(route('signals.mine'))->assertOk()->assertSee('Zu mir hochgekommen')->assertSee('Gehört nicht zu mir')->assertSee('Einheit A');
    }

    // ── lose Kopplung als Regel ──────────────────────────────────

    public function test_no_type_codes_and_the_resolver_only_in_the_adapter(): void
    {
        $root = getenv('SIGNALS_MODULE_PATH');
        $files = array_merge(glob("$root/src/*.php"), glob("$root/src/*/*.php"), glob("$root/src/*/*/*.php"), glob("$root/src/*/*/*/*.php"));
        $offenders = [];
        foreach ($files as $f) {
            $src = file_get_contents($f);
            if (str_contains($src, 'ResponsibilityResolver') && ! str_ends_with($f, '/src/Organization/OrganizationResponsibilityLookup.php')) {
                $offenders[] = basename($f) . ': ResponsibilityResolver';
            }
            if (preg_match('/type\??->code|whereHas\(\'type\'[^;]*\'code\'|filterByEntityType/', $src)) {
                $offenders[] = basename($f) . ': Knotentyp-Code';
            }
            foreach (["'container'", "'board'", "'venture", "'holding'", "'business_unit'", "'initiative'"] as $needle) {
                if (str_contains($src, $needle)) {
                    $offenders[] = basename($f) . ": {$needle}";
                }
            }
        }
        $this->assertSame([], $offenders);
    }
}
