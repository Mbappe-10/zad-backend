<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppProfile;
use App\Models\Order;
use App\Models\OrderMessage;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrderChatController extends Controller
{
    public function driverIndex(
        Request $request,
        Order $order,
    ): JsonResponse {
        $driverId = $this->driverId($request);

        $this->ensureBelongsToDriver($order, $driverId);

        return $this->messagesResponse(
            $order,
            (int) $request->user()->id,
        );
    }

    public function driverStore(
        Request $request,
        Order $order,
    ): JsonResponse {
        $driverId = $this->driverId($request);

        $this->ensureBelongsToDriver($order, $driverId);

        return $this->storeMessage(
            request: $request,
            order: $order,
            senderRole: OrderMessage::ROLE_DRIVER,
        );
    }

    public function familyIndex(
        Request $request,
        Order $order,
    ): JsonResponse {
        $familyId = $this->familyId($request);

        $this->ensureBelongsToFamily($order, $familyId);

        return $this->messagesResponse(
            $order,
            (int) $request->user()->id,
        );
    }

    public function familyStore(
        Request $request,
        Order $order,
    ): JsonResponse {
        $familyId = $this->familyId($request);

        $this->ensureBelongsToFamily($order, $familyId);

        return $this->storeMessage(
            request: $request,
            order: $order,
            senderRole: OrderMessage::ROLE_FAMILY,
        );
    }

    private function storeMessage(
        Request $request,
        Order $order,
        string $senderRole,
    ): JsonResponse {
        $this->ensureChatIsOpen($order);

        $data = $request->validate([
            'message' => [
                'required',
                'string',
                'max:500',
            ],
        ]);

        $text = trim($data['message']);

        if ($text === '') {
            throw ValidationException::withMessages([
                'message' => [
                    'لا يمكن إرسال رسالة فارغة.',
                ],
            ]);
        }

        $message = OrderMessage::query()->create([
            'order_id' => $order->id,
            'sender_user_id' => $request->user()->id,
            'sender_role' => $senderRole,
            'message' => $text,
        ]);

        $message->load('sender:id,name');

        return response()->json([
            'message' => 'تم إرسال الرسالة.',
            'data' => $this->messagePayload(
                $message,
                (int) $request->user()->id,
            ),
        ], 201);
    }

    private function messagesResponse(
        Order $order,
        int $currentUserId,
    ): JsonResponse {
        $messages = OrderMessage::query()
            ->where('order_id', $order->id)
            ->with('sender:id,name')
            ->oldest('id')
            ->get()
            ->map(
                fn (OrderMessage $message): array =>
                    $this->messagePayload(
                        $message,
                        $currentUserId,
                    ),
            )
            ->values();

        return response()->json([
            'data' => $messages,
            'meta' => [
                'order_id' => $order->id,
                'order_number' => $order->number,
                'chat_open' => $this->chatIsOpen($order),
                'maximum_message_length' => 500,
            ],
        ]);
    }

    private function messagePayload(
        OrderMessage $message,
        int $currentUserId,
    ): array {
        return [
            'id' => $message->id,
            'order_id' => $message->order_id,
            'sender_type' => $message->sender_role,
            'sender_role' => $message->sender_role,
            'sender_name' => $message->sender?->name,
            'message' => $message->message,
            'is_mine' =>
                (int) $message->sender_user_id === $currentUserId,
            'created_at' => $message->created_at,
        ];
    }

    private function ensureChatIsOpen(Order $order): void
    {
        if ($order->driver_id === null) {
            throw ValidationException::withMessages([
                'message' => [
                    'لم يتم إسناد الطلب إلى مندوب حتى الآن.',
                ],
            ]);
        }

        if (! $this->chatIsOpen($order)) {
            throw ValidationException::withMessages([
                'message' => [
                    'تم إغلاق المحادثة بعد انتهاء الطلب.',
                ],
            ]);
        }
    }

    private function chatIsOpen(Order $order): bool
    {
        return in_array($order->status, [
            Order::STATUS_ASSIGNED,
            Order::STATUS_PICKED_UP,
            Order::STATUS_DELIVERING,
        ], true);
    }

    private function driverId(Request $request): int
    {
        $profile = AppProfile::query()
            ->where('user_id', $request->user()->id)
            ->first();

        abort_unless(
            $profile !== null && $profile->driver_id !== null,
            403,
            'هذا الحساب غير مرتبط بمندوب.',
        );

        return (int) $profile->driver_id;
    }

    private function familyId(Request $request): int
    {
        $profile = AppProfile::query()
            ->where('user_id', $request->user()->id)
            ->first();

        abort_unless(
            $profile !== null &&
                $profile->productive_family_id !== null,
            403,
            'هذا الحساب غير مرتبط بأسرة منتجة.',
        );

        return (int) $profile->productive_family_id;
    }

    private function ensureBelongsToDriver(
        Order $order,
        int $driverId,
    ): void {
        abort_unless(
            (int) $order->driver_id === $driverId,
            403,
            'هذا الطلب غير مسند إلى المندوب الحالي.',
        );
    }

    private function ensureBelongsToFamily(
        Order $order,
        int $familyId,
    ): void {
        $belongsToFamily = Store::query()
            ->whereKey($order->store_id)
            ->where('productive_family_id', $familyId)
            ->exists();

        abort_unless(
            $belongsToFamily,
            403,
            'هذا الطلب غير تابع للأسرة الحالية.',
        );
    }
}