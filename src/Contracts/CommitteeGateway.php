<?php

namespace Platform\Signals\Contracts;

/**
 * Brücke zu einem Gremien-Modul (heute das Management Book). Bewusst schmal: das Gremien-Modul liefert nur,
 * was es selbst weiß – welche Lenkungsausschüsse es gibt, ihre Sitzungen, Beschlüsse und Entscheidungsrahmen.
 * Was ein Regelkreis ist (Einheit, Ebene, Stellvertretung, Eskalation, Stewards), entscheidet Signals selbst
 * aus der Struktur der Organisation. Das Gremien-Modul kennt Signals nicht – es sieht nur einen verknüpften
 * Objekt-Typ (Morph-Alias) und meldet Beschlüsse dazu zurück.
 */
interface CommitteeGateway
{
    /** Gemeinsames Vokabular der Beschluss-Arten, die zum Regelkreis zurückgemeldet werden. */
    public const KIND_MEASURE = 'measure';
    public const KIND_RETURN_TO_UNIT = 'return_to_unit';
    public const KIND_ESCALATION = 'escalation';
    public const KIND_FRAME = 'frame';
    public const KIND_STRATEGY_TOPIC = 'strategy_topic';

    /**
     * Aktive Lenkungsausschüsse, die Punkte aus dem Regelkreis aufnehmen.
     *
     * @return array<int, array{committee_id:int, entity_id:int, name:string, responsible_user_ids:int[]}>
     *         entity_id = Knoten des Gremiums in der Organisation
     */
    public function committees(): array;

    /** Nächste geplante Sitzung: ['meeting_id','title','scheduled_at','url'] oder null. */
    public function nextMeeting(int $committeeEntityId): ?array;

    /** Aktive Entscheidungsrahmen für einen Knoten: [['type' (Morph-Alias),'decision_id','text','since','committee']]. */
    public function frames(int $entityId): array;

    /**
     * Punkt nach den vier Fragen als Entwurf in eine Sitzung legen, verknüpft mit einem fremden Objekt.
     *
     * @param  array{title:string, goal:string, observation:string, obstacle:string, local_solution:string, system_support:string, unit_entity_id:int, local_solution_confirmed?:bool, agent_user_id?:int}  $item
     * @return array{type:string, id:int, meeting_id:int, url:?string}
     */
    public function draftAgendaItem(int $meetingId, array $item, string $linkType, int $linkId): array;

    /**
     * Rückweg: Beschlüsse (und herausgenommene Entwürfe) zu verknüpften Objekten melden.
     * $handler(string $linkType, int $linkId, string $kind, string $refType, int $refId, string $text, ?int $byUserId)
     */
    public function onOutcome(callable $handler): void;
}
