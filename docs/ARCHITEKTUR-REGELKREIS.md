# Architektur: Signale im Regelkreis des BROICH.MANAGEMENTMODELLS

Stand 2026-11-22 · Entwurf v3 (Zustellung und Eskalation) · abgeglichen mit Academy MGT-101 („Management als System“), insbesondere
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

### 6b. Rekursion, Ebenen und Stewards – lose gekoppelt
**Wer weiß was:**
- *Organisation* – Struktur und ihre Semantik: VSM-Klasse `carrier` (lebensfähiges System), Agent-Knoten (Scope `agents()`),
  Beziehungen. Signals kennt **keine Knotentyp-Codes**.
- *Gremien-Modul* (heute das Book, Vertrag `CommitteeGateway`) – nur eigenes Wissen: aktive LAs mit ihrem Knoten, Sitzungen,
  Entscheidungsrahmen, Entwürfe, Rückweg der Beschlüsse für *beliebige* verknüpfte Objekt-Typen. Kennt Signals und Knotentypen nicht.
- *Signals* – der Regelkreis: Einheit, Ebene, Stellvertretung, Eskalation, Stewards.

**Regeln (alle aus der Struktur):**
- Einheit eines Signals = nächster Träger. Träger eines LA = nächster Träger über dem Gremium-Knoten.
- Nächster LA eines Knotens = LA des nächsten Trägers, der einen hat. Kandidaten eines LA sind nur Signale, für die er der nächste ist.
- **Ebene:** Ein LA, unter dem weitere LAs arbeiten, ist Gruppen-Ebene, sonst operativ – ohne Typ-Listen.
- **Stellvertretung:** Hat ein Träger keinen aktiven LA, arbeitet der LA darüber „stellvertretend für …“. Bekommt er einen, wandern die Signale dorthin.
- **Eskalation** im LA macht das Signal zum Kandidaten im LA darüber (`origin_committee_entity_id` bleibt sichtbar).
- **Stewards:** Menschen und/oder Agenten, beliebig viele – Beziehung `signals.circle.steward_relation` (Standard `stewards`,
  „Betreut (Regelkreis)“) zum Gremium-Knoten, plus die Verantwortlichen, die das Gremien-Modul nennt. Führung einer Einheit:
  `signals.circle.leader_relation` (Standard `manages`). Agenten arbeiten per MCP, Menschen auf der Seite „Regelkreis“.
- Verweise (resolution_link) sind Morph-Aliase, die Module selbst registrieren – Signals führt keine Liste fremder Klassen.
- Tests: Signals gegen ein Test-Double des Vertrags und mit nichtssagenden Typ-Codes; das Book testet seine Umsetzung des Vertrags.

### 6c. Signals sammelt keine Daten vor
Externe und interne Daten (Kennzahlen, Korrespondenz, Aufnahmen, Aktivität, Umwelt/RSS, Strategie) gehören in ihre Module.
Agenten und Menschen lesen sie selbst – nur so viel, wie die Frage braucht. Signals liefert zur Auswertung nur eigenes Wissen
(Frage, Knoten, offene Signale, Gedächtnis) und hält das Ergebnis fest. Keine Umweltquellen, keine Abruf-Jobs, keine data_sources.

### 7. Lagebild
Je Einheit (was lokal gelöst wurde, im Rahmen) und je LA-Sitzung (was ansteht, was sich zwischen Einheiten verdichtet,
was lokal erledigt ist). Der Agent schreibt es, die Plattform füllt die Fakten vor und hängt es als Unterlage an die Sitzung.

### 8. Gespräch in Parlan (Phase 4)
Signale an die Einheit und LA-Entwürfe werden Vorgänge in Parlan beim Verantwortlichen („das ERP chattet nicht“).

### 9. Lernen
Gedächtnis aus Rückmeldungen (gibt es). Treffsicherheit je Template; Maßstab je Einheit und Gremium:
Anteil lokal gelöst, Anteil im LA, Ergebnis-Checks „handlungsfähiger“.

## Zustellung und Eskalation (v3, 2026-11-22)

Leitgedanke: **Ein Signal geht an den nächstmöglichen Verantwortlichen – egal, wo das Set hängt.** Signale sind nicht
schlimm; schlimm ist ein Signal, auf das niemand reagiert. Die UX muss einfach sein: ein Mensch versteht drei Knöpfe.

### Z1. Sets: eines oben, vererbt – das nächste gewinnt
- Das **allgemeine Set** hängt an BHG.DIGITAL und vererbt sich auf alle Ventures und alles darunter.
- Ein Set weiter unten (z. B. an einem Venture) **übernimmt** (nichts tun), **passt an** (Schwelle, Schweregrad,
  Eskalation) oder **wählt ab** („gilt bei uns nicht“). Pro Knoten und Template gilt **die nächste Zuordnung im Baum**.
