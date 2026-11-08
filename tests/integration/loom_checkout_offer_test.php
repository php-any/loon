<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/framework/bootstrap.php';

function checkoutOfferCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$app = (static function () { return require dirname(__DIR__, 2) . '/loom.php'; })();
try {
    $offer = $app->services->get(\App\Services\Customers\CheckoutOffer::class);
    checkoutOfferCheck($offer->name() === 'Loom 体验套餐', 'Offer name comes from config/checkout.php');
    checkoutOfferCheck($offer->amount() === 9900, 'Offer amount comes from config/checkout.php');
    checkoutOfferCheck($offer->price() === '¥99.00', 'Factory formats the display price once');
    checkoutOfferCheck($offer->notice('小明', 'ming@example.test', 'SIM1')['body'] === '用户 小明（ming@example.test）的订单 SIM1 模拟支付成功，金额 ¥99.00（未实际扣款）。', 'Notice uses the formatted price');
    $app->services->withScope(static function (\Loom\ServiceScope $scope) use ($offer): void {
        checkoutOfferCheck($scope->get(\App\Services\Customers\CheckoutOffer::class) === $offer, 'Scopes share the singleton offer');
        $request = new \Loom\Request(['method' => 'GET', 'path' => '/', 'headers' => [], 'query' => [], 'body' => '']);
        $home = $scope->get(\App\Http\Controllers\HomeController::class)($request)->toArray()['body'];
        $account = $scope->get(\App\Http\Controllers\CustomerController::class)->page($request)->toArray()['body'];
        checkoutOfferCheck(str_contains($home, 'Loom 体验套餐') && str_contains($home, '¥99.00'), 'Home page shows the configured offer');
        checkoutOfferCheck(str_contains($account, '确认模拟支付 ¥99.00'), 'Account page shows the configured price');
    });
    $failed = false;
    try {
        \App\Services\Customers\CheckoutOffer::from(['name' => '', 'amount' => 9900]);
    } catch (LogicException $error) {
        $failed = str_contains($error->getMessage(), 'checkout.name');
    }
    checkoutOfferCheck($failed, 'Empty package name is rejected');
    echo "loom_checkout_offer_test: OK\n";
} finally {
    $app->dispose();
}
