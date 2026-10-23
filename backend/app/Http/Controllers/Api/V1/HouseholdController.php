<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreHouseholdRequest;
use App\Http\Requests\UpdateHouseholdRequest;
use App\Services\HouseholdService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HouseholdController extends Controller
{
    public function __construct(private HouseholdService $households) {}

    public function index(Request $request): JsonResponse
    {
        return $this->paginated($this->households->list($request->user(), $this->perPage($request)));
    }

    public function store(StoreHouseholdRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->households->create($request->user(), $request->validated()), 'warnings' => []], 201);
    }

    public function show(Request $request, int $household): JsonResponse
    {
        return response()->json(['data' => $this->households->show($request->user(), $household), 'warnings' => []]);
    }

    public function update(UpdateHouseholdRequest $request, int $household): JsonResponse
    {
        return response()->json(['data' => $this->households->update($request->user(), $household, $request->validated()), 'warnings' => []]);
    }

    public function members(Request $request, int $household): JsonResponse
    {
        return $this->paginated($this->households->members($request->user(), $household, $this->perPage($request)));
    }

    private function perPage(Request $request): int
    {
        $validated = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']]);

        return (int) ($validated['per_page'] ?? 20);
    }

    private function paginated(LengthAwarePaginator $page): JsonResponse
    {
        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()], 'warnings' => []]);
    }
}
