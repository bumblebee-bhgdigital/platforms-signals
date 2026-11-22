<?php

namespace Platform\Signals\Contracts;

use DateTimeInterface;

/**
 * Frage an die Organisation: „Wer ist jetzt verantwortlich für Knoten X mit Fähigkeit C?“ (Architektur Z2/Z6).
 * Bewusst schmal: Signals kennt weder Beziehungsnamen noch Rang, Vertretung oder Abwesenheit – nur die Fähigkeit
 * (Standard `manage`). Die Organisation beantwortet das und liefert eine geordnete Liste mit Begründung.
 *
 * Umsetzung: OrganizationResponsibilityLookup (Adapter auf die Organisation), ohne Organisation
 * NullResponsibilityLookup (niemand gefunden). Tests laufen gegen ein Test-Double.
 *
 * Form eines Verantwortlichen (holder):
 *   ['entity_id' => int, 'user_id' => ?int, 'name' => string, 'kind' => 'person'|'agent', 'via' => string,
 *    'via_code' => string, 'via_name' => string, 'rank' => int, 'node_entity_id' => int, 'deputy_for' => ?array,
 *    'reason' => string, 'supervisor' => ?array{entity_id:?int, user_id:?int, name:?string}]
 */
interface ResponsibilityLookup
{
    /**
     * Verantwortliche direkt an einem Knoten (ohne Suche nach oben), geordnet nach Rang.
     *
     * @param  int[]  $excludeEntityIds  übersprungene Personen/Agenten (z. B. Betroffene eines Signals)
     * @return list<array<string,mixed>>
     */
    public function holdersAt(int $nodeId, string $capability = 'manage', array $excludeEntityIds = [], ?DateTimeInterface $at = null): array;

    /**
     * Nächster Verantwortlicher ab einem Knoten aufwärts, bis jemand gefunden ist.
     *
     * @param  int[]  $exclude  übersprungene Personen/Agenten
     * @return array{entity_id:int, found:bool, node:array{id:int,name:string}|null, distance:int|null,
     *               holders:list<array<string,mixed>>, path:list<array{id:int,name:string,reason:string}>}
     */
    public function responsibleFor(int $entityId, string $capability = 'manage', array $exclude = [], ?DateTimeInterface $at = null): array;
}