- Eine Zuordnung oben kann **verbindlich** sein: unten anpassbar, nicht abwählbar (z. B. Liquidität).
- Folge: **höchstens ein offenes Signal je Knoten und Template**, egal wie viele Sets darüber hängen. Die gewinnende
  Zuordnung bestimmt Empfänger-Fähigkeit, Zustellung und Eskalation.
- Der Typ-Filter `entity_types` (Knotentyp-Codes) entfällt – Abwahl am Set ersetzt ihn, Typ-Codes kennt Signals nicht.

### Z2. Empfänger: der nächste Verantwortliche, von unten gesucht
- **Verantwortlich** = hält am Knoten eine Beziehung oder Rolle mit der Fähigkeit **`manage`** (heute: „Führt an“,
  „Venture Owner von“, „Venture Co-Owner von“). Signals kennt keine Beziehungsnamen, nur die Fähigkeit.
- Signals fragt die Organisation: *„Wer ist jetzt verantwortlich für Knoten X mit Fähigkeit C?“* – ab dem Knoten des
  Signals aufwärts, bis jemand gefunden ist. Die Organisation beantwortet das aus **Rang, Vertretung und Abwesenheit**
  (Z6) und liefert eine geordnete Liste mit Begründung.
- **Mehrere Verantwortliche** am selben Knoten (Owner + Co-Owner): ein Vorgang als **offene Aufgabe**, wer zuerst
  übernimmt, hat ihn.
- **Befangen:** Betrifft das Signal den Empfänger selbst (z. B. Überlast einer Person), wird er übersprungen. Signale
  über Personen sehen nur Betroffener und Nächsthöherer.
- **Agent als Verantwortlicher** ist erlaubt; bei kritisch und Eskalation ist sein Mensch (Vorgesetzter im Profil) mit dabei.
- **Mehrere Einheiten betroffen:** Empfänger ist der Verantwortliche des gemeinsamen Elternknotens.
- Der **Agent des Sets** ist nicht Empfänger, sondern **Absender und Begleiter** (allgemeines Set: Ironhide).

### Z3. Eskalation: nur wenn nichts passiert – eine Stufe
Auslöser je Kontext (Knoten + Template), alle Werte an der Zuordnung im Set:

| Auslöser | Bedeutung | Beispiel-Standard |
|---|---|---|
| **ignoriert** | in der Reaktionszeit weder angenommen noch gelöst noch abgelehnt | 3 Arbeitstage |
| **wiederkehrend** | dieselbe Sache kommt im Zeitfenster N-mal (auch nach Auto-Auflösung) | 3× in 30 Tagen |
| **dauerhaft** | angenommen, aber zu lange offen | 14 Tage |
| **abgelehnt** | „gehört nicht zu mir“ (mit Begründung) | sofort |

**Eskalationsziel** je Zuordnung: *keins* (bleibt im Lagebild) · *Linie* (nächster Verantwortlicher darüber) ·
*Gremium* (der Steward bereitet den LA-Punkt nach den vier Fragen vor, Z5). An der Spitze einer Einheit folgt auf die
Linie ihr eigenes Gremium, erst danach die Ebene darüber. **Algedonisch** ist der einzige Sofortkanal an einen Menschen.
Die alte Zeitleiter (Frist ⇒ VSM-Ebene und Schweregrad automatisch hoch, `signals:escalate`) entfällt.

Fristen rechnet **der Zeitplan von Signals**, nicht der Agent – fällt ein Agent aus, bleibt nichts liegen.
Gerechnet wird in Arbeitstagen; pro Person höchstens N neue Vorgänge am Tag, der Rest gebündelt.

### Z4. Zustellung: ein Signal, ein Vorgang
- Zustellung je Zuordnung: *Lagebild* (Seite + gebündelte Morgen-Nachricht) · *Vorgang*. Standard: Hinweis/Warnung →
  Lagebild; kritisch, weitergereicht, „Einheit ohne Verantwortlichen“, algedonisch → Vorgang.
- Der Vorgang ist eine **Parlan-Aufgabe im Kontext-Raum der Einheit** (den Raum gibt es je Knoten bereits). Kein
  zentraler Signal-Kanal (Rauschen für alle), kein Direktgespräch (dort kann nicht übergeben werden).
- **Ein Vorgang über die ganze Lebensdauer:** Erinnerungen und Hinweise sind Kommentare; Eskalation = der Nächste wird
  in den Raum aufgenommen und bekommt **denselben Vorgang übergeben**; kommt die Sache wieder, wird der alte Vorgang
  wieder geöffnet („zum 3. Mal in 30 Tagen“).

