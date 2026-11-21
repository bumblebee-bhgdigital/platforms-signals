# Architektur: Signale im Regelkreis des BROICH.MANAGEMENTMODELLS

Stand 2026-11-21 · Entwurf v2 · abgeglichen mit Academy MGT-101 („Management als System“), insbesondere
„Der Lenkungsausschuss – lokal vor übergreifend“, „Die vier Fragen“, „Hilfe statt Macht“, „Der Regelkreis“.

## Ziel

Die Gremien füllen sich von selbst. Agenten sehen Probleme und Chancen. Die Organisation regelt sich im Wesentlichen
selbst – **lokal vor übergreifend**. Was die Einheit nicht allein lösen kann, kommt vorbereitet in den Lenkungsausschuss.
**Menschen entscheiden**; Gremien werden entlastet, nicht abgeschafft. Agenten und Menschen arbeiten im Einklang.

Maßstab (aus MGT-101): **Ist die Organisation danach handlungsfähiger?** – so viel Selbststeuerung wie möglich,
so viel übergreifende Regulation wie nötig.

## Grundsätze (aus dem Kurs)

1. **Lokal vor übergreifend.** Was innerhalb eines Verantwortungsbereichs gelöst werden kann, wird dort gelöst.
   Der LA beginnt, wo lokale Handlungsfähigkeit nicht mehr reicht.
2. **Faustregel für den LA:** andere Einheiten, gemeinsame Ressourcen oder strategische Prioritäten sind betroffen.
3. **Eskalation ist kein Versagen,** sondern professionelle Führung.
4. **Hilfe statt Macht.** Kein „Warum habt ihr …?“, sondern „Was hindert euch? Was könnt ihr selbst? Was muss das System tun?“
5. **Eine rote Ampel ist keine Managementfrage.** Regeln über Kennzahlen liefern Beobachtungen, keine Agendapunkte.
6. **Rahmen statt Einzelfreigabe.** „Innerhalb dieses Rahmens entscheidet ihr selbst.“
7. **Catchball.** Der LA ist Sensor des Modells: Zeigt eine Abweichung, dass eine Annahme der Strategie nicht trägt,
   geht das zurück in die Strategiearbeit (Regelkreis Station 8).

## Der Ablauf

```
Beobachtung ──► Signal an die EINHEIT ──► lokal lösen (Einheit + ihr Agent, im Entscheidungsrahmen)
 (Kennzahlen,        │                           │ gelöst → Signal erledigt, im Lagebild der Einheit
  Ereignisse,        │                           │
  Agenten, Menschen) │ Faustregel greift (andere Einheiten / Ressourcen / Prioritäten)
                     ▼
              LA-Punkt nach den vier Fragen (vom Agenten vorbefüllt, 4a vom Bereich bestätigt)
                     ▼
              Sitzung des LA (Menschen entscheiden)
                     ├─► Maßnahme ──► Agent setzt um ──► Signal erledigt (Verweis auf Beschluss)
                     ├─► Zurück an den Bereich ──► Signal geht mit geklärtem Rahmen zurück an die Einheit
                     ├─► Eskalation ──► nächste Ebene (Board)
                     └─► Annahme der Strategie trägt nicht ──► Thema fürs Strategiemeeting (Catchball)
   Algedonisch / dringend: sofort an einen Menschen, ohne auf die Sitzung zu warten.
```

## Bausteine

### 1. Beobachtungen (Rohstoff, kein Signal)
Kennzahlen der Knoten (gibt es: Bridge, Snapshots, `mb_*`, `planner_*` …), Ereignisse aus den Modulen (Phase 3),
Wahrnehmungen von Agenten und Menschen. Regel-Templates („Quote < 30 %“) erzeugen **Beobachtungen** am Knoten.
Ein **Signal** entsteht erst, wenn ein Agent (oder Mensch) es in die Hilfelogik übersetzt: *Was hindert die Einheit?*
Übergang: Bis Agenten das leisten, bleiben Regel-Treffer Signale mit Weg „offen“ an der Einheit.

