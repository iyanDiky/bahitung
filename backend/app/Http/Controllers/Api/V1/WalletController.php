<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListWalletsRequest;
use App\Http\Requests\StoreWalletRequest;
use App\Http\Requests\UpdateWalletRequest;
use App\Http\Requests\WalletMovementsRequest;
use App\Services\WalletService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(private WalletService $wallets) {}

    public function index(ListWalletsRequest $request, int $household): JsonResponse
    {
        return $this->paginated($this->wallets->list($request->user(), $household, $request->validated()));
    }

    public function store(StoreWalletRequest $request, int $household): JsonResponse
    {
        return response()->json($this->wallets->create($request->user(), $household, $request->validated()), 201)->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, int $household, int $wallet): JsonResponse
    {
        return response()->json(['data' => $this->wallets->show($request->user(), $household, $wallet), 'warnings' => []])->header('Cache-Control', 'no-store');
    }

    public function update(UpdateWalletRequest $request, int $household, int $wallet): JsonResponse
    {
        return response()->json(['data' => $this->wallets->update($request->user(), $household, $wallet, $request->validated()), 'warnings' => []])->header('Cache-Control', 'no-store');
    }

    public function movements(WalletMovementsRequest $request, int $household, int $wallet): JsonResponse
    {
        return $this->paginated($this->wallets->movements($request->user(), $household, $wallet, $request->validated()));
    }

    private function paginated(LengthAwarePaginator $page): JsonResponse
    {
        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()], 'warnings' => []])->header('Cache-Control', 'no-store');
    }
}
