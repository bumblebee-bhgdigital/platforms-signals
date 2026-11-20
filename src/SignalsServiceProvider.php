<?php

/**
 * Signals Service Provider
 * 
 * Dieser Service Provider ist das Herzstück jedes Platform-Moduls.
 * 
 * WICHTIG FÜR LLMs:
 * - Dieser Service Provider folgt dem exakten Muster von HCM und Planner
 * - Alle wichtigen Schritte sind kommentiert
 * - Config wird in register() geladen (Laravel Best Practice)
 * - Modul-Registrierung erfolgt in boot()
 * 
 * ANPASSUNGEN FÜR NEUES MODUL:
 * 1. Ersetze "Signals" durch deinen Modul-Namen (PascalCase)
 * 2. Ersetze "signals" durch deinen Modul-Namen (kebab-case)
 * 3. Passe Namespaces an
 * 4. Füge Commands/Tools hinzu falls nötig
 * 
 * @see Platform\Core\PlatformCore für Modul-Registrierung
 * @see Platform\Core\Routing\ModuleRouter für Route-Registrierung
 */

namespace Platform\Signals;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\Core\PlatformCore;
use Platform\Core\Routing\ModuleRouter;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class SignalsServiceProvider extends ServiceProvider
{
    /**
     * Register Services
     * 
     * Wird VOR boot() aufgerufen.
     * Hier sollten nur leichte Registrierungen erfolgen.
     * 
     * LARAVEL BEST PRACTICE:
     * - Config sollte hier geladen werden (mergeConfigFrom)
     * - Commands können hier registriert werden
     */
    public function register(): void
    {
        /**
         * Config laden
         * 
         * mergeConfigFrom lädt die Config aus dem Package-Verzeichnis
         * und merged sie mit der Config aus config/ (falls vorhanden).
         * 
         * WICHTIG: Muss in register() sein, nicht in boot()!
         */
        $this->mergeConfigFrom(__DIR__.'/../config/signals.php', 'signals');
        
        /**
         * Commands registrieren (optional)
         * 
         * Falls dein Modul Artisan Commands hat:
         * 
         * if ($this->app->runningInConsole()) {
         *     $this->commands([
         *         \Platform\Signals\Console\Commands\YourCommand::class,
         *     ]);
         * }
         */
    }

    /**
     * Boot Services
     * 
     * Wird NACH register() aufgerufen.
     * Hier erfolgt die eigentliche Modul-Registrierung.
     * 
     * REIHENFOLGE IST WICHTIG:
     * 1. Config prüfen (bereits in register() geladen)
     * 2. Modul bei PlatformCore registrieren
     * 3. Routes laden (nur wenn Modul registriert)
     * 4. Migrationen, Views, Livewire registrieren
     */
    public function boot(): void
    {
        /**
         * SCHRITT 1: Modul-Registrierung prüfen
         * 
         * Prüft ob:
         * - Config vorhanden ist
         * - modules-Tabelle existiert (für Datenbank-Registrierung)
         * 
         * Nur wenn beide Bedingungen erfüllt, wird das Modul registriert.
         */
        if (
            config()->has('signals.routing') &&
            config()->has('signals.navigation') &&
            PlatformCore::modulesTableExists()
        ) {
            /**
             * Modul bei PlatformCore registrieren
             * 
             * Dies registriert das Modul in:
             * - Der Modul-Registry (für Navigation, Sidebar)
             * - Der Datenbank (modules-Tabelle)
             * 
             * Die Config wird automatisch aus config/signals.php geladen.
             */
            PlatformCore::registerModule([
                'key'        => 'signals', // Eindeutiger Schlüssel
                'title'      => 'Signals', // Anzeige-Name
                'group'      => 'dev',
                'routing'    => config('signals.routing'),
                'guard'      => config('signals.guard'),
                'navigation' => config('signals.navigation'),
                'sidebar'    => config('signals.sidebar'),
            ]);
        }

        /**
         * SCHRITT 2: Routes laden
         * 
         * Routes werden nur geladen, wenn das Modul erfolgreich registriert wurde.
         * 
         * ModuleRouter::group() erstellt automatisch:
         * - Route-Prefix (aus Config)
         * - Middleware (web, auth, etc.)
         * - Domain-Handling (für Subdomain-Modus)
         */
        if (PlatformCore::getModule('signals')) {
            /**
             * Web-Routes (authentifiziert)
             * 
             * Standard: requireAuth = true
             * Für öffentliche Routes: requireAuth = false
             */
            ModuleRouter::group('signals', function () {
                $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            });
            
            /**
             * API-Routes (optional)
             * 
             * Falls dein Modul API-Endpoints hat:
             * 
             * ModuleRouter::apiGroup('signals', function () {
             *     $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
             * });
             */
        }

        /**
         * SCHRITT 3: Migrationen laden
         * 
         * Lädt alle Migrationen aus database/migrations/
         * Wird automatisch bei `php artisan migrate` ausgeführt.
         */
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        /**
         * SCHRITT 4: Config veröffentlichen
         * 
         * Ermöglicht es, die Config in config/signals.php zu überschreiben.
         * 
         * Publizieren mit:
         * php artisan vendor:publish --tag=config --provider="Platform\Signals\SignalsServiceProvider"
         * 
         * WICHTIG: mergeConfigFrom funktioniert auch OHNE Publizierung!
         */
        $this->publishes([
            __DIR__.'/../config/signals.php' => config_path('signals.php'),
        ], 'config');

        /**
         * SCHRITT 5: Views laden
         * 
         * Registriert Views unter dem Namespace 'signals'
         * 
         * Verwendung in Views:
         * @return view('signals::livewire.dashboard')
         */
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'signals');
        
        /**
         * SCHRITT 6: Livewire Components registrieren
         * 
         * Registriert alle Livewire-Komponenten automatisch.
         * 
         * Pattern:
         * - Datei: src/Livewire/Dashboard.php
         * - Alias: signals.dashboard
         * 
         * Verwendung:
         * <livewire:signals.dashboard />
         */
        $this->registerLivewireComponents();

        /**
         * SCHRITT 7: Morph-Alias, Policy, Tools
         */
        $this->registerMorphMap();
        $this->registerPolicies();
        $this->registerEntityLinkProvider();
        $this->registerTools();
    }

    /**
     * Organization-Bridge: Signal-Set als verknüpfbarer Objekttyp (EntityLinkProvider) + Link-Hook.
     */
    protected function registerEntityLinkProvider(): void
    {
        try {
            resolve(\Platform\Organization\Services\EntityLinkRegistry::class)
                ->register(new \Platform\Signals\Organization\SignalSetEntityLinkProvider());
        } catch (\Throwable $e) {
            // Organization-Modul nicht geladen
        }

        // Verknüpfungen entstehen nicht nur über den SignalSetService, sondern auch extern
        // (organization.entity.link.POST, Terminal-App "Verknüpfungen"). Der Hook hält die
        // Vererbungs-Einstellungen (signal_set_nodes) konsistent und dispatcht das Event.
        if (class_exists(\Platform\Organization\Models\OrganizationDimensionLink::class)) {
            \Platform\Organization\Models\OrganizationDimensionLink::created(function ($link) {
                $this->onDimensionLinkChanged($link, \Platform\Signals\Events\SignalSetLinkChanged::LINKED);
            });
            \Platform\Organization\Models\OrganizationDimensionLink::deleted(function ($link) {
                $this->onDimensionLinkChanged($link, \Platform\Signals\Events\SignalSetLinkChanged::UNLINKED);
            });
        }
    }

    protected function onDimensionLinkChanged($link, string $action): void
    {
        if ($link->linkable_type !== \Platform\Signals\Models\SignalSet::MORPH_ALIAS) {
            return;
        }

        try {
            $set = \Platform\Signals\Models\SignalSet::withTrashed()->find($link->linkable_id);
            if (! $set) {
                return;
            }
            \Platform\Organization\Services\EntityDimensionBridge::flush();
            $entityId = \Platform\Organization\Services\EntityDimensionBridge::entityId((int) $link->dimension_value_id);

            if ($entityId) {
                if ($action === \Platform\Signals\Events\SignalSetLinkChanged::LINKED) {
                    \Platform\Signals\Models\SignalSetNode::query()->firstOrCreate(
                        ['signal_set_id' => $set->id, 'entity_id' => $entityId],
                        ['inherit_down' => false],
                    );
                } else {
                    \Platform\Signals\Models\SignalSetNode::query()
                        ->where('signal_set_id', $set->id)->where('entity_id', $entityId)->delete();
                }
            }

            \Platform\Signals\Events\SignalSetLinkChanged::dispatch($set, $action, $entityId ?: null);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Signals: DimensionLink-Hook fehlgeschlagen', ['error' => $e->getMessage()]);
        }
    }

    /** Morph-Alias `signal_set` (DimensionLinks, EntityLinkProvider, Authz). */
    protected function registerMorphMap(): void
    {
        Relation::morphMap([
            \Platform\Signals\Models\SignalSet::MORPH_ALIAS => \Platform\Signals\Models\SignalSet::class,
        ]);
    }

    /** Graph-Autorisierung wie PlannerProjectPolicy. */
    protected function registerPolicies(): void
    {
        Gate::policy(
            \Platform\Signals\Models\SignalSet::class,
            \Platform\Signals\Policies\SignalSetPolicy::class
        );
    }

    /** MCP-Tools signals.signal_sets.GET/POST/PUT/DELETE in der Tool-Registry. */
    protected function registerTools(): void
    {
        try {
            $registry = resolve(\Platform\Core\Tools\ToolRegistry::class);
            $registry->register(new \Platform\Signals\Tools\ListSignalSetsTool());
            $registry->register(new \Platform\Signals\Tools\CreateSignalSetTool());
            $registry->register(new \Platform\Signals\Tools\UpdateSignalSetTool());
            $registry->register(new \Platform\Signals\Tools\DeleteSignalSetTool());
        } catch (\Throwable $e) {
            // ToolRegistry nicht verfügbar
        }
    }

    /**
     * Registriert alle Livewire-Komponenten automatisch
     * 
     * Scant das src/Livewire/ Verzeichnis rekursiv und registriert
     * alle PHP-Dateien als Livewire-Komponenten.
     * 
     * NAMING CONVENTION:
     * - Datei: src/Livewire/Dashboard.php
     * - Namespace: Platform\Signals\Livewire\Dashboard
     * - Alias: signals.dashboard
     * 
     * - Datei: src/Livewire/Entity/Index.php
     * - Namespace: Platform\Signals\Livewire\Entity\Index
     * - Alias: signals.entity.index
     * 
     * @return void
     */
    protected function registerLivewireComponents(): void
    {
        $basePath = __DIR__ . '/Livewire';
        $baseNamespace = 'Platform\\Signals\\Livewire';
        $prefix = 'signals';

        // Prüfe ob Verzeichnis existiert
        if (!is_dir($basePath)) {
            return;
        }

        // Rekursiv alle PHP-Dateien durchsuchen
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath)
        );

        foreach ($iterator as $file) {
            // Nur PHP-Dateien verarbeiten
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            // Relativen Pfad extrahieren
            $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $file->getPathname());
            
            // Klassenpfad generieren (z.B. Entity\Index -> Entity\Index)
            $classPath = str_replace(['/', '.php'], ['\\', ''], $relativePath);
            $class = $baseNamespace . '\\' . $classPath;

            // Prüfe ob Klasse existiert
            if (!class_exists($class)) {
                continue;
            }

            // Alias generieren (z.B. Entity\Index -> entity.index)
            $aliasPath = str_replace(['\\', '/'], '.', Str::kebab(str_replace('.php', '', $relativePath)));
            $alias = $prefix . '.' . $aliasPath;

            // Livewire-Komponente registrieren
            Livewire::component($alias, $class);
        }
    }
}
