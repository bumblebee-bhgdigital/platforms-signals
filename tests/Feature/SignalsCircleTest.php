<?php

namespace Tests\Feature;

require_once __DIR__ . '/SignalsHarness.php';

use Illuminate\Support\Facades\DB;
use Platform\ManagementBook\Models\ManagementBookAgendaItem;
use Platform\Signals\Models\Signal;
use Platform\Signals\Services\CircleService;
use Platform\Signals\Tools\CreateCommitteeItemTool;
use Platform\Signals\Tools\CreateSignalTool;
use Platform\Signals\Tools\GetCommitteeSituationTool;
use Platform\Signals\Tools\UpdateSignalTool;
use Tests\TestCase;

/**
 * Regelkreis Einheit → Lenkungsausschuss (Architektur v2, MGT-101): Signal gehört der Einheit, lokal lösen,
 * nach der Faustregel als LA-Punkt (vier Fragen) als Entwurf in die nächste Sitzung, Beschluss meldet sich zurück.
 * Aufruf: MB_MODULE_PATH=<management-book> tests/run-in-taiste.sh tests/Feature/SignalsCircleTest.php
 */
class SignalsCircleTest extends TestCase
{
    use SignalsHarness;

    private $meeting;
    private $committee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootModule();
        $this->withoutExceptionHandling();
        $this->seedWorld();
        if (! $this->bootManagementBook()) {
            $this->markTestSkipped('Management Book nicht verfügbar (MB_MODULE_PATH).');
        }
        \Illuminate\Support\Facades\Gate::before(fn () => true);

        // Gruppe → Governance (Ordner) → LA (Gremium); Einheit A wird von Martin geführt
        $t = $this->s['teamId'];
        $typeId = fn ($code) => DB::table('organization_entity_types')->where('code', $code)->value('id')
            ?? $this->row('organization_entity_types', ['code' => $code, 'name' => $code, 'team_id' => $t, 'is_active' => true, 'sort_order' => 3, 'entity_type_group_id' => DB::table('organization_entity_type_groups')->value('id')]);
        $mk = fn ($name, $parent, $code) => $this->row('organization_entities', ['name' => $name, 'team_id' => $t, 'user_id' => $this->s['user']->id, 'entity_type_id' => $typeId($code), 'parent_entity_id' => $parent, 'is_active' => true]);
        $this->ids['gov'] = $mk('Governance', $this->ids['group'], 'container');
        $this->ids['la'] = $mk('Gruppe Lenkungsausschuss', $this->ids['gov'], 'board');
        $this->ids['martin'] = $mk('Martin', null, 'person');
        DB::table('organization_entities')->where('id', $this->ids['martin'])->update(['linked_user_id' => $this->s['user']->id]);
        $this->relate($this->ids['martin'], $this->ids['a'], 'manages', 'Führt an');