| Parlan | Signal |
|---|---|
| annehmen | gesehen/übernommen – Reaktionsuhr stoppt |
| erledigt (Notiz) | gelöst – Notiz wird Lösung |
| ablehnen (Begründung) | gehört nicht zu mir – eine Stufe weiter |
| übergeben | Eskalation |
| Kommentar | Erinnerung, Rückfrage, Hinweis des Agenten |

- **Lose:** Vertrag `DeliveryChannel` in Signals (Vorgang anlegen, übergeben, kommentieren, wieder öffnen, schließen;
  Callback für annehmen/erledigt/abgelehnt). Umgesetzt im Modul mit der Parlan-Verbindung. Ohne Kanal läuft Signals
  über die Seite. Signals wird gegen ein Test-Double getestet.

### Z5. Ins Management Book nur, was eine Entscheidung braucht
Eskalationsziel *Gremium* oder die Linie reicht an der Spitze einer Einheit nicht ⇒ der Agent des Sets bereitet den
Punkt nach den vier Fragen vor ⇒ Entwurfs-Agendapunkt im LA **dieser** Einheit (bei einem Venture dessen LA) ⇒ Mensch
bestätigt, LA entscheidet ⇒ Ausgang über `CommitteeGateway` zurück (Maßnahme, Rückgabe mit Rahmen, Eskalation,
Strategiethema). Ein Rahmen-Beschluss erlaubt beim nächsten ähnlichen Signal das lokale Lösen. Der Parlan-Vorgang läuft
durch: „liegt im LA, Sitzung am …“, Beschluss als Kommentar, zurück an den Verantwortlichen oder geschlossen.
Regel-Treffer, Hinweise und lokal Gelöstes gehen nicht ins Book.

### Z6. Zuständigkeit als Plattform-Fähigkeit (Organisation + People)
Die Frage „wer ist jetzt zuständig?“ brauchen auch Planner, Helpdesk und Book – sie gehört nicht in Signals.

| Modul | Trägt |
|---|---|
| **Organisation** | **Rang** an Beziehungsarten und Rollen (Owner vor Co-Owner, „Führt an“ vor Projektleitung) · **Vertretung** („A vertritt B“, generell oder je Knoten, optional nur bei Abwesenheit; sonst rückt der Nächste im Rang am Knoten nach) · die **Abfrage** „verantwortlich für Knoten X mit Fähigkeit C“ (geordnet, mit Begründung) · Vertrag **Verfügbarkeit** (`isAbsent(person, Zeitraum)`) |
| **People** | **Abwesenheiten** (Zeitraum, Art, Quelle manuell oder M365-Abwesenheitsnotiz); bindet den Vertrag Verfügbarkeit. Agenten: abwesend = offline (Heartbeat der Organisation). Ohne Bindung gelten alle als anwesend. |
| **Signals** | stellt nur die eine Frage an die Organisation; kennt weder Rang noch Vertretung noch Abwesenheit. |

Abhängigkeit wie beim Agenten-Gedächtnis: Organisation definiert den Vertrag, People bindet ihn (nie Organisation → People).

### Z7. „Einheit ohne Verantwortlichen“
Verbindliches Template im allgemeinen Set: jede Einheit (VSM-Klasse *carrier*) ohne jemanden mit `manage`. Geht an den
nächsten Verantwortlichen **darüber** (er muss benennen), hoher Schweregrad, Vorgang. Solange offen, rutschen die Signale
der Einheit eine Stufe höher – sichtbar mit Grund. Erledigt sich, sobald jemand eingetragen ist; Knopf „Verantwortlichen
eintragen“. Agenten finden und schlagen vor (mit Begründung), **eintragen tut ein Mensch**. Kein Schattenbetrieb: die
Lücken zeigen sich als Signale und werden geschlossen.

### Z8. Die Schleife kontrolliert sich selbst
Je Template und Einheit: offen je Stufe, Zeit bis zur ersten Reaktion, Anteil lokal gelöst / weitergereicht / im LA,
Ablehn- und Verwurf-Quote. Über einer Schwelle löst das ein Signal an den Agenten des Sets aus („Template X erzeugt vor
allem Lärm“).

### Z9. „Meine Signale“
Eine Seite, drei Bereiche: **Bei mir** (ich bin der nächste Verantwortliche) · **Zu mir hochgekommen** (mit wer, wie
lange, warum) · **Erledigt** (wodurch). Drei Knöpfe: **gesehen**, **gelöst (wodurch)**, **gehört nicht zu mir**.

