<?php

namespace Platform\Signals\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\User;
use Platform\Organization\Models\OrganizationEntity;
use Platform\Signals\Exceptions\SignalSetException;
use Platform\Signals\Models\Signal;
use Platform\Signals\Models\SignalAction;
use Platform\Signals\Models\SignalComment;
use Platform\Signals\Models\SignalFocus;

/**
 * Lebenszyklus eines Signals: anlegen, bestätigen, auflösen (mit Notiz + Verweis, #1061), verwerfen,
 * zurückstellen, zuweisen, kommentieren, Maßnahmen entscheiden, Fokus.
 */
class SignalService
{
    /** Kurzschlüssel für Auflösungs-Verweise (zusätzlich zu Morph-Aliasen und Klassennamen). */
    /**
     * Signal anlegen (Agent, Mensch, Umzug). Maßnahmen optional als [{title, description}].
     *
     * @param  array<string,mixed>  $data
     */
    public function create(array $data, ?User $by = null): Signal
    {
        $entityId = (int) ($data['entity_id'] ?? 0);
        if ($entityId <= 0 || ! OrganizationEntity::whereKey($entityId)->exists()) {
            throw new SignalSetException('entity_id fehlt oder der Knoten existiert nicht.', 'VALIDATION_ERROR');
        }
        $message = trim((string) ($data['message'] ?? ''));
        if ($message === '') {
            throw new SignalSetException('message ist erforderlich.', 'VALIDATION_ERROR');
        }
        $severity = (string) ($data['severity'] ?? 'warning');
        if (! in_array($severity, Signal::SEVERITIES, true)) {
            throw new SignalSetException('severity muss info, warning, critical oder algedonic sein.', 'VALIDATION_ERROR');
        }
        $status = (string) ($data['status'] ?? 'open');
        if (! in_array($status, Signal::STATUSES, true)) {
            throw new SignalSetException('Ungültiger status.', 'VALIDATION_ERROR');
        }
        if (! empty($data['vsm_level']) && ! in_array($data['vsm_level'], Signal::VSM_LEVELS, true)) {
            throw new SignalSetException('vsm_level muss s1, s2, s3, s3_star, s4 oder s5 sein.', 'VALIDATION_ERROR');
        }

        $fields = array_intersect_key($data, array_flip([
            'team_id', 'signal_set_id', 'signal_template_id', 'source', 'source_type', 'affected_entity_ids',
            'trigger_metrics', 'evidence', 'responsible_user_id', 'assignee_entity_id', 'perspective_entity_id',
            'current_owner_entity_id', 'created_by_agent_entity_id', 'vsm_level', 'deadline_at', 'escalated_at',
            'acknowledged_at', 'snooze_until', 'resolved_at', 'resolved_by_user_id', 'dismissed_reason',
            'resolution_note', 'legacy_organization_signal_id', 'created_at', 'signal_set_template_id',
        ]));

        return DB::transaction(function () use ($fields, $entityId, $message, $severity, $status, $data, $by) {
            $signal = Signal::create(array_merge([
                'source' => 'inference',
                'source_type' => Signal::SOURCE_TYPE_MANUAL,
                'created_by_user_id' => $by?->id,
            ], $fields, [
                'entity_id' => $entityId,
                'message' => $message,
                'severity' => $severity,
                'status' => $status,
            ]));

            foreach (array_values((array) ($data['actions'] ?? [])) as $i => $action) {
                if (! is_array($action) || trim((string) ($action['title'] ?? '')) === '') {
                    continue;
                }
                $signal->actions()->create([
                    'position' => $i,
                    'title' => mb_substr(trim((string) $action['title']), 0, 255),
                    'description' => $action['description'] ?? null,
                    'status' => in_array($action['status'] ?? 'pending', SignalAction::STATUSES, true) ? ($action['status'] ?? 'pending') : 'pending',
                ]);
            }

            return $signal->fresh();
        });
    }

