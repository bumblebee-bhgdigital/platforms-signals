{{--
    Sidebar View (nx-Design-System) — Signal-Sets nach Knoten gruppiert, Unverknüpfte separat.
    Struktur wie planner::livewire.sidebar. Nur var(--nx-*) Tokens.
    Ein Set kann unter mehreren Knoten erscheinen.
--}}

<div>
    <a x-show="!collapsed" href="{{ route('signals.dashboard') }}" wire:navigate
       class="block p-3 text-sm italic text-[var(--nx-text)] uppercase border-b border-[var(--nx-line)] mb-2 hover:text-[var(--nx-accent)]">
        Signals
    </a>

    {{-- Eingeklappt: Einstieg in die Übersicht --}}
    <div x-show="collapsed" class="px-2 py-2 border-b border-[var(--nx-line)]">
        <a href="{{ route('signals.dashboard') }}" wire:navigate title="Signal-Sets"
           class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('signals.*') ? 'bg-[var(--nx-accent)]/10 text-[var(--nx-accent)]' : 'text-[var(--nx-text)] hover:bg-[var(--nx-bg)]' }}">
            @svg('heroicon-o-bell-alert', 'w-5 h-5')
        </a>
    </div>

    <div class="mt-2" x-show="!collapsed">
        <x-ui-sidebar-list label="Arbeiten">
            <x-ui-sidebar-item :href="route('signals.circle.index')">
                @svg('heroicon-o-arrow-path', 'w-4 h-4 text-[var(--nx-text)]')
                <span class="ml-2 text-sm">Regelkreis</span>
            </x-ui-sidebar-item>
            <x-ui-sidebar-item :href="route('signals.signals.index')">
                @svg('heroicon-o-exclamation-triangle', 'w-4 h-4 text-[var(--nx-text)]')
                <span class="ml-2 text-sm">Signale</span>
                @if($openSignals > 0)<span class="ml-auto text-xs text-[var(--nx-muted)]">{{ $openSignals }}</span>@endif
            </x-ui-sidebar-item>
            <x-ui-sidebar-item :href="route('signals.templates.index')">
                @svg('heroicon-o-adjustments-horizontal', 'w-4 h-4 text-[var(--nx-text)]')
                <span class="ml-2 text-sm">Templates</span>
            </x-ui-sidebar-item>
        </x-ui-sidebar-list>

        {{-- Knoten-Gruppen (Entity-Typ -> Baum -> Sets) --}}
        @foreach($entityTypeGroups as $typeGroup)
            <x-ui-sidebar-list wire:key="sg-type-group-{{ $typeGroup['type_id'] }}" :label="$typeGroup['type_name']">
                @foreach($typeGroup['entities'] as $entityNode)
                    @include('signals::livewire.partials.sidebar-entity-node', [
                        'node' => $entityNode,
                        'typeIcon' => $typeGroup['type_icon'] ?? null,
                        'linkingSetId' => $linkingSetId,
                        'linkingAnchor' => $linkingAnchor,
                        'linkInherit' => $linkInherit,
                        'linkCandidates' => $linkCandidates,
                    ])
                @endforeach
            </x-ui-sidebar-list>
        @endforeach

        {{-- Nicht verknüpfte Sets --}}
        @if($unlinkedSets->isNotEmpty())
            <x-ui-sidebar-list label="Unverknüpft">
                @foreach($unlinkedSets as $set)
                    @include('signals::livewire.partials.sidebar-set-row', [
                        'set' => $set, 'nodeId' => null, 'inherit' => false,
                        'linkingSetId' => $linkingSetId, 'linkingAnchor' => $linkingAnchor, 'linkInherit' => $linkInherit, 'linkCandidates' => $linkCandidates,
                    ])
                @endforeach
            </x-ui-sidebar-list>
        @endif

        @if($entityTypeGroups->isEmpty() && $unlinkedSets->isEmpty())
            <div class="px-3 py-1 text-xs text-[var(--nx-muted)]">Keine Signal-Sets</div>
        @endif

        {{-- Showcase (Gerüst-Beispiel) bleibt erreichbar --}}
        <x-ui-sidebar-list label="Entwicklung">
            <x-ui-sidebar-item :href="route('signals.showcase')">
                @svg('heroicon-o-swatch', 'w-4 h-4 text-[var(--nx-text)]')
                <span class="ml-2 text-sm">nx-Showcase</span>
            </x-ui-sidebar-item>
        </x-ui-sidebar-list>
    </div>
</div>
