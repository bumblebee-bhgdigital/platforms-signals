{{-- Arbeitsliste aller ausgelösten Signale (nx-Design-System). --}}
@php($P = \Platform\Signals\Support\SignalPresenter::class)
<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Signale" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[['label' => 'Signale', 'icon' => 'bell-alert']]" />
    </x-slot>

    <x-ui-page-container width="contained" spacing="space-y-6">
        <x-nx-stat-grid :cols="4">
            <x-nx-stat label="Zu bearbeiten" :value="$stats['actionable']" icon="heroicon-o-bell-alert" />
            <x-nx-stat label="Kritisch" :value="$stats['critical']" icon="heroicon-o-exclamation-triangle" accent="var(--nx-danger)" />
            <x-nx-stat label="Eskaliert" :value="$stats['escalated']" icon="heroicon-o-arrow-trending-up" accent="var(--nx-warning)" />
            <x-nx-stat label="Zurückgestellt" :value="$stats['snoozed']" icon="heroicon-o-clock" />
        </x-nx-stat-grid>

        <x-nx-section icon="heroicon-o-list-bullet" title="Signale" :hint="$signals->count()"
                      description="Schwerste zuerst. Ein Signal erledigt man mit einer Notiz und – wenn es eins gibt – dem Verweis auf den Beschluss oder die Aufgabe, die es gelöst hat.">
            <x-slot name="action">
                <div class="flex flex-wrap items-center gap-2">
                    <x-nx-input-select name="status" size="sm" wire:model.live="status" :options="[
                        ['value' => 'actionable', 'label' => 'Zu bearbeiten'],
                        ['value' => 'open', 'label' => 'Offen'],
                        ['value' => 'acknowledged', 'label' => 'Gesehen'],
                        ['value' => 'snoozed', 'label' => 'Zurückgestellt'],
                        ['value' => 'closed', 'label' => 'Erledigt / verworfen'],
                    ]" />
                    <x-nx-input-select name="severity" size="sm" wire:model.live="severity" nullable="true" nullLabel="Alle Schweregrade"
                                       :options="collect($P::SEVERITY_LABELS)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values()->all()" />
                    <x-nx-input-select name="setId" size="sm" wire:model.live="setId" nullable="true" nullLabel="Alle Sets" :options="$setOptions" />
                    <label class="flex items-center gap-1 text-xs text-[color:var(--nx-muted)]">
                        <input type="checkbox" wire:model.live="mine" class="rounded border-[color:var(--nx-line)]" /> Mein Fokus
                    </label>
                </div>
            </x-slot>

            @if($signals->isEmpty())
                <x-nx-card><x-nx-empty icon="heroicon-o-bell-alert">Keine Signale in dieser Ansicht.</x-nx-empty></x-nx-card>
            @else
                <x-nx-card flush>
                    <x-nx-table>
                        <x-nx-table-header>
                            <x-nx-table-header-cell>Signal</x-nx-table-header-cell>
                            <x-nx-table-header-cell>Knoten</x-nx-table-header-cell>
                            <x-nx-table-header-cell>Herkunft</x-nx-table-header-cell>
                            <x-nx-table-header-cell>Status</x-nx-table-header-cell>
                            <x-nx-table-header-cell align="right">Seit</x-nx-table-header-cell>
                        </x-nx-table-header>
                        <x-nx-table-body>
                            @foreach($signals as $signal)
                                <x-nx-table-row wire:key="s-{{ $signal->id }}" :href="route('signals.signals.show', $signal)">
                                    <x-nx-table-cell>
                                        <x-nx-badge :variant="$P::severityVariant($signal->severity)">{{ $P::SEVERITY_LABELS[$signal->severity] ?? $signal->severity }}</x-nx-badge>
                                        @if($signal->escalated_at)<x-nx-badge variant="warning">eskaliert {{ strtoupper((string) $signal->vsm_level) }}</x-nx-badge>@endif
                                        <span class="text-sm">{{ \Illuminate\Support\Str::limit($signal->message, 120) }}</span>
                                    </x-nx-table-cell>
                                    <x-nx-table-cell>{{ $signal->entity?->name ?? '#' . $signal->entity_id }}</x-nx-table-cell>
                                    <x-nx-table-cell>
                                        <span class="text-xs">{{ $signal->set?->title ?? ($signal->source_type === 'human_algedonic' ? 'Algedonic-Knopf' : ($signal->source === 'migration' ? 'aus der Organisation' : 'manuell')) }}</span>
                                        @if($signal->responsible)<div class="text-xs text-[color:var(--nx-muted)]">{{ $signal->responsible->name }}</div>@endif
                                    </x-nx-table-cell>
                                    <x-nx-table-cell><x-nx-badge :variant="$P::statusVariant($signal->status)" dot>{{ $P::STATUS_LABELS[$signal->status] ?? $signal->status }}</x-nx-badge></x-nx-table-cell>
                                    <x-nx-table-cell align="right"><span class="text-xs text-[color:var(--nx-muted)]">{{ $signal->created_at?->diffForHumans() }}</span></x-nx-table-cell>
                                </x-nx-table-row>
                            @endforeach
                        </x-nx-table-body>
                    </x-nx-table>
                </x-nx-card>
            @endif
        </x-nx-section>
    </x-ui-page-container>
</x-ui-page>
