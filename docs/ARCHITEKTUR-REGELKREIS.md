# Architektur: Signale als Regelkreis zwischen Agenten und Gremien

Stand 2026-11-21 · Entwurf zur Abstimmung (Martin)

## Ziel

Die Gremien füllen sich von selbst. Agenten sehen Probleme und Chancen und regeln, was in ihrem Rahmen liegt, selbst.
Was den Rahmen sprengt, landet vorbereitet im Gremium. **Der Mensch entscheidet** – er ist das Zünglein an der Waage.
Gremien werden nicht abgeschafft, sondern entlastet: Ihre Zeit geht in Entscheidungen, nicht in Lagefeststellung.

Messgröße: **Absorption** – wie viel wird auf welcher Ebene erledigt (Agent im Mandat → Gremium → Eskalation).

## Der Kreis

```
 Wahrnehmen ──► Deuten ──► Weg wählen ──┬─► (A) Absorbieren im Mandat ──► Lagebild ──┐
 (Fakten)       (Set +    (Agent)       ├─► (B) Vorlage ans Gremium ──► Sitzung ──►│ Beschluss
                 Agent)                 └─► (C) Sofort an einen Menschen ──────────┘   │
      ▲                                                                                 │
      └──────────── Rückmeldung (Auflösung, Gedächtnis, Mandat) ◄──── Umsetzung (Agent) ◄┘
```

1. **Wahrnehmen** – Fakten an Knoten: Kennzahlen der Module (Dimension Bridge, Snapshots, gibt es), **Beobachtungen**
   (neu: Ereignisse aus Modulen, Agenten, Menschen, Umwelt).
2. **Deuten** – Das **Set** am Knoten sagt, worauf geachtet wird (Templates). Der **Agent des Sets** entscheidet,
   was davon ein Signal ist. Regeln bleiben als einfache, verlässliche Auslöser.
3. **Weg wählen** – jedes Signal bekommt einen von drei Wegen:
   - **A `absorb`** – liegt im **Mandat** des Agenten: er erledigt es selbst (Aufgabe, Rückfrage, Umplanung) und berichtet im Lagebild.
   - **B `propose`** – sprengt den Rahmen: der Agent schreibt eine **Beschlussvorlage** (Lage, Belege, 2–3 Optionen,
     Empfehlung); sie steht automatisch auf der Agenda der **nächsten Sitzung** des zuständigen Gremiums.
   - **C `escalate`** – algedonisch/dringend: sofort an einen Menschen, ohne auf die Sitzung zu warten.
4. **Entscheiden** – das Gremium entscheidet (Mensch). Die Sitzung bleibt der Ort.
5. **Umsetzen & schließen** – Beschluss → Agent legt Folgeaufgaben an → Signal wird mit Verweis auf den Beschluss aufgelöst →
   Gedächtnis lernt; Leitplanken-Beschlüsse verändern das Mandat.

## Bausteine

### 1. Gremium = Knoten (gibt es)
Ein Set hängt an einem Knoten; ist der Knoten ein Gremium (Management Book, `organization_entity_id`), ist das
**zuständige Gremium** damit bekannt. Hängt das Set an einer Einheit, ist das zuständige Gremium das nächste
Gremium der Einheit (Kind-Knoten vom Typ `board` unter der Einheit bzw. ihren Vorfahren) – konfigurierbar am Set (`committee_entity_id`).

### 2. Mandat = Leitplanken des Gremiums (neu)
- Das Gremium beschließt, was sein Agent allein darf: Beschluss-Art **`mandate`** („Mandat für den Agenten“) in allen
  Gremienformaten (LA, Board, Strategiemeeting), Text = Rahmen in Klartext, z. B. „Ratchet plant Aufgaben bis 2 Tage Verzug selbst um.“
- Aktive Mandate gehen in den Kontext des Agenten (`signals.inference.GET`). Die Plattform erzwingt nichts technisch –
  Agenten sind attribuierte Akteure; jede Absorption verweist auf das Mandat, auf das sie sich stützt (Nachvollziehbarkeit).
- Mit jeder Sitzung kann das Gremium das Mandat weiten oder enger ziehen → kontrolliert wachsende Selbststeuerung.

### 3. Beobachtungen (neu, Phase 2)
`signal_observations`: Fakt an einem Knoten (Quelle: Modul | Agent | Mensch | Umwelt, Code, Text, Payload, Zeitpunkt).
Module melden Ereignisse lose gekoppelt (z. B. Book: Beschluss überfällig, Sitzung ausgefallen; Helpdesk: Eskalation).
Beobachtungen sind **kein** Signal – sie sind Rohstoff für Templates und Agenten (Grundsatz: kein Signal im Code).
Erste Stufe kommt ohne aus: viele Ereignisse gibt es schon als Kennzahl (`mb_*`, `planner_items_overdue`).

