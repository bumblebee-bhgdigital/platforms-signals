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

MCP: `signals.templates.GET/POST/PUT/DELETE`, `signals.set_templates.PUT`, `signals.signals.GET/POST/PUT`, `signals.evaluate.POST` (Probelauf).
Oberfläche: Set-Seite (Templates, Probelauf, Signale), `/signals/signals` (Arbeitsliste + Detail), `/signals/templates`.

Tests: `tests/run-in-taiste.sh tests/Feature/SignalsCoreTest.php tests/Feature/SignalsUiTest.php`
