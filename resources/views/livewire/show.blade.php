{{--
    Detailseite Signal-Set (nx-Design-System): Eigenschaften, Templates (mit Probelauf), ausgelöste Signale.
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

        @php($P = \Platform\Signals\Support\SignalPresenter::class)

        {{-- Templates im Set --}}
        <x-nx-section icon="heroicon-o-adjustments-horizontal" title="Templates" :hint="$assignments->count()"
                      description="Worauf das Set achtet. Regeln wertet die Plattform nach jedem Snapshot an den Knoten des Sets aus, KI-Templates der verantwortliche Agent.">
            <x-slot name="action">
                <x-nx-button variant="secondary" size="sm" wire:click="runDryRun">@svg('heroicon-o-beaker', 'w-4 h-4') Probelauf</x-nx-button>
            </x-slot>

            @if($dryRun)
                <x-nx-callout :variant="isset($dryRun['error']) ? 'warning' : 'info'" icon="heroicon-o-beaker" title="Probelauf (nichts gespeichert)">
                    @isset($dryRun['error'])
                        {{ $dryRun['error'] }}
                    @else
                        {{ $dryRun['nodes'] }} Knoten im Geltungsbereich · {{ $dryRun['created'] }} neue Signale@if(! empty($dryRun['reopened'])) · {{ $dryRun['reopened'] }} würden wieder geöffnet@endif · {{ $dryRun['resolved'] }} würden sich auflösen.
                        @foreach($dryRun['rows'] as $row)
                            <div class="mt-2">
                                <div class="font-medium">{{ $row['template'] }}</div>
                                @if($row['error'])<div class="text-[color:var(--nx-danger)]">{{ $row['error'] }}</div>@endif
                                @forelse($row['hits'] as $hit)
                                    <div class="text-xs">· {{ $hit }}</div>
                                @empty
                                    <div class="text-xs text-[color:var(--nx-muted)]">schlägt nirgends an</div>
                                @endforelse
                            </div>
                        @endforeach
                    @endisset
                </x-nx-callout>
            @endif

            @if($assignments->isEmpty())
                <x-nx-card><x-nx-empty icon="heroicon-o-adjustments-horizontal">Noch keine Templates im Set.</x-nx-empty></x-nx-card>
            @else
                <x-nx-card flush>
                    <x-nx-table>
                        <x-nx-table-header>
                            <x-nx-table-header-cell>Template</x-nx-table-header-cell>
                            <x-nx-table-header-cell>Regel / Auswertung</x-nx-table-header-cell>
                            <x-nx-table-header-cell>Schwere</x-nx-table-header-cell>
                            <x-nx-table-header-cell>Takt</x-nx-table-header-cell>
                            <x-nx-table-header-cell align="right">Zuletzt</x-nx-table-header-cell>
                        </x-nx-table-header>
                        <x-nx-table-body>
                            @foreach($assignments as $a)
                                <x-nx-table-row wire:key="tpl-{{ $a->id }}">
                                    <x-nx-table-cell>
                                        <span class="{{ $a->is_active ? '' : 'line-through text-[color:var(--nx-muted)]' }}">{{ $a->template->name }}</span>
                                        <x-nx-badge variant="neutral">{{ $a->template->isRule() ? ($P::PATTERN_LABELS[$a->template->pattern_type] ?? 'Regel') : 'KI' }}</x-nx-badge>
                                        @if($a->conditions_override || $a->severity_override || $a->frequency_override || $a->schedule_interval_hours_override)
                                            <x-nx-badge variant="info" title="Für dieses Set angepasst">angepasst</x-nx-badge>
                                        @endif
                                        @can('update', $set)
                                            <div class="mt-1 flex gap-3 text-xs">
                                                <button type="button" wire:click="toggleTemplate({{ $a->id }})" class="text-[color:var(--nx-muted)] hover:text-[color:var(--nx-text)] underline">{{ $a->is_active ? 'Pausieren' : 'Fortsetzen' }}</button>
                                                <button type="button" wire:click="removeTemplate({{ $a->id }})" wire:confirm="Template aus dem Set nehmen?" class="text-[color:var(--nx-muted)] hover:text-[color:var(--nx-danger)] underline">Entfernen</button>
                                            </div>
                                        @endcan
                                    </x-nx-table-cell>
                                    <x-nx-table-cell><span class="text-xs font-mono">{{ $P::assignment($a) }}</span></x-nx-table-cell>
                                    <x-nx-table-cell><x-nx-badge :variant="$P::severityVariant($a->effectiveSeverity())">{{ $P::SEVERITY_LABELS[$a->effectiveSeverity()] ?? $a->effectiveSeverity() }}</x-nx-badge></x-nx-table-cell>
                                    <x-nx-table-cell><span class="text-xs">{{ $P::cadence($a) }}</span><div class="text-xs text-[color:var(--nx-muted)]">{{ $P::policy($a) }}</div></x-nx-table-cell>
                                    <x-nx-table-cell align="right">
                                        <span class="text-xs text-[color:var(--nx-muted)]">{{ $a->last_evaluated_at?->diffForHumans() ?? 'noch nie' }}</span>
                                        @if($a->last_error)<div class="text-xs text-[color:var(--nx-danger)]" title="{{ $a->last_error }}">Fehler</div>@endif
                                    </x-nx-table-cell>
                                </x-nx-table-row>
                            @endforeach
                        </x-nx-table-body>
                    </x-nx-table>
                </x-nx-card>
            @endif

            @can('update', $set)
                @if($availableTemplates->isNotEmpty())
                    <div class="mt-3 flex items-end gap-2">
                        <div class="grow">
                            <x-nx-input-select name="addTemplateId" size="sm" wire:model="addTemplateId" nullable="true" nullLabel="Template hinzufügen …"
                                               :options="$availableTemplates->map(fn ($t) => ['value' => $t->id, 'label' => $t->name . ($t->kind === 'inference' ? ' (KI)' : '')])->all()" />
                        </div>
                        <x-nx-button variant="secondary" size="sm" wire:click="addTemplate">Hinzufügen</x-nx-button>
                    </div>
                @endif
                <p class="mt-2 text-xs text-[color:var(--nx-muted)]">Neue Templates und Schwellen je Set: <a href="{{ route('signals.templates.index') }}" wire:navigate class="underline">Templates</a> bzw. per MCP <code>signals.set_templates.PUT</code>.</p>
            @endcan
        </x-nx-section>

        {{-- Ausgelöste Signale --}}
        <x-nx-section icon="heroicon-o-bell-alert" title="Ausgelöste Signale" :hint="$signals->count()"
                      description="Offene und gesehene Signale aus diesem Set{{ $closedCount ? ' · ' . $closedCount . ' erledigt/verworfen' : '' }}.">
            @if($signals->isEmpty())
                <x-nx-card><x-nx-empty icon="heroicon-o-bell-alert">Keine offenen Signale.</x-nx-empty></x-nx-card>
            @else
                <x-nx-card flush>
                    <x-nx-table>
                        <x-nx-table-header>
                            <x-nx-table-header-cell>Signal</x-nx-table-header-cell>
                            <x-nx-table-header-cell>Knoten</x-nx-table-header-cell>
                            <x-nx-table-header-cell>Status</x-nx-table-header-cell>
                            <x-nx-table-header-cell align="right">Seit</x-nx-table-header-cell>
                        </x-nx-table-header>
                        <x-nx-table-body>
                            @foreach($signals as $signal)
                                <x-nx-table-row wire:key="sig-{{ $signal->id }}" :href="route('signals.signals.show', $signal)">
                                    <x-nx-table-cell>
                                        <x-nx-badge :variant="$P::severityVariant($signal->severity)">{{ $P::SEVERITY_LABELS[$signal->severity] ?? $signal->severity }}</x-nx-badge>
                                        <span class="text-sm">{{ \Illuminate\Support\Str::limit($signal->message, 110) }}</span>
                                    </x-nx-table-cell>
                                    <x-nx-table-cell>{{ $signal->entity?->name ?? '#' . $signal->entity_id }}</x-nx-table-cell>
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
