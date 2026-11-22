{{-- Z9 „Meine Signale“ (nx-Design-System): Bei mir · Zu mir hochgekommen · Erledigt – drei Knöpfe je Signal. --}}
@php($P = \Platform\Signals\Support\SignalPresenter::class)
<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Meine Signale" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[['label' => 'Meine Signale', 'icon' => 'inbox']]" />
    </x-slot>

    <x-ui-page-container width="contained" spacing="space-y-6">
        @if($error)
            <x-nx-callout variant="danger" icon="heroicon-o-exclamation-triangle" title="Nicht möglich">{{ $error }}</x-nx-callout>
        @elseif($flash)
            <x-nx-callout variant="success" icon="heroicon-o-check-circle" title="Erledigt">{{ $flash }}</x-nx-callout>
        @endif

        <x-nx-stat-grid :cols="3">
            <x-nx-stat label="Bei mir" :value="$with_me->count()" icon="heroicon-o-inbox" />
            <x-nx-stat label="Zu mir hochgekommen" :value="$came_up->count()" icon="heroicon-o-arrow-trending-up" accent="var(--nx-warning)" />
            <x-nx-stat label="Erledigt (30 Tage)" :value="$done->count()" icon="heroicon-o-check-circle" accent="var(--nx-success)" />
        </x-nx-stat-grid>

        @foreach([
            ['key' => 'with_me', 'items' => $with_me, 'title' => 'Bei mir', 'icon' => 'heroicon-o-inbox', 'description' => 'Du bist die nächste Verantwortliche bzw. der nächste Verantwortliche. Gesehen stoppt die Reaktionsuhr; wenn es nicht zu dir gehört, sag es – dann geht es eine Stufe weiter.'],
            ['key' => 'came_up', 'items' => $came_up, 'title' => 'Zu mir hochgekommen', 'icon' => 'heroicon-o-arrow-trending-up', 'description' => 'Darauf hat unten niemand reagiert, es kam wieder oder wurde abgelehnt – mit wer, wie lange, warum.'],
        ] as $group)
            <x-nx-section :icon="$group['icon']" :title="$group['title']" :hint="$group['items']->count()" :description="$group['description']">
                @if($group['items']->isEmpty())
                    <x-nx-card><x-nx-empty :icon="$group['icon']">Nichts in diesem Bereich.</x-nx-empty></x-nx-card>
                @else
                    <x-nx-card>
                        @foreach($group['items'] as $s)
                            <div wire:key="mine-{{ $group['key'] }}-{{ $s->id }}" class="py-3 space-y-2 @if(! $loop->last) border-b border-[color:var(--nx-line)] @endif">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <x-nx-badge :variant="$P::severityVariant($s->severity)">{{ $P::SEVERITY_LABELS[$s->severity] ?? $s->severity }}</x-nx-badge>
                                            @if($s->status === 'acknowledged')<x-nx-badge variant="info" dot>gesehen</x-nx-badge>@endif
                                            @if((int) $s->recurrence_count > 1)<x-nx-badge variant="warning">{{ $s->recurrence_count }}. Mal</x-nx-badge>@endif
                                            @if((int) $s->escalation_level > 0)<x-nx-badge variant="warning">Stufe {{ $s->escalation_level }}</x-nx-badge>@endif
                                            @if($s->delivery_state)<x-nx-badge variant="neutral" title="{{ $s->delivery_thread ? 'Parlan-Vorgang ' . $s->delivery_thread : '' }}">{{ $P::DELIVERY_STATE_LABELS[$s->delivery_state] ?? $s->delivery_state }}</x-nx-badge>@endif
                                            <a href="{{ route('signals.signals.show', $s) }}" wire:navigate class="text-sm font-medium hover:underline">{{ \Illuminate\Support\Str::limit($s->message, 140) }}</a>
                                        </div>
                                        <div class="mt-1 text-xs text-[color:var(--nx-muted)]">
                                            {{ $s->entity?->name ?? '#' . $s->entity_id }}
                                            @if($s->recipient_reason) · {{ $s->recipient_reason }}@endif
                                            @if($w = $P::waiting($s->delivered_at)) · {{ $w }}@endif
                                            @if($s->responsible) · begleitet von {{ $s->responsible->name }}@endif
                                        </div>
                                        @if($group['key'] === 'came_up' && $s->escalation_reason)
                                            <div class="mt-1 text-xs">Warum: {{ $s->escalation_reason }}</div>
                                        @endif
                                    </div>
                                    @if($s->status === 'open')
                                        <x-nx-button variant="secondary" size="sm" wire:click="acknowledge({{ $s->id }})">Gesehen</x-nx-button>
                                    @endif
                                </div>
                                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    <div class="flex items-end gap-2">
                                        <div class="grow"><x-nx-input-text name="notes.{{ $s->id }}" label="Wodurch gelöst?" wire:model="notes.{{ $s->id }}" /></div>
                                        <x-nx-button variant="primary" size="sm" wire:click="resolve({{ $s->id }})">Gelöst</x-nx-button>
                                    </div>
                                    <div class="flex items-end gap-2">
                                        <div class="grow"><x-nx-input-text name="reasons.{{ $s->id }}" label="Warum nicht bei dir?" wire:model="reasons.{{ $s->id }}" /></div>
                                        <x-nx-button variant="ghost" size="sm" wire:click="reject({{ $s->id }})">Gehört nicht zu mir</x-nx-button>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </x-nx-card>
                @endif
            </x-nx-section>
        @endforeach

        <x-nx-section icon="heroicon-o-check-circle" title="Erledigt" :hint="$done->count()" description="Letzte 30 Tage – und wodurch.">
            @if($done->isEmpty())
                <x-nx-card><x-nx-empty icon="heroicon-o-check-circle">In den letzten 30 Tagen nichts erledigt.</x-nx-empty></x-nx-card>
            @else
                <x-nx-card flush>
                    <x-nx-table>
                        <x-nx-table-header>
                            <x-nx-table-header-cell>Signal</x-nx-table-header-cell>
                            <x-nx-table-header-cell>Wodurch</x-nx-table-header-cell>
                            <x-nx-table-header-cell align="right">Wann</x-nx-table-header-cell>
                        </x-nx-table-header>
                        <x-nx-table-body>
                            @foreach($done as $s)
                                <x-nx-table-row wire:key="mine-done-{{ $s->id }}" :href="route('signals.signals.show', $s)">
                                    <x-nx-table-cell>
                                        <x-nx-badge :variant="$P::statusVariant($s->status)" dot>{{ $P::STATUS_LABELS[$s->status] ?? $s->status }}</x-nx-badge>
                                        <span class="text-sm">{{ \Illuminate\Support\Str::limit($s->message, 100) }}</span>
                                    </x-nx-table-cell>
                                    <x-nx-table-cell><span class="text-xs">{{ \Illuminate\Support\Str::limit($s->resolution_note ?: $s->dismissed_reason ?: '–', 120) }}</span></x-nx-table-cell>
                                    <x-nx-table-cell align="right"><span class="text-xs text-[color:var(--nx-muted)]">{{ ($s->resolved_at ?? $s->updated_at)?->format('d.m.Y') }}</span></x-nx-table-cell>
                                </x-nx-table-row>
                            @endforeach
                        </x-nx-table-body>
                    </x-nx-table>
                </x-nx-card>
            @endif
        </x-nx-section>
    </x-ui-page-container>
</x-ui-page>
