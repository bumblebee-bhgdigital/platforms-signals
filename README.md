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

- **Template** (`SignalTemplate`, `signal_templates`): `kind=rule` = Muster `threshold | trend | ratio | cross_dimension | no_responsible` mit `conditions`
  auf Kennzahlen der Knoten-Snapshots (Organisation), Takt `every_snapshot | daily | weekly`. `kind=inference` = Prompt + VSM-System +
  Kontextumfang; ausgewertet vom **verantwortlichen Agenten des Sets per MCP**, nicht vom Server.
- **Set ↔ Template** (`SignalSetTemplate`, `signal_set_templates`): Overrides je Set (`conditions_override` wird über die conditions gelegt,
  `severity_override`, `frequency_override`, `schedule_interval_hours_override`), pausierbar. Dazu `is_binding`, `opts_out` und die Politik
  (`reaction_days`, `recurrence_count`, `recurrence_window_days`, `persistence_days`, `escalation_target`, `delivery`, `recipient_capability`; null = `config signals.delivery`).
- **Auswertung** (`SignalEvaluationService`, `RuleEvaluator`, Command `signals:evaluate`, 08:16/18:16 nach dem Snapshot): nur aktive Sets,
  nur an deren Knoten (mit `inherit_down` inkl. Unterknoten). **Das nächste gewinnt** (`EffectiveAssignmentService`): pro Knoten und Template
  wertet nur die nächste Zuordnung im Baum aus; Abwahl (`opts_out`) schaltet ab, außer eine Zuordnung weiter oben ist verbindlich.
  Ein offenes Signal je Team/Knoten/Template (egal aus welchem Set); entfällt die Bedingung, löst es sich mit Notiz selbst auf; kommt es im
  Zeitfenster wieder, wird das alte Signal **wieder geöffnet** (`recurrence_count`, Kommentar „zum N. Mal in X Tagen“).
  Der frühere Typ-Filter `entity_types`/`exclude_entity_types` entfällt (Schlüssel werden ignoriert) – Abwahl ersetzt ihn.
- **Bedingungen** (`conditions`, je Set überlagert von `conditions_override`):
  `threshold {metric, operator, value}` oder kombiniert `{all: [{metric, operator, value}, …]}` (alle müssen zutreffen, fehlende Kennzahl = kein Treffer;
  Meldung verbindet die Teile mit „ und “) · `trend {metric, direction, periods, min_change_percent, min_value?}` (`min_value`: letzter Wert mindestens) ·
  `ratio {numerator, denominator, operator, value, min_denominator?, grace_days?}` · `cross_dimension {metric_a, metric_b, relationship}`.
  **`units_only: true`** (alle vier Muster): nur Einheiten (VSM-Klasse carrier) werten aus, Kennzahlen als `<metric>_cascaded` aus dem Snapshot
  (Summe über den Teilbaum; fehlt sie, der einfache Wert). Die Meldung nennt die bis zu drei größten Beiträger darunter
  („– vor allem: IT-Infrastruktur (12), …“, `trigger_metrics.contributors`; abgestiegen wird bis zur nächsten Einheit). Offene Signale an anderen
  Knoten lösen sich beim nächsten Lauf auf („Regel zählt jetzt je Einheit – zusammengefasst an …“).
  Beispiel Stillstand: `{"units_only": true, "all": [{"metric": "planner_items_open", "operator": ">=", "value": 5}, {"metric": "planner_items_done_7d", "operator": "==", "value": 0}]}`.
  Probelauf per MCP: `signals.evaluate.POST {signal_set_id, signal_template_id?, dry_run: true}` → je Template `hits` (new/open/reopen, Empfänger) und `resolving`.
  `responsible_user_id` = Agent des Sets: **Absender und Begleiter**, nicht Empfänger.
- **Signal** (`Signal`, Morph-Alias `signal`): Lebenszyklus (Schwere inkl. algedonic, Zurückstellen, Zuweisen, Maßnahmen, Kommentare, Fokus) plus **Auflösung**: `resolution_note`,
  `resolution_link_type/id` (Beschluss, Agendapunkt, Sitzung, Risiko, Planner-Aufgabe, Ticket oder Morph-Alias), `resolved_by_user_id`.
