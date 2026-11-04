<?php

declare(strict_types=1);

namespace App\Services\Customers;

/**
 * 一次启动时定下来的体验套餐。金额和展示价在工厂里算好，请求期间不再读取配置。
 */
final class CheckoutOffer
{
    private string $name;
    private int $amount;
    private string $price;

    private function __construct(string $name, int $amount, string $price)
    {
        $this->name = $name;
        $this->amount = $amount;
        $this->price = $price;
    }

    /**
     * 校验配置并生成展示价。名称或金额不合法时抛出 LogicException。
     *
     * @param array $config checkout 配置节，需包含 name 和 amount。
     */
    public static function from(array $config): self
    {
        $name = $config['name'] ?? null;
        $amount = $config['amount'] ?? null;
        if (!is_string($name) || trim($name) === '' || strlen($name) > 40) {
            throw new \LogicException('checkout.name must be a non-empty string');
        }
        if (!is_int($amount) || $amount < 1 || $amount > 100000000) {
            throw new \LogicException('checkout.amount must be a positive integer in cents');
        }
        $price = '¥' . intdiv($amount, 100) . '.' . str_pad((string)($amount % 100), 2, '0', STR_PAD_LEFT);
        return new self($name, $amount, $price);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function amount(): int
    {
        return $this->amount;
    }

    public function price(): string
    {
        return $this->price;
    }

    public function notice(string $customer, string $email, string $number): array
    {
        return [
            'title' => '用户模拟支付成功',
            'body' => '用户 ' . $customer . '（' . $email . '）的订单 ' . $number . ' 模拟支付成功，金额 ' . $this->price . '（未实际扣款）。',
        ];
    }
}
