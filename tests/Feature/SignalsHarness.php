<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Platform\Organization\Models\OrganizationEntity;
use Platform\Organization\Services\EntityDimensionBridge;

/**
 * Gemeinsames Setup für die Signals-Tests (sqlite :memory: in einer Verifikations-App mit vendor/martin3r/*).
 * Lädt das Modul aus SIGNALS_MODULE_PATH, spielt Core-, Organisations- und Modul-Migrationen ein und legt
 * eine kleine Organisation mit Snapshots an. Aufruf: tests/run-in-taiste.sh tests/Feature/<Test>.php
 */
trait SignalsHarness
{
    protected string $module;
    protected array $s = [];
    protected array $ids = [];

    protected function bootModule(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->module = getenv('SIGNALS_MODULE_PATH') ?: dirname(__DIR__, 2);
        $module = $this->module;
        spl_autoload_register(function ($c) use ($module) {
            $p = 'Platform\\Signals\\';
            if (str_starts_with($c, $p)) {
                $f = $module . '/src/' . str_replace('\\', '/', substr($c, strlen($p))) . '.php';
                if (is_file($f)) {
                    require $f;
                }
            }
        });
        DB::connection()->getPdo()->sqliteCreateFunction('JSON_UNQUOTE', fn ($v) => is_string($v) ? trim($v, '"') : $v, 1);

        $files = array_merge(
            glob(base_path('vendor/martin3r/platform-core/database/migrations/*.php')),
            glob(base_path('vendor/martin3r/platform-organization/database/migrations/*.php')),
            glob($module . '/database/migrations/*.php'),
        );
        usort($files, fn ($a, $b) => strcmp(basename($a), basename($b)));
        foreach ($files as $f) {
            try {
                (require $f)->up();
            } catch (\Throwable $e) {
                // Fremd-Migrationen, die auf sqlite nicht laufen, ignorieren
            }
        }
        $this->app->register(\Platform\Signals\SignalsServiceProvider::class);
        if (! DB::table('organization_dimension_definitions')->where('key', 'entity')->exists()) {
            DB::table('organization_dimension_definitions')->insert(['key' => 'entity', 'name' => 'Entity', 'value_source' => 'entity', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        EntityDimensionBridge::flush();

        // Schreibrecht auf alle Knoten (der echte AuthzResolver braucht Grants/Closure)
        $this->app->instance(\Platform\Core\Authz\AuthzResolver::class, new class extends \Platform\Core\Authz\AuthzResolver {
            public function reachableEntityIds(\Platform\Core\Models\User $user, string $cap = 'read'): array
            {
                return OrganizationEntity::pluck('id')->map(fn ($i) => (int) $i)->all();
            }
        });
    }

    protected function row(string $table, array $data): int
    {
        $data += ['uuid' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()];

        return DB::table($table)->insertGetId(array_intersect_key($data, array_flip(Schema::getColumnListing($table))));
    }

    /** Team, User, Agent; Knoten: Gruppe → Einheit A (→ Projekt A1, A2), Einheit B. */
    protected function seedWorld(): void
    {
        $U = \Platform\Core\Models\User::class;
        $user = $U::create(['name' => 'Martin', 'email' => 'm@x.test', 'password' => 'x']);
        $agent = $U::create(['name' => 'Ratchet', 'email' => 'r@x.test', 'password' => 'x']);
        // wie auf office: der Agent ist ein normales Konto, das an einem Agent-Knoten der Organisation hängt
        $teamId = DB::table('teams')->insertGetId(['name' => 'T1', 'user_id' => $user->id, 'personal_team' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('team_user')->insert(['team_id' => $teamId, 'user_id' => $user->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('team_user')->insert(['team_id' => $teamId, 'user_id' => $agent->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
        $user->forceFill(['current_team_id' => $teamId])->save();
        $user = $user->fresh();
        $this->actingAs($user);
        $team = \Platform\Core\Models\Team::find($teamId);

        $group = $this->row('organization_entity_type_groups', ['code' => 'units', 'name' => 'Einheiten', 'team_id' => $teamId, 'is_active' => true, 'sort_order' => 1]);
        $type = DB::table('organization_entity_types')->where('code', 'business_unit')->value('id') ?? $this->row('organization_entity_types', ['code' => 'business_unit', 'name' => 'Einheit', 'team_id' => $teamId, 'is_active' => true, 'sort_order' => 1, 'entity_type_group_id' => $group]);
        $e = fn ($name, $parent = null, array $extra = []) => $this->row('organization_entities', $extra + [
            'name' => $name, 'team_id' => $teamId, 'user_id' => $user->id, 'entity_type_id' => $type, 'parent_entity_id' => $parent, 'is_active' => true,
        ]);
        $this->ids['group'] = $e('Gruppe');
        $this->ids['a'] = $e('Einheit A', $this->ids['group']);
        $this->ids['a1'] = $e('Projekt A1', $this->ids['a']);
        $this->ids['a2'] = $e('Projekt A2', $this->ids['a']);
        $this->ids['b'] = $e('Einheit B', $this->ids['group']);
        $agentType = DB::table('organization_entity_types')->where('code', 'agent')->value('id') ?? $this->row('organization_entity_types', ['code' => 'agent', 'name' => 'Agent', 'team_id' => $teamId, 'is_active' => true, 'sort_order' => 2, 'entity_type_group_id' => $group]);
        $this->ids['ratchet'] = $e('Ratchet', null, ['linked_user_id' => $agent->id, 'entity_type_id' => $agentType]);

        $this->s = compact('user', 'agent', 'team', 'teamId');
    }

    protected function snapshot(int $entityId, array $metrics, int $daysAgo = 0, string $period = 'morning'): void
    {
        DB::table('organization_entity_snapshots')->insert([
            'entity_id' => $entityId, 'snapshot_date' => now()->subDays($daysAgo)->toDateString(),
            'snapshot_period' => $period, 'metrics' => json_encode($metrics), 'created_at' => now(),
        ]);
    }

    protected function ctx(?\Platform\Core\Models\User $user = null): \Platform\Core\Contracts\ToolContext
    {
        return new \Platform\Core\Contracts\ToolContext($user ?? $this->s['user'], $this->s['team']);
    }

    protected function tool(string $class, array $args, ?\Platform\Core\Models\User $user = null): \Platform\Core\Contracts\ToolResult
    {
        return (new $class())->execute($args, $this->ctx($user));
    }

    /** Livewire, Routen und ein Minimal-Layout registrieren (UI-Render per HTTP). */
    protected function bootUi(): void
    {
        $provider = $this->app->getProvider(\Platform\Signals\SignalsServiceProvider::class);
        $m = new \ReflectionMethod($provider, 'registerLivewireComponents');
        $m->setAccessible(true);
        $m->invoke($provider);
        if (! \Illuminate\Support\Facades\Route::has('signals.dashboard')) {
            \Illuminate\Support\Facades\Route::middleware('web')->prefix('signals')->group($this->module . '/routes/web.php');
        }
        if (! \Illuminate\Support\Facades\Route::has('organization.entities.show')) {
            \Illuminate\Support\Facades\Route::get('/organization/entities/{entity}', fn () => 'ok')->name('organization.entities.show');
        }
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->app['router']->getRoutes()->refreshActionLookups();
        $this->app['view']->addNamespace('signals', $this->module . '/resources/views');
        $layoutDir = sys_get_temp_dir() . '/signals-ui-layout';
        @mkdir($layoutDir . '/layouts', 0777, true);
        file_put_contents($layoutDir . '/layouts/app.blade.php', '<html><body>{{ $slot }}</body></html>');
        $this->app['view']->prependNamespace('platform', $layoutDir);
    }

    protected function relate(int $from, int $to, string $code, string $name): void
    {
        $typeId = DB::table('organization_entity_relation_types')->where('code', $code)->value('id')
            ?? $this->row('organization_entity_relation_types', ['code' => $code, 'name' => $name, 'is_active' => true, 'sort_order' => 1]);
        $this->row('organization_entity_relationships', ['from_entity_id' => $from, 'to_entity_id' => $to, 'relation_type_id' => $typeId, 'team_id' => $this->s['teamId'], 'user_id' => $this->s['user']->id]);
    }
}

/**
 * Test-Double der Gremien-Brücke: Signals wird nur gegen den Vertrag getestet, nie gegen ein konkretes Gremien-Modul.
 * Beschlüsse werden mit decide() simuliert; als Beschluss-Objekt dient ein beliebiges registriertes Modell (hier User).
 */
require_once getenv('SIGNALS_MODULE_PATH') . '/src/Contracts/CommitteeGateway.php';

class FakeCommitteeGateway implements \Platform\Signals\Contracts\CommitteeGateway
{
    public const REF = 'test_ref';

    public array $committees = [];
    public array $meetings = [];
    public array $items = [];
    public array $frames = [];
    private $handler;

    public function addCommittee(int $entityId, string $name, array $responsible = [], bool $withMeeting = true): void
    {
        $id = count($this->committees) + 1;
        $this->committees[] = ['committee_id' => $id, 'entity_id' => $entityId, 'name' => $name, 'responsible_user_ids' => $responsible];
        if ($withMeeting) {
            $this->meetings[$entityId] = ['meeting_id' => 100 + $id, 'title' => "{$name} Dezember", 'scheduled_at' => now()->addWeek()->toIso8601String(), 'url' => null];
        }
    }

    public function committees(): array
    {
        return $this->committees;
    }

    public function nextMeeting(int $committeeEntityId): ?array
    {
        return $this->meetings[$committeeEntityId] ?? null;
    }

    public function frames(int $entityId): array
    {
        return $this->frames[$entityId] ?? [];
    }

    public function draftAgendaItem(int $meetingId, array $item, string $linkType, int $linkId): array
    {
        $id = count($this->items) + 1;
        $this->items[$id] = $item + ['meeting_id' => $meetingId, 'link_type' => $linkType, 'link_id' => $linkId];

        return ['type' => self::REF, 'id' => $id, 'meeting_id' => $meetingId, 'url' => null];
    }

    public function onOutcome(callable $handler): void
    {
        $this->handler = $handler;
    }

    public function decide(int $itemId, string $kind, string $text, int $refId): void
    {
        $i = $this->items[$itemId];
        ($this->handler)($i['link_type'], $i['link_id'], $kind, self::REF, $refId, $text, null);
    }
}