- **Algedonic-Knopf**: Listener auf `Core\Events\AlgedonicTriggered`; übernimmt erst, wenn der Listener der Organisation zurückgebaut ist.

## Zustellung und Eskalation (Architektur v3, Z1–Z9)

- **Empfänger** (`SignalDelivery`, beim Anlegen): Frage an die Organisation über den Vertrag `Contracts\ResponsibilityLookup`
  („wer ist verantwortlich für Knoten X mit Fähigkeit `manage`?“, ab dem Knoten aufwärts). Adapter `Organization\OrganizationResponsibilityLookup`,
  ohne Organisation `Support\NullResponsibilityLookup` (niemand). Der Betroffene selbst wird übersprungen. Felder: `recipient_node_entity_id`,
  `recipient_entity_ids`, `recipient_user_ids`, `recipient_reason` (z. B. „Projekt P: niemand verantwortlich → nächster Verantwortlicher an FOOD.WORKS (Venture Owner)“),
  `delivered_at`, `reacted_at`, `signal_set_template_id` (gewinnende Zuordnung).
- **Eskalation** (`SignalEscalationService`, `signals:escalate` stündlich) – nur wenn nichts passiert, eine Stufe je Auslöser und nur einmal:
  *ignoriert* (keine Reaktion nach `reaction_days` Arbeitstagen), *dauerhaft* (angenommen, länger als `persistence_days` offen),
  *wiederkehrend* (`recurrence_count` erreicht), *abgelehnt* (`signals.signals.PUT action=reject`, Begründung Pflicht – sofort).
  Ziel: `none` (markieren) · `line` (nächster Verantwortlicher über dem Empfänger-Knoten; an der Spitze einer Einheit mit eigenem LA → Gremium) ·
  `committee` (an den LA über der Einheit, `stage=escalated` – der Steward hebt es mit den vier Fragen). Systemkommentar je Eskalation.
  Die alte VSM-Zeitleiter und die S5-Aggregation gibt es nicht mehr.
- **Einheit ohne Verantwortlichen**: Muster `no_responsible` – jede Einheit (VSM-Klasse `carrier`) ohne jemanden mit `manage`; Empfänger ist der
  Verantwortliche darüber, Standard-Schweregrad critical; erledigt sich, sobald jemand eingetragen ist.
- **Meine Signale**: `/signals/mine` (Bei mir · Zu mir hochgekommen · Erledigt; Knöpfe Gesehen, Gelöst, Gehört nicht zu mir) und `signals.mine.GET`.
- **Ein Signal, ein Vorgang** (Z4, Schritt 3): Bei Zustellung *Vorgang* (Politik `delivery=task`; Standard kritisch/algedonisch, immer bei
  Eskalation und „Einheit ohne Verantwortlichen“) führt der **Agent des Sets** einen Parlan-Vorgang im Kontext-Raum des Knotens. Signals kann in
  Parlan nicht selbst handeln (nur signierte Aktionen der handelnden Identität) und legt darum **Aufträge** ab (`SignalDeliveryQueue`,
  Tabelle `signal_delivery_ops`: `create`, `handover`, `comment`, `reopen`, `close`; je Signal der Reihe nach, höchstens ein `create`).
  Auslöser: zugestellt → create · Linie → handover (Grund, wer hatte es, wie lange) · Gremium → comment · wieder aufgetreten → reopen ·
  Halbzeit der Reaktionszeit ohne Reaktion → eine Erinnerung (`signals:escalate`) · gelöst/verworfen/automatisch aufgelöst → close.
  Der Agent holt sie mit `signals.deliveries.GET` und meldet mit `signals.deliveries.POST` (thread, nach 5 Fehlversuchen `failed` + Systemkommentar).
  Offene Aufträge erscheinen im Arbeitseingang des Agenten (Organization `GET /api/org/agent/work`, Zeile `signals.deliveries`) – so weckt der Takt ihn. Beim Schließen verfallen offene Kommentare/Übergaben davor.
  Felder am Signal: `delivery_thread`, `delivery_room_entity_id`, `delivery_state` (null · pending · open · closed). Anleitung für den Agenten: `docs/AGENT-ZUSTELLUNG.md`.
