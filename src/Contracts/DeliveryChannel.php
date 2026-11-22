<?php

namespace Platform\Signals\Contracts;

/**
 * Rückweg aus dem Kanal, über den Signale als Vorgang zugestellt werden (Z4, heute Parlan).
 *
 * Der Hinweg läuft nicht über diesen Vertrag: In Parlan zählt nur, was die handelnde Identität selbst signiert –
 * Signals kann dort nichts im Namen des Agenten anlegen. Darum legt Signals Aufträge in die eigene Warteschlange
 * (signal_delivery_ops), der Agent des Sets führt sie mit seinem Parlan-Zugang aus (signals.deliveries.GET/POST).
 *
 * Der Vertrag liefert nur, was der Kanal selbst weiß: wer auf einen Vorgang wie reagiert hat. Was das für das
 * Signal heißt (gesehen, gelöst, eine Stufe weiter), entscheidet Signals. Umsetzung: OrganizationParlanFeedback
 * (Adapter auf das Ereignis der Organisation); Tests laufen gegen ein Test-Double.
 */
interface DeliveryChannel
{
    /** Gemeinsames Vokabular der Rückmeldungen. */
    public const ACCEPTED = 'accepted';
    public const DONE = 'done';
    public const DECLINED = 'declined';
    public const KINDS = [self::ACCEPTED, self::DONE, self::DECLINED];

    /**
     * Rückweg registrieren.
     * $handler(string $thread, string $kind, ?int $byUserId, ?string $note, string $eventId)
     *   thread  = Vorgang im Kanal (wie per signals.deliveries.POST gemeldet)
     *   kind    = accepted | done | declined
     *   eventId = eindeutige ID der Rückmeldung (Doppelte werden ignoriert)
     */
    public function onFeedback(callable $handler): void;
}
