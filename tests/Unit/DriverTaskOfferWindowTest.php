<?php

namespace Tests\Unit;

use App\Services\DriverTaskOfferService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class DriverTaskOfferWindowTest extends TestCase
{
    public function test_twenty_second_offer_boundaries(): void
    {
        $start = CarbonImmutable::parse('2026-10-11T00:00:00Z');
        self::assertSame('2026-10-11T00:00:20+00:00', DriverTaskOfferService::windowExpiresAt($start, $start)->toIso8601String());
        self::assertSame('2026-10-11T00:00:20+00:00', DriverTaskOfferService::windowExpiresAt($start, $start->addSeconds(19))->toIso8601String());
        self::assertSame('2026-10-11T00:00:40+00:00', DriverTaskOfferService::windowExpiresAt($start, $start->addSeconds(20))->toIso8601String());
    }
}
