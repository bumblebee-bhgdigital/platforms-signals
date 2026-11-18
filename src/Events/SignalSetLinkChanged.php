<?php

namespace Platform\Signals\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Platform\Signals\Models\SignalSet;

/**
 * Wird dispatched, wenn sich die Knoten-Zuordnung (DimensionLink) eines Signal-Sets ändert:
 * verknüpft / gelöst / umgehängt / Vererbung geändert.
 *
 * Dient der Sidebar-Verknüpfung: Livewire-Komponenten hören auf das UI-Event `updateSidebar`
 * — dieses Laravel-Event ist die serverseitige Entsprechung für Listener anderer Module
 * (z. B. Cache-Invalidierung, Signale).
 */
class SignalSetLinkChanged
{
    use Dispatchable;

    public const LINKED = 'linked';
    public const UNLINKED = 'unlinked';
    public const RELINKED = 'relinked';
    public const INHERIT_CHANGED = 'inherit_changed';

    public function __construct(
        public readonly SignalSet $set,
        public readonly string $action,
        public readonly ?int $entityId = null,
        public readonly ?int $previousEntityId = null,
        public readonly ?bool $inheritDown = null,
    ) {
    }
}
