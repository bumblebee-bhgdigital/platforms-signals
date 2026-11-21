{{-- Regelkreis für Stewards: lokal vor übergreifend, vier Fragen (nx-Design-System). --}}
@php($P = \Platform\Signals\Support\SignalPresenter::class)
<x-ui-page>
    <x-slot name="navbar"><x-ui-page-navbar title="Regelkreis" /></x-slot>
    <x-slot name="actionbar"><x-ui-page-actionbar :breadcrumbs="[['label' => 'Regelkreis', 'icon' => 'arrow-path']]" /></x-slot>

    <x-ui-page-container width="contained" spacing="space-y-6">
        @if($message)<x-nx-callout variant="success" icon="heroicon-o-check-circle" title="Erledigt">{{ $message }}</x-nx-callout>@endif
        @if($error)<x-nx-callout variant="danger" icon="heroicon-o-exclamation-triangle" title="Nicht möglich">{{ $error }}</x-nx-callout>@endif

        @if(empty($mine))
            <x-nx-card><x-nx-empty icon="heroicon-o-arrow-path">Du betreust keinen Regelkreis. Zuordnung in der Organisation: Beziehung „betreut (Regelkreis)“ von dir zum Gremium.</x-nx-empty></x-nx-card>
        @else
            <div class="flex flex-wrap gap-2">
                @foreach($mine as $c)
                    <a href="?committee={{ $c['entity_id'] }}" wire:navigate class="rounded-md border px-3 py-1.5 text-sm {{ $current === $c['entity_id'] ? 'border-[color:var(--nx-text)] font-semibold' : 'border-[color:var(--nx-line)] text-[color:var(--nx-muted)]' }}">
                        {{ $c['name'] }} <span class="text-xs">· {{ $c['level'] === 'group' ? 'Gruppe' : 'operativ' }}</span>
                    </a>
                @endforeach
            </div>

            @if($situation)
                <x-nx-card>
                    <x-nx-property-row icon="heroicon-o-calendar" label="Nächste Sitzung">
                        @if($situation['next_meeting'])
                            <a href="{{ $situation['next_meeting']['url'] }}" class="hover:underline">{{ $situation['next_meeting']['title'] }}</a>
                            · {{ \Illuminate\Support\Carbon::parse($situation['next_meeting']['scheduled_at'])->format('d.m.Y H:i') }}
                        @else <span class="text-[color:var(--nx-muted)]">keine geplant</span> @endif
                    </x-nx-property-row>
                    <x-nx-property-row icon="heroicon-o-user-group" label="Stewards">{{ collect($situation['stewards'])->pluck('name')->implode(', ') ?: '–' }}</x-nx-property-row>
                </x-nx-card>

                @foreach([
                    ['escalated_here', 'Von unten eskaliert', 'heroicon-o-arrow-up-circle', 'Ein LA darunter hat das eskaliert – immer ein Kandidat für diese Ebene.'],
                    ['candidates', 'Kandidaten aus den Einheiten', 'heroicon-o-inbox', 'Zuerst lokal lösen. In den LA nur, wenn andere Einheiten, gemeinsame Ressourcen oder strategische Prioritäten betroffen sind.'],
                    ['in_committee', 'Liegt im LA', 'heroicon-o-clipboard-document-list', 'Als Entwurf oder übernommen auf der Agenda.'],
                ] as [$key, $title, $icon, $desc])
                    <x-nx-section :icon="$icon" :title="$title" :hint="count($situation[$key])" :description="$desc">
                        @if(count($situation[$key]) === 0)
                            <x-nx-card><x-nx-empty :icon="$icon">Nichts.</x-nx-empty></x-nx-card>
                        @else
                            <x-nx-card>
                                @foreach($situation[$key] as $s)
                                    @php($u = $situation['units'][$s->unit_entity_id] ?? null)
                                    <div wire:key="{{ $key }}-{{ $s->id }}" class="py-3 @if(! $loop->last) border-b border-[color:var(--nx-line)] @endif">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <x-nx-badge :variant="$P::severityVariant($s->severity)">{{ $P::SEVERITY_LABELS[$s->severity] ?? $s->severity }}</x-nx-badge>
                                            <a href="{{ route('signals.signals.show', $s) }}" wire:navigate class="text-sm hover:underline">{{ \Illuminate\Support\Str::limit($s->message, 140) }}</a>
                                        </div>
                                        <div class="mt-1 text-xs text-[color:var(--nx-muted)]">
                                            {{ $u['name'] ?? 'Einheit #' . $s->unit_entity_id }}@if($u['leader'] ?? null) · verantwortlich {{ $u['leader']['name'] }}@endif
                                            @if($u['proxy_for'] ?? null) · stellvertretend für {{ $u['proxy_for']['name'] }}@endif
                                            @if(! empty($u['frames'])) · Rahmen: {{ \Illuminate\Support\Str::limit($u['frames'][0]['text'], 80) }}@endif
                                        </div>
                                        @if($key !== 'in_committee')
                                            <div class="mt-2 flex gap-2">
                                                <x-nx-button variant="ghost" size="sm" wire:click="openSolve({{ $s->id }})">Lokal gelöst</x-nx-button>
                                                <x-nx-button variant="secondary" size="sm" wire:click="openLift({{ $s->id }})">In den LA (vier Fragen)</x-nx-button>
                                            </div>
                                            @if($solveId === $s->id)
                                                <div class="mt-2 flex items-end gap-2">
                                                    <div class="grow"><x-nx-input-text name="note" label="Wodurch gelöst?" wire:model="note" /></div>
                                                    <x-nx-button variant="primary" size="sm" wire:click="solve">Speichern</x-nx-button>
                                                </div>
                                            @endif
                                        @endif
                                    </div>
                                @endforeach
                            </x-nx-card>
                        @endif
                    </x-nx-section>
                @endforeach
            @endif
        @endif
    </x-ui-page-container>

    <x-nx-modal wire:model="liftId" size="lg">
        <x-slot name="header">In den Lenkungsausschuss – die vier Fragen</x-slot>
        <div class="space-y-3">
            @if($error)<x-nx-callout variant="danger" icon="heroicon-o-exclamation-triangle" title="Nicht möglich">{{ $error }}</x-nx-callout>@endif
            <x-nx-input-text name="q.title" label="Titel" wire:model="q.title" />
            <x-nx-input-textarea name="q.goal" label="1 · Was wollten wir erreichen?" wire:model="q.goal" rows="2" />
            <x-nx-input-textarea name="q.observation" label="2 · Was beobachten wir tatsächlich?" wire:model="q.observation" rows="2" />
            <x-nx-input-textarea name="q.obstacle" label="3 · Was verhindert die Zielerreichung?" wire:model="q.obstacle" rows="2" />
            <x-nx-input-textarea name="q.local_solution" label="4a · Was kann der Bereich selbst lösen?" wire:model="q.local_solution" rows="2" />
            <label class="flex items-center gap-2 text-xs text-[color:var(--nx-muted)]"><input type="checkbox" wire:model="q.local_solution_confirmed" class="rounded border-[color:var(--nx-line)]" /> 4a ist mit dem Bereich abgestimmt</label>
            <x-nx-input-textarea name="q.system_support" label="4b · Wobei braucht er das Gesamtsystem?" wire:model="q.system_support" rows="2" />
            <p class="text-xs text-[color:var(--nx-muted)]">Ohne 4b kein LA-Punkt – dann bleibt es lokal. Der Punkt kommt als Entwurf in die nächste Sitzung; der Vorsitz übernimmt ihn oder nimmt ihn heraus.</p>
        </div>
        <x-slot name="footer">
            <div class="flex justify-end gap-3">
                <x-nx-button variant="ghost" wire:click="$set('liftId', null)">Abbrechen</x-nx-button>
                <x-nx-button variant="primary" wire:click="lift">Als Entwurf einstellen</x-nx-button>
            </div>
        </x-slot>
    </x-nx-modal>
</x-ui-page>