### 2. Zuständigkeit: zuerst die Einheit
- Jedes Signal hat eine **verantwortliche Einheit** (der Knoten selbst oder seine nächste Einheit im Baum) mit ihrem
  Verantwortlichen (Person, „Führt an“) und ihrem Agenten (Set an der Einheit, `responsible_agent`).
- Das Signal geht **zuerst an die Einheit**. Agent und Verantwortlicher lösen lokal im **Entscheidungsrahmen**.
- Das zuständige **übergreifende Gremium** ist der LA über der Einheit (Gremium = Knoten; nächster LA-Knoten unter
  den Vorfahren). Das Set der Einheit kennt es; überschreibbar am Set.

### 3. Entscheidungsrahmen (statt „Agenten-Mandat“)
- Der LA beschließt den **Rahmen einer Einheit**: Beschluss-Art `frame` („Entscheidungsrahmen“) im LA-Format, z. B.
  „Der Bereich Event plant Personal bis 2 Wochen Vorlauf selbst um; Budget bis X frei.“
- Der Rahmen gilt für die Einheit – Menschen und ihren Agenten. Er geht in den Kontext des Agenten; lokale Lösungen
  verweisen darauf. Mit jeder Sitzung kann der LA den Rahmen weiten oder enger ziehen.
- Die Plattform erzwingt nichts technisch; sie macht sichtbar, worauf sich eine lokale Lösung stützt.

### 4. Signal (gibt es, erweitert)
Neu: `unit_entity_id` (verantwortliche Einheit), `stage` (`unit` | `committee` | `escalated` | `strategy`),
`committee_entity_id`, `due_meeting_ref` (nächste LA-Sitzung = Frist, sobald im LA), `frame_ref` (Rahmen, auf den sich
eine lokale Lösung stützt), `outcome` (`solved_locally` | `measure` | `returned_to_unit` | `escalated` | `strategy_topic` | `dismissed`).
Die stündliche VSM-Eskalation bleibt nur für algedonische Signale und für Signale ohne Gremium.

### 5. LA-Punkt nach den vier Fragen
Statt einer Empfehlung „von oben“ füllt der Agent einen **echten LA-Agendapunkt** vor – die Felder gibt es im Book:
- `goal` – Was wollten wir erreichen?
- `observation` – Was beobachten wir tatsächlich? (Belege aus dem Signal)
- `obstacle` – Was verhindert die Zielerreichung?
- `local_solution` – 4a: Was kann der Bereich selbst lösen? (vom Bereich bestätigt)
- `system_support` – 4b: Wobei braucht er das Gesamtsystem?
Regel: **Ohne 4b kein LA-Punkt** – dann bleibt es lokal. Ohne 4a droht Mikromanagement – dann zurück an den Bereich.
Der Punkt erscheint in der Vorbereitung der nächsten LA-Sitzung als **Entwurf**; der Vorsitz kann ihn herausnehmen.
`linked_object = signal`.

### 6. Gremien-Schnittstelle (Vertrag in Signals, Umsetzung im Book)
```php
interface CommitteeGateway {
    public function committeeAbove(int $unitEntityId): ?array;          // nächster LA über der Einheit
    public function nextMeeting(int $committeeEntityId): ?array;
    public function frames(int $unitEntityId): array;                   // aktive Entscheidungsrahmen der Einheit
    public function draftAgendaItem(int $meetingId, array $fourQuestions, array $link): array; // Entwurf, kind „regulation“
    public function attachBriefing(int $meetingId, array $doc): array;  // Lagebild als Unterlage
}
```
Rückweg: Beschluss zu einem Agendapunkt mit `linked_object = signal` → Event `DecisionTaken(kind)` → Signals setzt
`outcome` nach Beschluss-Art (`measure` | `return_to_unit` | `escalation` | Strategie-Thema) und verweist auf den Beschluss.
Ergebnis-Checks des Agendapunkts („Blockaden beseitigt“, „Einheit kann wieder selbstständig handeln“ …) fließen in den Maßstab.

