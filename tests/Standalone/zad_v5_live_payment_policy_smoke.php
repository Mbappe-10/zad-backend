<?php
require dirname(__DIR__, 2).'/app/Services/App/MoyasarV5LivePaymentPolicy.php';
use App\Services\App\MoyasarV5LivePaymentPolicy as P;
function must(bool $actual, string $label): void {
    if (!$actual) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
    echo "PASS: {$label}\n";
}
$paid = ['id'=>'real_provider_id','status'=>'paid','amount'=>1500,'currency'=>'SAR','metadata'=>['order_id'=>'27']];
must(P::hasLiveCredentials('production','pk_live_example','sk_live_example'), 'production live credentials');
must(!P::hasLiveCredentials('production','pk_test_example','sk_live_example'), 'reject test publishable key');
must(!P::hasLiveCredentials('production','pk_live_example','sk_test_example'), 'reject test secret');
must(!P::hasLiveCredentials('staging','pk_live_example','sk_live_example'), 'reject staging live credentials');
must(P::matchesProviderPayment($paid,27,1500), 'matching provider payment');
must(!P::matchesProviderPayment([...$paid,'status'=>'initiated'],27,1500), 'reject pending payment');
must(!P::matchesProviderPayment([...$paid,'amount'=>1400],27,1500), 'reject wrong amount');
must(!P::matchesProviderPayment([...$paid,'currency'=>'USD'],27,1500), 'reject wrong currency');
must(!P::matchesProviderPayment([...$paid,'metadata'=>['order_id'=>'28']],27,1500), 'reject wrong order');
must(!P::matchesProviderPayment([...$paid,'id'=>'local_fake'],27,1500), 'reject local simulation');
must(!P::matchesProviderPayment([...$paid,'id'=>''],27,1500), 'reject missing provider ID');
echo "PASS - 11 LIVE PAYMENT POLICY CHECKS\n";
