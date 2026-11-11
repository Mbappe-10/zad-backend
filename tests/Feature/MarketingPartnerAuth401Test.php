<?php

namespace Tests\Feature;

use Tests\TestCase;

class MarketingPartnerAuth401Test extends TestCase
{
    public function test_unauthenticated_partner_coupons_return_401(): void
    {
        $this->get('/api/admin/partner/coupons')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_unauthenticated_partner_coupons_with_json_header_return_401(): void
    {
        $this->getJson('/api/admin/partner/coupons')
            ->assertStatus(401);
    }

    public function test_unauthenticated_partner_report_returns_401(): void
    {
        $this->get('/api/admin/partner/coupons/1/report')
            ->assertStatus(401);
    }
}