{{-- Umwelt (VSM S4): Quellen und ihr letzter Stand (nx-Design-System). --}}
<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Umwelt" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[['label' => 'Umwelt', 'icon' => 'globe-europe-africa']]" />
    </x-slot>

    <x-ui-page-container width="contained" spacing="space-y-6">
        @if($message)
            <x-nx-callout variant="info" icon="heroicon-o-arrow-path" title="Abruf">{{ $message }}</x-nx-callout>
        @endif

        <x-nx-section icon="heroicon-o-globe-europe-africa" title="Quellen" :hint="$sources->count()"
                      description="Das Außen der Organisation (Markt, Branche, Makro, Kennzahlen). Abgerufen wird je Quelle im eingestellten Intervall; gelernte Relevanz filtert Irrelevantes aus dem Kontext der Agenten. Anlegen und bewerten per MCP (signals.environment_sources.*).">
            @if($sources->isEmpty())
                <x-nx-card><x-nx-empty icon="heroicon-o-globe-europe-africa">Noch keine Umweltquellen.</x-nx-empty></x-nx-card>
            @else
                <x-nx-card flush>
                    <x-nx-table>
                        <x-nx-table-header>
                            <x-nx-table-header-cell>Quelle</x-nx-table-header-cell>
                            <x-nx-table-header-cell>Letzter Stand</x-nx-table-header-cell>
                            <x-nx-table-header-cell>Relevanz</x-nx-table-header-cell>
                            <x-nx-table-header-cell align="right">Abgerufen</x-nx-table-header-cell>
                        </x-nx-table-header>
                        <x-nx-table-body>
                            @foreach($sources as $s)
                                @php($snap = $latest->get($s->id))
                                <x-nx-table-row wire:key="src-{{ $s->id }}">
                                    <x-nx-table-cell>
                                        <span class="{{ $s->is_active ? '' : 'line-through text-[color:var(--nx-muted)]' }}">{{ $s->name }}</span>
                                        <div class="text-xs text-[color:var(--nx-muted)]">{{ $s->source_type }} · {{ $s->category }}@if($s->cluster) · {{ $s->cluster }}@endif · alle {{ $s->pull_interval_hours }} h</div>
                                        <div class="mt-1 flex gap-3 text-xs">
                                            <button type="button" wire:click="open({{ $s->id }})" class="underline text-[color:var(--nx-muted)] hover:text-[color:var(--nx-text)]">{{ $openSourceId === $s->id ? 'Verlauf zu' : 'Verlauf' }}</button>
                                            <button type="button" wire:click="pullNow({{ $s->id }})" class="underline text-[color:var(--nx-muted)] hover:text-[color:var(--nx-text)]">Jetzt abrufen</button>
                                            <button type="button" wire:click="toggle({{ $s->id }})" class="underline text-[color:var(--nx-muted)] hover:text-[color:var(--nx-text)]">{{ $s->is_active ? 'Pausieren' : 'Fortsetzen' }}</button>
                                        </div>
                                    </x-nx-table-cell>
                                    <x-nx-table-cell><span class="text-xs">{{ $snap ? \Illuminate\Support\Str::limit($snap->summary, 160) : '–' }}</span></x-nx-table-cell>
                                    <x-nx-table-cell><span class="text-xs">{{ isset($snap?->metrics['relevance_score']) ? number_format((float) $snap->metrics['relevance_score'], 1, ',', '') : '–' }}</span></x-nx-table-cell>
                                    <x-nx-table-cell align="right"><span class="text-xs text-[color:var(--nx-muted)]">{{ $s->last_pulled_at?->diffForHumans() ?? 'nie' }}</span></x-nx-table-cell>
                                </x-nx-table-row>
                                @if($openSourceId === $s->id)
                                    <x-nx-table-row wire:key="hist-{{ $s->id }}">
                                        <x-nx-table-cell colspan="4">
                                            @forelse($history as $h)
                                                <div class="py-1 text-xs"><span class="text-[color:var(--nx-muted)]">{{ $h->snapshot_date?->format('d.m.Y') }}</span> · {{ $h->summary }}@if(! empty($h->metrics['topics'])) <span class="text-[color:var(--nx-muted)]">({{ implode(', ', array_slice((array) $h->metrics['topics'], 0, 5)) }})</span>@endif</div>
                                            @empty
                                                <div class="text-xs text-[color:var(--nx-muted)]">Noch kein Verlauf.</div>
                                            @endforelse
                                        </x-nx-table-cell>
                                    </x-nx-table-row>
                                @endif
                            @endforeach
                        </x-nx-table-body>
                    </x-nx-table>
                </x-nx-card>
            @endif
        </x-nx-section>
    </x-ui-page-container>
</x-ui-page>
