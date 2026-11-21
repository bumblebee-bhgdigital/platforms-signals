<?php

namespace Tests\Feature;

require_once __DIR__ . '/SignalsHarness.php';

use Livewire\Livewire;
use Platform\Signals\Models\Signal;
use Platform\Signals\Services\SignalEvaluationService;
use Platform\Signals\Services\SignalSetService;
use Platform\Signals\Services\SignalTemplateService;
use Tests\TestCase;

/**
 * Oberfläche: Set-Seite mit Templates/Probelauf/Signalen, Signal-Liste, Signal-Detail (erledigen mit Notiz), Templates.
 * Aufruf: tests/run-in-taiste.sh tests/Feature/SignalsUiTest.php
 */
class SignalsUiTest extends TestCase
{
    use SignalsHarness;

    private $set;
    private $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootModule();
        $this->bootUi();
        $this->withoutExceptionHandling();
        $this->seedWorld();
        \Illuminate\Support\Facades\Gate::before(fn () => true);

        $this->set = app(SignalSetService::class)->create($this->s['user'], $this->s['team'], ['title' => 'Basis', 'responsible_agent_user_id' => $this->s['agent']->id]);
        app(SignalSetService::class)->link($this->set, $this->ids['a'], true);
        app(SignalSetService::class)->activate($this->set);
        $this->template = app(SignalTemplateService::class)->create($this->s['user'], $this->s['teamId'], [
            'name' => 'Überlast', 'pattern_type' => 'threshold', 'conditions' => ['metric' => 'items_open', 'operator' => '>', 'value' => 50],
        ]);
        app(SignalTemplateService::class)->attach($this->set, $this->template);
        $this->snapshot($this->ids['a1'], ['items_open' => 70]);
        app(SignalEvaluationService::class)->run();
    }

    public function test_pages_render(): void
    {
        $signal = Signal::first();
        $this->assertNotNull($signal);

        $this->get(route('signals.sets.show', $this->set))->assertOk()->assertSee('Überlast')->assertSee('items_open &gt; 50', false)->assertSee('Projekt A1');
        $this->get(route('signals.signals.index'))->assertOk()->assertSee('Projekt A1')->assertSee('Basis');
        $this->get(route('signals.signals.show', $signal))->assertOk()->assertSee('Wodurch erledigt')->assertSee('Ratchet');
        $this->get(route('signals.templates.index'))->assertOk()->assertSee('Überlast');
        \Platform\Signals\Models\SignalEnvironmentSource::create(['team_id' => $this->s['teamId'], 'name' => 'foodservice.de', 'category' => 'gastronomy', 'config' => ['url' => 'https://x.test']]);
        $this->get(route('signals.environment.index'))->assertOk()->assertSee('foodservice.de');
    }

    public function test_resolve_from_the_detail_page_and_dry_run_on_the_set_page(): void
    {
        $signal = Signal::first();
        $c = new \Platform\Signals\Livewire\Signal\Show();
        $c->mount($signal);
        $c->note = 'Zweites Team eingesetzt';
        $c->resolve();
        $this->assertNull($c->error);
        $this->assertSame('resolved', $signal->fresh()->status);
        $this->assertSame('Zweites Team eingesetzt', $signal->fresh()->resolution_note);

        $show = new \Platform\Signals\Livewire\Show();
        $show->mount($this->set);
        $show->runDryRun();
        $this->assertSame(1, $show->dryRun['created']);
        $this->assertSame(1, Signal::count(), 'Probelauf schreibt nichts');
    }

    public function test_template_form_creates_rule_and_rejects_broken_json(): void
    {
        $c = new \Platform\Signals\Livewire\Template\Index();
        $c->openCreate('rule');
        $c->form['name'] = 'Viele offene';
        $c->conditionsJson = '{kaputt';
        $c->save();
        $this->assertSame('Bedingungen sind kein gültiges JSON-Objekt.', $c->error);
        $c->conditionsJson = '{"metric":"items_open","operator":">","value":10}';
        $c->save();
        $this->assertFalse($c->showForm, (string) $c->error);
        $this->assertSame(2, \Platform\Signals\Models\SignalTemplate::count());
    }
}
