<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\Notifications\PrepareNotificationCampaignJob;
use App\Models\NotificationCampaign;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Notifications\FcmClient;
use App\Services\Notifications\NotificationAudienceService;
use App\Services\Notifications\NotificationPayloadPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class NotificationCampaignController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:180'],
            'status' => ['nullable', 'string', 'max:30'],
            'channel' => ['nullable', Rule::in(['in_app', 'push', 'both'])],
            'audience' => ['nullable', Rule::in(['all', 'customers', 'families', 'drivers', 'admins', 'guests', 'selected', 'custom'])],
            'type' => ['nullable', Rule::in(['transactional', 'marketing'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $query = NotificationCampaign::query()->latest();
        if ($search = trim((string) ($validated['search'] ?? ''))) {
            $query->where(fn (Builder $builder) => $builder
                ->where('title', 'like', "%{$search}%")
                ->orWhere('body', 'like', "%{$search}%"));
        }
        if ($status = $validated['status'] ?? null) {
            $query->when(
                $status === 'sent',
                fn (Builder $builder) => $builder->whereIn('status', ['completed', 'partially_failed']),
                fn (Builder $builder) => $builder->where('status', $status),
            );
        }
        foreach (['channel', 'audience', 'type'] as $field) {
            if (! empty($validated[$field])) {
                $value = $field === 'audience' && $validated[$field] === 'custom'
                    ? 'selected'
                    : $validated[$field];
                $query->where($field, $value);
            }
        }

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 15));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (NotificationCampaign $campaign) => $this->campaignData($campaign))->values(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ]);
    }

    public function store(
        Request $request,
        NotificationPayloadPolicy $payloadPolicy,
    ): JsonResponse {
        $data = $this->validatedCampaign($request, $payloadPolicy);
        $sendNow = (bool) ($data['send_now'] ?? false);
        unset($data['send_now']);

        $campaign = $this->createCampaign($request, $data);
        if ($sendNow || $campaign->scheduled_at !== null) {
            $this->queueCampaign($campaign);
        }

        return response()->json([
            'message' => $campaign->status === 'draft'
                ? 'Notification draft saved.'
                : 'Notification campaign queued.',
            'data' => $this->campaignData($campaign->fresh()),
        ], 201);
    }

    public function show(NotificationCampaign $notification): JsonResponse
    {
        return response()->json(['data' => $this->campaignData($notification)]);
    }

    public function update(
        Request $request,
        NotificationCampaign $notification,
        NotificationPayloadPolicy $payloadPolicy,
    ): JsonResponse {
        abort_unless(in_array($notification->status, ['draft', 'scheduled'], true), 409, 'Only draft or scheduled notifications can be edited.');

        $data = $this->validatedCampaign($request, $payloadPolicy, true);
        $sendNow = (bool) ($data['send_now'] ?? false);
        unset($data['send_now']);
        $data = $this->applyTemplate($data, $notification);
        $this->assertTransactionalScope($data);

        $notification->fill($data);
        $notification->status = $notification->scheduled_at && $notification->scheduled_at->isFuture()
            ? 'scheduled'
            : 'draft';
        $notification->save();

        if ($sendNow || $notification->status === 'scheduled') {
            $this->queueCampaign($notification);
        }

        return response()->json([
            'message' => 'Notification updated.',
            'data' => $this->campaignData($notification->fresh()),
        ]);
    }

    public function destroy(NotificationCampaign $notification): JsonResponse
    {
        abort_unless(in_array($notification->status, ['draft', 'scheduled', 'cancelled'], true), 409, 'Queued or completed notification history cannot be deleted.');
        $notification->forceFill(['status' => 'cancelled'])->save();
        $notification->delete();

        return response()->json(['message' => 'Notification draft cancelled and removed.']);
    }

    public function send(NotificationCampaign $notification): JsonResponse
    {
        $this->queueCampaign($notification, true);

        return response()->json([
            'message' => 'Notification campaign accepted for queued processing.',
            'data' => $this->campaignData($notification->fresh()),
        ], 202);
    }

    public function preview(
        NotificationCampaign $notification,
        NotificationAudienceService $audiences,
        FcmClient $fcm,
    ): JsonResponse {
        return response()->json([
            'data' => [
                'notification' => $this->campaignData($notification),
                'estimated' => $audiences->preview($notification),
                'fcm' => $fcm->configurationStatus(),
                'delivery_note' => 'FCM acceptance is tracked separately; device delivery is not claimed.',
            ],
        ]);
    }

    public function previewDraft(
        Request $request,
        NotificationPayloadPolicy $payloadPolicy,
        NotificationAudienceService $audiences,
        FcmClient $fcm,
    ): JsonResponse {
        $data = $this->validatedCampaign($request, $payloadPolicy);
        unset($data['send_now']);
        $data = $this->applyTemplate($data);
        $this->assertTransactionalScope($data);
        $campaign = new NotificationCampaign($data);

        return response()->json([
            'data' => [
                'notification' => $this->campaignData($campaign),
                'estimated' => $audiences->preview($campaign),
                'fcm' => $fcm->configurationStatus(),
                'delivery_note' => 'FCM acceptance is tracked separately; device delivery is not claimed.',
            ],
        ]);
    }

    public function testSend(Request $request, NotificationCampaign $notification): JsonResponse
    {
        $validated = $request->validate([
            'recipient_user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('status', 'active')],
            'channel' => ['nullable', Rule::in(['in_app', 'push', 'both'])],
        ]);

        $test = NotificationCampaign::query()->create([
            'template_id' => $notification->template_id,
            'type' => $notification->type,
            'locale' => $notification->locale,
            'title' => '[TEST] '.$notification->title,
            'body' => $notification->body,
            'channel' => $validated['channel'] ?? $notification->channel,
            'audience' => 'selected',
            'audience_ids' => [(int) $validated['recipient_user_id']],
            'image_url' => $notification->image_url,
            'action_url' => $notification->action_url,
            'data' => ['test_send' => 'true'],
            'status' => 'draft',
            'idempotency_key' => (string) Str::uuid(),
            'requested_by' => $request->user()->id,
        ]);

        $this->queueCampaign($test, true);

        return response()->json([
            'message' => 'Single-account test notification queued.',
            'data' => $this->campaignData($test->fresh()),
        ], 202);
    }

    public function history(Request $request, NotificationCampaign $notification): JsonResponse
    {
        $recipients = $notification->recipients()
            ->with(['pushAttempts:id,recipient_id,status,attempts,provider_message_id,error_code,error_message,accepted_at,failed_at'])
            ->latest('id')
            ->paginate(min(max((int) $request->input('per_page', 25), 1), 100));

        return response()->json([
            'data' => [
                'campaign' => $this->campaignData($notification->fresh()),
                'recipients' => collect($recipients->items())->map(fn ($recipient) => [
                    'id' => $recipient->id,
                    'user_id' => $recipient->user_id,
                    'guest' => $recipient->guest_session_id !== null,
                    'audience_role' => $recipient->audience_role,
                    'status' => $recipient->status,
                    'in_app_stored_at' => $recipient->in_app_stored_at?->toISOString(),
                    'push_devices_count' => $recipient->push_devices_count,
                    'fcm_accepted_count' => $recipient->fcm_accepted_count,
                    'push_failed_count' => $recipient->push_failed_count,
                    'opened_at' => $recipient->opened_at?->toISOString(),
                    'attempts' => $recipient->pushAttempts->map(fn ($attempt) => [
                        'status' => $attempt->status,
                        'attempts' => $attempt->attempts,
                        'provider_message_id' => $attempt->provider_message_id,
                        'error_code' => $attempt->error_code,
                        'error_message' => $attempt->error_message,
                        'fcm_accepted_at' => $attempt->accepted_at?->toISOString(),
                        'failed_at' => $attempt->failed_at?->toISOString(),
                    ])->values(),
                ])->values(),
                'pagination' => [
                    'current_page' => $recipients->currentPage(),
                    'last_page' => $recipients->lastPage(),
                    'per_page' => $recipients->perPage(),
                    'total' => $recipients->total(),
                ],
                'semantics' => [
                    'fcm_accepted' => 'FCM accepted the request; this is not proof of device delivery.',
                    'opened' => 'The application reported that the in-app notification was opened.',
                ],
            ],
        ]);
    }

    private function createCampaign(Request $request, array $data): NotificationCampaign
    {
        $data = $this->applyTemplate($data);
        $this->assertTransactionalScope($data);
        $data['audience'] = ($data['audience'] ?? 'all') === 'custom' ? 'selected' : ($data['audience'] ?? 'all');
        $data['status'] = ! empty($data['scheduled_at']) ? 'scheduled' : 'draft';
        $data['idempotency_key'] = $data['idempotency_key'] ?? (string) Str::uuid();
        $data['requested_by'] = $request->user()->id;

        return NotificationCampaign::query()->firstOrCreate(
            ['idempotency_key' => $data['idempotency_key']],
            $data,
        );
    }

    private function validatedCampaign(
        Request $request,
        NotificationPayloadPolicy $payloadPolicy,
        bool $updating = false,
    ): array {
        $sometimes = $updating ? 'sometimes' : 'nullable';
        $data = $request->validate([
            'template_id' => [$sometimes, 'nullable', 'integer', 'exists:notification_templates,id'],
            'type' => [$sometimes, Rule::in(['transactional', 'marketing'])],
            'locale' => [$sometimes, Rule::in(['ar', 'en'])],
            'title' => [$sometimes, 'nullable', 'string', 'max:180'],
            'body' => [$sometimes, 'nullable', 'string', 'max:5000'],
            'channel' => [$sometimes, Rule::in(['in_app', 'push', 'both'])],
            'audience' => [$sometimes, Rule::in(['all', 'customers', 'families', 'drivers', 'admins', 'guests', 'selected', 'custom'])],
            'audience_ids' => [$sometimes, 'nullable', 'array', 'max:1000'],
            'audience_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'image_url' => [$sometimes, 'nullable', 'string', 'max:2048'],
            'action_url' => [$sometimes, 'nullable', 'string', 'max:2048'],
            'scheduled_at' => [$sometimes, 'nullable', 'date', 'after:now'],
            'send_now' => ['sometimes', 'boolean'],
            'idempotency_key' => ['sometimes', 'uuid'],
        ]);

        $payloadPolicy->assertSafe($data['image_url'] ?? null, $data['action_url'] ?? null);

        return $data;
    }

    private function applyTemplate(array $data, ?NotificationCampaign $existing = null): array
    {
        $templateId = $data['template_id'] ?? $existing?->template_id;
        if ($templateId) {
            $template = NotificationTemplate::query()->where('is_active', true)->findOrFail($templateId);
            $data['template_id'] = $template->id;
            $data['type'] = $template->type;
            $data['locale'] = $data['locale'] ?? $template->locale;
            $data['title'] = trim((string) ($data['title'] ?? $template->title));
            $data['body'] = trim((string) ($data['body'] ?? $template->body));
            $data['channel'] = $data['channel'] ?? $template->default_channel;
        } else {
            $data['type'] = $data['type'] ?? $existing?->type ?? 'marketing';
            $data['locale'] = $data['locale'] ?? $existing?->locale ?? 'ar';
            $data['channel'] = $data['channel'] ?? $existing?->channel ?? 'both';
        }

        $title = trim((string) ($data['title'] ?? $existing?->title));
        $body = trim((string) ($data['body'] ?? $existing?->body));
        if ($title === '' || $body === '') {
            throw ValidationException::withMessages([
                'title' => ['A title and message are required unless an active template supplies them.'],
            ]);
        }
        $data['title'] = $title;
        $data['body'] = $body;
        $data['audience'] = ($data['audience'] ?? $existing?->audience ?? 'all') === 'custom'
            ? 'selected'
            : ($data['audience'] ?? $existing?->audience ?? 'all');
        $data['audience_ids'] = $data['audience_ids'] ?? $existing?->audience_ids;

        if ($data['audience'] === 'selected' && empty($data['audience_ids'])) {
            throw ValidationException::withMessages(['audience_ids' => ['Select at least one recipient.']]);
        }
        if ($data['audience'] !== 'selected') {
            $data['audience_ids'] = null;
        }

        return $data;
    }

    private function assertTransactionalScope(array $data): void
    {
        if (($data['type'] ?? 'marketing') === 'transactional') {
            throw ValidationException::withMessages([
                'type' => ['Transactional templates are reserved for system events and cannot be sent from the marketing notification center.'],
            ]);
        }
    }

    private function queueCampaign(NotificationCampaign $campaign, bool $manual = false): void
    {
        $dispatch = false;
        $campaign = DB::transaction(function () use ($campaign, $manual, &$dispatch): NotificationCampaign {
            $locked = NotificationCampaign::query()->lockForUpdate()->findOrFail($campaign->id);

            if (in_array($locked->status, ['queued', 'processing', 'completed', 'partially_failed'], true)) {
                return $locked;
            }
            abort_if($locked->status === 'cancelled', 409, 'Cancelled campaigns cannot be sent.');
            abort_if($locked->status === 'failed', 409, 'Create a new campaign instead of replaying a failed campaign.');

            if ($manual) {
                $locked->scheduled_at = null;
            }
            $locked->status = $locked->scheduled_at?->isFuture() ? 'scheduled' : 'queued';
            $locked->queued_at = now();
            $locked->save();
            $dispatch = true;

            return $locked;
        });

        if (! $dispatch) {
            return;
        }

        $pending = PrepareNotificationCampaignJob::dispatch($campaign->id)
            ->onQueue((string) config('notifications.queue', 'notifications'));
        if ($campaign->scheduled_at?->isFuture()) {
            $pending->delay($campaign->scheduled_at);
        }
    }

    private function campaignData(NotificationCampaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'template_id' => $campaign->template_id,
            'type' => $campaign->type,
            'locale' => $campaign->locale,
            'title' => $campaign->title,
            'body' => $campaign->body,
            'channel' => $campaign->channel,
            'audience' => $campaign->audience,
            'audience_ids' => $campaign->audience_ids,
            'status' => $campaign->status,
            'scheduled_at' => $campaign->scheduled_at?->toISOString(),
            'queued_at' => $campaign->queued_at?->toISOString(),
            'sent_at' => $campaign->completed_at?->toISOString(),
            'completed_at' => $campaign->completed_at?->toISOString(),
            'recipients_count' => $campaign->recipients_count ?? 0,
            'in_app_count' => $campaign->in_app_count ?? 0,
            'fcm_accepted_count' => $campaign->fcm_accepted_count ?? 0,
            'delivered_count' => 0,
            'failed_count' => $campaign->failed_count ?? 0,
            'opened_count' => $campaign->opened_count ?? 0,
            'clicked_count' => 0,
            'failure_summary' => $campaign->failure_summary,
            'image_url' => $campaign->image_url,
            'action_url' => $campaign->action_url,
            'created_at' => $campaign->created_at?->toISOString(),
            'updated_at' => $campaign->updated_at?->toISOString(),
            'delivery_tracking_available' => false,
        ];
    }
}
