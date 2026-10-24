<?php

namespace App\Services\App;

/**
 * Fail-closed policy for NEW production postpay orders only.
 * IMPORTANT: matchesProviderPayment() must receive data obtained through
 * a server-to-Moyasar API call, never raw client or webhook JSON.
 */
final class MoyasarV5LivePaymentPolicy
{
    public static function hasLiveCredentials(string $environment, string $publishableKey, string $secretKey): bool
    {
        return $environment === 'production'
            && str_starts_with(trim($publishableKey), 'pk_live_')
            && str_starts_with(trim($secretKey), 'sk_live_');
    }

    public static function matchesProviderPayment(array $payment, int $orderId, int $amountHalalas): bool
    {
        $id = trim((string) ($payment['id'] ?? ''));
        $metadata = $payment['metadata'] ?? null;
        return $orderId > 0 && $amountHalalas > 0
            && $id !== '' && ! str_starts_with(strtolower($id), 'local_')
            && (string) ($payment['status'] ?? '') === 'paid'
            && is_numeric($payment['amount'] ?? null)
            && (int) $payment['amount'] === $amountHalalas
            && strtoupper((string) ($payment['currency'] ?? '')) === 'SAR'
            && is_array($metadata)
            && (string) ($metadata['order_id'] ?? '') === (string) $orderId;
    }
}
