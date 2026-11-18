<?php

/**
 * Signals Web Routes
 * 
 * Diese Datei definiert alle Web-Routes für das Modul.
 * 
 * WICHTIG FÜR LLMs:
 * - Routes werden automatisch mit dem Modul-Prefix versehen (aus Config)
 * - Middleware wird automatisch hinzugefügt (web, auth, etc.)
 * - Route-Namen sollten mit dem Modul-Prefix beginnen
 * 
 * BEISPIEL:
 * Route::get('/', Dashboard::class)->name('signals.dashboard');
 * 
 * Wird zu: /signals/ (wenn prefix = 'signals')
 * 
 * @see Platform\Core\Routing\ModuleRouter für Details
 */

use Platform\Signals\Livewire\Dashboard;
use Platform\Signals\Livewire\Show;
use Platform\Signals\Livewire\Showcase;

/**
 * Dashboard Route
 *
 * Hauptübersicht des Moduls
 */
Route::get('/', Dashboard::class)->name('signals.dashboard');

// Übersicht aller Sets (Alias auf dieselbe Komponente) und Detailseite.
// {signalSet} = Route-Model-Binding auf die ID (wie {plannerProject} im Planner).
Route::get('/sets', Dashboard::class)->name('signals.sets.index');
Route::get('/sets/{signalSet}', Show::class)->name('signals.sets.show');

/**
 * nx-Showcase Route
 *
 * Lebendes Verzeichnis aller x-nx-* Komponenten (Design-Referenz).
 */
Route::get('/showcase', Showcase::class)->name('signals.showcase');

/**
 * Weitere Routes hinzufügen:
 *
 * Route::get('/entities', Entity\Index::class)->name('signals.entities.index');
 * Route::get('/entities/{entity}', Entity\Show::class)->name('signals.entities.show');
 */
