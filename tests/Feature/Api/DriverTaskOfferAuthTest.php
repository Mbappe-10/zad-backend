<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class DriverTaskOfferAuthTest extends TestCase
{
    public function test_anonymous_user_cannot_access_or_claim_driver_offers(): void
    {
        $this->getJson('/api/v1/app/driver/task-offers')->assertUnauthorized();
        $this->postJson('/api/v1/app/driver/task-offers/1/accept')->assertUnauthorized();
        $this->postJson('/api/v1/app/driver/task-offers/1/decline')->assertUnauthorized();
    }
}
