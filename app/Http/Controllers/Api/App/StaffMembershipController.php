<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Services\App\CashierStaffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "I work at a store": an employee links her own Zad account with an invite code. */
class StaffMembershipController extends Controller
{
    public function __construct(private readonly CashierStaffService $staff) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->staff->memberships($request->user())]);
    }

    public function join(Request $request): JsonResponse
    {
        abort_unless($this->staff->ready(), 503, 'الميزة قيد التجهيز.');
        $data = $request->validate(['code' => ['required', 'string', 'min:6', 'max:20']]);
        $member = $this->staff->join($request->user(), $data['code']);

        return response()->json([
            'message' => 'تم! صرتِ ضمن فريق المتجر.',
            'data' => $this->staff->memberships($request->user())->firstWhere('id', $member->id),
        ]);
    }

    public function leave(Request $request, int $membership): JsonResponse
    {
        \App\Models\CashierStaff::query()->whereKey($membership)->where('user_id', $request->user()->id)
            ->update(['user_id' => null]);

        return response()->json(['message' => 'تم الخروج من المتجر.']);
    }
}
