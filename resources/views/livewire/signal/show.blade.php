{{-- Detailseite eines Signals (nx-Design-System). --}}
@php($P = \Platform\Signals\Support\SignalPresenter::class)
<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar :title="'Signal #' . $s->id" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Signale', 'href' => route('signals.signals.index'), 'icon' => 'bell-alert'],
            ['label' => '#' . $s->id],
        ]">
            <x-nx-button variant="ghost" size="sm" wire:click="toggleFocus">
                @svg($focused ? 'heroicon-s-star' : 'heroicon-o-star', 'w-4 h-4') {{ $focused ? 'Im Fokus' : 'Fokus' }}
            </x-nx-button>
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container width="contained" spacing="space-y-6">
        @if($error)
            <x-nx-callout variant="danger" icon="heroicon-o-exclamation-triangle" title="Nicht möglich">{{ $error }}</x-nx-callout>
        @endif

        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <x-nx-badge :variant="$P::severityVariant($s->severity)">{{ $P::SEVERITY_LABELS[$s->severity] ?? $s->severity }}</x-nx-badge>
                    @if($s->vsm_level)<x-nx-badge variant="neutral">{{ strtoupper($s->vsm_level) }}</x-nx-badge>@endif
                    @if($s->escalated_at)<x-nx-badge variant="warning">eskaliert {{ $s->escalated_at->diffForHumans() }}@if($s->escalation_level) · Stufe {{ $s->escalation_level }}@endif</x-nx-badge>@endif
                    @if((int) $s->recurrence_count > 1)<x-nx-badge variant="warning">{{ $s->recurrence_count }}. Mal</x-nx-badge>@endif
                    @if($s->snooze_until && $s->snooze_until->isFuture())<x-nx-badge variant="neutral">zurückgestellt bis {{ $s->snooze_until->format('d.m.Y') }}</x-nx-badge>@endif
                </div>
                <h1 class="mt-2 mb-0 text-lg font-semibold text-[color:var(--nx-text)] whitespace-pre-line">{{ $s->message }}</h1>
            </div>
            <x-nx-badge :variant="$P::statusVariant($s->status)" dot>{{ $P::STATUS_LABELS[$s->status] ?? $s->status }}</x-nx-badge>
        </div>

        <x-nx-card>
            <x-nx-property-row icon="heroicon-o-building-office" label="Knoten">
                @if($s->entity)
                    <a href="{{ route('organization.entities.show', $s->entity) }}" wire:navigate class="hover:underline">{{ $s->entity->name }}</a>
                @else #{{ $s->entity_id }} @endif
            </x-nx-property-row>
            <x-nx-property-row icon="heroicon-o-bell-alert" label="Herkunft">
                @if($s->set)
                    <a href="{{ route('signals.sets.show', $s->set) }}" wire:navigate class="hover:underline">{{ $s->set->title }}</a>
                    @if($s->template) · {{ $s->template->name }} <span class="text-xs font-mono text-[color:var(--nx-muted)]">({{ $P::rule($s->template) }})</span>@endif
                @elseif($s->source_type === 'human_algedonic') Algedonic-Knopf
                @elseif($s->source === 'migration') aus der Organisation übernommen
                @else manuell @endif
                @if($s->createdByAgent)<span class="text-xs text-[color:var(--nx-muted)]"> · von {{ $s->createdByAgent->name }}</span>@endif
            </x-nx-property-row>
            <x-nx-property-row icon="heroicon-o-user" label="Bei">
                {{ $recipients }}
                @if($s->recipient_reason)<div class="text-xs text-[color:var(--nx-muted)]">{{ $s->recipient_reason }}@if($w = $P::waiting($s->delivered_at)) · {{ $w }}@endif</div>@endif
                @if($s->escalation_reason)<div class="text-xs">Eskalation: {{ $s->escalation_reason }}</div>@endif
            </x-nx-property-row>
            <x-nx-property-row icon="heroicon-o-cpu-chip" label="Begleitet von">
                {{ $s->responsible?->name ?? '–' }}
                @if($s->assignee) · zugewiesen an {{ $s->assignee->name }}@endif
                @if($s->currentOwner) · Owner {{ $s->currentOwner->name }}@endif
            </x-nx-property-row>
            @if($s->trigger_metrics)
                <x-nx-property-row icon="heroicon-o-chart-bar" label="Messwerte">
                    <span class="text-xs font-mono">{{ collect($s->trigger_metrics)->reject(fn ($v) => is_array($v))->map(fn ($v, $k) => $k . ' = ' . (is_bool($v) ? ($v ? 'ja' : 'nein') : $v))->implode(' · ') }}</span>
                </x-nx-property-row>
            @endif
            <x-nx-property-row icon="heroicon-o-clock" label="Zeit">
                ausgelöst {{ $s->created_at?->format('d.m.Y H:i') }}
                @if($s->deadline_at) · Frist {{ $s->deadline_at->format('d.m.Y H:i') }}@endif
                @if($s->acknowledged_at) · gesehen {{ $s->acknowledged_at->format('d.m.Y H:i') }}@endif
            </x-nx-property-row>
            @if($s->isClosed())
                <x-nx-property-row icon="heroicon-o-check-circle" label="Auflösung">
                    <div>{{ $s->status === 'resolved' ? 'Erledigt' : 'Verworfen' }} {{ $s->resolved_at?->format('d.m.Y') }} @if($s->resolvedBy) von {{ $s->resolvedBy->name }}@endif</div>
                    @if($s->resolution_note)<div class="whitespace-pre-line">{{ $s->resolution_note }}</div>@endif
                    @if($s->dismissed_reason)<div class="text-[color:var(--nx-muted)]">Grund: {{ $s->dismissed_reason }}</div>@endif
                    @if($link)<div class="text-xs">Verweis: {{ $link['label'] ?? $link['type'] }} (#{{ $link['id'] }})</div>@endif
                </x-nx-property-row>
            @endif
        </x-nx-card>

        @if($s->actions->isNotEmpty())
            <x-nx-section icon="heroicon-o-light-bulb" title="Maßnahmen" description="Entscheidungen zu den Maßnahmen bestimmen den Status: alle umgesetzt = erledigt, alle verworfen = verworfen.">
                <x-nx-card>
                    @foreach($s->actions as $a)
                        <div wire:key="act-{{ $a->id }}" class="flex items-start justify-between gap-3 py-2 @if(! $loop->last) border-b border-[color:var(--nx-line)] @endif">
                            <div class="min-w-0">
                                <div class="text-sm font-medium {{ $a->status === 'dismissed' ? 'line-through text-[color:var(--nx-muted)]' : '' }}">{{ $a->title }}</div>
                                @if($a->description)<div class="text-xs text-[color:var(--nx-muted)]">{{ $a->description }}</div>@endif
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                @if($a->status === 'pending')
                                    <x-nx-button variant="secondary" size="sm" wire:click="decide({{ $a->id }}, 'applied')">Umgesetzt</x-nx-button>
                                    <x-nx-button variant="ghost" size="sm" wire:click="decide({{ $a->id }}, 'dismissed')">Verwerfen</x-nx-button>
                                @else
                                    <x-nx-badge :variant="$a->status === 'applied' ? 'success' : 'neutral'">{{ $a->status === 'applied' ? 'umgesetzt' : 'verworfen' }}</x-nx-badge>
                                    <button type="button" wire:click="decide({{ $a->id }}, 'pending')" class="text-xs underline text-[color:var(--nx-muted)]">zurück</button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </x-nx-card>
            </x-nx-section>
        @endif

        <x-nx-section icon="heroicon-o-check-circle" title="Bearbeiten">
            <x-nx-card>
                @if($s->isClosed())
                    <x-nx-button variant="secondary" size="sm" wire:click="reopen">Wieder öffnen</x-nx-button>
                @else
                    <div class="space-y-4">
                        <div class="flex flex-wrap items-center gap-2">
                            @if($s->status === 'open')
                                <x-nx-button variant="secondary" size="sm" wire:click="acknowledge">Gesehen</x-nx-button>
                            @endif
                            <div class="flex items-center gap-1">
                                <x-nx-input-select name="snoozeDays" size="sm" wire:model="snoozeDays" :options="[['value' => '1', 'label' => '1 Tag'], ['value' => '7', 'label' => '1 Woche'], ['value' => '14', 'label' => '2 Wochen'], ['value' => '30', 'label' => '1 Monat']]" />
                                <x-nx-button variant="ghost" size="sm" wire:click="snooze">Zurückstellen</x-nx-button>
                                @if($s->snooze_until && $s->snooze_until->isFuture())
                                    <x-nx-button variant="ghost" size="sm" wire:click="unsnooze">Aufheben</x-nx-button>
                                @endif
                            </div>
                        </div>
                        <x-nx-input-textarea name="note" label="Wodurch erledigt? (Notiz)" wire:model="note" rows="2" />
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                            <x-nx-input-select name="linkType" label="Verweis (optional)" wire:model="linkType" nullable="true" nullLabel="kein Verweis" :options="$linkTypes" />
                            <x-nx-input-text name="linkId" label="ID" wire:model="linkId" />
                            <div class="flex items-end"><x-nx-button variant="primary" size="sm" wire:click="resolve">Erledigt</x-nx-button></div>
                        </div>
                        <div class="flex items-end gap-2">
                            <div class="grow"><x-nx-input-text name="rejectReason" label="Gehört nicht zu mir – warum?" wire:model="rejectReason" /></div>
                            <x-nx-button variant="ghost" size="sm" wire:click="reject">Gehört nicht zu mir</x-nx-button>
                        </div>
                        <div class="flex items-end gap-2">
                            <div class="grow"><x-nx-input-text name="reason" label="Verwerfen – warum?" wire:model="reason" /></div>
                            <x-nx-button variant="ghost" size="sm" wire:click="dismiss">Verwerfen</x-nx-button>
                        </div>
                    </div>
                @endif
            </x-nx-card>
        </x-nx-section>

        <x-nx-section icon="heroicon-o-chat-bubble-left-right" title="Kommentare" :hint="$comments->count()">
            <x-nx-card>
                @foreach($comments as $c)
                    <div wire:key="c-{{ $c->id }}" class="py-2 @if(! $loop->last) border-b border-[color:var(--nx-line)] @endif">
                        <div class="text-xs text-[color:var(--nx-muted)]">{{ $c->user?->name ?? ($c->author_context === 'system' ? 'System' : 'Agent') }} · {{ $c->created_at?->format('d.m.Y H:i') }}</div>
                        <div class="text-sm whitespace-pre-line">{{ $c->content }}</div>
                    </div>
                @endforeach
                <div class="mt-3 flex items-end gap-2">
                    <div class="grow"><x-nx-input-textarea name="comment" label="Kommentar" wire:model="comment" rows="2" /></div>
                    <x-nx-button variant="secondary" size="sm" wire:click="addComment">Senden</x-nx-button>
                </div>
            </x-nx-card>
        </x-nx-section>
    </x-ui-page-container>
</x-ui-page>
