<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\PlatformRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AppSupportTicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $items = PlatformRecord::query()
            ->where('resource', 'support')
            ->where('payload->requester_user_id', $userId)
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (PlatformRecord $record): array => $this->ticket($record))
            ->values();

        return response()->json(['data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->create($request, false);
    }

    public function guestStore(Request $request): JsonResponse
    {
        return $this->create($request, true);
    }

    public function show(Request $request, int $ticket): JsonResponse
    {
        $record = $this->owned($request, $ticket);
        return response()->json(['data' => $this->ticket($record)]);
    }

    public function reply(Request $request, int $ticket): JsonResponse
    {
        $record = $this->owned($request, $ticket);
        $data = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:4000'],
        ]);

        $payload = $record->payload ?? [];
        $messages = is_array($payload['messages'] ?? null) ? $payload['messages'] : [];
        $messages[] = [
            'id' => count($messages) + 1,
            'sender_name' => $request->user()->name ?? 'ZADSYNC App',
            'sender_type' => 'customer',
            'message' => trim($data['message']),
            'created_at' => now()->toISOString(),
        ];
        $payload['messages'] = $messages;
        $payload['messages_count'] = count($messages);
        $payload['status'] = 'open';
        $payload['last_customer_reply_at'] = now()->toISOString();

        $record->update([
            'status' => 'open',
            'payload' => $payload,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'تم إرسال رسالتك إلى الدعم الفني.',
            'data' => $this->ticket($record->fresh()),
        ]);
    }

    private function create(Request $request, bool $guest): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'min:3', 'max:180'],
            'details' => ['required', 'string', 'min:3', 'max:4000'],
            'phone' => [$guest ? 'required' : 'nullable', 'string', 'max:30'],
            'category' => ['nullable', 'string', 'max:80'],
            'requester_type' => ['nullable', Rule::in(['customer', 'family', 'driver'])],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'module' => ['nullable', Rule::in(['support', 'change-requests', 'settings', 'account-deletion'])],
        ]);

        $user = $request->user();
        $requesterType = $guest ? 'customer' : ($data['requester_type'] ?? 'customer');
        $number = $this->number();
        $name = $guest ? 'عميل زاد سينك' : trim((string) ($user?->name ?: 'مستخدم زاد سينك'));
        $details = trim($data['details']);
        $now = now()->toISOString();

        $payload = [
            'ticket_number' => $number,
            'subject' => trim($data['subject']),
            'details' => $details,
            'requester_name' => $name,
            'requester_email' => $guest ? null : $user?->email,
            'requester_phone' => trim((string) ($data['phone'] ?? '')) ?: null,
            'requester_type' => $requesterType,
            'requester_user_id' => $user?->id,
            'priority' => $data['priority'] ?? 'medium',
            'category' => $data['category'] ?? $this->category($data['module'] ?? 'support'),
            'source' => 'zad_mobile_app',
            'module' => $data['module'] ?? 'support',
            'status' => 'open',
            'assigned_to_id' => null,
            'assigned_to_name' => null,
            'assigned_agent_type' => null,
            'messages' => [[
                'id' => 1,
                'sender_name' => $name,
                'sender_type' => 'customer',
                'message' => $details,
                'created_at' => $now,
            ]],
            'messages_count' => 1,
        ];

        $record = DB::transaction(fn (): PlatformRecord => PlatformRecord::query()->create([
            'resource' => 'support',
            'external_key' => $number,
            'status' => 'open',
            'payload' => $payload,
            'created_by' => $user?->id,
            'updated_by' => $user?->id,
        ]));

        return response()->json([
            'message' => 'تم إنشاء تذكرتك بنجاح. رقم التذكرة: '.$number,
            'ticket_number' => $number,
            'data' => $this->ticket($record),
        ], 201);
    }

    private function owned(Request $request, int $ticket): PlatformRecord
    {
        return PlatformRecord::query()
            ->whereKey($ticket)
            ->where('resource', 'support')
            ->where('payload->requester_user_id', (int) $request->user()->id)
            ->firstOrFail();
    }

    private function ticket(PlatformRecord $record): array
    {
        return array_merge([
            'id' => $record->id,
            'status' => $record->status,
            'created_at' => $record->created_at?->toISOString(),
            'updated_at' => $record->updated_at?->toISOString(),
        ], $record->payload ?? []);
    }

    private function number(): string
    {
        do {
            $number = 'ZADSUP-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
        } while (PlatformRecord::query()->where('resource', 'support')->where('external_key', $number)->exists());
        return $number;
    }

    private function category(string $module): string
    {
        return match ($module) {
            'change-requests' => 'طلب تعديل بيانات',
            'settings' => 'طلب إعدادات',
            'account-deletion' => 'حذف الحساب',
            default => 'دعم فني',
        };
    }
}