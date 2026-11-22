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
    public const PATTERN_LABELS = ['threshold' => 'Schwelle', 'trend' => 'Trend', 'ratio' => 'Quote', 'cross_dimension' => 'Vergleich', 'no_responsible' => 'Ohne Verantwortlichen'];
    public const ESCALATION_TARGET_LABELS = ['none' => 'keins (Lagebild)', 'line' => 'Linie', 'committee' => 'Gremium'];
    public const DELIVERY_LABELS = ['overview' => 'Lagebild', 'task' => 'Vorgang'];
    public const TRIGGER_LABELS = ['ignored' => 'ignoriert', 'persistent' => 'dauerhaft', 'recurring' => 'wiederkehrend', 'rejected' => 'abgelehnt'];

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
            return 'KI · ' . strtoupper((string) ($t->vsm_system ?: '–'));
        }
        $c = $conditions ?? (array) $t->conditions;

        return match ($t->pattern_type) {
            'threshold' => sprintf('%s %s %s', $c['metric'] ?? '?', $c['operator'] ?? '>', $c['value'] ?? '?'),
            'trend' => sprintf('%s %s über %d Perioden (≥ %s %%)', $c['metric'] ?? '?', ($c['direction'] ?? 'increasing') === 'increasing' ? 'steigt' : 'fällt', (int) ($c['periods'] ?? 3), $c['min_change_percent'] ?? 10),
            'ratio' => sprintf('%s / %s %s %s %%%s', $c['numerator'] ?? '?', $c['denominator'] ?? '?', $c['operator'] ?? '<', round(((float) ($c['value'] ?? 0)) * 100, 1), ! empty($c['min_denominator']) ? ' (ab ' . $c['min_denominator'] . ')' : ''),
            'no_responsible' => 'Einheit ohne Verantwortlichen (' . ($c['capability'] ?? 'manage') . ')',
            'cross_dimension' => sprintf('%s ↔ %s %s', $c['metric_a'] ?? '?', $c['metric_b'] ?? '?', ($c['relationship'] ?? 'diverging') === 'diverging' ? 'driften auseinander' : 'nähern sich an'),
            default => '–',
        };
    }

    public static function assignment(SignalSetTemplate $a): string
    {
        return self::rule($a->template, $a->template->isRule() ? $a->effectiveConditions() : null);
    }

    /** Kurzform der Politik einer Zuordnung, z. B. „Linie · 3 AT · 3× in 30 T · 14 T · Lagebild“ bzw. „abgewählt“. */
    public static function policy(SignalSetTemplate $a): string
    {
        if ($a->opts_out) {
            return 'abgewählt';
        }
        $p = $a->policy();

        return ($a->is_binding ? 'verbindlich · ' : '')
            . (self::ESCALATION_TARGET_LABELS[$p['escalation_target']] ?? $p['escalation_target'])
            . " · {$p['reaction_days']} AT · {$p['recurrence_count']}× in {$p['recurrence_window_days']} T · {$p['persistence_days']} T · "
            . (self::DELIVERY_LABELS[$p['delivery']] ?? $p['delivery']);
    }

    /** Seit wann liegt ein Signal beim Empfänger (Arbeitstage), z. B. „seit 4 Arbeitstagen“. */
    public static function waiting(?\DateTimeInterface $since): ?string
    {
        if (! $since) {
            return null;
        }
        $d = (int) \Illuminate\Support\Carbon::instance($since)->diffInWeekdays(now());

        return $d === 0 ? 'seit heute' : ($d === 1 ? 'seit 1 Arbeitstag' : "seit {$d} Arbeitstagen");
    }

    public static function cadence(SignalSetTemplate $a): string
    {
        return $a->template->isInference()
            ? 'alle ' . ($a->effectiveIntervalHours() ?: 168) . ' h (Agent)'
            : (self::FREQUENCY_LABELS[$a->effectiveFrequency()] ?? $a->effectiveFrequency());
    }
}
