<?php

namespace Tests\Feature;

require_once __DIR__ . '/SignalsHarness.php';
require_once getenv('SIGNALS_MODULE_PATH') . '/src/Contracts/DeliveryChannel.php';

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Platform\Signals\Contracts\DeliveryChannel;
use Platform\Signals\Contracts\ResponsibilityLookup;
use Platform\Signals\Models\Signal;
use Platform\Signals\Models\SignalDeliveryOp;
use Platform\Signals\Models\SignalSet;
use Platform\Signals\Services\SignalEscalationService;
use Platform\Signals\Services\SignalEvaluationService;
use Platform\Signals\Services\SignalService;
use Platform\Signals\Services\SignalSetService;
use Platform\Signals\Services\SignalTemplateService;
use Platform\Signals\Support\DeliveryChannels;
use Platform\Signals\Tools\GetDeliveriesTool;
use Platform\Signals\Tools\GetMySignalsTool;
use Platform\Signals\Tools\ReportDeliveryTool;
use Platform\Signals\Tools\UpsertSetTemplateTool;
use Tests\TestCase;

/**
 * Test-Double des Zustell-Kanals: Signals wird nur gegen den Vertrag getestet. Reaktionen im Vorgang simuliert emit().
 */
class FakeDeliveryChannel implements DeliveryChannel
{
    private $handler;

    public function onFeedback(callable $handler): void
    {
        $this->handler = $handler;
    }

    public function emit(string $thread, string $kind, ?int $byUserId, ?string $note = null, ?string $eventId = null): void
    {
        ($this->handler)($thread, $kind, $byUserId, $note, $eventId ?? uniqid('ev', true));
    }
}

/**
 * Z4 „ein Signal, ein Vorgang“ (Schritt 3): Aufträge an den Agenten des Sets und Rückweg aus dem Kanal –
 * nur gegen Test-Doubles (FakeResponsibilityLookup, FakeDeliveryChannel) und mit nichtssagenden Typ-Codes.
 *
 * Struktur wie SignalsDeliveryTest: Gruppe (Clara) ─┬─ Einheit A (Bernd) ─┬─ Projekt A1 (Anna)
 *                                                   │                     └─ Projekt A2 (niemand)
 *                                                   └─ Einheit B (niemand)
 * Allgemeines Set an der Gruppe (Agent Ratchet), Template „Stau“ (kritisch ⇒ Vorgang) items_open > 50.
 * Aufruf: tests/run-in-taiste.sh tests/Feature/SignalsChannelTest.php
 */
class SignalsChannelTest extends TestCase
{
    use SignalsHarness;

    private FakeResponsibilityLookup $lookup;
    private FakeDeliveryChannel $channel;
    private SignalSet $general;
    private int $templateId;
    private array $types = [];
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootModule();
        $this->withoutExceptionHandling();
        Carbon::setTestNow(Carbon::parse('2026-11-23 10:00')); // Montag
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
        $this->ids['bernd'] = $this->person('Bernd');
        $this->ids['clara'] = $this->person('Clara');

        $this->lookup = new FakeResponsibilityLookup();
        $this->lookup->hold($this->ids['a1'], $this->ids['anna'], $this->users['Anna']->id, 'Anna', 'Projektleitung');
        $this->lookup->hold($this->ids['a'], $this->ids['bernd'], $this->users['Bernd']->id, 'Bernd', 'Venture Owner');
        $this->lookup->hold($this->ids['group'], $this->ids['clara'], $this->users['Clara']->id, 'Clara', 'Führt an');
        $this->app->instance(ResponsibilityLookup::class, $this->lookup);

        $this->channel = new FakeDeliveryChannel();
        DeliveryChannels::register($this->channel);

        $this->general = $this->set('Allgemein', $this->ids['group'], true, $this->s['agent']->id);
        $this->templateId = $this->template('Stau', 'critical');
        $this->assign($this->general);

