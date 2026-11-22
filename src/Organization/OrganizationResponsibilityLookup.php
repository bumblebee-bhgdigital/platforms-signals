<?php

namespace Platform\Signals\Organization;

use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Platform\Signals\Contracts\ResponsibilityLookup;

/**
 * Adapter auf die Zuständigkeits-Abfrage der Organisation. Die einzige Stelle in Signals, die die konkrete
 * Klasse der Organisation kennt (Regel-Test in SignalsDeliveryTest). Fehler der Organisation dürfen das
 * Zustellen nie verhindern – dann gilt „niemand gefunden“.
 */
class OrganizationResponsibilityLookup implements ResponsibilityLookup
{
    private const RESOLVER = \Platform\Organization\Services\ResponsibilityResolver::class;

    public static function available(): bool
    {
        return class_exists(self::RESOLVER);
    }

    public function holdersAt(int $nodeId, string $capability = 'manage', array $excludeEntityIds = [], ?DateTimeInterface $at = null): array
    {
        try {
            return array_values((array) app(self::RESOLVER)->holdersAt($nodeId, $capability, $excludeEntityIds, $at));
        } catch (\Throwable $e) {
            Log::warning('Signals: Zuständigkeits-Abfrage (holdersAt) fehlgeschlagen', ['node' => $nodeId, 'error' => $e->getMessage()]);

            return [];
        }
    }

    public function responsibleFor(int $entityId, string $capability = 'manage', array $exclude = [], ?DateTimeInterface $at = null): array
    {
        try {
            $r = (array) app(self::RESOLVER)->responsibleFor($entityId, $capability, $exclude, $at);

            return $r + ['entity_id' => $entityId, 'found' => false, 'node' => null, 'distance' => null, 'holders' => [], 'path' => []];
        } catch (\Throwable $e) {
            Log::warning('Signals: Zuständigkeits-Abfrage (responsibleFor) fehlgeschlagen', ['entity' => $entityId, 'error' => $e->getMessage()]);

            return (new \Platform\Signals\Support\NullResponsibilityLookup())->responsibleFor($entityId, $capability, $exclude, $at);
        }
    }
}
