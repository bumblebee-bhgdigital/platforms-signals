<?php

/**
 * Signals Configuration
 * 
 * Diese Config-Datei definiert die Konfiguration für das Modul.
 * 
 * WICHTIG FÜR LLMs:
 * - Ersetze "signals" durch deinen Modul-Namen
 * - Ersetze "Signals" durch deinen Modul-Namen (PascalCase)
 * - Alle Routes müssen mit dem Modul-Prefix beginnen
 * 
 * @see Platform\Core\PlatformCore::registerModule() für Details zur Modul-Registrierung
 */

return [
    /**
     * Routing-Konfiguration
     * 
     * 'mode': 'path' = /signals/... (Standard)
     *         'subdomain' = signals.domain.com/... (Alternative)
     * 'prefix': URL-Präfix für alle Routes
     */
    'routing' => [
        'mode' => env('SIGNALS_MODE', 'path'),
        'prefix' => 'signals',
    ],
    
    /**
     * Guard für Authentication
     * Standard: 'web'
     */
    'guard' => 'web',

    /*
     * Frist des Algedonic-Kanals (Stunden, Beer: Schmerz-Kanal, extrem kurz). Die alte VSM-Zeitleiter
     * (Frist je Ebene ⇒ Ebene und Schweregrad hoch) entfällt mit Z3 – Eskalation siehe 'delivery'.
     */
    'deadlines' => [
        'algedonic' => 1,
    ],

    /*
     * Zustellung und Eskalation (Architektur Z1–Z4): Standards, wenn die gewinnende Zuordnung im Set nichts
     * eigenes setzt. Reaktionszeit in Arbeitstagen (Mo–Fr), übrige Fristen in Kalendertagen.
     */
    'delivery' => [
        'reaction_days' => (int) env('SIGNALS_REACTION_DAYS', 3),
        'recurrence_count' => 3,
        'recurrence_window_days' => 30,
        'persistence_days' => 14,
        'escalation_target' => 'line', // none | line | committee
        'delivery' => 'overview', // overview | task
        'task_severities' => ['critical', 'algedonic'], // Zustellung als Vorgang, wenn die Zuordnung nichts setzt
        'recipient_capability' => 'manage',
        'no_responsible_severity' => 'critical', // Standard-Schweregrad für „Einheit ohne Verantwortlichen“
    ],

    /**
     * Navigation-Konfiguration
     * 
     * Definiert, wie das Modul in der Hauptnavigation erscheint.
     * 'route': Route-Name für den Link
     * 'icon': Heroicon-Name (ohne heroicon-o- Präfix)
     * 'order': Sortier-Reihenfolge (niedrigere Zahlen = weiter oben)
     */
    'navigation' => [
        'route' => 'signals.dashboard',
        'icon'  => 'heroicon-o-bell-alert',
        'order' => 100, // Hohe Zahl = weiter unten in der Navigation
    ],

    /**
     * Sidebar-Konfiguration
     * 
     * Definiert die Sidebar-Struktur für das Modul.
     * 
     * Struktur:
     * - 'group': Gruppenname (optional)
     * - 'items': Array von Sidebar-Items
     *   - 'label': Anzeige-Text
     *   - 'route': Route-Name
     *   - 'icon': Heroicon-Name
     * 
     * Alternative: 'dynamic' für dynamische Listen (z.B. aus Datenbank)
     *   - 'model': Model-Klasse
     *   - 'team_based': true/false (nach Team filtern)
     *   - 'order_by': Sortier-Feld
     *   - 'route': Basis-Route (wird mit ID erweitert)
     *   - 'icon': Icon für alle Items
     *   - 'label_key': Feldname für Label
     */
    'sidebar' => [
        [
            'group' => 'Signal-Sets',
            'items' => [
                [
                    'label' => 'Meine Signale',
                    'route' => 'signals.mine',
                    'icon'  => 'heroicon-o-inbox',
                ],
                [
                    'label' => 'Übersicht',
                    'route' => 'signals.dashboard',
                    'icon'  => 'heroicon-o-bell-alert',
                ],
                [
                    'label' => 'Signale',
                    'route' => 'signals.signals.index',
                    'icon'  => 'heroicon-o-exclamation-triangle',
                ],
                [
                    'label' => 'Templates',
                    'route' => 'signals.templates.index',
                    'icon'  => 'heroicon-o-adjustments-horizontal',
                ],
                [
                    'label' => 'nx-Showcase',
                    'route' => 'signals.showcase',
                    'icon'  => 'heroicon-o-swatch',
                ],
            ],
        ],
    ],

    /*
    | Regelkreis: welche Beziehungen der Organisation Führung und Betreuung bedeuten. Einheiten sind Träger
    | (VSM-Klasse carrier der Organisation) – Knotentypen kennt Signals nicht.
    */
    'circle' => [
        'leader_relation' => env('SIGNALS_CIRCLE_LEADER_RELATION', 'manages'),
        'steward_relation' => env('SIGNALS_CIRCLE_STEWARD_RELATION', 'stewards'),
    ],
];
