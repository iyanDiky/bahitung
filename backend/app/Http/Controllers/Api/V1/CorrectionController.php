<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CorrectOperationRequest;
use App\Http\Requests\VoidOperationRequest;
use App\Services\CorrectionService;
use Illuminate\Http\JsonResponse;

class CorrectionController extends Controller
{
    public function __construct(private CorrectionService $corrections) {}

    public function correct(CorrectOperationRequest $request, int $household, int $operation): JsonResponse
    {
        return response()->json($this->corrections->apply($request->user(), $household, $operation, $request->validated(), correct: true), 201)->header('Cache-Control', 'no-store');
    }

    public function void(VoidOperationRequest $request, int $household, int $operation): JsonResponse
    {
        return response()->json($this->corrections->apply($request->user(), $household, $operation, $request->validated(), correct: false))->header('Cache-Control', 'no-store');
    }
}