### 4. Signal (gibt es, erweitert)
Neu am Signal:
- `route` (`absorb` | `propose` | `escalate`), `route_decided_by` (Agent/Mensch), `route_reason`
- `committee_entity_id` (zuständiges Gremium), `due_meeting_ref` (nächste Sitzung = Frist)
- `mandate_ref` (Beschluss, auf den sich eine Absorption stützt)
- **Vorlage** als eigenes Objekt `signal_proposals`: Lage, Optionen [{title, consequences}], Empfehlung, Belege, Status
  (`draft` → `on_agenda` → `decided` | `withdrawn`), Verweis auf Agendapunkt und Beschluss.
Status-Lebenszyklus: `open` → (`absorbed` | `proposed` → `decided`) | `dismissed`. Frist = nächste Sitzung des Gremiums;
die stündliche VSM-Eskalation bleibt nur für Weg C und für Signale ohne Gremium.

### 5. Gremien-Schnittstelle (neu, Vertrag)
Signals definiert den Vertrag, das Management Book implementiert ihn (wie Wirkungs-Quellen bei People):

```php
interface CommitteeGateway {
    public function committeeFor(int $entityId): ?array;              // zuständiges Gremium (id, name, entity_id, format)
    public function nextMeeting(int $committeeEntityId): ?array;      // id, scheduled_at, status
    public function mandates(int $committeeEntityId): array;          // aktive Mandats-Beschlüsse (id, text, since)
    public function proposeAgendaItem(int $meetingId, array $item): array; // legt Agendapunkt an (kind decision, linked_object = signal)
    public function attachBriefing(int $meetingId, array $doc): array;     // Lagebild als Unterlage der Sitzung
}
```
Rückweg: das Book löst bei einem Beschluss zu einem Agendapunkt mit `linked_object = signal` das Event
`DecisionTaken` aus → Signals löst das Signal mit `resolution_link = Beschluss` auf und gibt dem Agenten die Umsetzung.

### 6. Lagebild (neu)
Je Gremium und Sitzung: was hat der Agent im Mandat absorbiert, welche Vorlagen liegen an, was verdichtet sich.
Der Agent schreibt es (`signals.briefings.POST`), die Plattform hängt es als Unterlage an die Sitzung. Absorbierte Signale
füllen den Teil „bereits erledigt“ automatisch.

### 7. Gespräch in Parlan (Phase 3)
Signale auf Weg C und Vorlagen werden zusätzlich Vorgänge in Parlan beim Zuständigen (Rückfrage, Übernahme, Erledigt).
Signals hält Zustand und Belege; das Gespräch liegt in Parlan („das ERP chattet nicht“).

### 8. Lernen (gibt es, erweitert)
Gedächtnis aus Rückmeldungen (gibt es). Neu: Treffsicherheit je Template (Anteil absorbiert/entschieden vs. verworfen) –
was oft danebenliegt, wird leiser bzw. fällt zur Überprüfung ins Gremium.

## Verantwortung der Module

| Modul | Rolle |
|---|---|
| **Organisation** | Knoten, Beziehungen, Kennzahlen (Bridge). Keine Signal-Logik mehr (Rückbau). |
| **Signals** | Sets, Templates, Beobachtungen, Signale, Wege, Vorlagen, Lagebild, Gedächtnis, Vertrag `CommitteeGateway`. |
| **Management Book** | Gremien, Sitzungen, Beschlüsse, Mandate; implementiert `CommitteeGateway`; meldet Beschlüsse zurück. |
| **Agenten** (per MCP) | Wahrnehmen, Weg wählen, absorbieren, Vorlagen und Lagebild schreiben, umsetzen. |
| **Menschen** | Entscheiden in der Sitzung, setzen Mandate, Algedonic-Knopf. |

## Schritte

1. **Kreis am LA mit Ratchet** (zuerst, Ziel: Sitzung 01.12.)
   - Signal: `route`, `committee_entity_id`, `due_meeting_ref`, `mandate_ref`; `signal_proposals`
   - `CommitteeGateway` im Book; Beschluss-Art `mandate`; Agendapunkt aus Vorlage; Rückweg Beschluss → Signal
   - MCP: `signals.signals.PUT route=…`, `signals.proposals.POST/PUT`, Mandate + nächste Sitzung im Agenten-Kontext
   - Set „Lenkungsausschuss“ am Knoten #39, Agent Ratchet; erstes Template auf `mb_*`-Kennzahlen (z. B. Beschlüsse überfällig)
2. **Lagebild** als Sitzungsunterlage; Absorption sichtbar machen (Kennzahl je Gremium).
3. **Beobachtungen** aus den Modulen; Book-Ereignisse zuerst.
4. **Parlan** für Weg C und Vorlagen.
5. **Rückbau Organisation** (Signal-Quelle für Brief/Übersicht), Lernen je Template.

## Was vom heutigen Bau bleibt
Sets an Knoten, verantwortlicher Agent, Templates (Regel/KI), Auswertung nur wo ein Set hängt, Auflösung mit Verweis,
Gedächtnis, Umwelt, Agenten-Arbeitsliste per MCP. Die Basis-Regeln laufen weiter als Auslöser; ihr Weg ist zunächst
„offen“, bis ein Agent ihn wählt.
