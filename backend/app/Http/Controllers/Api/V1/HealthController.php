<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\HealthService;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(HealthService $health): JsonResponse
    {
        $available = $health->databaseIsAvailable();

        return response()->json([
            'data' => [
                'status' => $available ? 'ok' : 'unavailable',
                'service' => 'bahitung-api',
                'database' => $available ? 'ok' : 'unavailable',
            ],
        ], $available ? 200 : 503)->header('Cache-Control', 'no-store');
    }
}