    /**
     * acknowledge | resolve | dismiss | reopen | reject. Bei resolve/dismiss optional Notiz und Verweis (Beschluss, Aufgabe …).
     * reject = „gehört nicht zu mir“ (Begründung Pflicht) – eskaliert sofort eine Stufe (Z3).
     * Die erste Reaktion (gesehen, gelöst, verworfen, abgelehnt) setzt reacted_at und stoppt die Reaktionsuhr.
     *
     * @param  array{reason?:string, resolution_note?:string, link_type?:string, link_id?:int, comment?:string}  $opts
     */
    public function transition(Signal $signal, string $action, ?User $by, array $opts = []): Signal
    {
        if (! in_array($action, ['acknowledge', 'resolve', 'dismiss', 'reopen', 'reject'], true)) {
            throw new SignalSetException('action muss acknowledge, resolve, dismiss, reopen oder reject sein.', 'VALIDATION_ERROR');
        }

        $ackAt = $signal->acknowledged_at ?? now();
        $note = trim((string) ($opts['resolution_note'] ?? ''));
        $reason = trim((string) ($opts['reason'] ?? ''));

        if ($action === 'reject') {
            return $this->reject($signal, $reason, $by);
        }

        $update = match ($action) {
            'acknowledge' => ['status' => 'acknowledged', 'acknowledged_at' => $ackAt],
            'resolve' => ['status' => 'resolved', 'resolved_at' => now(), 'resolved_by_user_id' => $by?->id, 'acknowledged_at' => $ackAt],
            'dismiss' => ['status' => 'dismissed', 'dismissed_reason' => $reason ?: null, 'resolved_by_user_id' => $by?->id, 'acknowledged_at' => $ackAt],
            'reopen' => ['status' => 'open', 'resolved_at' => null, 'resolved_by_user_id' => null, 'dismissed_reason' => null],
        };
        if ($action !== 'reopen') {
            $update['reacted_at'] = $signal->reacted_at ?? now();
        }
        if ($note !== '' && in_array($action, ['resolve', 'dismiss'], true)) {
            $update['resolution_note'] = $note;
        }
        if (! empty($opts['link_type']) && in_array($action, ['resolve', 'dismiss', 'acknowledge'], true)) {
            [$type, $id] = $this->resolveLink((string) $opts['link_type'], (int) ($opts['link_id'] ?? 0));
            $update['resolution_link_type'] = $type;
            $update['resolution_link_id'] = $id;
        }

        $signal->update($update);
        $this->recordFeedback($signal, $action, $reason, $note, $by);
        $this->followInChannel($signal, $action, $note ?: $reason, $by, ! empty($opts['from_channel']));

        if (trim((string) ($opts['comment'] ?? '')) !== '') {
            $this->comment($signal, (string) $opts['comment'], $by);
        }

        return $signal->fresh();
    }

    /**
     * Z4: den Parlan-Vorgang mitziehen. Gelöst/verworfen → schließen, wieder geöffnet → wieder öffnen.
     * Kam die Reaktion selbst aus dem Vorgang (from_channel), ist er dort schon zu – nur den Stand merken.
     */
    protected function followInChannel(Signal $signal, string $action, string $text, ?User $by, bool $fromChannel): void
    {
        if (! in_array($action, ['resolve', 'dismiss', 'reopen'], true)) {
            return;
        }
        if ($fromChannel) {
            if ($signal->delivery_state) {
                $signal->forceFill(['delivery_state' => 'closed'])->saveQuietly();
            }

            return;
        }
        $who = $by?->name ? " von {$by->name}" : '';
        $queue = app(SignalDeliveryQueue::class);
        if ($action === 'reopen') {
            $queue->reopened($signal, "Wieder geöffnet{$who}.");

            return;
        }
        $label = $action === 'resolve' ? 'Gelöst' : 'Verworfen';
        $queue->closed($signal, "{$label}{$who} in der Plattform" . ($text !== '' ? ': ' . mb_strimwidth($text, 0, 500, '…') : '.'));
    }

    /** „Gehört nicht zu mir“: Begründung Pflicht, Kommentar, sofort eine Stufe weiter (Ziel der Zuordnung). */
    public function reject(Signal $signal, string $reason, ?User $by): Signal
    {
        if ($signal->isClosed()) {
            throw new SignalSetException('Das Signal ist bereits geschlossen.', 'VALIDATION_ERROR');
        }
        if (trim($reason) === '') {
            throw new SignalSetException('reason ist erforderlich: Warum gehört das Signal nicht zu dir?', 'VALIDATION_ERROR');
        }
        $signal->forceFill(['reacted_at' => $signal->reacted_at ?? now()])->save();
        $this->comment($signal, 'Gehört nicht zu mir: ' . trim($reason), $by);
        app(SignalEscalationService::class)->escalate($signal, 'rejected', 'abgelehnt: ' . mb_strimwidth(trim($reason), 0, 300, '…'), $by);

        return $signal->fresh();
    }

    /**
     * Lernschleife (wie bisher in der Organisation): Rückmeldungen zu KI-Signalen landen im Gedächtnis.
     * bestätigt/gelöst → baseline, verworfen → suppression (mit Grund). Regel-Signale lernen nicht.
     */
    protected function recordFeedback(Signal $signal, string $action, string $reason, string $note, ?User $by): void
    {
        if ($action === 'reopen' || $signal->source_type !== Signal::SOURCE_TYPE_INFERENCE || ! $signal->signal_template_id) {
            return;
        }
        try {
            $content = match ($action) {
                'acknowledge' => 'Signal bestätigt: ' . mb_substr($signal->message, 0, 500),
                'resolve' => 'Signal gelöst: ' . mb_substr($signal->message, 0, 400) . ($note !== '' ? ' – ' . mb_substr($note, 0, 300) : ''),
                'dismiss' => $reason !== '' ? 'Signal verworfen: ' . $reason : 'Signal verworfen (ohne Begründung): ' . mb_substr($signal->message, 0, 300),
            };
            \Platform\Signals\Models\SignalMemoryEntry::create([
                'team_id' => $signal->team_id,
                'entity_id' => $signal->entity_id,
                'signal_template_id' => $signal->signal_template_id,
                'signal_id' => $signal->id,
                'memory_type' => $action === 'dismiss' ? 'suppression' : 'baseline',
                'content' => $content,
                'structured_data' => array_filter([
                    'signal_id' => $signal->id,
                    'severity' => $signal->severity,
                    'resolved' => $action === 'resolve' ?: null,
                    'reason' => $reason ?: null,
                ], fn ($v) => $v !== null),
                'confidence' => 0.9,
                'source_type' => 'signal_feedback',
                'created_by_user_id' => $by?->id,
                'is_active' => true,
            ]);
        } catch (\Throwable) {
            // Gedächtnis darf die Bearbeitung nie blockieren
        }
    }

