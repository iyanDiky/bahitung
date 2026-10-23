<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashflowRequest;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    public function cashflow(CashflowRequest $request, int $household): JsonResponse
    {
        return response()->json(['data' => $this->reports->cashflow($request->user(), $household, $request->validated()), 'warnings' => []])->header('Cache-Control', 'no-store');
    }
}
