<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AcceptInvitationRequest;
use App\Http\Requests\InviteMemberRequest;
use App\Http\Requests\PageRequest;
use App\Http\Requests\TransferOwnershipRequest;
use App\Http\Requests\UpdateMemberRequest;
use App\Services\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MembershipController extends Controller
{
    public function __construct(private MembershipService $members) {}

    public function invite(InviteMemberRequest $request, int $household): JsonResponse
    {
        return $this->response($this->members->invite($request->user(), $household, $request->validated()), 201);
    }

    public function index(PageRequest $request, int $household): JsonResponse
    {
        $page = $this->members->invitations($request->user(), $household, (int) $request->validated('per_page', 20));

        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()], 'warnings' => []]);
    }

    public function revoke(Request $request, int $household, int $invitation): JsonResponse
    {
        return $this->response($this->members->revoke($request->user(), $household, $invitation));
    }

    public function accept(AcceptInvitationRequest $request): JsonResponse
    {
        return $this->response($this->members->accept($request->user(), $request->validated('token')), 201);
    }

    public function update(UpdateMemberRequest $request, int $household, int $member): JsonResponse
    {
        return $this->response($this->members->update($request->user(), $household, $member, $request->validated()));
    }

    public function transfer(TransferOwnershipRequest $request, int $household): JsonResponse
    {
        return $this->response($this->members->transfer($request->user(), $household, $request->validated()));
    }

    private function response(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'warnings' => []], $status)->header('Cache-Control', 'no-store');
    }
}
