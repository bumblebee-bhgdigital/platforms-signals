<?php

namespace Platform\Signals\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Platform\Organization\Models\OrganizationEntity;
use Platform\Signals\Services\EnvironmentContextService;

/**
 * UMWELT als Agent-Vertrag (VSM S4, Perceptor): Quellen mit letztem Stand und Bewegung, gelernte Relevanz filtert
 * Irrelevantes. Übernommen aus der Organisation (GET /api/org/agent/environment); gleiche Antwortform.
 */
class AgentEnvironmentController extends Controller
{
    /** GET /api/signals/agent/environment */
    public function environment(Request $request): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        if ($userId < 1) {
            return response()->json(['message' => 'No user for this token'], 404);
        }
        $agent = OrganizationEntity::query()->where('linked_user_id', $userId)->first();
        if (! $agent) {
            return response()->json(['message' => 'No agent for this token'], 404);
        }

        try {
            $sources = app(EnvironmentContextService::class)->buildInferenceContext((int) $agent->team_id);
        } catch (\Throwable) {
            $sources = [];
        }

        return response()->json(['data' => ['sources' => array_values($sources)]]);
    }
}
