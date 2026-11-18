{{--
    Übersicht aller Signal-Sets (nx-Design-System).
    Shell: x-ui-page*; Inhalt: x-nx-* + var(--nx-*). Datenform "gleichförmige Datensätze" -> Tabelle.
--}}

<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Signal-Sets" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Signal-Sets', 'icon' => 'bell-alert'],
        ]">
            @can('create', \Platform\Signals\Models\SignalSet::class)
                <x-nx-button variant="primary" size="sm" wire:click="openCreate">
                    @svg('heroicon-o-plus', 'w-4 h-4')
                    <span>Neu</span>
                </x-nx-button>
            @endcan
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container width="contained" spacing="space-y-6">
        <x-nx-stat-grid :cols="4">
            <x-nx-stat label="Sets gesamt" :value="$stats['total']" icon="heroicon-o-bell-alert" />
            <x-nx-stat label="Aktiv" :value="$stats['active']" icon="heroicon-o-check-circle" accent="var(--nx-success)" />
            <x-nx-stat label="Entwurf" :value="$stats['draft']" icon="heroicon-o-pencil-square" />
            <x-nx-stat label="Unverknüpft" :value="$stats['unlinked']" icon="heroicon-o-link-slash" accent="var(--nx-warning)" />
        </x-nx-stat-grid>

        <x-nx-section icon="heroicon-o-list-bullet" title="Alle Signal-Sets" :hint="$sets->count()"
                      description="Ein Signal-Set bündelt Signaldefinitionen und Kennzahlen und kann an mehreren Knoten hängen; die Vererbung nach unten ist pro Zuordnung wählbar.">
            <x-slot name="action">
                <x-nx-input-select name="statusFilter" size="sm" :options="$statusOptions" nullable="true" nullLabel="Alle Status" wire:model.live="statusFilter" />
            </x-slot>

            @if($sets->isEmpty())
                <x-nx-card>
                    <x-nx-empty icon="heroicon-o-bell-alert">
                        Noch keine Signal-Sets{{ $statusFilter !== '' ? ' in diesem Status' : '' }}.
                        @can('create', \Platform\Signals\Models\SignalSet::class)
                            <x-slot name="action">
                                <x-nx-button variant="secondary" size="sm" wire:click="openCreate">Erstes Set anlegen</x-nx-button>
                            </x-slot>
                        @endcan
                    </x-nx-empty>
                </x-nx-card>
            @else
                <x-nx-card flush>
                    <x-nx-table>
                        <x-nx-table-header>
                            <x-nx-table-header-cell>Titel</x-nx-table-header-cell>
                            <x-nx-table-header-cell>Knoten</x-nx-table-header-cell>
                            <x-nx-table-header-cell>Status</x-nx-table-header-cell>
                            <x-nx-table-header-cell>Owner</x-nx-table-header-cell>
                            <x-nx-table-header-cell align="right">Agent</x-nx-table-header-cell>
                        </x-nx-table-header>
                        <x-nx-table-body>
                            @foreach($sets as $set)
                                <x-nx-table-row wire:key="set-row-{{ $set->id }}" :href="route('signals.sets.show', $set)">
                                    <x-nx-table-cell>
                                        {{ $set->title }}
                                        @if($multiTeam && $set->team)
                                            <span class="ml-1 text-xs text-[color:var(--nx-muted)]">{{ $set->team->name }}</span>
                                        @endif
                                    </x-nx-table-cell>
                                    <x-nx-table-cell>
                                        @forelse($nodesBySet[$set->id] ?? [] as $node)
                                            <span class="text-xs">{{ $node['name'] }}@if($node['inherit']) <span title="Wirkt auch an Unterknoten" class="text-[color:var(--nx-muted)]">↓</span>@endif</span>@if(! $loop->last), @endif
                                        @empty
                                            <span class="text-xs text-[color:var(--nx-muted)]">Unverknüpft</span>
                                        @endforelse
                                    </x-nx-table-cell>
                                    <x-nx-table-cell><x-nx-badge :variant="$set->status->badgeVariant()" dot>{{ $set->status->label() }}</x-nx-badge></x-nx-table-cell>
                                    <x-nx-table-cell>{{ $set->owner?->name ?? '–' }}</x-nx-table-cell>
                                    <x-nx-table-cell align="right">{{ $set->responsibleAgent?->name ?? '–' }}</x-nx-table-cell>
                                </x-nx-table-row>
                            @endforeach
                        </x-nx-table-body>
                    </x-nx-table>
                </x-nx-card>
            @endif
        </x-nx-section>
    </x-ui-page-container>

    {{-- Die Modul-Sidebar (Sets nach Knoten) kommt aus signals.sidebar (Shell), wie im Planner. --}}

    {{-- Anlegen --}}
    <x-nx-modal wire:model="showCreate" size="md">
        <x-slot name="header">Signal-Set anlegen</x-slot>
        <div class="space-y-4">
            <x-nx-input-text name="form.title" label="Titel" wire:model="form.title" required />
            <x-nx-input-textarea name="form.description" label="Beschreibung" wire:model="form.description" rows="3" />
            <p class="text-xs text-[color:var(--nx-muted)]">Das Set startet als Entwurf. Knoten-Verknüpfung und Aktivierung folgen auf der Detailseite bzw. in der Sidebar.</p>
        </div>
        <x-slot name="footer">
            <div class="flex justify-end gap-3">
                <x-nx-button variant="ghost" wire:click="$set('showCreate', false)">Abbrechen</x-nx-button>
                <x-nx-button variant="primary" wire:click="create">Anlegen</x-nx-button>
            </div>
        </x-slot>
    </x-nx-modal>
</x-ui-page>
