<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\BudgetUsageRequest;
use App\Http\Requests\CopyBudgetRequest;
use App\Http\Requests\ListBudgetsRequest;
use App\Http\Requests\StoreBudgetRequest;
use App\Http\Requests\UpdateBudgetRequest;
use App\Services\BudgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BudgetController extends Controller
{
    public function __construct(private BudgetService $budgets) {}

    public function index(ListBudgetsRequest $request, int $household): JsonResponse
    {
        $page = $this->budgets->list($request->user(), $household, $request->validated());

        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()], 'warnings' => []])->header('Cache-Control', 'no-store');
    }

    public function store(StoreBudgetRequest $request, int $household): JsonResponse
    {
        return $this->item($this->budgets->save($request->user(), $household, $request->validated()), 201);
    }

    public function show(Request $request, int $household, int $budget): JsonResponse
    {
        return $this->item($this->budgets->show($request->user(), $household, $budget));
    }

    public function update(UpdateBudgetRequest $request, int $household, int $budget): JsonResponse
    {
        return $this->item($this->budgets->save($request->user(), $household, $request->validated(), $budget));
    }

    public function copy(CopyBudgetRequest $request, int $household): JsonResponse
    {
        return $this->item($this->budgets->copy($request->user(), $household, $request->validated()), 201);
    }

    public function usage(BudgetUsageRequest $request, int $household): JsonResponse
    {
        return $this->item($this->budgets->report($request->user(), $household, $request->validated('month_start')));
    }

    private function item(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'warnings' => []], $status)->header('Cache-Control', 'no-store');
    }
}
