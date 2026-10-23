<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListOperationsRequest;
use App\Http\Requests\StoreOperationRequest;
use App\Services\OperationPostingService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationController extends Controller
{
    public function __construct(private OperationPostingService $operations) {}

    public function index(ListOperationsRequest $request, int $household): JsonResponse
    {
        return $this->paginated($this->operations->list($request->user(), $household, $request->validated()));
    }

    public function store(StoreOperationRequest $request, int $household): JsonResponse
    {
        return response()->json($this->operations->create($request->user(), $household, $request->validated()), 201)->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, int $household, int $operation): JsonResponse
    {
        return response()->json($this->operations->show($request->user(), $household, $operation))->header('Cache-Control', 'no-store');
    }

    private function paginated(LengthAwarePaginator $page): JsonResponse
    {
        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()], 'warnings' => []])->header('Cache-Control', 'no-store');
    }
}
