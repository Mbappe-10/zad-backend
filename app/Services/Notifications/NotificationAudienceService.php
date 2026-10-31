<?php

namespace App\Services\Notifications;

use App\Models\AppGuestSession;
use App\Models\NotificationCampaign;
use App\Models\NotificationDeviceToken;
use App\Models\NotificationPreference;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class NotificationAudienceService
{
    /**
     * Streams eligible actors in bounded groups. The callback receives a list
     * of descriptors containing only internal IDs and the resolved audience role.
     */
    public function eachEligibleChunk(NotificationCampaign $campaign, Closure $callback): int
    {
        $chunkSize = max(25, min((int) config('notifications.chunk_size', 250), 1000));
        $chunks = 0;

        if ($campaign->audience !== 'guests') {
            $this->userQuery($campaign->audience, (array) $campaign->audience_ids)
                ->with(['appProfile:id,user_id,customer_id,productive_family_id,driver_id,active_mode', 'roles:id'])
                ->select(['id', 'name', 'name_ar', 'name_en', 'status', 'is_platform_owner'])
                ->chunkById($chunkSize, function (Collection $users) use ($campaign, $callback, &$chunks): void {
                    $descriptors = $this->eligibleUserDescriptors($campaign, $users);
                    if ($descriptors !== []) {
                        $callback($descriptors);
                        $chunks++;
                    }
                });
        }

        if (in_array($campaign->audience, ['all', 'guests'], true)) {
            $this->guestQuery($campaign)
                ->select(['app_guest_sessions.id'])
                ->chunkById($chunkSize, function (Collection $guests) use ($campaign, $callback, &$chunks): void {
                    $descriptors = $this->eligibleGuestDescriptors($campaign, $guests);
                    if ($descriptors !== []) {
                        $callback($descriptors);
                        $chunks++;
                    }
                }, 'app_guest_sessions.id', 'id');
        }

        return $chunks;
    }

    /** @return array<string, int|array<string, int>> */
    public function preview(NotificationCampaign $campaign): array
    {
        $counts = [
            'recipients' => 0,
            'authenticated_users' => 0,
            'anonymous_guests' => 0,
            'in_app_eligible' => 0,
            'push_eligible' => 0,
            'push_devices' => 0,
            'by_role' => [],
        ];

        $this->eachEligibleChunk($campaign, function (array $descriptors) use (&$counts): void {
            foreach ($descriptors as $descriptor) {
                $counts['recipients']++;
                $isGuest = isset($descriptor['guest_session_id']);
                $counts[$isGuest ? 'anonymous_guests' : 'authenticated_users']++;
                $counts['in_app_eligible'] += $descriptor['in_app'] ? 1 : 0;
                $counts['push_eligible'] += $descriptor['push_devices'] > 0 ? 1 : 0;
                $counts['push_devices'] += $descriptor['push_devices'];
                $role = $descriptor['audience_role'];
                $counts['by_role'][$role] = ($counts['by_role'][$role] ?? 0) + 1;
            }
        });

        return $counts;
    }

    public function userQuery(string $audience, array $audienceIds = []): Builder
    {
        $audience = $audience === 'custom' ? 'selected' : $audience;
        $query = User::query()->where('status', 'active');

        return match ($audience) {
            'customers' => $query->whereHas('appProfile', fn (Builder $builder) => $builder->whereNotNull('customer_id')),
            'families' => $query->whereHas('appProfile', fn (Builder $builder) => $builder->whereNotNull('productive_family_id')),
            'drivers' => $query->whereHas('appProfile', fn (Builder $builder) => $builder->whereNotNull('driver_id')),
            'admins' => $query->where(fn (Builder $builder) => $builder
                ->where('is_platform_owner', true)
                ->orWhereHas('roles')),
            'selected' => $query->whereKey(array_values(array_unique(array_map('intval', $audienceIds)))),
            'guests' => $query->whereRaw('1 = 0'),
            default => $query,
        };
    }

    public function roleForUser(User $user, string $audience = 'all'): string
    {
        $user->loadMissing(['appProfile', 'roles']);

        return $this->roleFor($user, $audience);
    }

    /** @return list<array<string, mixed>> */
    private function eligibleUserDescriptors(NotificationCampaign $campaign, Collection $users): array
    {
        $userIds = $users->pluck('id')->map(fn ($id) => (int) $id)->all();
        $preferences = NotificationPreference::query()
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy('user_id');
        $devices = NotificationDeviceToken::query()
            ->whereIn('user_id', $userIds)
            ->whereNull('revoked_at')
            ->where('permission_granted', true)
            ->where('push_enabled', true)
            ->when($campaign->type === 'marketing', fn (Builder $query) => $query->where('marketing_enabled', true))
            ->get(['id', 'user_id'])
            ->groupBy('user_id');

        $descriptors = [];
        foreach ($users as $user) {
            $preference = $preferences->get($user->id);
            if (! $this->marketingAllowed($campaign, $preference)) {
                continue;
            }

            $inApp = $this->includesInApp($campaign)
                && ($preference?->in_app_enabled ?? true);
            $pushDevices = $this->includesPush($campaign)
                && ($preference?->push_enabled ?? true)
                ? $devices->get($user->id, collect())->count()
                : 0;

            if (! $inApp && $pushDevices === 0) {
                continue;
            }

            $descriptors[] = [
                'user_id' => (int) $user->id,
                'recipient_key' => 'user:'.$user->id,
                'audience_role' => $this->roleFor($user, $campaign->audience),
                'in_app' => $inApp,
                'push_devices' => $pushDevices,
            ];
        }

        return $descriptors;
    }

    /** @return list<array<string, mixed>> */
    private function eligibleGuestDescriptors(NotificationCampaign $campaign, Collection $guests): array
    {
        $guestIds = $guests->pluck('id')->map(fn ($id) => (string) $id)->all();
        $preferences = NotificationPreference::query()
            ->whereIn('guest_session_id', $guestIds)
            ->get()
            ->keyBy('guest_session_id');
        $devices = NotificationDeviceToken::query()
            ->whereIn('guest_session_id', $guestIds)
            ->whereNull('revoked_at')
            ->where('permission_granted', true)
            ->where('push_enabled', true)
            ->when($campaign->type === 'marketing', fn (Builder $query) => $query->where('marketing_enabled', true))
            ->get(['id', 'guest_session_id'])
            ->groupBy('guest_session_id');

        $descriptors = [];
        foreach ($guests as $guest) {
            $preference = $preferences->get($guest->id);
            if (! $this->marketingAllowed($campaign, $preference)) {
                continue;
            }

            $inApp = $this->includesInApp($campaign)
                && ($preference?->in_app_enabled ?? true);
            $pushDevices = $this->includesPush($campaign)
                && ($preference?->push_enabled ?? true)
                ? $devices->get($guest->id, collect())->count()
                : 0;

            if (! $inApp && $pushDevices === 0) {
                continue;
            }

            $descriptors[] = [
                'guest_session_id' => (string) $guest->id,
                'recipient_key' => 'guest:'.$guest->id,
                'audience_role' => 'guests',
                'in_app' => $inApp,
                'push_devices' => $pushDevices,
            ];
        }

        return $descriptors;
    }

    private function guestQuery(NotificationCampaign $campaign): Builder
    {
        return AppGuestSession::query()
            ->whereNull('revoked_at')
            ->where('session_token_expires_at', '>', now())
            ->where(function (Builder $query) use ($campaign): void {
                if ($this->includesInApp($campaign)) {
                    $query->orWhereExists(function ($subQuery) use ($campaign): void {
                        $subQuery->selectRaw('1')
                            ->from('notification_preferences')
                            ->whereColumn('notification_preferences.guest_session_id', 'app_guest_sessions.id')
                            ->where('notification_preferences.in_app_enabled', true)
                            ->when($campaign->type === 'marketing', fn ($builder) => $builder->where('notification_preferences.marketing_enabled', true));
                    });
                }

                if ($this->includesPush($campaign)) {
                    $query->orWhereExists(function ($subQuery) use ($campaign): void {
                        $subQuery->selectRaw('1')
                            ->from('notification_device_tokens')
                            ->whereColumn('notification_device_tokens.guest_session_id', 'app_guest_sessions.id')
                            ->whereNull('notification_device_tokens.revoked_at')
                            ->where('notification_device_tokens.permission_granted', true)
                            ->where('notification_device_tokens.push_enabled', true)
                            ->when($campaign->type === 'marketing', fn ($builder) => $builder->where('notification_device_tokens.marketing_enabled', true));
                    });
                }
            });
    }

    private function roleFor(User $user, string $audience): string
    {
        if (in_array($audience, ['customers', 'families', 'drivers', 'admins'], true)) {
            return $audience;
        }

        $profile = $user->appProfile;
        if ($profile?->driver_id) {
            return 'drivers';
        }
        if ($profile?->productive_family_id) {
            return 'families';
        }
        if ($profile?->customer_id) {
            return 'customers';
        }

        return 'admins';
    }

    private function marketingAllowed(NotificationCampaign $campaign, ?NotificationPreference $preference): bool
    {
        return $campaign->type !== 'marketing' || $preference?->marketing_enabled === true;
    }

    private function includesInApp(NotificationCampaign $campaign): bool
    {
        return in_array($campaign->channel, ['in_app', 'both'], true);
    }

    private function includesPush(NotificationCampaign $campaign): bool
    {
        return in_array($campaign->channel, ['push', 'both'], true);
    }
}
