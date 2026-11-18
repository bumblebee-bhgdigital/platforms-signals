<?php

namespace Platform\Signals\Organization;

use Illuminate\Database\Eloquent\Builder;
use Platform\Organization\Contracts\EntityLinkProvider;
use Platform\Signals\Enums\SignalSetStatus;
use Platform\Signals\Models\SignalSet;

/**
 * Macht das Signal-Set im Organisations-Modul an Knoten (Entities) verknüpfbar —
 * DimensionLink/Bridge analog PlannerEntityLinkProvider (Alias 'project' -> hier 'signal_set').
 *
 * Registrierung: SignalsServiceProvider::boot() (EntityLinkRegistry::register).
 * Damit ist der Typ für organization.entity.link.POST (object_type "signal_set"), entity.detail/summary
 * und die Terminal-App "Verknüpfungen" adressierbar. Anders als beim Planner-Projekt darf ein Set an
 * mehreren Knoten hängen.
 *
 * Bewusst NICHT implementiert (Messlogik folgt in eigenen Boards): HasMetricDefinitions,
 * HasPersonMetrics, HasContextParent.
 */
class SignalSetEntityLinkProvider implements EntityLinkProvider
{
    public function morphAliases(): array
    {
        return [SignalSet::MORPH_ALIAS];
    }

    public function linkTypeConfig(): array
    {
        return [
            SignalSet::MORPH_ALIAS => [
                'label' => 'Signal-Sets',
                'singular' => 'Signal-Set',
                'icon' => 'bell-alert',
                'route' => 'signals.sets.show',
            ],
        ];
    }

    public function applyEagerLoading(Builder $query, string $morphAlias, string $fqcn): void
    {
        if ($morphAlias === SignalSet::MORPH_ALIAS) {
            $query->with(['owner:id,name', 'responsibleAgent:id,name']);
        }
    }

    public function extractMetadata(string $morphAlias, mixed $model): array
    {
        if ($morphAlias !== SignalSet::MORPH_ALIAS) {
            return [];
        }

        return [
            'status' => $model->status?->label(),
            'is_active' => $model->status === SignalSetStatus::ACTIVE,
            'responsible' => $model->owner?->name,
            'agent' => $model->responsibleAgent?->name,
        ];
    }

    public function metadataDisplayRules(): array
    {
        return [
            SignalSet::MORPH_ALIAS => [
                ['field' => 'status', 'format' => 'badge'],
                ['field' => 'responsible', 'format' => 'text'],
            ],
        ];
    }

    /** Keine Zeiterfassung am Signal-Set. */
    public function timeTrackableCascades(): array
    {
        return [];
    }

    /** Noch keine Kennzahlen (Messlogik folgt in eigenen Boards). */
    public function metrics(string $morphAlias, array $linksByEntity): array
    {
        return [];
    }

    /** Noch keine Kind-Objekte für den Activity-Feed. */
    public function activityChildren(string $morphAlias, array $linkableIds): array
    {
        return [];
    }
}
