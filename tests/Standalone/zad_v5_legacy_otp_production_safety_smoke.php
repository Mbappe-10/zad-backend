<?php

declare(strict_types=1);

// Framework-free local checks. Full Laravel/DB/provider tests remain outstanding.
$policyFile = $argv[1] ?? '';
$controllerFile = $argv[2] ?? '';
if (! is_file($policyFile) || ! is_file($controllerFile)) {
    fwrite(STDERR, "FAIL - Missing files\n");
    exit(2);
}
require $policyFile;
use App\Services\PhoneVerification\LegacyInternalOtpPolicy;
$cases = [
    ['production', false, false], ['production', true, false],
    ['staging', false, false], ['staging', true, false],
    ['local', false, false], ['local', true, true],
    ['testing', false, false], ['testing', true, true],
    ['unknown', true, false], ['PRODUCTION', true, false],
];
foreach ($cases as [$environment, $optIn, $expected]) {
    if (LegacyInternalOtpPolicy::allowsInternalOtp($environment, $optIn) !== $expected) {
        fwrite(STDERR, "FAIL - Environment policy regression: $environment\n");
        exit(3);
    }
}
echo 'PASS - 10 LEGACY OTP ENVIRONMENT POLICY CASES' . PHP_EOL;
$source = file_get_contents($controllerFile);
$send = strpos($source, 'public function send(Request $request)');
$guard = strpos($source, 'LegacyInternalOtpPolicy::allowsInternalOtp(', $send !== false ? $send : 0);
$validation = strpos($source, '$request->validate(', $send !== false ? $send : 0);
$mint = strpos($source, 'random_int(', $send !== false ? $send : 0);
$issue = strpos($source, 'PhoneVerification::query()', $send !== false ? $send : 0);
if ($send === false || $guard === false || $validation === false || $mint === false || $issue === false
    || !($send < $guard && $guard < $validation && $guard < $mint && $guard < $issue)) {
    fwrite(STDERR, "FAIL - Guard must precede validation, minting, and database writes\n");
    exit(4);
}
if (strpos($source, "'code' => 'legacy_internal_otp_disabled'", $guard) === false) {
    fwrite(STDERR, "FAIL - Explicit blocked response missing\n");
    exit(5);
}
echo 'PASS - LEGACY SEND FAILS CLOSED BEFORE OTP GENERATION (STATIC CHECK)' . PHP_EOL;