### 6a. Takt der Vorbereitung
Die Vorbereitung läuft **fortwährend**, nicht an einem Tag: In jedem Takt arbeitet der Steward neue Signale sofort ab
(lokal lösen oder als Entwurf in die nächste Sitzung heben); die Agenda wächst laufend. Kurz vor der Sitzung wird nur
noch abgeschlossen (Lagebild, Vollständigkeit der vier Fragen, offene 4a-Bestätigungen). Nach der Sitzung: Beschlüsse nachhalten.

### 7. Lagebild
Je Einheit (was lokal gelöst wurde, im Rahmen) und je LA-Sitzung (was ansteht, was sich zwischen Einheiten verdichtet,
was lokal erledigt ist). Der Agent schreibt es, die Plattform füllt die Fakten vor und hängt es als Unterlage an die Sitzung.

### 8. Gespräch in Parlan (Phase 4)
Signale an die Einheit und LA-Entwürfe werden Vorgänge in Parlan beim Verantwortlichen („das ERP chattet nicht“).

### 9. Lernen
Gedächtnis aus Rückmeldungen (gibt es). Treffsicherheit je Template; Maßstab je Einheit und Gremium:
Anteil lokal gelöst, Anteil im LA, Ergebnis-Checks „handlungsfähiger“.

## Verantwortung der Module

| Modul | Rolle |
|---|---|
| **Organisation** | Knoten, Beziehungen (Verantwortliche), Kennzahlen. Keine Signal-Logik mehr. |
| **Signals** | Sets, Templates, Beobachtungen, Signale mit Stufe und Ausgang, Lagebild, Gedächtnis, Vertrag `CommitteeGateway`. |
| **Management Book** | Gremien, Sitzungen, Agendapunkte nach den vier Fragen, Beschlüsse (inkl. `frame`), Ergebnis-Checks; setzt `CommitteeGateway` um. |
| **Agenten** (MCP) | wahrnehmen, in die Hilfelogik übersetzen, lokal lösen im Rahmen, LA-Punkte vorbefüllen, Lagebild, umsetzen. |
| **Menschen** | Bereich: 4a bestätigen, lokal führen. LA: entscheiden, Rahmen setzen, zurückgeben. Algedonic-Knopf. |

## Schritte

1. **Kreis Einheit → LA** (Ziel: LA-Sitzung 01.12.)
   - Signal: `unit_entity_id`, `stage`, `committee_entity_id`, `due_meeting_ref`, `frame_ref`, `outcome`
   - Book: `CommitteeGateway`, Beschluss-Art `frame` im LA-Format, Entwurfs-Agendapunkt aus den vier Fragen,
     Rückweg Beschluss → Signal nach Beschluss-Art
   - MCP: Signal an die Einheit / in den LA heben (mit den vier Fragen) / Ausgang; Rahmen + nächste Sitzung im Agenten-Kontext
   - Pilot: BHG.DIGITAL-Einheiten mit Set + Agent, LA #39 mit Ratchet
2. **Lagebild** je Einheit und LA-Sitzung; Maßstab sichtbar.
3. **Beobachtungen** aus den Modulen; Regel-Treffer werden Beobachtungen, Agenten übersetzen.
4. **Parlan** für Signale an die Einheit und LA-Entwürfe.
5. **Rückbau Organisation** (Signal-Quelle für Brief/Übersicht), Catchball-Weg ins Strategiemeeting ausbauen.

## Was vom heutigen Bau bleibt
Sets an Knoten, verantwortlicher Agent, Templates (Regel/KI), Auswertung nur wo ein Set hängt, Auflösung mit Verweis,
Gedächtnis, Umwelt, Arbeitsliste per MCP. Die Basis-Regeln laufen weiter; ihre Treffer gehen an die Einheit.
