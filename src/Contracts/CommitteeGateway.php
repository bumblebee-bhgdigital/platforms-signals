<?php

namespace Platform\Signals\Contracts;

/**
 * Brücke zu den Gremien (umgesetzt vom Management Book). Signals kennt keine Gremien-Interna:
 * es fragt nach dem Lenkungsausschuss über einer Einheit, seiner nächsten Sitzung und den
 * Entscheidungsrahmen der Einheit – und legt LA-Punkte als Entwurf an.
 */
interface CommitteeGateway
{
    /**
     * Nächster Lenkungsausschuss über der Einheit (Rekursion: der LA, dessen Träger die Einheit oder ihr nächster Vorfahr ist):
     * ['committee_id','entity_id','name','carrier_entity_id','level' (unit|group),'proxy_for_entity_id'|null] oder null.
     * proxy_for_entity_id: nächster Träger (vsm_class carrier) der Einheit unterhalb des LA-Trägers, der selbst keinen aktiven LA hat
     * – der LA arbeitet dann stellvertretend für ihn.
     */
    public function committeeAbove(int $unitEntityId): ?array;

    /** Ein Gremium selbst: ['committee_id','entity_id','name','carrier_entity_id','level'] oder null. */
    public function committeeInfo(int $committeeEntityId): ?array;

    /** Nächster Lenkungsausschuss über einem Gremium (für Eskalationen eine Ebene höher) oder null. */
    public function committeeAboveCommittee(int $committeeEntityId): ?array;

    /** Wer betreut den Regelkreis dieses Gremiums? [['entity_id','name','user_id','is_agent']] – Menschen und/oder Agenten. */
    public function stewards(int $committeeEntityId): array;

    /** Gremien, deren Regelkreis ein Nutzer (Mensch oder Agent) betreut: [['committee_id','entity_id','name','level']]. */
    public function committeesStewardedBy(int $userId): array;

    /** Nächste geplante Sitzung des Gremiums: ['meeting_id','title','scheduled_at'] oder null. */
    public function nextMeeting(int $committeeEntityId): ?array;

    /** Aktive Entscheidungsrahmen einer Einheit: [['decision_id','text','since','committee']]. */
    public function frames(int $unitEntityId): array;

    /**
     * LA-Punkt nach den vier Fragen als Entwurf in eine Sitzung legen.
     *
     * @param  array{title:string, goal:string, observation:string, obstacle:string, local_solution:string, system_support:string, unit_entity_id:int, local_solution_confirmed?:bool, agent_user_id?:int}  $item
     * @return array{type:string, id:int, meeting_id:int, url:?string}
     */
    public function draftAgendaItem(int $meetingId, array $item, string $linkType, int $linkId): array;
}
