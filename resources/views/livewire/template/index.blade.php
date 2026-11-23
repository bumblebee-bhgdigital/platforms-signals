{{-- Signal-Templates (nx-Design-System). --}}
@php($P = \Platform\Signals\Support\SignalPresenter::class)
<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Templates" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[['label' => 'Templates', 'icon' => 'adjustments-horizontal']]">
            <x-nx-dropdown label="Neu">
                <x-nx-dropdown-item wire:click="openCreate('rule')">@svg('heroicon-o-adjustments-horizontal', 'w-4 h-4') Regel (Kennzahl)</x-nx-dropdown-item>
                <x-nx-dropdown-item wire:click="openCreate('inference')">@svg('heroicon-o-cpu-chip', 'w-4 h-4') KI-Auswertung (Agent)</x-nx-dropdown-item>
            </x-nx-dropdown>
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container width="contained" spacing="space-y-6">
        <x-nx-section icon="heroicon-o-adjustments-horizontal" title="Templates" :hint="$templates->count()"
                      description="Wiederverwendbare Signaldefinitionen. Sie wirken erst, wenn sie in einem aktiven Signal-Set an einem Knoten liegen – Schwellen lassen sich dort je Set anpassen.">
            @if($templates->isEmpty())
                <x-nx-card><x-nx-empty icon="heroicon-o-adjustments-horizontal">Noch keine Templates.</x-nx-empty></x-nx-card>
            @else
                <x-nx-card flush>
                    <x-nx-table>
                        <x-nx-table-header>
                            <x-nx-table-header-cell>Name</x-nx-table-header-cell>
                            <x-nx-table-header-cell>Regel / Auswertung</x-nx-table-header-cell>
                            <x-nx-table-header-cell>Schwere</x-nx-table-header-cell>
                            <x-nx-table-header-cell>In Sets</x-nx-table-header-cell>
                            <x-nx-table-header-cell align="right">Offen</x-nx-table-header-cell>
                        </x-nx-table-header>
                        <x-nx-table-body>
                            @foreach($templates as $t)
                                <x-nx-table-row wire:key="t-{{ $t->id }}">
                                    <x-nx-table-cell>
                                        <span class="{{ $t->is_active ? '' : 'line-through text-[color:var(--nx-muted)]' }}">{{ $t->name }}</span>
                                        <x-nx-badge variant="neutral">{{ $t->isRule() ? ($P::PATTERN_LABELS[$t->pattern_type] ?? 'Regel') : 'KI' }}</x-nx-badge>
                                        <div class="mt-1 flex gap-3 text-xs">
                                            <button type="button" wire:click="openEdit({{ $t->id }})" class="underline text-[color:var(--nx-muted)] hover:text-[color:var(--nx-text)]">Bearbeiten</button>
                                            <button type="button" wire:click="toggle({{ $t->id }})" class="underline text-[color:var(--nx-muted)] hover:text-[color:var(--nx-text)]">{{ $t->is_active ? 'Deaktivieren' : 'Aktivieren' }}</button>
                                        </div>
                                    </x-nx-table-cell>
                                    <x-nx-table-cell><span class="text-xs font-mono">{{ $P::rule($t) }}</span></x-nx-table-cell>
                                    <x-nx-table-cell><x-nx-badge :variant="$P::severityVariant($t->severity)">{{ $P::SEVERITY_LABELS[$t->severity] ?? $t->severity }}</x-nx-badge></x-nx-table-cell>
                                    <x-nx-table-cell>{{ $t->assignments_count }}</x-nx-table-cell>
                                    <x-nx-table-cell align="right">{{ $t->open_signals_count }}</x-nx-table-cell>
                                </x-nx-table-row>
                            @endforeach
                        </x-nx-table-body>
                    </x-nx-table>
                </x-nx-card>
            @endif
        </x-nx-section>
    </x-ui-page-container>

    <x-nx-modal wire:model="showForm" size="lg">
        <x-slot name="header">{{ $editId ? 'Template bearbeiten' : (($form['kind'] ?? 'rule') === 'rule' ? 'Regel anlegen' : 'KI-Auswertung anlegen') }}</x-slot>
        <div class="space-y-4">
            @if($error)<x-nx-callout variant="danger" icon="heroicon-o-exclamation-triangle" title="Nicht gespeichert">{{ $error }}</x-nx-callout>@endif
            <x-nx-input-text name="form.name" label="Name" wire:model="form.name" required />
            <x-nx-input-textarea name="form.description" label="Beschreibung" wire:model="form.description" rows="2" />
            <x-nx-input-select name="form.severity" label="Schwere" wire:model="form.severity" :options="collect($P::SEVERITY_LABELS)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values()->all()" />
            @if(($form['kind'] ?? 'rule') === 'rule')
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <x-nx-input-select name="form.pattern_type" label="Muster" wire:model="form.pattern_type" :options="collect($P::PATTERN_LABELS)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values()->all()" />
                    <x-nx-input-select name="form.frequency" label="Takt" wire:model="form.frequency" :options="collect($P::FREQUENCY_LABELS)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values()->all()" />
                </div>
                <x-nx-input-textarea name="conditionsJson" label="Bedingungen (JSON)" wire:model="conditionsJson" rows="6" />
                <p class="text-xs text-[color:var(--nx-muted)]">Schwelle: {"metric","operator","value"} oder {"all":[{"metric","operator","value"},…]} · Trend: {"metric","direction":"increasing|decreasing","periods","min_change_percent","min_value"} · Quote: {"numerator","denominator","operator","value","min_denominator","grace_days"} · Vergleich: {"metric_a","metric_b","relationship":"diverging|converging"} · Je Einheit statt je Knoten (Summe über den Teilbaum, größte Beiträger in der Meldung): "units_only": true</p>
            @else
                <x-nx-input-textarea name="form.prompt" label="Prompt" wire:model="form.prompt" rows="6" />
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <x-nx-input-select name="form.vsm_system" label="VSM-System" wire:model="form.vsm_system" :options="collect(['s1', 's2', 's3', 's3_star', 's4', 's5'])->map(fn ($v) => ['value' => $v, 'label' => strtoupper($v)])->all()" />
                    <x-nx-input-text name="form.dimension" label="Dimension" wire:model="form.dimension" />
                    <x-nx-input-number name="form.schedule_interval_hours" label="Takt (Stunden)" wire:model="form.schedule_interval_hours" />
                </div>
                <p class="text-xs text-[color:var(--nx-muted)]">Ausgewertet wird vom verantwortlichen Agenten des Sets per MCP – nicht vom Server.</p>
            @endif
        </div>
        <x-slot name="footer">
            <div class="flex justify-end gap-3">
                <x-nx-button variant="ghost" wire:click="$set('showForm', false)">Abbrechen</x-nx-button>
                <x-nx-button variant="primary" wire:click="save">Speichern</x-nx-button>
            </div>
        </x-slot>
    </x-nx-modal>
</x-ui-page>
