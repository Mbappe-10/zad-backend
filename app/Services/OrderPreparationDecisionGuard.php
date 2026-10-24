<?php
namespace App\Services;
use App\Models\Order;
use App\Models\OrderPreparationDecision;
use Illuminate\Validation\ValidationException;
class OrderPreparationDecisionGuard {
    public function hasBlockingOperation(Order $order): bool {
        if (!(bool) config('zad_v4_family_actions.enabled', false)) return false;
        return OrderPreparationDecision::query()->where('order_id', $order->id)
            ->whereIn('status', ['pending', 'refund_review'])->exists();
    }
    public function hasBlockingSettlement(Order $order): bool {
        if (!(bool) config('zad_v4_family_actions.enabled', false)) return false;
        return OrderPreparationDecision::query()->where('order_id', $order->id)
            ->where(function ($q): void {
                $q->whereIn('status', ['pending','refund_review'])
                    ->orWhere(function ($inner): void {
                        $inner->where('status', 'accepted')
                            ->where('compensation_state', 'manual_review');
                    });
            })->exists();
    }
    public function assertOperational(Order $order): void {
        if (!(bool) config('zad_v4_family_actions.enabled', false)) return;
        if ($this->hasBlockingOperation($order)) {
            throw ValidationException::withMessages(['order'=>['الطلب معلّق بانتظار قرار العميل أو مراجعة الاسترداد.']]);
        }
    }
    public function assertSettlement(Order $order): void {
        if (!(bool) config('zad_v4_family_actions.enabled', false)) return;
        if ($this->hasBlockingSettlement($order)) {
            throw ValidationException::withMessages(['order'=>['لا يمكن تسوية طلب عليه تعويض أو استرداد قيد المراجعة.']]);
        }
    }
}
