{{--
    Ein Signal-Set in der Sidebar: Link zur Detailseite + Hover-Aktionen.
    $nodeId = Knoten, unter dem die Zeile steht (null = Liste "Unverknüpft").
    Aktionen: weiteren Knoten hinzufügen, Vererbung nach unten umschalten, von diesem Knoten umhängen / lösen.
--}}
@props(['set', 'nodeId' => null, 'inherit' => false, 'linkingSetId' => null, 'linkingAnchor' => null, 'linkInherit' => false, 'linkCandidates' => []])

@php
    $dot = match ($set->status->value) {
        'active' => 'bg-[var(--nx-success)]',
        default => 'bg-[var(--nx-muted)] opacity-40',
    };
    $isLinking = $linkingSetId === $set->id && $linkingAnchor === ($nodeId ?? 0);
@endphp

<div wire:key="sg-row-{{ $set->id }}-{{ $nodeId ?? 'none' }}" class="group/row flex flex-col">
    <div class="flex items-center gap-1.5 py-0.5 pl-3 pr-1 text-[var(--nx-text)] hover:text-[var(--nx-accent)] transition">
        <span class="w-1.5 h-1.5 rounded-full flex-shrink-0 {{ $dot }}" title="{{ $set->status->label() }}"></span>
        <a href="{{ route('signals.sets.show', ['signalSet' => $set]) }}" wire:navigate
           title="{{ $set->title }} ({{ $set->status->label() }})"
           class="truncate text-[11px] flex-1">{{ $set->title }}</a>

        @if($nodeId && $inherit)
            <span title="Wirkt auch an allen Unterknoten" class="text-[var(--nx-muted)]">@svg('heroicon-o-arrow-down-circle', 'w-3 h-3')</span>
        @endif

        @can('update', $set)
            <span class="flex items-center gap-0.5 opacity-0 group-hover/row:opacity-100 focus-within:opacity-100 transition-opacity">
                <button type="button" wire:click="startLinking({{ $set->id }}, null, {{ $nodeId ?? 0 }})"
                        title="{{ $nodeId ? 'An weiteren Knoten hängen' : 'Mit Knoten verknüpfen' }}"
                        class="p-0.5 rounded text-[var(--nx-muted)] hover:text-[var(--nx-text)]">
                    @svg($nodeId ? 'heroicon-o-plus' : 'heroicon-o-link', 'w-3 h-3')
                </button>
                @if($nodeId)
                    <button type="button" wire:click="toggleInherit({{ $set->id }}, {{ $nodeId }})"
                            title="{{ $inherit ? 'Vererbung nach unten ausschalten' : 'Nach unten vererben' }}"
                            class="p-0.5 rounded {{ $inherit ? 'text-[var(--nx-text)]' : 'text-[var(--nx-muted)]' }} hover:text-[var(--nx-text)]">
                        @svg('heroicon-o-arrow-down-circle', 'w-3 h-3')
                    </button>
                    <button type="button" wire:click="startLinking({{ $set->id }}, {{ $nodeId }})" title="Von diesem Knoten umhängen"
                            class="p-0.5 rounded text-[var(--nx-muted)] hover:text-[var(--nx-text)]">
                        @svg('heroicon-o-arrows-right-left', 'w-3 h-3')
                    </button>
                    <button type="button" wire:click="unlinkNode({{ $set->id }}, {{ $nodeId }})" wire:confirm="Zuordnung zu diesem Knoten lösen?" title="Zuordnung lösen"
                            class="p-0.5 rounded text-[var(--nx-muted)] hover:text-[var(--nx-danger)]">
                        @svg('heroicon-o-link-slash', 'w-3 h-3')
                    </button>
                @endif
            </span>
        @endcan
    </div>

    @if($isLinking)
        <div class="mx-2 mb-1 mt-0.5 rounded-md border border-[var(--nx-line)] bg-[var(--nx-surface)] p-1.5">
            <div class="flex items-center gap-1 mb-1">
                <input type="text" wire:model.live.debounce.300ms="linkSearch" placeholder="Knoten suchen …" autofocus
                       class="w-full rounded border border-[var(--nx-line)] bg-transparent px-1.5 py-0.5 text-[11px] text-[var(--nx-text)]" />
                <button type="button" wire:click="cancelLinking" title="Abbrechen" class="p-0.5 text-[var(--nx-muted)] hover:text-[var(--nx-text)]">
                    @svg('heroicon-o-x-mark', 'w-3 h-3')
                </button>
            </div>
            @unless($linkingFromEntityId ?? null)
                <label class="flex items-center gap-1 px-1 pb-1 text-[10px] text-[var(--nx-muted)]">
                    <input type="checkbox" wire:model.live="linkInherit" class="rounded border-[var(--nx-line)]" />
                    Nach unten vererben
                </label>
            @endunless
            @forelse($linkCandidates as $candidate)
                <button type="button" wire:key="sg-cand-{{ $set->id }}-{{ $nodeId ?? 'none' }}-{{ $candidate['id'] }}" wire:click="linkTo({{ $candidate['id'] }})"
                        class="flex w-full items-center gap-1 rounded px-1 py-0.5 text-left text-[11px] text-[var(--nx-text)] hover:bg-[var(--nx-bg)]">
                    <span class="truncate">{{ $candidate['name'] }}</span>
                    @if($candidate['type'])<span class="ml-auto text-[9px] uppercase tracking-wider text-[var(--nx-muted)]">{{ $candidate['type'] }}</span>@endif
                </button>
            @empty
                <div class="px-1 py-0.5 text-[11px] text-[var(--nx-muted)]">Keine passenden Knoten.</div>
            @endforelse
        </div>
    @endif
</div>
