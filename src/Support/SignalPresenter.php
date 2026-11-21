<?php

namespace Platform\Signals\Support;

use Platform\Signals\Models\SignalSetTemplate;
use Platform\Signals\Models\SignalTemplate;

/** Lesbare Darstellung von Templates und Signalen (Oberfläche, Brief). */
class SignalPresenter
{
    public const SEVERITY_LABELS = ['info' => 'Info', 'warning' => 'Warnung', 'critical' => 'Kritisch', 'algedonic' => 'Algedonisch'];
    public const STATUS_LABELS = ['open' => 'Offen', 'acknowledged' => 'Gesehen', 'resolved' => 'Erledigt', 'dismissed' => 'Verworfen'];
    public const FREQUENCY_LABELS = ['every_snapshot' => 'jeder Snapshot', 'daily' => 'täglich', 'weekly' => 'wöchentlich'];
    public const PATTERN_LABELS = ['threshold' => 'Schwelle', 'trend' => 'Trend', 'ratio' => 'Quote', 'cross_dimension' => 'Vergleich'];

    public static function severityVariant(?string $severity): string
    {
        return match ($severity) {
            'algedonic', 'critical' => 'danger',
            'warning' => 'warning',
            default => 'info',
        };
    }

    public static function statusVariant(?string $status): string
    {
        return match ($status) {
            'open' => 'warning',
            'acknowledged' => 'info',
            'resolved' => 'success',
            default => 'neutral',
        };
    }

    /** Kurzform der Regel, z. B. „items_open > 50“ oder „items_done / items_total < 30 %“. */
    public static function rule(SignalTemplate $t, ?array $conditions = null): string
    {
        if ($t->isInference()) {
            return 'KI · ' . strtoupper((string) ($t->vsm_system ?: '–')) . ($t->data_sources ? ' · ' . implode(', ', $t->data_sources) : '');
        }
        $c = $conditions ?? (array) $t->conditions;

        return match ($t->pattern_type) {
            'threshold' => sprintf('%s %s %s', $c['metric'] ?? '?', $c['operator'] ?? '>', $c['value'] ?? '?'),
            'trend' => sprintf('%s %s über %d Perioden (≥ %s %%)', $c['metric'] ?? '?', ($c['direction'] ?? 'increasing') === 'increasing' ? 'steigt' : 'fällt', (int) ($c['periods'] ?? 3), $c['min_change_percent'] ?? 10),
            'ratio' => sprintf('%s / %s %s %s %%%s', $c['numerator'] ?? '?', $c['denominator'] ?? '?', $c['operator'] ?? '<', round(((float) ($c['value'] ?? 0)) * 100, 1), ! empty($c['min_denominator']) ? ' (ab ' . $c['min_denominator'] . ')' : ''),
            'cross_dimension' => sprintf('%s ↔ %s %s', $c['metric_a'] ?? '?', $c['metric_b'] ?? '?', ($c['relationship'] ?? 'diverging') === 'diverging' ? 'driften auseinander' : 'nähern sich an'),
            default => '–',
        };
    }

    public static function assignment(SignalSetTemplate $a): string
    {
        return self::rule($a->template, $a->template->isRule() ? $a->effectiveConditions() : null);
    }

    public static function cadence(SignalSetTemplate $a): string
    {
        return $a->template->isInference()
            ? 'alle ' . ($a->effectiveIntervalHours() ?: 168) . ' h (Agent)'
            : (self::FREQUENCY_LABELS[$a->effectiveFrequency()] ?? $a->effectiveFrequency());
    }
}
