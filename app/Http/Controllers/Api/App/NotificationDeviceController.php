<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\NotificationDeviceToken;
use App\Models\NotificationPreference;
use App\Services\Notifications\NotificationActor;
use App\Services\Notifications\NotificationActorResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class NotificationDeviceController extends Controller
{
    public function store(Request $request, NotificationActorResolver $actors): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:4096'],
            'platform' => ['required', Rule::in(['android', 'ios', 'web'])],
            'device_id' => ['nullable', 'string', 'max:191'],
            'app_version' => ['nullable', 'string', 'max:50'],
            'locale' => ['nullable', Rule::in(['ar', 'en'])],
            'permission_granted' => ['required', 'boolean'],
            'push_enabled' => ['sometimes', 'boolean'],
            'marketing_enabled' => ['sometimes', 'boolean'],
        ]);
        $actor = $actors->resolve($request);
        $tokenHash = hash('sha256', $data['token']);

        $device = DB::transaction(function () use ($actor, $data, $tokenHash): NotificationDeviceToken {
            $device = NotificationDeviceToken::query()->where('token_hash', $tokenHash)->lockForUpdate()->first();
            if ($device && ! $this->sameOwner($device, $actor) && $actor->user === null) {
                abort(409, 'This push token is already registered to another account.');
            }
            $device ??= new NotificationDeviceToken(['token_hash' => $tokenHash]);

            $device->fill([
                'user_id' => $actor->user?->id,
                'guest_session_id' => $actor->user ? null : $actor->guest?->id,
                'token_ciphertext' => $data['token'],
                'device_id_hash' => empty($data['device_id']) ? null : hash('sha256', $data['device_id']),
                'platform' => $data['platform'],
                'app_version' => $data['app_version'] ?? null,
                'locale' => $data['locale'] ?? 'ar',
                'permission_granted' => (bool) $data['permission_granted'],
                'push_enabled' => (bool) ($data['push_enabled'] ?? true),
                'marketing_enabled' => array_key_exists('marketing_enabled', $data)
                    ? (bool) $data['marketing_enabled']
                    : (bool) ($device->marketing_enabled ?? false),
                'last_seen_at' => now(),
                'revoked_at' => null,
            ]);
            $device->save();

            $preference = NotificationPreference::query()->firstOrNew(['actor_key' => $actor->key()]);
            $preference->fill([
                'user_id' => $actor->user?->id,
                'guest_session_id' => $actor->guest?->id,
                'in_app_enabled' => $preference->exists ? $preference->in_app_enabled : true,
                'push_enabled' => (bool) ($data['push_enabled'] ?? true),
                'marketing_enabled' => array_key_exists('marketing_enabled', $data)
                    ? (bool) $data['marketing_enabled']
                    : (bool) ($preference->marketing_enabled ?? false),
            ]);
            $preference->save();

            return $device;
        }, 3);

        return response()->json(['data' => $this->deviceData($device)], 201);
    }

    public function update(
        Request $request,
        NotificationDeviceToken $device,
        NotificationActorResolver $actors,
    ): JsonResponse {
        $data = $request->validate([
            'token' => ['sometimes', 'string', 'min:20', 'max:4096'],
            'platform' => ['sometimes', Rule::in(['android', 'ios', 'web'])],
            'device_id' => ['sometimes', 'nullable', 'string', 'max:191'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:50'],
            'locale' => ['sometimes', Rule::in(['ar', 'en'])],
            'permission_granted' => ['sometimes', 'boolean'],
            'push_enabled' => ['sometimes', 'boolean'],
            'marketing_enabled' => ['sometimes', 'boolean'],
        ]);
        $actor = $actors->resolve($request);
        $this->authorizeOwner($device, $actor);

        DB::transaction(function () use ($device, $actor, $data): void {
            $locked = NotificationDeviceToken::query()->lockForUpdate()->findOrFail($device->id);
            if (isset($data['token'])) {
                $hash = hash('sha256', $data['token']);
                $conflict = NotificationDeviceToken::query()
                    ->where('token_hash', $hash)
                    ->whereKeyNot($locked->id)
                    ->exists();
                abort_if($conflict, 409, 'This push token is already registered.');
                $locked->token_hash = $hash;
                $locked->token_ciphertext = $data['token'];
            }

            foreach (['platform', 'app_version', 'locale', 'permission_granted', 'push_enabled', 'marketing_enabled'] as $field) {
                if (array_key_exists($field, $data)) {
                    $locked->{$field} = $data[$field];
                }
            }
            if (array_key_exists('device_id', $data)) {
                $locked->device_id_hash = empty($data['device_id']) ? null : hash('sha256', $data['device_id']);
            }
            $locked->last_seen_at = now();
            $locked->revoked_at = null;
            $locked->save();

            if (array_key_exists('push_enabled', $data) || array_key_exists('marketing_enabled', $data)) {
                $preference = NotificationPreference::query()->firstOrNew(['actor_key' => $actor->key()]);
                $preference->fill([
                    'user_id' => $actor->user?->id,
                    'guest_session_id' => $actor->guest?->id,
                    'in_app_enabled' => $preference->exists ? $preference->in_app_enabled : true,
                    'push_enabled' => $data['push_enabled'] ?? ($preference->push_enabled ?? true),
                    'marketing_enabled' => $data['marketing_enabled'] ?? ($preference->marketing_enabled ?? false),
                ])->save();
            }
        }, 3);

        return response()->json(['data' => $this->deviceData($device->fresh())]);
    }

    public function destroy(
        Request $request,
        NotificationDeviceToken $device,
        NotificationActorResolver $actors,
    ): JsonResponse {
        $actor = $actors->resolve($request);
        $this->authorizeOwner($device, $actor);
        $device->forceFill([
            'token_ciphertext' => null,
            'permission_granted' => false,
            'push_enabled' => false,
            'revoked_at' => now(),
        ])->save();

        return response()->json(['message' => 'Push token revoked.']);
    }

    /** Revoke the current FCM token without requiring its database ID on the device. */
    public function destroyCurrent(Request $request, NotificationActorResolver $actors): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'min:20', 'max:4096']]);
        $actor = $actors->resolve($request);
        $device = NotificationDeviceToken::query()
            ->where('token_hash', hash('sha256', $data['token']))->first();
        if ($device) {
            $this->authorizeOwner($device, $actor);
            $device->forceFill([
                'token_ciphertext' => null,
                'permission_granted' => false,
                'push_enabled' => false,
                'revoked_at' => now(),
            ])->save();
        }
        return response()->json(['revoked' => true]);
    }

    private function sameOwner(NotificationDeviceToken $device, NotificationActor $actor): bool
    {
        return $actor->user
            ? (int) $device->user_id === (int) $actor->user->id
            : (string) $device->guest_session_id === (string) $actor->guest?->id;
    }

    private function authorizeOwner(NotificationDeviceToken $device, NotificationActor $actor): void
    {
        abort_unless($this->sameOwner($device, $actor), 404);
    }

    private function deviceData(NotificationDeviceToken $device): array
    {
        return [
            'id' => $device->id,
            'platform' => $device->platform,
            'app_version' => $device->app_version,
            'locale' => $device->locale,
            'permission_granted' => $device->permission_granted,
            'push_enabled' => $device->push_enabled,
            'marketing_enabled' => $device->marketing_enabled,
            'last_seen_at' => $device->last_seen_at?->toISOString(),
            'revoked' => $device->revoked_at !== null,
        ];
    }
}
