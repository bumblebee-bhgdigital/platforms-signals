# platforms-signals

Plattform-Modul: Signals (Signal-Sets inkl. Kennzahlen, an Knoten hängbar).
Gerüst aus `martin3r-me/platform-module-template`. Namespace `Platform\Signals`, Paket `martin3r/platform-signals`. Doku zum Gerüst: `LLM_GUIDE.md`, `DESIGN.md`.

## Signal-Set (Basis, #1028–#1030)

Grundmodell: `SignalSet` (Morph-Alias `signal_set`), Service `SignalSetService`, Policy `SignalSetPolicy`,
MCP-Tools `signals.signal_sets.GET/POST/PUT/DELETE`.

### Unterschiede zum Planner-Projekt

| Thema | Planner-Projekt | Signal-Set |
|-------|-----------------|------------|
| Knoten-Zuordnung | ein Knoten | mehrere Knoten gleichzeitig |
| Vererbung nach unten | – | pro Zuordnung wählbar (`signal_set_nodes.inherit_down`; die Dimension Bridge kennt keine Vererbungs-Option je Link) |
| Umhängen | ersetzt den Knoten | ersetzt genau die Zuordnung `from_entity_id` -> `entity_id`, übrige Knoten bleiben |
| Status | Projekt-Lifecycle | `draft` / `active` / `archived`, keine Einzel-Aktiv-Regel pro Knoten |
| Verantwortlicher Agent | – | `responsible_agent_user_id` (User vom Typ `ai_user`) |
| Event | Sidebar-Event | `SignalSetLinkChanged` (linked/unlinked/relinked/inherit_changed) + UI-Event `updateSidebar` |

Direkt vs. vererbt: `SignalSetService::setsForNode()` bzw. `signals.signal_sets.GET` mit `entity_id` liefert je Set `source=direct|inherited`.

## Messlogik: Templates, Auswertung, Signale (#1047, #1059, #1061)

Übernommen aus der Organisation (`organization.signal_definitions`, `organization.signals`) – nichts schlechter, kein Signal im Code.

- **Template** (`SignalTemplate`, `signal_templates`): `kind=rule` = Muster `threshold | trend | ratio | cross_dimension` mit `conditions`
  auf Kennzahlen der Knoten-Snapshots (Organisation), Takt `every_snapshot | daily | weekly`. `kind=inference` = Prompt + VSM-System +
  Kontextumfang; ausgewertet vom **verantwortlichen Agenten des Sets per MCP**, nicht vom Server.
- **Set ↔ Template** (`SignalSetTemplate`, `signal_set_templates`): Overrides je Set (`conditions_override` wird über die conditions gelegt,
  `severity_override`, `frequency_override`, `schedule_interval_hours_override`), pausierbar.
- **Auswertung** (`SignalEvaluationService`, `RuleEvaluator`, Command `signals:evaluate`, 08:16/18:16 nach dem Snapshot): nur aktive Sets,
  nur an deren Knoten (mit `inherit_down` inkl. Unterknoten). Ein offenes Signal je Set/Template/Knoten; entfällt die Bedingung,
  löst es sich mit Notiz selbst auf. Ausgelöste Signale bekommen `responsible_user_id` = Agent des Sets.
- **Signal** (`Signal`, Morph-Alias `signal`): Lebenszyklus wie bisher (Schwere inkl. algedonic, VSM-Ebene, Frist, Eskalation `signals:escalate`
  stündlich, Aggregation, Zurückstellen, Zuweisen, Maßnahmen, Kommentare, Fokus) plus **Auflösung**: `resolution_note`,
  `resolution_link_type/id` (Beschluss, Agendapunkt, Sitzung, Risiko, Planner-Aufgabe, Ticket oder Morph-Alias), `resolved_by_user_id`.
- **Algedonic-Knopf**: Listener auf `Core\Events\AlgedonicTriggered`; übernimmt erst, wenn der Listener der Organisation zurückgebaut ist.