    public function assign(Signal $signal, ?int $assigneeEntityId): Signal
    {
        if ($assigneeEntityId && ! OrganizationEntity::whereKey($assigneeEntityId)->exists()) {
            throw new SignalSetException("Knoten {$assigneeEntityId} existiert nicht.", 'VALIDATION_ERROR');
        }
        $signal->update(['assignee_entity_id' => $assigneeEntityId ?: null]);

        return $signal->fresh();
    }

    public function snooze(Signal $signal, ?\DateTimeInterface $until): Signal
    {
        $signal->update(['snooze_until' => $until]);

        return $signal->fresh();
    }

    public function comment(Signal $signal, string $content, ?User $by, ?int $parentId = null, ?string $authorContext = null): SignalComment
    {
        $content = trim($content);
        if ($content === '') {
            throw new SignalSetException('Kommentar ist leer.', 'VALIDATION_ERROR');
        }
        if ($parentId && ! $signal->comments()->whereKey($parentId)->exists()) {
            throw new SignalSetException('parent_id gehört nicht zu diesem Signal.', 'VALIDATION_ERROR');
        }

        return $signal->comments()->create([
            'parent_id' => $parentId,
            'user_id' => $by?->id,
            'author_context' => $authorContext ?: (\Platform\Signals\Support\Agents::isAgent($by) ? 'agent' : 'user'),
            'content' => $content,
        ]);
    }

    /** Maßnahme entscheiden (applied | dismissed | pending). Leitet danach den Signal-Status ab. */
    public function decideAction(Signal $signal, int $actionId, string $status, ?User $by, ?string $reason = null): Signal
    {
        if (! in_array($status, SignalAction::STATUSES, true)) {
            throw new SignalSetException('status der Maßnahme muss pending, applied oder dismissed sein.', 'VALIDATION_ERROR');
        }
        $action = $signal->actions()->whereKey($actionId)->first();
        if (! $action) {
            throw new SignalSetException('Maßnahme gehört nicht zu diesem Signal.', 'NOT_FOUND');
        }
        $action->update([
            'status' => $status,
            'decision_reason' => $reason,
            'decided_by' => $status === 'pending' ? null : $by?->id,
            'decided_at' => $status === 'pending' ? null : now(),
        ]);

        $signal->load('actions');
        if ($derived = $signal->deriveStatusFromActions()) {
            if ($derived !== $signal->status) {
                $this->transition($signal, match ($derived) {
                    'resolved' => 'resolve', 'dismissed' => 'dismiss', default => 'acknowledge',
                }, $by);
            }
        }

        return $signal->fresh();
    }

    public function toggleFocus(Signal $signal, User $user): bool
    {
        $existing = SignalFocus::query()->where('signal_id', $signal->id)->where('user_id', $user->id)->first();
        if ($existing) {
            $existing->delete();

            return false;
        }
        SignalFocus::create(['signal_id' => $signal->id, 'user_id' => $user->id, 'focused_at' => now()]);

        return true;
    }

    /**
     * Verweis prüfen: Morph-Alias eines registrierten Objekt-Typs (jedes Modul meldet seine selbst an); das Objekt muss existieren.
     *
     * @return array{0:string, 1:int}
     */
    public function resolveLink(string $type, int $id): array
    {
        if ($id <= 0) {
            throw new SignalSetException('link_id fehlt.', 'VALIDATION_ERROR');
        }
        $class = Relation::getMorphedModel($type);
        if (! $class || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            throw new SignalSetException("Unbekannter link_type '{$type}'. Erlaubt: " . implode(', ', array_column(self::linkTypes(), 'value')) . '.', 'VALIDATION_ERROR');
        }
        if (! $class::query()->whereKey($id)->exists()) {
            throw new SignalSetException("Verweis {$type} #{$id} existiert nicht.", 'NOT_FOUND');
        }

        return [$type, $id];
    }

    /** Verweis-Typen zur Auswahl: alle Objekt-Typen, die Module bei der Organisation anmelden. [['value','label']] */
    public static function linkTypes(): array
    {
        try {
            $config = app(\Platform\Organization\Services\EntityLinkRegistry::class)->allLinkTypeConfig();
        } catch (\Throwable) {
            return [];
        }

        return collect($config)->filter(fn ($c, $alias) => $alias !== Signal::MORPH_ALIAS && Relation::getMorphedModel($alias))
            ->map(fn ($c, $alias) => ['value' => $alias, 'label' => $c['singular'] ?? $c['label'] ?? $alias])
            ->sortBy('label')->values()->all();
    }
}
