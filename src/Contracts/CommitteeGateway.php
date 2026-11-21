<?php

namespace Platform\Signals\Contracts;

/**
 * Brücke zu den Gremien (umgesetzt vom Management Book). Signals kennt keine Gremien-Interna:
 * es fragt nach dem Lenkungsausschuss über einer Einheit, seiner nächsten Sitzung und den
 * Entscheidungsrahmen der Einheit – und legt LA-Punkte als Entwurf an.
 */
interface CommitteeGateway
{
    /** Nächster Lenkungsausschuss über der Einheit: ['committee_id','entity_id','name'] oder null. */
    public function committeeAbove(int $unitEntityId): ?array;

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