- **KI-Auswertung durch Agenten** (`InferenceContextService`): `signals.inference.GET` liefert die fälligen KI-Zuordnungen des Agenten bzw.
  mit `assignment_id` den Kontext (Knoten des Sets mit Snapshots/Bewegung, Korrespondenz, Aufnahmen, Aktivität, Umwelt, Strategie, offene Signale,
  Gedächtnis); Befunde per `signals.signals.POST`, „nichts gefunden“ per `signals.inference.POST`.
- **Gedächtnis** (`SignalMemoryEntry`, `signal_memory_entries`): Rückmeldungen zu KI-Signalen (bestätigt/gelöst → baseline, verworfen → suppression)
  und Beobachtungen der Agenten; `signals.memory.GET/POST/PUT/DELETE`.

- **Umwelt (VSM S4, #1046)**: `SignalEnvironmentSource`/`SignalEnvironmentSnapshot`, Abruf RSS/KPI mit Extraktion (`EnvironmentPullService`,
  `signals:pull-environment-sources` stündlich, Intervall je Quelle), gelernte Relevanz (`source_relevance` im Gedächtnis, `signals:decay-environment-relevance`
  wöchentlich), Kontext mit Bewegung (`EnvironmentContextService`) für KI-Templates und Agent-API `GET /api/signals/agent/environment` (Perceptor).
- **Umzug der Verlaufsdaten**: `signals:import-organization --team=<root> [--dry-run]` übernimmt Umweltquellen + Snapshots und das Gedächtnis
  idempotent (legacy-IDs, Umschlüsselung) und deaktiviert die alten Quellen. Regeln/Prompts/Sets werden per MCP umgezogen.

MCP: `signals.templates.GET/POST/PUT/DELETE`, `signals.set_templates.PUT`, `signals.signals.GET/POST/PUT`, `signals.evaluate.POST` (Probelauf),
`signals.inference.GET/POST`, `signals.memory.GET/POST/PUT/DELETE`, `signals.environment_sources.GET/POST/PUT/DELETE`,
`signals.environment_sources.rate`, `signals.environment_snapshots.GET`.
Oberfläche: Set-Seite (Templates, Probelauf, Signale), `/signals/signals` (Arbeitsliste + Detail), `/signals/templates`, `/signals/environment`.

Tests: `tests/run-in-taiste.sh tests/Feature/SignalsCoreTest.php tests/Feature/SignalsUiTest.php tests/Feature/SignalsInferenceTest.php tests/Feature/SignalsEnvironmentTest.php`

## Regelkreis Einheit → Lenkungsausschuss (Architektur v2, `docs/ARCHITEKTUR-REGELKREIS.md`)

- Jedes neue Signal gehört zuerst seiner **verantwortlichen Einheit** (`unit_entity_id`: nächster Knoten mit „Führt an“, kein Ordner/Gremium), `stage=unit`.
- **Lokal lösen**: `signals.signals.PUT action=solve_locally` (resolution_note, optional frame_id) → `outcome=solved_locally`.
- **In den LA heben** (Faustregel: andere Einheiten, gemeinsame Ressourcen, strategische Prioritäten): `signals.committee_items.POST` mit den vier Fragen
  (goal, observation, obstacle, local_solution = 4a, system_support = 4b; ohne 4b kein LA-Punkt) → Entwurf eines Agendapunkts in der nächsten Sitzung
  des LA über der Einheit; `stage=committee`, Frist = Sitzung.
- **Rückweg**: Beschluss zum Agendapunkt → `SignalFeedback::decision` → measure/frame erledigt, return_to_unit zurück an die Einheit, escalation, strategy_topic.
- **Lage** für den Steward: `signals.committee_items.GET committee_entity_id=…` (nächste Sitzung, Signale im LA, Kandidaten mit Verantwortlichem und Rahmen).
- Gremien-Brücke `Contracts\CommitteeGateway` (umgesetzt vom Management Book), Registry `Support\CommitteeGateways`. Morph-Alias des Signals: `signals_signal`.
