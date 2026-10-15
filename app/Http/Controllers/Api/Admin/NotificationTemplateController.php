<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificationTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = NotificationTemplate::query()->orderBy('type')->orderBy('key')->orderBy('locale');
        if ($request->filled('type')) {
            $request->validate(['type' => [Rule::in(['transactional', 'marketing'])]]);
            $query->where('type', $request->string('type'));
        }

        return response()->json(['data' => $query->get()->map(fn ($template) => $this->data($template))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()->id;
        $data['updated_by'] = $request->user()->id;
        $template = NotificationTemplate::query()->create($data);

        return response()->json(['message' => 'Notification template created.', 'data' => $this->data($template)], 201);
    }

    public function show(NotificationTemplate $notificationTemplate): JsonResponse
    {
        return response()->json(['data' => $this->data($notificationTemplate)]);
    }

    public function update(Request $request, NotificationTemplate $notificationTemplate): JsonResponse
    {
        $data = $this->validated($request, $notificationTemplate);
        $data['updated_by'] = $request->user()->id;
        $notificationTemplate->update($data);

        return response()->json(['message' => 'Notification template updated.', 'data' => $this->data($notificationTemplate->fresh())]);
    }

    public function destroy(NotificationTemplate $notificationTemplate): JsonResponse
    {
        abort_if(
            $notificationTemplate->campaigns()->exists() || $notificationTemplate->key === 'phone_verified_welcome',
            409,
            'Templates referenced by notifications cannot be deleted; deactivate them instead.',
        );
        $notificationTemplate->delete();

        return response()->json(['message' => 'Notification template deleted.']);
    }

    private function validated(Request $request, ?NotificationTemplate $template = null): array
    {
        $required = $template ? 'sometimes' : 'required';

        return $request->validate([
            'key' => [$required, 'string', 'max:100', 'regex:/^[a-z0-9_\-]+$/', Rule::unique('notification_templates')->where(fn ($query) => $query->where('locale', $request->input('locale', $template?->locale ?? 'ar')))->ignore($template?->id)],
            'type' => [$required, Rule::in(['transactional', 'marketing'])],
            'locale' => [$required, Rule::in(['ar', 'en'])],
            'title' => [$required, 'string', 'max:180'],
            'body' => [$required, 'string', 'max:5000'],
            'default_channel' => [$required, Rule::in(['in_app', 'push', 'both'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function data(NotificationTemplate $template): array
    {
        return [
            'id' => $template->id,
            'key' => $template->key,
            'type' => $template->type,
            'locale' => $template->locale,
            'title' => $template->title,
            'body' => $template->body,
            'default_channel' => $template->default_channel,
            'is_active' => $template->is_active,
            'created_at' => $template->created_at?->toISOString(),
            'updated_at' => $template->updated_at?->toISOString(),
        ];
    }
}
