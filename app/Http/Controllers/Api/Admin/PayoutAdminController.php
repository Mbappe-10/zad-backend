<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Services\FinancialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayoutAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Payout::query()->with('wallet');
        if ($request->filled('wallet_id')) {
            $query->where('wallet_id', $request->integer('wallet_id'));
        }

        return response()->json([
            'data' => $query->latest()->limit(200)->get()->map(fn (Payout $payout) => $this->payload($payout)),
        ]);
    }

    public function show(Payout $payout): JsonResponse
    {
        return response()->json(['data' => $this->payload($payout->load('wallet'))]);
    }

    public function decide(Request $request, Payout $payout, FinancialService $financialService): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($data['decision'] === 'approve') {
            abort_unless(
                $payout->declaration_reference && $payout->declaration_signature &&
                $payout->declaration_hash && $payout->contract_reference &&
                $payout->contract_acceptance_reference,
                422,
                'لا يمكن اعتماد السحب قبل اكتمال الإقرار وتحقق العقد.',
            );
        }

        $updated = $financialService->decidePayout(
            $payout,
            $data['decision'] === 'approve' ? 'approve' : 'reject',
            $request->user()?->id,
        );

        return response()->json([
            'message' => $data['decision'] === 'approve' ? 'تم اعتماد طلب السحب.' : 'تم رفض طلب السحب.',
            'data' => $this->payload($updated->load('wallet')),
        ]);
    }

    private function payload(Payout $payout): array
    {
        return [
            ...$payout->toArray(),
            'requested_at' => $payout->created_at?->toIso8601String(),
            'declaration_complete' => (bool) ($payout->declaration_reference && $payout->declaration_signature && $payout->declaration_hash),
            'contract_verified' => (bool) ($payout->contract_reference && $payout->contract_acceptance_reference),
        ];
    }
}
