<?php

namespace Platform\Signals\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\User;
use Platform\Signals\Exceptions\SignalSetException;
use Platform\Signals\Models\SignalSet;
use Platform\Signals\Models\SignalSetTemplate;
use Platform\Signals\Models\SignalTemplate;

/**
 * Templates anlegen/ändern und in Sets legen (mit Overrides). Validiert die Bedingungen je Muster,
 * damit kein Template stumm bleibt, weil ein Pflichtfeld fehlt.
 */
class SignalTemplateService
{
    public const FIELDS = [
        'kind', 'name', 'description', 'severity', 'is_active', 'pattern_type', 'conditions', 'frequency',
        'prompt', 'vsm_system', 'dimension', 'schedule_interval_hours',
    ];

    /** Pflichtfelder der conditions je Muster. */
    public const REQUIRED_CONDITIONS = [
        'threshold' => ['metric', 'value'],
        'trend' => ['metric'],
        'ratio' => ['numerator', 'denominator', 'value'],
        'cross_dimension' => ['metric_a', 'metric_b'],
    ];

    public function create(User $by, int $teamId, array $data): SignalTemplate
    {
        $fields = $this->validated(array_merge(['kind' => SignalTemplate::KIND_RULE], $data), null);

        return SignalTemplate::create(array_merge($fields, ['team_id' => $teamId, 'created_by_user_id' => $by->id]));
    }

    public function update(SignalTemplate $template, array $data): SignalTemplate
    {
        $template->update($this->validated($data, $template));

        return $template->fresh();
    }

    /** Template ins Set legen bzw. Overrides der Zuordnung ändern. */
    public function attach(SignalSet $set, SignalTemplate $template, array $overrides = []): SignalSetTemplate
    {
        if ((int) $template->team_id !== (int) $set->team_id) {
            throw new SignalSetException('Template und Set gehören zu unterschiedlichen Teams.', 'VALIDATION_ERROR');
        }

        $values = [];
        if (array_key_exists('conditions_override', $overrides)) {
            $o = $overrides['conditions_override'];
            $values['conditions_override'] = ($o === null || $o === []) ? null : (array) $o;
            if ($values['conditions_override'] && $template->isRule()) {
                $this->assertConditions((string) $template->pattern_type, array_replace((array) $template->conditions, $values['conditions_override']));
            }
        }
        if (array_key_exists('severity_override', $overrides)) {
            $s = $overrides['severity_override'] ?: null;
            if ($s && ! in_array($s, SignalTemplate::SEVERITIES, true)) {
                throw new SignalSetException('severity_override muss info, warning, critical oder algedonic sein.');
            }
            $values['severity_override'] = $s;
        }
        if (array_key_exists('frequency_override', $overrides)) {
            $f = $overrides['frequency_override'] ?: null;
            if ($f && ! in_array($f, SignalTemplate::FREQUENCIES, true)) {
                throw new SignalSetException('frequency_override muss every_snapshot, daily oder weekly sein.');
            }
            $values['frequency_override'] = $f;
        }
        if (array_key_exists('schedule_interval_hours_override', $overrides)) {
            $h = $overrides['schedule_interval_hours_override'];
            $values['schedule_interval_hours_override'] = $h ? max(1, (int) $h) : null;
        }
        foreach (['is_active' => 'bool', 'position' => 'int'] as $key => $cast) {
            if (array_key_exists($key, $overrides)) {
                $values[$key] = $cast === 'bool' ? (bool) $overrides[$key] : (int) $overrides[$key];
            }
        }

        return DB::transaction(fn () => SignalSetTemplate::query()->updateOrCreate(
            ['signal_set_id' => $set->id, 'signal_template_id' => $template->id],
            $values,
        ))->fresh(['template']);
    }

    public function detach(SignalSet $set, SignalTemplate $template): void
    {
        SignalSetTemplate::query()->where('signal_set_id', $set->id)->where('signal_template_id', $template->id)->delete();
    }

