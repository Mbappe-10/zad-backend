<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Sanctum writes last_used_at on every request: one extra write to the database
 * per click. Writing it at most every 5 minutes keeps it useful and fast.
 */
class PersonalAccessToken extends SanctumToken
{
    protected $table = 'personal_access_tokens';

    public function save(array $options = []): bool
    {
        $dirty = array_keys($this->getDirty());
        if ($this->exists && $dirty === ['last_used_at']) {
            $previous = $this->getOriginal('last_used_at');
            if ($previous && now()->diffInSeconds($previous, true) < 300) {
                $this->syncOriginal();

                return true;
            }
        }

        return parent::save($options);
    }
}
