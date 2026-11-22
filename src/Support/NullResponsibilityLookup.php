<?php

namespace Platform\Signals\Support;

use DateTimeInterface;
use Platform\Signals\Contracts\ResponsibilityLookup;

/** Ohne Organisation (bzw. ohne deren Zuständigkeits-Abfrage): niemand ist verantwortlich. */
class NullResponsibilityLookup implements ResponsibilityLookup
{
    public function holdersAt(int $nodeId, string $capability = 'manage', array $excludeEntityIds = [], ?DateTimeInterface $at = null): array
    {
        return [];
    }

    public function responsibleFor(int $entityId, string $capability = 'manage', array $exclude = [], ?DateTimeInterface $at = null): array
    {
        return ['entity_id' => $entityId, 'found' => false, 'node' => null, 'distance' => null, 'holders' => [], 'path' => []];
    }
}