    /** @return array<string,mixed> */
    private function validated(array $data, ?SignalTemplate $existing): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            if (array_key_exists($f, $data)) {
                $out[$f] = $data[$f] === '' ? null : $data[$f];
            }
        }

        $kind = $out['kind'] ?? $existing?->kind ?? SignalTemplate::KIND_RULE;
        if (! in_array($kind, SignalTemplate::KINDS, true)) {
            throw new SignalSetException("kind muss 'rule' oder 'inference' sein.");
        }
        if ($existing && isset($out['kind']) && $out['kind'] !== $existing->kind) {
            throw new SignalSetException('kind lässt sich nicht nachträglich ändern – neues Template anlegen.');
        }

        $name = $out['name'] ?? $existing?->name;
        if (! is_string($name) || trim($name) === '') {
            throw new SignalSetException('name ist erforderlich.');
        }
        if (isset($out['severity']) && ! in_array($out['severity'], SignalTemplate::SEVERITIES, true)) {
            throw new SignalSetException('severity muss info, warning, critical oder algedonic sein.');
        }
        if (isset($out['frequency']) && ! in_array($out['frequency'], SignalTemplate::FREQUENCIES, true)) {
            throw new SignalSetException('frequency muss every_snapshot, daily oder weekly sein.');
        }
        if (array_key_exists('is_active', $out)) {
            $out['is_active'] = (bool) $out['is_active'];
        }

        if ($kind === SignalTemplate::KIND_RULE) {
            $pattern = $out['pattern_type'] ?? $existing?->pattern_type;
            if (! in_array($pattern, SignalTemplate::PATTERN_TYPES, true)) {
                throw new SignalSetException('pattern_type muss threshold, trend, ratio oder cross_dimension sein.');
            }
            $conditions = $out['conditions'] ?? $existing?->conditions;
            if (! is_array($conditions)) {
                throw new SignalSetException('conditions (Objekt) ist für Regel-Templates erforderlich.');
            }
            $this->assertConditions($pattern, $conditions);
        } else {
            $prompt = $out['prompt'] ?? $existing?->prompt;
            if (! is_string($prompt) || trim($prompt) === '') {
                throw new SignalSetException('prompt ist für KI-Templates erforderlich.');
            }
            $vsm = $out['vsm_system'] ?? $existing?->vsm_system;
            if ($vsm !== null && ! in_array($vsm, SignalTemplate::VSM_SYSTEMS, true)) {
                throw new SignalSetException('vsm_system muss s1, s2, s3, s3_star, s4 oder s5 sein.');
            }
            if (array_key_exists('schedule_interval_hours', $out)) {
                $out['schedule_interval_hours'] = $out['schedule_interval_hours'] ? max(1, (int) $out['schedule_interval_hours']) : null;
            }
        }

        return $out;
    }

    private function assertConditions(string $pattern, array $conditions): void
    {
        foreach (self::REQUIRED_CONDITIONS[$pattern] ?? [] as $key) {
            if (! array_key_exists($key, $conditions) || $conditions[$key] === '' || $conditions[$key] === null) {
                throw new SignalSetException("conditions.{$key} fehlt (Muster {$pattern} braucht: " . implode(', ', self::REQUIRED_CONDITIONS[$pattern]) . ').');
            }
        }
        if (isset($conditions['operator']) && ! in_array($conditions['operator'], ['>', '>=', '<', '<=', '==', '!='], true)) {
            throw new SignalSetException('conditions.operator muss >, >=, <, <=, == oder != sein.');
        }
        if (isset($conditions['direction']) && ! in_array($conditions['direction'], ['increasing', 'decreasing'], true)) {
            throw new SignalSetException("conditions.direction muss 'increasing' oder 'decreasing' sein.");
        }
        if (isset($conditions['relationship']) && ! in_array($conditions['relationship'], ['diverging', 'converging'], true)) {
            throw new SignalSetException("conditions.relationship muss 'diverging' oder 'converging' sein.");
        }
    }
}
