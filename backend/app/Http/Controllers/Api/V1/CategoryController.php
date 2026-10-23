<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListCategoriesRequest;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Services\CategoryService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function __construct(private CategoryService $categories) {}

    public function index(ListCategoriesRequest $request, int $household): JsonResponse
    {
        return $this->paginated($this->categories->list($request->user(), $household, $request->validated()));
    }

    public function store(StoreCategoryRequest $request, int $household): JsonResponse
    {
        return response()->json(['data' => $this->categories->save($request->user(), $household, $request->validated()), 'warnings' => []], 201)->header('Cache-Control', 'no-store');
    }

    public function update(UpdateCategoryRequest $request, int $household, int $category): JsonResponse
    {
        return response()->json(['data' => $this->categories->save($request->user(), $household, $request->validated(), $category), 'warnings' => []])->header('Cache-Control', 'no-store');
    }

    private function paginated(LengthAwarePaginator $page): JsonResponse
    {
        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()], 'warnings' => []])->header('Cache-Control', 'no-store');
    }
}