        $book = app(\Platform\ManagementBook\Services\ManagementBookService::class)->create($this->s['user'], $this->s['team'], ['title' => 'Book']);
        $this->committee = \Platform\ManagementBook\Models\ManagementBookCommittee::create([
            'team_id' => $t, 'management_book_id' => $book->id, 'name' => 'LA', 'type' => 'steering_committee', 'organization_entity_id' => $this->ids['la'], 'created_by' => $this->s['user']->id,
        ]);
        $this->meeting = \Platform\ManagementBook\Models\ManagementBookMeeting::create([
            'team_id' => $t, 'committee_id' => $this->committee->id, 'title' => 'LA Dezember', 'scheduled_at' => now()->addWeek(), 'status' => 'planned',
        ]);
    }

    private function signalAt(string $node): Signal
    {
        $r = $this->tool(CreateSignalTool::class, ['entity_id' => $this->ids[$node], 'message' => 'Projekt hängt'], $this->s['agent']);
        $this->assertTrue($r->success, json_encode($r->error ?? null));

        return Signal::find($r->data['signal']['id']);
    }

    private function fourQuestions(int $signalId, array $override = []): array
    {
        return $override + [
            'signal_id' => $signalId, 'goal' => 'Projekt bis Q1 live', 'observation' => 'seit 6 Wochen kein Fortschritt',
            'obstacle' => 'Entwicklerin auch in Einheit B gebunden', 'local_solution' => 'Scope verkleinern',
            'system_support' => 'Priorität zwischen A und B klären', 'local_solution_confirmed' => true,
        ];
    }

    public function test_signal_belongs_to_the_unit_with_a_leader(): void
    {
        $signal = $this->signalAt('a1');
        $this->assertSame($this->ids['a'], $signal->unit_entity_id, 'Projekt A1 → Einheit A (von Martin geführt)');
        $this->assertSame('unit', $signal->stage);
        $this->assertSame('Martin', app(CircleService::class)->leaderOf($this->ids['a'])['name']);
        $this->assertSame($this->ids['la'], app(\Platform\Signals\Support\CommitteeGateways::class)::get()->committeeAbove($this->ids['a'])['entity_id']);
    }

    public function test_solve_locally(): void
    {
        $signal = $this->signalAt('a1');
        $r = $this->tool(UpdateSignalTool::class, ['id' => $signal->id, 'action' => 'solve_locally', 'resolution_note' => 'Bereich hat Scope verkleinert'], $this->s['agent']);
        $this->assertTrue($r->success, json_encode($r->error ?? null));
        $this->assertSame('resolved', $r->data['signal']['status']);
        $this->assertSame('solved_locally', $r->data['signal']['outcome']);
    }

    public function test_lift_requires_4b_and_4a(): void
    {
        $signal = $this->signalAt('a1');
        $r = $this->tool(CreateCommitteeItemTool::class, $this->fourQuestions($signal->id, ['system_support' => '']), $this->s['agent']);
        $this->assertFalse($r->success);
        $this->assertStringContainsString('4b', json_encode($r->error));
        $r = $this->tool(CreateCommitteeItemTool::class, $this->fourQuestions($signal->id, ['local_solution' => '']), $this->s['agent']);
        $this->assertFalse($r->success);
        $this->assertSame(0, ManagementBookAgendaItem::count());
    }

    public function test_lift_creates_a_draft_and_the_decision_returns_to_the_unit(): void
    {
        $signal = $this->signalAt('a1');
        $r = $this->tool(CreateCommitteeItemTool::class, $this->fourQuestions($signal->id), $this->s['agent']);
        $this->assertTrue($r->success, json_encode($r->error ?? null));
        $this->assertSame('committee', $r->data['signal']['stage']);
        $this->assertSame($this->ids['la'], $r->data['signal']['committee_entity_id']);
        $this->assertNotNull($r->data['signal']['due_at']);

        $item = ManagementBookAgendaItem::find($r->data['agenda_item']['id']);
        $this->assertSame($this->meeting->id, (int) $item->meeting_id);
        $this->assertTrue($item->isProposalDraft());
        $this->assertSame('Priorität zwischen A und B klären', $item->system_support);
        $this->assertSame($this->ids['a'], (int) $item->organization_entity_id);
        $this->assertSame(['type' => 'signals_signal', 'id' => $signal->id], ['type' => $item->linked_object_type, 'id' => (int) $item->linked_object_id]);

        // Lage für den Steward
        $sit = $this->tool(GetCommitteeSituationTool::class, ['committee_entity_id' => $this->ids['la']], $this->s['agent']);
        $this->assertSame([$signal->id], array_column($sit->data['in_committee'], 'id'));

        // Beschluss „zurück an den Bereich“
        $this->meeting->decisions()->create(['team_id' => $this->s['teamId'], 'agenda_item_id' => $item->id, 'text' => 'A und B klären das bilateral', 'kind' => 'return_to_unit', 'created_by' => $this->s['user']->id]);
        $signal->refresh();
        $this->assertSame('unit', $signal->stage);
        $this->assertSame('returned_to_unit', $signal->outcome);
        $this->assertFalse($signal->isClosed());
    }

    public function test_measure_resolves_and_frame_becomes_visible_for_the_unit(): void
    {
        $signal = $this->signalAt('a1');
        $r = $this->tool(CreateCommitteeItemTool::class, $this->fourQuestions($signal->id), $this->s['agent']);
        $item = ManagementBookAgendaItem::find($r->data['agenda_item']['id']);

        $decision = $this->meeting->decisions()->create(['team_id' => $this->s['teamId'], 'agenda_item_id' => $item->id, 'text' => 'Entwicklerin 2 Tage/Woche fest bei A', 'kind' => 'measure', 'created_by' => $this->s['user']->id]);
        $signal->refresh();
        $this->assertSame('resolved', $signal->status);
        $this->assertSame('measure', $signal->outcome);
        $this->assertSame($decision->id, (int) $signal->resolution_link_id);

        $this->meeting->decisions()->create(['team_id' => $this->s['teamId'], 'agenda_item_id' => $item->id, 'text' => 'Einheit A plant Personal bis 2 Wochen selbst um', 'kind' => 'frame', 'created_by' => $this->s['user']->id]);
        $frames = \Platform\Signals\Support\CommitteeGateways::get()->frames($this->ids['a']);
        $this->assertSame('Einheit A plant Personal bis 2 Wochen selbst um', $frames[0]['text']);
    }
    public function test_rejecting_the_draft_sends_the_signal_back_to_the_unit(): void
    {
        $signal = $this->signalAt('a1');
        $r = $this->tool(CreateCommitteeItemTool::class, $this->fourQuestions($signal->id), $this->s['agent']);
        $item = ManagementBookAgendaItem::find($r->data['agenda_item']['id']);
        \Platform\ManagementBook\Events\AgendaProposalRejected::dispatch($item, $this->s['user']->id);
        $signal->refresh();
        $this->assertSame('unit', $signal->stage);
        $this->assertSame('returned_to_unit', $signal->outcome);
    }

    public function test_book_knows_nothing_about_signals(): void
    {
        $src = implode("\n", array_map('file_get_contents', array_merge(
            glob(getenv('MB_MODULE_PATH') . '/src/Models/*.php'), glob(getenv('MB_MODULE_PATH') . '/src/Livewire/*/*.php'),
            glob(getenv('MB_MODULE_PATH') . '/resources/views/livewire/*/partials/*.php'),
        )));
        $this->assertStringNotContainsString('Platform\\Signals', $src);
        $this->assertStringNotContainsString('signals_signal', $src);
    }
}
