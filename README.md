# platforms-signals

Plattform-Modul: Signals (Signal-Sets inkl. Kennzahlen, an Knoten hängbar).
Gerüst aus `martin3r-me/platform-module-template`; noch keine Fachlogik. Namespace `Platform\Signals`, Paket `martin3r/platform-signals`. Doku zum Gerüst: `LLM_GUIDE.md`, `DESIGN.md`.

## Signal-Set (Basis, #1028–#1030)

Grundmodell: `SignalSet` (Morph-Alias `signal_set`), Service `SignalSetService`, Policy `SignalSetPolicy`,
MCP-Tools `signals.signal_sets.GET/POST/PUT/DELETE`. Messlogik (Definitionen, Kennzahlen, Signale, Agent) folgt in eigenen Boards.

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
