<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\GoalMovementRequest;
use App\Http\Requests\ListWalletsRequest;
use App\Http\Requests\StoreGoalRequest;
use App\Http\Requests\UpdateGoalRequest;
use App\Http\Requests\WalletMovementsRequest;
use App\Services\GoalService;
use App\Services\OperationPostingService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoalController extends Controller
{
    public function __construct(private GoalService $goals, private OperationPostingService $posting) {}

    public function index(ListWalletsRequest $request, int $household): JsonResponse
    {
        return $this->page($this->goals->list($request->user(), $household, $request->validated()));
    }

    public function store(StoreGoalRequest $request, int $household): JsonResponse
    {
        return $this->item($this->goals->save($request->user(), $household, $request->validated()), 201);
    }

    public function show(Request $request, int $household, int $goal): JsonResponse
    {
        return $this->item($this->goals->show($request->user(), $household, $goal));
    }

    public function update(UpdateGoalRequest $request, int $household, int $goal): JsonResponse
    {
        return $this->item($this->goals->save($request->user(), $household, $request->validated(), $goal));
    }

    public function movements(WalletMovementsRequest $request, int $household, int $goal): JsonResponse
    {
        return $this->page($this->goals->movements($request->user(), $household, $goal, $request->validated()));
    }

    public function contribute(GoalMovementRequest $request, int $household, int $goal): JsonResponse
    {
        return response()->json($this->posting->createGoal($request->user(), $household, $goal, $request->validated(), false), 201)->header('Cache-Control', 'no-store');
    }

    public function withdraw(GoalMovementRequest $request, int $household, int $goal): JsonResponse
    {
        return response()->json($this->posting->createGoal($request->user(), $household, $goal, $request->validated(), true), 201)->header('Cache-Control', 'no-store');
    }

    private function item(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'warnings' => []], $status)->header('Cache-Control', 'no-store');
    }

    private function page(LengthAwarePaginator $page): JsonResponse
    {
        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()], 'warnings' => []])->header('Cache-Control', 'no-store');
    }
}
