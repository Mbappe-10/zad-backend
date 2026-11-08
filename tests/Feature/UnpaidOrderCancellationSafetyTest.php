<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\App\UnpaidOrderCancellationController;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UnpaidOrderCancellationSafetyTest extends TestCase
{
    #[Test]
    public function cancellation_keeps_guest_ownership_check(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2).'/app/Http/Controllers/Api/App/UnpaidOrderCancellationController.php'
        );

        $this->assertStringContainsString(
            '$this->access->assert($request, $order)',
            $source
        );
        $this->assertStringContainsString(
            'lockForUpdate()',
            $source
        );
    }

    #[Test]
    public function cancellation_rejects_active_provider_sessions(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2).'/app/Http/Controllers/Api/App/UnpaidOrderCancellationController.php'
        );

        $this->assertStringContainsString(
            "blank(\$attempt->provider_payment_id)",
            $source
        );
        $this->assertStringContainsString(
            '$state === \'ready\'',
            $source
        );
    }
}
