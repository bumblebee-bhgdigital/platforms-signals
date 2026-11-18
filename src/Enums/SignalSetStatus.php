<?php

namespace Platform\Signals\Enums;

/**
 * Status eines Signal-Sets.
 *
 * draft -> active -> archived; archived -> active ist erlaubt (Reaktivieren).
 * Anders als beim Management Book gibt es KEINE Einzel-Aktiv-Regel pro Knoten:
 * ein Knoten kann beliebig viele aktive Sets tragen.
 */
enum SignalSetStatus: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case ARCHIVED = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Entwurf',
            self::ACTIVE => 'Aktiv',
            self::ARCHIVED => 'Archiviert',
        };
    }

    /** nx-badge variant */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::DRAFT => 'neutral',
            self::ACTIVE => 'success',
            self::ARCHIVED => 'neutral',
        };
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