        foreach (['a' => 10, 'a1' => 70, 'a2' => 10, 'b' => 10] as $k => $open) {
            $this->snapshot($this->ids[$k], ['items_open' => $open]);
        }
    }

    protected function tearDown(): void
    {
        DeliveryChannels::forget();
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Hilfen ───────────────────────────────────────────────────

    private function person(string $name, ?int $parent = null, string $class = 'actor'): int
    {
        $user = \Platform\Core\Models\User::create(['name' => $name, 'email' => strtolower($name) . '@x.test', 'password' => 'x']);
        DB::table('team_user')->insert(['team_id' => $this->s['teamId'], 'user_id' => $user->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
        $user->forceFill(['current_team_id' => $this->s['teamId']])->save();
        $this->users[$name] = $user->fresh();

        return $this->row('organization_entities', ['name' => $name, 'team_id' => $this->s['teamId'], 'user_id' => $this->s['user']->id,
            'entity_type_id' => $this->types[$class], 'parent_entity_id' => $parent, 'is_active' => true, 'linked_user_id' => $user->id]);
    }

    private function set(string $title, int $entityId, bool $inherit, int $agentUserId): SignalSet
    {
        $set = app(SignalSetService::class)->create($this->s['user'], $this->s['team'], ['title' => $title, 'responsible_agent_user_id' => $agentUserId]);
        app(SignalSetService::class)->link($set, $entityId, $inherit);

        return app(SignalSetService::class)->activate($set);
    }

    private function template(string $name, string $severity): int
    {
        return app(SignalTemplateService::class)->create($this->s['user'], $this->s['teamId'], [
            'name' => $name, 'pattern_type' => 'threshold', 'severity' => $severity, 'conditions' => ['metric' => 'items_open', 'operator' => '>', 'value' => 50],
        ])->id;
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

    private function escalate(string $at): array
    {
        Carbon::setTestNow(Carbon::parse($at));

        return app(SignalEscalationService::class)->run();
    }

    private function at(string $key, ?int $templateId = null): ?Signal
    {
        return Signal::where('entity_id', $this->ids[$key])->where('signal_template_id', $templateId ?? $this->templateId)->first();
    }

    /** @return SignalDeliveryOp[] */
    private function ops(Signal $signal, ?string $status = null): array
    {
        return SignalDeliveryOp::where('signal_id', $signal->id)->when($status, fn ($q) => $q->where('status', $status))->orderBy('id')->get()->all();
    }

    private function report(int $opId, bool $ok, ?string $thread = null, ?string $error = null, ?\Platform\Core\Models\User $as = null): \Platform\Core\Contracts\ToolResult
    {
        return $this->tool(ReportDeliveryTool::class, array_filter(['op_id' => $opId, 'ok' => $ok, 'thread' => $thread, 'error' => $error], fn ($v) => $v !== null), $as ?? $this->s['agent']);
    }

    /** Auswerten und den Vorgang für A1 anlegen lassen (Agent meldet thread zurück). */
    private function deliveredA1(string $thread = 'th-a1'): Signal
    {
        $this->evaluate();
        $signal = $this->at('a1');
        $create = $this->ops($signal)[0];
        $this->assertSame('create', $create->op);
        $this->assertTrue($this->report($create->id, true, $thread)->success);

        return $signal->fresh();
    }

    // ── Hinweg: Aufträge ─────────────────────────────────────────

    public function test_create_is_enqueued_only_for_task_delivery_with_the_payload_shape(): void
    {
        // Hinweis/Warnung → Lagebild: kein Auftrag
        $quiet = $this->template('Überlast', 'warning');
        $this->assign($this->general, [], $quiet);
        // Agent als Verantwortlicher an A2: bei kritisch ist sein Mensch (Clara) mit dabei
        $bumble = $this->person('Bumble', null, 'actor');
        $this->lookup->hold($this->ids['a2'], $bumble, $this->users['Bumble']->id, 'Bumble', 'Projektleitung', 'agent', ['entity_id' => $this->ids['clara'], 'user_id' => $this->users['Clara']->id, 'name' => 'Clara']);
        $this->setOpen('a2', 80);

        $this->evaluate();
        $this->assertSame([], $this->ops($this->at('a1', $quiet)), 'Warnung → Lagebild, kein Vorgang');
        $this->assertNull($this->at('a1', $quiet)->delivery_state);

        $signal = $this->at('a1');
        $ops = $this->ops($signal);
        $this->assertCount(1, $ops);
        $p = $ops[0]->payload;
        $this->assertSame(['create', 'pending'], [$ops[0]->op, $ops[0]->status]);
        $this->assertSame('Signal: Stau in Projekt A1', $p['title']);
        $this->assertSame(['object' => 'entity', 'id' => $this->ids['a1']], $p['room']);
        $this->assertSame(['anna@x.test'], $p['members']);
        $this->assertSame('anna@x.test', $p['assignee']);
        foreach ([$signal->message, 'Was hindert euch?', 'Was könnt ihr selbst lösen?', 'Wobei braucht ihr Unterstützung?', '**Annehmen**', '**Erledigt**', '**Ablehnen** mit Begründung', 'verantwortlich an Projekt A1'] as $needle) {
            $this->assertStringContainsString($needle, $p['markdown']);
        }
        $this->assertArrayHasKey('url', $p);
        $this->assertSame(['pending', $this->ids['a1']], [$signal->delivery_state, $signal->delivery_room_entity_id]);

        $a2 = $this->ops($this->at('a2'))[0]->payload;
        $this->assertEqualsCanonicalizing(['bumble@x.test', 'clara@x.test'], $a2['members'], 'Mensch des Agenten bei kritisch dabei');
        $this->assertSame('bumble@x.test', $a2['assignee']);

        // kein zweites create – weder bei erneuter Auswertung noch bei erneuter Zustellung
        $this->evaluate();
        app(\Platform\Signals\Services\SignalDelivery::class)->deliver($signal->fresh(), null, true);
        $this->assertCount(1, $this->ops($signal));

        // mehrere Verantwortliche am Knoten → offene Aufgabe ohne Zuweisung
        $this->lookup->hold($this->ids['b'], $this->ids['anna'], $this->users['Anna']->id, 'Anna', 'Owner');
        $this->lookup->hold($this->ids['b'], $this->ids['bernd'], $this->users['Bernd']->id, 'Bernd', 'Co-Owner');
        $this->setOpen('b', 90);
        $this->evaluate();
        $b = $this->ops($this->at('b'))[0]->payload;
        $this->assertNull($b['assignee']);
        $this->assertEqualsCanonicalizing(['anna@x.test', 'bernd@x.test'], $b['members']);
    }

    public function test_agent_queue_shows_only_own_ops_and_post_stores_the_thread(): void
    {
        $optimus = \Platform\Core\Models\User::create(['name' => 'Optimus', 'email' => 'o@x.test', 'password' => 'x']);
        $optimus->forceFill(['type' => 'ai_user'])->save();
        DB::table('team_user')->insert(['team_id' => $this->s['teamId'], 'user_id' => $optimus->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
        $this->assign($this->set('Einheit B', $this->ids['b'], true, $optimus->id));
        $this->lookup->hold($this->ids['b'], $this->ids['bernd'], $this->users['Bernd']->id, 'Bernd', 'Führt an');
        $this->setOpen('b', 90);
        $this->evaluate();
        $a1 = $this->at('a1');
        $b = $this->at('b');

        $mine = $this->tool(GetDeliveriesTool::class, [], $this->s['agent']);
        $this->assertTrue($mine->success, json_encode($mine->error ?? null));
        $this->assertSame([$a1->id], array_column($mine->data['deliveries'], 'signal_id'));
        $row = $mine->data['deliveries'][0];
        $this->assertSame(['create', true, null, $this->ids['a1']], [$row['op'], $row['ready'], $row['thread'], $row['room']['id']]);
        $this->assertSame([$b->id], array_column($this->tool(GetDeliveriesTool::class, [], $optimus)->data['deliveries'], 'signal_id'));
        $this->assertSame([], $this->tool(GetDeliveriesTool::class, ['signal_id' => $b->id], $this->s['agent'])->data['deliveries']);

        $this->assertFalse($this->tool(GetDeliveriesTool::class, ['agent_user_id' => $optimus->id], $this->users['Anna'])->success, 'nur Admins für andere');
        $asAdmin = $this->tool(GetDeliveriesTool::class, ['agent_user_id' => $optimus->id], $this->s['user']);
        $this->assertSame([$b->id], array_column($asAdmin->data['deliveries'], 'signal_id'));

        $this->assertFalse($this->report($row['op_id'], true, 'th-x', null, $optimus)->success, 'fremder Agent darf nicht melden');
        $this->assertFalse($this->report($row['op_id'], true)->success, 'thread ist bei create Pflicht');
        $r = $this->report($row['op_id'], true, 'th-a1');
        $this->assertTrue($r->success, json_encode($r->error ?? null));
        $a1->refresh();
        $this->assertSame(['th-a1', 'open'], [$a1->delivery_thread, $a1->delivery_state]);
        $this->assertSame('done', SignalDeliveryOp::find($row['op_id'])->status);
        $this->assertFalse($this->report($row['op_id'], true, 'th-a1')->success, 'nicht zweimal');

        // „Meine Signale“ zeigt den Stand des Vorgangs
        $minePage = $this->tool(GetMySignalsTool::class, [], $this->users['Anna']);
        $this->assertSame(['open', 'th-a1'], [$minePage->data['with_me'][0]['delivery_state'], $minePage->data['with_me'][0]['delivery_thread']]);
        $this->bootUi();
        $this->actingAs($this->users['Anna']);
        $this->get(route('signals.mine'))->assertOk()->assertSee('Vorgang in Parlan offen');
        $this->get(route('signals.signals.show', $a1))->assertOk()->assertSee('Vorgang in Parlan offen');
    }

    public function test_order_per_signal_is_kept(): void
    {
        $this->evaluate();
        $signal = $this->at('a1');
        // Eskalation, bevor der Agent den Vorgang angelegt hat: Übergabe wartet auf create
        app(SignalService::class)->transition($signal, 'reject', $this->users['Anna'], ['reason' => 'nicht meins']);
        [$create, $handover] = $this->ops($signal);
        $this->assertSame(['create', 'handover'], [$create->op, $handover->op]);

        $rows = $this->tool(GetDeliveriesTool::class, ['signal_id' => $signal->id], $this->s['agent'])->data['deliveries'];
        $this->assertSame([[true, null], [false, $create->id]], array_map(fn ($r) => [$r['ready'], $r['waits_for_op_id']], $rows));
        $this->assertFalse($this->report($handover->id, true)->success, 'erst create');
        $this->assertTrue($this->report($create->id, true, 'th-1')->success);
        $this->assertTrue($this->report($handover->id, true)->success);
    }

    public function test_line_escalation_enqueues_a_handover_with_the_reason(): void
    {
        $signal = $this->deliveredA1();
        $this->escalate('2026-11-26 10:00'); // Donnerstag: 3 Arbeitstage ohne Reaktion

        $signal->refresh();
        $this->assertSame([1, [$this->ids['bernd']]], [$signal->escalation_level, $signal->recipient_entity_ids]);
        $handover = $this->ops($signal, 'pending');
        $this->assertCount(1, $handover);
        $this->assertSame('handover', $handover[0]->op);
        $this->assertSame('bernd@x.test', $handover[0]->payload['to']);
        $this->assertContains('bernd@x.test', $handover[0]->payload['members_add']);
        $this->assertStringContainsString('ignoriert: 3 Arbeitstage ohne Reaktion', $handover[0]->payload['note']);
        $this->assertStringContainsString('lag 3 Arbeitstage bei Anna (Projekt A1)', $handover[0]->payload['note']);
    }

    public function test_escalation_of_an_overview_signal_creates_the_task(): void
    {
        $quiet = $this->template('Überlast', 'warning');
        $this->assign($this->general, [], $quiet);
        $this->evaluate();
        $signal = $this->at('a1', $quiet);
        $this->assertSame([], $this->ops($signal));

        app(SignalService::class)->transition($signal, 'reject', $this->users['Anna'], ['reason' => 'gehört zu A']);
        $ops = $this->ops($signal->fresh());
        $this->assertSame(['create'], array_map(fn ($o) => $o->op, $ops), 'weitergereicht ⇒ immer Vorgang, gleich beim Neuen');
        $this->assertSame('bernd@x.test', $ops[0]->payload['assignee']);
        $this->assertStringContainsString('abgelehnt: gehört zu A', $ops[0]->payload['markdown']);
    }

    // ── Rückweg: Reaktionen im Vorgang ───────────────────────────

    public function test_declined_feedback_rejects_and_hands_over(): void
    {
        $signal = $this->deliveredA1();
        $this->channel->emit('th-a1', 'declined', $this->users['Anna']->id, 'gehört zu Einheit A', 'e-1');

        $signal->refresh();
        $this->assertSame([1, [$this->ids['bernd']], 'open'], [$signal->escalation_level, $signal->recipient_entity_ids, $signal->status]);
        $this->assertStringContainsString('In Parlan abgelehnt: gehört zu Einheit A', $signal->escalation_reason);
        $pending = $this->ops($signal, 'pending');
        $this->assertSame(['handover'], array_map(fn ($o) => $o->op, $pending));
        $this->assertSame('bernd@x.test', $pending[0]->payload['to']);
    }

    public function test_accepted_sets_reacted_at_and_stops_the_ignored_trigger(): void
    {
        $signal = $this->deliveredA1();
        $this->channel->emit('th-a1', 'accepted', $this->users['Anna']->id);

        $signal->refresh();
        $this->assertSame('acknowledged', $signal->status);
        $this->assertNotNull($signal->reacted_at);
        $this->escalate('2026-11-30 10:00'); // 5 Arbeitstage später
        $this->assertSame(0, $signal->fresh()->escalation_level);
        $this->assertSame([], $this->ops($signal, 'pending'), 'keine Erinnerung, keine Übergabe');
    }

    public function test_done_resolves_without_a_close_order(): void
    {
        $signal = $this->deliveredA1();
        $this->channel->emit('th-a1', 'done', $this->users['Anna']->id, 'Aufgaben bereinigt');

        $signal->refresh();
        $this->assertSame(['resolved', 'In Parlan erledigt: Aufgaben bereinigt', 'closed'], [$signal->status, $signal->resolution_note, $signal->delivery_state]);
        $this->assertSame($this->users['Anna']->id, $signal->resolved_by_user_id);
        $this->assertNotNull($signal->reacted_at);
        $this->assertSame([], $this->ops($signal, 'pending'), 'in Parlan schon erledigt – nichts zu schließen');

        // kommt wieder → derselbe Vorgang wird wieder geöffnet
        Carbon::setTestNow(Carbon::parse('2026-11-25 10:00'));
        $this->evaluate();
        $reopen = $this->ops($signal, 'pending');
        $this->assertSame(['reopen'], array_map(fn ($o) => $o->op, $reopen));
        $this->assertStringContainsString('zum 2. Mal in 2 Tagen', $reopen[0]->payload['note']);
        $this->assertSame('anna@x.test', $reopen[0]->payload['to']);
        $this->assertTrue($this->report($reopen[0]->id, true, 'th-a1-neu')->success, 'neuer Vorgang, falls Parlan nicht wieder öffnen kann');
        $this->assertSame(['th-a1-neu', 'open'], [$signal->fresh()->delivery_thread, $signal->fresh()->delivery_state]);

        // späte Reaktion im alten Vorgang zählt noch
        $this->channel->emit('th-a1', 'accepted', $this->users['Anna']->id, null, 'e-late');
        $this->assertSame('acknowledged', $signal->fresh()->status);
    }

    public function test_open_signal_without_delivery_is_delivered_on_the_next_run(): void
    {
        $this->evaluate();
        $signal = $this->at('a1');
        SignalDeliveryOp::where('signal_id', $signal->id)->delete();
        $signal->forceFill(['delivered_at' => null, 'recipient_reason' => null, 'recipient_entity_ids' => null, 'recipient_user_ids' => null, 'delivery_state' => null])->saveQuietly();

        $this->evaluate();
        $signal->refresh();
        $this->assertNotNull($signal->delivered_at);
        $this->assertSame([$this->users['Anna']->id], array_map('intval', (array) $signal->recipient_user_ids));
        $this->assertSame(['create'], array_map(fn ($o) => $o->op, $this->ops($signal, 'pending')));

        $this->evaluate();
        $this->assertCount(1, $this->ops($signal), 'nur einmal nachholen');
    }

    public function test_person_signal_goes_to_the_room_above_without_the_person(): void
    {
        $this->ids['dora'] = $this->person('Dora', $this->ids['a1']);
        $this->snapshot($this->ids['dora'], ['items_open' => 80]);
        $this->lookup->hold($this->ids['a1'], $this->ids['dora'], $this->users['Dora']->id, 'Dora', 'Mitarbeit');

        $this->evaluate();
        $signal = $this->at('dora');
        $this->assertNotNull($signal);
        $create = $this->ops($signal)[0];
        $this->assertSame($this->ids['a1'], $create->payload['room']['id'], 'Raum der Einheit darüber, nicht der Raum der Person');
        $this->assertSame($this->ids['a1'], (int) $signal->fresh()->delivery_room_entity_id);
        $this->assertNotContains('dora@x.test', $create->payload['members']);
        $this->assertContains('anna@x.test', $create->payload['members']);
    }

    public function test_feedback_by_the_agent_unknown_threads_and_duplicates_are_ignored(): void
    {
        $signal = $this->deliveredA1();

        $this->channel->emit('th-a1', 'accepted', $this->s['agent']->id, null, 'e-agent');
        $this->assertSame(['open', null], [$signal->fresh()->status, $signal->fresh()->reacted_at]);

        $this->channel->emit('th-unbekannt', 'done', $this->users['Anna']->id, null, 'e-x');
        $this->assertSame('open', $signal->fresh()->status);

        $this->channel->emit('th-a1', 'declined', $this->users['Anna']->id, 'nicht meins', 'e-dup');
        $this->channel->emit('th-a1', 'declined', $this->users['Anna']->id, 'nicht meins', 'e-dup');
        $this->assertSame(1, $signal->fresh()->escalation_level);
        $this->assertCount(1, $this->ops($signal, 'pending'));
        $this->assertSame(['ignored_agent', 'applied'], DB::table('signal_delivery_events')->orderBy('id')->pluck('result')->all());
    }

    // ── Erinnerung, Schließen, Fehler ────────────────────────────

    public function test_reminder_is_enqueued_once_at_half_time(): void
    {
        $signal = $this->deliveredA1();

        $this->assertSame(0, $this->escalate('2026-11-24 12:00')['reminded'], 'Dienstag Mittag: noch vor der Halbzeit (1,5 Arbeitstage)');
        $this->assertSame(1, $this->escalate('2026-11-25 08:00')['reminded']);
        $this->assertSame(0, $this->escalate('2026-11-25 09:00')['reminded'], 'einmal je Zyklus');

        $comments = array_values(array_filter($this->ops($signal), fn ($o) => $o->op === 'comment'));
        $this->assertCount(1, $comments);
        $this->assertSame('reminder', $comments[0]->payload['purpose']);
        $this->assertStringContainsString('Wobei braucht ihr Unterstützung?', $comments[0]->payload['text']);
    }

    public function test_auto_resolve_enqueues_close_and_cancels_a_task_that_never_existed(): void
    {
        $signal = $this->deliveredA1();
        $this->setOpen('a1', 10);
        Carbon::setTestNow(Carbon::parse('2026-11-23 12:00'));
        $this->evaluate();

        $this->assertSame('resolved', $signal->fresh()->status);
        $close = $this->ops($signal, 'pending');
        $this->assertSame(['close'], array_map(fn ($o) => $o->op, $close));
        $this->assertStringContainsString('Automatisch aufgelöst', $close[0]->payload['note']);
        $this->assertTrue($this->report($close[0]->id, true)->success);
        $this->assertSame('closed', $signal->fresh()->delivery_state);

        // Signal geht zu, bevor der Agent den Vorgang angelegt hat: Aufträge verfallen, kein Vorgang
        $this->setOpen('a2', 80);
        $this->evaluate();
        $a2 = $this->at('a2');
        $this->assertSame('pending', $a2->delivery_state);
        app(SignalService::class)->transition($a2, 'dismiss', $this->users['Bernd'], ['reason' => 'Messfehler']);
        $this->assertSame(['cancelled'], array_map(fn ($o) => $o->status, $this->ops($a2)));
        $this->assertNull($a2->fresh()->delivery_state);
    }

    public function test_five_failures_mark_the_op_failed(): void
    {
        $this->evaluate();
        $signal = $this->at('a1');
        $create = $this->ops($signal)[0];

        for ($i = 1; $i <= 4; $i++) {
            $this->assertTrue($this->report($create->id, false, null, "Raum nicht erreichbar ({$i})")->success);
        }
        $this->assertSame(['pending', 4], [$create->fresh()->status, $create->fresh()->attempts]);
        $r = $this->report($create->id, false, null, 'Raum nicht erreichbar (5)');
        $this->assertSame('failed', $r->data['op']['status']);
        $this->assertSame(5, $create->fresh()->attempts);
        $this->assertSame('Raum nicht erreichbar (5)', $create->fresh()->last_error);
        $this->assertNull($signal->fresh()->delivery_state);
        $this->assertStringContainsString('Zustellung in Parlan fehlgeschlagen (create) nach 5 Versuchen', $signal->comments()->latest('id')->value('content'));
        $this->assertSame([], $this->tool(GetDeliveriesTool::class, [], $this->s['agent'])->data['deliveries']);
    }

    // ── Adapter und lose Kopplung ────────────────────────────────

    public function test_the_adapter_maps_organization_events(): void
    {
        if (! class_exists('Platform\\Organization\\Events\\CoordinationEventsRecorded')) {
            eval('namespace Platform\\Organization\\Events; class CoordinationEventsRecorded { public function __construct(public array $events) {} }');
        }
        $adapter = new \Platform\Signals\Organization\OrganizationParlanFeedback();
        DeliveryChannels::register($adapter);
        $signal = $this->deliveredA1();

        $event = fn (string $kind, string $id) => ['event_id' => $id, 'kind' => $kind, 'thread' => 'th-a1', 'actor_user_id' => $this->users['Anna']->id,
            'actor_handle' => 'anna', 'context_ref' => null, 'occurred_at' => now()->toIso8601String()];
        Event::dispatch(new \Platform\Organization\Events\CoordinationEventsRecorded([$event('message', 'o-1'), $event('handoff_claim', 'o-2')]));
        $this->assertSame('acknowledged', $signal->fresh()->status);
        Event::dispatch(new \Platform\Organization\Events\CoordinationEventsRecorded([$event('decision', 'o-3'), $event('handoff_done', 'o-4')]));
        $this->assertSame(['resolved', 'In Parlan erledigt'], [$signal->fresh()->status, $signal->fresh()->resolution_note]);
        $this->assertSame(0, $adapter->receive([$event('request', 'o-5'), $event('agreement_decline', 'o-6')]), 'alles andere wird ignoriert');
    }

    public function test_only_the_adapter_knows_the_organization_event(): void
    {
        $root = getenv('SIGNALS_MODULE_PATH');
        $files = array_merge(glob("$root/src/*.php"), glob("$root/src/*/*.php"), glob("$root/src/*/*/*.php"), glob("$root/src/*/*/*/*.php"));
        $offenders = [];
        foreach ($files as $f) {
            $src = file_get_contents($f);
            if (str_contains($src, 'CoordinationEventsRecorded') && ! str_ends_with($f, '/src/Organization/OrganizationParlanFeedback.php')) {
                $offenders[] = basename($f);
            }
            // Parlan-Arten (handoff_*) kennt nur der Adapter
            if (preg_match('/[\'"]handoff_(accept|claim|done|decline)[\'"]/', $src) && ! str_ends_with($f, '/src/Organization/OrganizationParlanFeedback.php')) {
                $offenders[] = basename($f) . ': Parlan-Art';
            }
        }
        $this->assertSame([], $offenders);
    }
}