### Stand der Umsetzung (2026-11-22, Schritt 2 „Signals“)
| | Stand |
|---|---|
| **Z1** | gebaut: `EffectiveAssignmentService` („das nächste gewinnt“), `is_binding`, `opts_out`, Politik-Felder an `signal_set_templates`; ein offenes Signal je Team/Knoten/Template; Typ-Filter `entity_types` entfernt (Schlüssel werden ignoriert). |
| **Z2** | gebaut: Vertrag `ResponsibilityLookup` (Adapter auf die Organisation, sonst niemand), `SignalDelivery` mit Begründung, Betroffener übersprungen, Mensch des Agenten bei kritisch/Eskalation dabei. Offen: „mehrere Einheiten betroffen → gemeinsamer Elternknoten“, „Signale über Personen sehen nur Betroffener und Nächsthöherer“. |
| **Z3** | gebaut: ignoriert (Arbeitstage), dauerhaft, wiederkehrend, abgelehnt; Ziel none / line / committee; Zeitleiter und S5-Aggregation entfernt. Offen: „pro Person höchstens N neue Vorgänge am Tag“. |
| **Z4** | teilweise: Wiederöffnen statt neu („zum N. Mal in X Tagen“), Feld `delivery` an der Zuordnung. Offen: Vertrag `DeliveryChannel` / Parlan-Vorgang (Schritt 3). |
| **Z5** | über den bestehenden Regelkreis: Übergabe an das Gremium als `stage=escalated`, der Steward hebt mit den vier Fragen. |
| **Z6** | liegt in der Organisation (Schritt 1); Signals stellt nur die eine Frage. |
| **Z7** | gebaut: Muster `no_responsible` (Träger ohne `manage`), Empfänger darüber, erledigt sich selbst. Signale der Einheit gehen dabei über die Suche nach oben von selbst eine Stufe höher (ohne eigenen Hinweis). Offen: Knopf „Verantwortlichen eintragen“. |
| **Z8** | offen. |
| **Z9** | gebaut: `/signals/mine` und `signals.mine.GET`. |

### Durchgerechnet
**Projekt in FOOD.WORKS bis zum LA.** Regel trifft an Projekt P (Set an BHG.DIGITAL, vererbt) ⇒ nächster mit `manage`
ist die Projektverantwortliche ⇒ Vorgang im Raum von P, Absender Ironhide ⇒ 3 Arbeitstage keine Reaktion ⇒ Venture
Owner von FOOD.WORKS wird aufgenommen, Vorgang übergeben ⇒ er sieht: gemeinsame Ressource mit FOOD.ALCHEMIST betroffen
(Faustregel) ⇒ Ironhide bereitet die vier Fragen vor ⇒ Entwurf im LA von FOOD.WORKS ⇒ LA beschließt Maßnahme ⇒ Kommentar
im Vorgang, zurück an die Projektverantwortliche ⇒ umgesetzt, Vorgang geschlossen.

**Wiederkehrende Quote.** „Erledigungsquote < 30 %“ an Container C, Zuordnung: Ziel *Linie*, wiederkehrend 3× in 30
Tagen ⇒ Signal 1, gelöst („Aufgaben bereinigt“) ⇒ zwei Wochen später wieder unter 30 % ⇒ der alte Vorgang wird wieder
geöffnet, „zum 2. Mal“ ⇒ beim 3. Mal geht er an den Nächsten darüber: die lokale Lösung hält nicht, das ist ein Muster.
Ist die Ablehnquote des Templates hoch, meldet Z8 dem Agenten des Sets, dass die Regel überarbeitet werden muss.

### Reihenfolge
1. **Organisation:** Rang, Vertretung, Abfrage „verantwortlich“, Vertrag Verfügbarkeit.
2. **Signals:** Vererbung „nächstes gewinnt“, Empfänger über `manage`, Eskalation (Z3), Zeitleiter raus,
   „Einheit ohne Verantwortlichen“, „Meine Signale“, Kennzahlen der Schleife.
3. **Parlan-Kanal:** ein Signal, ein Vorgang im Kontext-Raum.
4. **People:** Abwesenheiten (parallel möglich – bis dahin gelten alle als anwesend).

## Verantwortung der Module

| Modul | Rolle |
|---|---|
| **Organisation** | Knoten, Beziehungen mit Fähigkeiten und Rang, Vertretung, Abfrage „verantwortlich“, Kennzahlen. Keine Signal-Logik mehr. |
| **People** | Abwesenheiten (bindet den Vertrag Verfügbarkeit der Organisation). |
| **Parlan-Adapter** | setzt `DeliveryChannel` um: ein Signal, ein Vorgang im Kontext-Raum. |
| **Signals** | Sets (vererbt, nächstes gewinnt), Templates, Signale mit Stufe und Ausgang, Zustellung und Eskalation, Lagebild, Gedächtnis, Verträge `CommitteeGateway` und `DeliveryChannel`. |
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
Gedächtnis, Arbeitsliste per MCP. Die Basis-Regeln laufen weiter; ihre Treffer gehen an die Einheit.
