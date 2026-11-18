{{--
    Detailseite Signal-Set (nx-Design-System): Eigenschaften + Platzhalter-Bereiche.
    Definitionen / Kennzahlen / ausgelöste Signale / Agent folgen in eigenen Boards und docken hier an.
--}}

<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar :title="$set->title" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Signal-Sets', 'href' => route('signals.dashboard'), 'icon' => 'bell-alert'],
            ['label' => $set->title],
        ]">
            @can('update', $set)
                {{-- ≥2 Aktionen -> ein Dropdown --}}
                <x-nx-dropdown label="Aktionen">
                    <x-nx-dropdown-item wire:click="openEdit">@svg('heroicon-o-pencil-square', 'w-4 h-4') Bearbeiten</x-nx-dropdown-item>
                    @if($set->status !== \Platform\Signals\Enums\SignalSetStatus::ACTIVE)
                        <x-nx-dropdown-item wire:click="activate">@svg('heroicon-o-check-circle', 'w-4 h-4') Aktivieren</x-nx-dropdown-item>
                    @endif
                    @if($set->status !== \Platform\Signals\Enums\SignalSetStatus::ARCHIVED)
                        <x-nx-dropdown-item wire:click="archive" wire:confirm="Set archivieren?">@svg('heroicon-o-archive-box', 'w-4 h-4') Archivieren</x-nx-dropdown-item>
                    @endif
                    @can('delete', $set)
                        <x-nx-dropdown-divider />
                        <x-nx-dropdown-item variant="danger" wire:click="deleteSet" wire:confirm="Set löschen? (Soft-Delete)">@svg('heroicon-o-trash', 'w-4 h-4') Löschen</x-nx-dropdown-item>
                    @endcan
                </x-nx-dropdown>
            @endcan
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container width="contained" spacing="space-y-6">
        @if(session('error'))
            <x-nx-callout variant="danger" icon="heroicon-o-exclamation-triangle" title="Fehler">{{ session('error') }}</x-nx-callout>
        @endif

        {{-- Kopf --}}
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <h1 class="m-0 text-xl font-semibold text-[color:var(--nx-text)] truncate">{{ $set->title }}</h1>
                @if($set->description)
                    <p class="mt-1 mb-0 text-sm text-[color:var(--nx-muted)] whitespace-pre-line">{{ $set->description }}</p>
                @endif
            </div>
            <x-nx-badge :variant="$set->status->badgeVariant()" dot>{{ $set->status->label() }}</x-nx-badge>
        </div>

        {{-- Eigenschaften --}}
        <x-nx-card>
            <x-nx-property-row icon="heroicon-o-building-office" label="Knoten">
                <div class="flex flex-col gap-1">
                    @forelse($nodes as $node)
                        <div wire:key="node-{{ $node['entity']->id }}" class="flex flex-wrap items-center gap-2">
                            <a href="{{ route('organization.entities.show', $node['entity']) }}" wire:navigate class="inline-flex items-center gap-1 text-sm hover:underline">
                                {{ $node['entity']->name }}
                                @if($node['entity']->type)<span class="text-xs text-[color:var(--nx-muted)]">({{ $node['entity']->type->name }})</span>@endif
                            </a>
                            <x-nx-badge :variant="$node['inherit'] ? 'info' : 'neutral'">{{ $node['inherit'] ? 'vererbt nach unten' : 'nur dieser Knoten' }}</x-nx-badge>
                            @can('update', $set)
                                <button type="button" wire:click="toggleInherit({{ $set->id }}, {{ $node['entity']->id }})" class="text-xs text-[color:var(--nx-muted)] hover:text-[color:var(--nx-text)] underline">
                                    {{ $node['inherit'] ? 'Vererbung aus' : 'Vererbung an' }}
                                </button>
                                <button type="button" wire:click="startLinking({{ $set->id }}, {{ $node['entity']->id }})" class="text-xs text-[color:var(--nx-muted)] hover:text-[color:var(--nx-text)] underline">Umhängen</button>
                                <button type="button" wire:click="unlinkNode({{ $set->id }}, {{ $node['entity']->id }})" wire:confirm="Zuordnung zu diesem Knoten lösen?" class="text-xs text-[color:var(--nx-muted)] hover:text-[color:var(--nx-danger)] underline">Lösen</button>
                            @endcan
                        </div>
                    @empty
                        <span class="text-[color:var(--nx-muted)]">Nicht verknüpft</span>
                    @endforelse

                    @can('update', $set)
                        <div>
                            <button type="button" wire:click="startLinking({{ $set->id }})" class="text-xs text-[color:var(--nx-muted)] hover:text-[color:var(--nx-text)] underline">
                                {{ $nodes->isEmpty() ? 'Mit Knoten verknüpfen' : 'Weiteren Knoten hinzufügen' }}
                            </button>
                        </div>
                    @endcan
                </div>

                @if($linkingSetId === $set->id)
                    <div class="mt-2 rounded-md border border-[color:var(--nx-line)] p-2">
                        <div class="flex items-center gap-2 mb-1">
                            <input type="text" wire:model.live.debounce.300ms="linkSearch" placeholder="Knoten suchen …"
                                   class="w-full rounded border border-[color:var(--nx-line)] bg-transparent px-2 py-1 text-sm text-[color:var(--nx-text)]" />
                            <x-nx-button variant="ghost" size="sm" wire:click="cancelLinking">Abbrechen</x-nx-button>
                        </div>
                        @unless($linkingFromEntityId)
                            <label class="flex items-center gap-2 px-2 pb-1 text-xs text-[color:var(--nx-muted)]">
                                <input type="checkbox" wire:model.live="linkInherit" class="rounded border-[color:var(--nx-line)]" />
                                Nach unten vererben
                            </label>
                        @endunless
                        @forelse($linkCandidates as $candidate)
                            <button type="button" wire:key="cand-{{ $candidate['id'] }}" wire:click="linkTo({{ $candidate['id'] }})"
                                    class="flex w-full items-center gap-2 rounded px-2 py-1 text-left text-sm hover:bg-[color:var(--nx-hover)]">
                                <span class="truncate">{{ $candidate['name'] }}</span>
                                @if($candidate['type'])<span class="ml-auto text-xs text-[color:var(--nx-muted)]">{{ $candidate['type'] }}</span>@endif
                            </button>
                        @empty
                            <div class="px-2 py-1 text-sm text-[color:var(--nx-muted)]">Keine passenden Knoten (Schreibrecht erforderlich).</div>
                        @endforelse
                    </div>
                @endif
            </x-nx-property-row>
            <x-nx-property-row icon="heroicon-o-user" label="Owner">{{ $set->owner?->name ?? '–' }}</x-nx-property-row>
            <x-nx-property-row icon="heroicon-o-cpu-chip" label="Verantwortlicher Agent">{{ $set->responsibleAgent?->name ?? '–' }}</x-nx-property-row>
        </x-nx-card>

        {{-- Platzhalter-Bereiche (folgen in eigenen Boards) --}}
        <x-nx-section icon="heroicon-o-adjustments-horizontal" title="Definitionen" description="Signaldefinitionen: Quellen, Schwellwerte, Frequenz und Eskalation.">
            <x-nx-card><x-nx-empty icon="heroicon-o-adjustments-horizontal">Noch keine Definitionen — dieser Bereich folgt in einem eigenen Board.</x-nx-empty></x-nx-card>
        </x-nx-section>

        <x-nx-section icon="heroicon-o-chart-bar" title="Kennzahlen" description="Kennzahlen, auf denen die Signale beruhen.">
            <x-nx-card><x-nx-empty icon="heroicon-o-chart-bar">Noch keine Kennzahlen — dieser Bereich folgt in einem eigenen Board.</x-nx-empty></x-nx-card>
        </x-nx-section>

        <x-nx-section icon="heroicon-o-bell-alert" title="Ausgelöste Signale" description="Signale, die aus diesem Set ausgelöst wurden.">
            <x-nx-card><x-nx-empty icon="heroicon-o-bell-alert">Noch keine ausgelösten Signale — dieser Bereich folgt in einem eigenen Board.</x-nx-empty></x-nx-card>
        </x-nx-section>

        <x-nx-section icon="heroicon-o-cpu-chip" title="Agent" description="Verantwortlicher Agent, der das Set überwacht und eskaliert.">
            <x-nx-card><x-nx-empty icon="heroicon-o-cpu-chip">Agent-Anbindung folgt in einem eigenen Board.</x-nx-empty></x-nx-card>
        </x-nx-section>
    </x-ui-page-container>

    {{-- Bearbeiten --}}
    <x-nx-modal wire:model="showEdit" size="md">
        <x-slot name="header">Signal-Set bearbeiten</x-slot>
        <div class="space-y-4">
            <x-nx-input-text name="form.title" label="Titel" wire:model="form.title" required />
            <x-nx-input-textarea name="form.description" label="Beschreibung" wire:model="form.description" rows="3" />
        </div>
        <x-slot name="footer">
            <div class="flex justify-end gap-3">
                <x-nx-button variant="ghost" wire:click="$set('showEdit', false)">Abbrechen</x-nx-button>
                <x-nx-button variant="primary" wire:click="save">Speichern</x-nx-button>
            </div>
        </x-slot>
    </x-nx-modal>
</x-ui-page>
