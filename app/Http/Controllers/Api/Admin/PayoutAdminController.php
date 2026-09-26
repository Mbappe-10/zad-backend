<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Services\FinancialService;
use App\Services\SecurePayoutProofService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class PayoutAdminController extends Controller
{
    public function __construct(
        private readonly SecurePayoutProofService $payoutProofs,
    ) {
    }

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

            if (
                $payout->iban_proof_required
                && (
                    $payout->iban_proof_uploaded_at === null
                    || $payout->iban_proof_deleted_at !== null
                    || blank($payout->iban_proof_public_id)
                )
            ) {
                throw ValidationException::withMessages([
                    'iban_proof' => ['لا يمكن اعتماد طلب السحب قبل مراجعة إثبات الآيبان.'],
                ]);
            }
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

    public function downloadProof(Payout $payout): RedirectResponse
    {
        abort_unless(
            $payout->iban_proof_uploaded_at !== null
            && $payout->iban_proof_deleted_at === null
            && filled($payout->iban_proof_public_id)
            && filled($payout->iban_proof_format)
            && filled($payout->iban_proof_resource_type),
            404,
        );

        $url = $this->payoutProofs->temporaryDownloadUrl(
            (string) $payout->iban_proof_public_id,
            (string) $payout->iban_proof_format,
            (string) $payout->iban_proof_resource_type,
            (string) ($payout->iban_proof_delivery_type ?: 'authenticated'),
            120,
        );

        return redirect()->away($url);
    }

    public function deleteProof(Request $request, Payout $payout): JsonResponse
    {
        $data = $request->validate([
            'confirmation' => ['required', 'accepted'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        if (! in_array($payout->status, ['paid', 'completed'], true)) {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن حذف إثبات الآيبان قبل اكتمال التحويل المالي.'],
            ]);
        }

        if ($payout->iban_proof_deleted_at !== null) {
            return response()->json([
                'message' => 'تم حذف إثبات الآيبان مسبقًا.',
                'data' => $this->payload($payout),
            ]);
        }

        abort_unless(filled($payout->iban_proof_public_id), 404);

        $this->payoutProofs->delete(
            (string) $payout->iban_proof_public_id,
            (string) ($payout->iban_proof_resource_type ?: 'image'),
            (string) ($payout->iban_proof_delivery_type ?: 'authenticated'),
        );

        $payout->forceFill([
            'iban_proof_deleted_at' => now(),
            'iban_proof_deleted_by' => $request->user()?->id,
            'iban_proof_deletion_reason' => trim($data['reason']),
        ])->save();

        return response()->json([
            'message' => 'تم حذف الملف من التخزين الخارجي مع الاحتفاظ بسجل التدقيق والبصمة الرقمية.',
            'data' => $this->payload($payout->fresh()),
        ]);
    }

    private function payload(Payout $payout): array
    {
        $raw = $payout->makeHidden([
            'iban_proof_public_id',
            'iban_proof_asset_id',
            'iban_proof_resource_type',
            'iban_proof_delivery_type',
        ])->toArray();
        $proofAvailable = $payout->iban_proof_uploaded_at !== null
            && $payout->iban_proof_deleted_at === null
            && filled($payout->iban_proof_public_id);

        return [
            ...$raw,
            'requested_at' => $payout->created_at?->toIso8601String(),
            'declaration_complete' => (bool) ($payout->declaration_reference && $payout->declaration_signature && $payout->declaration_hash),
            'contract_verified' => (bool) ($payout->contract_reference && $payout->contract_acceptance_reference),
            'iban_proof' => [
                'required' => (bool) $payout->iban_proof_required,
                'available' => $proofAvailable,
                'file_name' => $payout->iban_proof_original_name,
                'mime_type' => $payout->iban_proof_mime_type,
                'size' => $payout->iban_proof_size,
                'sha256' => $payout->iban_proof_sha256,
                'uploaded_at' => $payout->iban_proof_uploaded_at?->toIso8601String(),
                'deleted_at' => $payout->iban_proof_deleted_at?->toIso8601String(),
                'download_url' => $proofAvailable
                    ? URL::temporarySignedRoute(
                        'api.admin.payout-proof.download',
                        now()->addMinutes(5),
                        ['payout' => $payout->id],
                    )
                    : null,
                'can_delete' => $proofAvailable
                    && in_array($payout->status, ['paid', 'completed'], true),
            ],
        ];
    }
}