- **Rückweg aus Parlan**: Vertrag `Contracts\DeliveryChannel` (`onFeedback`), Registry `Support\DeliveryChannels`, Adapter
  `Organization\OrganizationParlanFeedback` (einzige Stelle, die das Ereignis `CoordinationEventsRecorded` der Organisation kennt).
  angenommen → gesehen · erledigt → gelöst („In Parlan erledigt“ + Notiz) · abgelehnt → „gehört nicht zu mir“ („In Parlan abgelehnt“ + Notiz, eine Stufe weiter).
  Ignoriert: Reaktionen des Set-Agenten selbst, unbekannte Vorgänge, doppelte Ereignisse (`signal_delivery_events.event_id`).

- **KI-Auswertung durch Agenten** (`InferenceContextService`): `signals.inference.GET` liefert die fälligen KI-Zuordnungen des Agenten bzw.
  mit `assignment_id` die Frage samt Knoten, offenen Signalen und Gedächtnis. Daten sammelt Signals nicht vor: der Agent liest sie selbst
  über die Tools der Module (`tool_registry.SEARCH`, Umwelt-Feeds z. B. `radar.items.GET`); Befunde per `signals.signals.POST`, „nichts gefunden“ per `signals.inference.POST`.
- **Gedächtnis** (`SignalMemoryEntry`, `signal_memory_entries`): Rückmeldungen zu KI-Signalen (bestätigt/gelöst → baseline, verworfen → suppression)
  und Beobachtungen der Agenten; `signals.memory.GET/POST/PUT/DELETE`.

- **Neustart statt Umzug** (2026-11-22): Altsignale und Gedächtnis der Organisation werden nicht übernommen; Signals wertet ab dem Neustart nur mit den eigenen Templates aus.

MCP: `signals.templates.GET/POST/PUT/DELETE`, `signals.set_templates.PUT`, `signals.signals.GET/POST/PUT`, `signals.evaluate.POST` (Probelauf),
`signals.inference.GET/POST`, `signals.memory.GET/POST/PUT/DELETE`, `signals.committee_items.GET/POST`, `signals.mine.GET`, `signals.deliveries.GET/POST`.
Oberfläche: `/signals/mine` (Meine Signale), Set-Seite (Templates, Probelauf, Signale), `/signals/signals` (Arbeitsliste + Detail), `/signals/templates`, `/signals/circle` (Regelkreis).

Tests: `tests/run-in-taiste.sh tests/Feature/SignalsCoreTest.php tests/Feature/SignalsUiTest.php tests/Feature/SignalsInferenceTest.php tests/Feature/SignalsCircleTest.php tests/Feature/SignalsDeliveryTest.php tests/Feature/SignalsChannelTest.php`

## Regelkreis Einheit → Lenkungsausschuss (Architektur v2, `docs/ARCHITEKTUR-REGELKREIS.md`)

- Jedes neue Signal gehört zuerst seiner **verantwortlichen Einheit** (`unit_entity_id`: nächster Knoten mit „Führt an“, kein Ordner/Gremium), `stage=unit`.
- **Lokal lösen**: `signals.signals.PUT action=solve_locally` (resolution_note, optional frame_id) → `outcome=solved_locally`.
- **In den LA heben** (Faustregel: andere Einheiten, gemeinsame Ressourcen, strategische Prioritäten): `signals.committee_items.POST` mit den vier Fragen
  (goal, observation, obstacle, local_solution = 4a, system_support = 4b; ohne 4b kein LA-Punkt) → Entwurf eines Agendapunkts in der nächsten Sitzung
  des LA über der Einheit; `stage=committee`, Frist = Sitzung.
- **Rückweg**: Beschluss zum Agendapunkt → `SignalFeedback::decision` → measure/frame erledigt, return_to_unit zurück an die Einheit, escalation, strategy_topic.
- **Lage** für den Steward: `signals.committee_items.GET committee_entity_id=…` (nächste Sitzung, Signale im LA, Kandidaten mit Verantwortlichem und Rahmen).
- Gremien-Brücke `Contracts\CommitteeGateway` (umgesetzt vom Management Book), Registry `Support\CommitteeGateways`. Morph-Alias des Signals: `signals_signal`.
