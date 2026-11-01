<?php

declare(strict_types=1);

namespace App\Console\Commands;

#[\Loom\Attributes\ConsoleCommand]
final class AdminMail extends \Loom\Console\Command
{
    private \App\Services\Identity\Account $account;

    public function __construct(\App\Services\Identity\Account $account)
    {
        $this->account = $account;
    }

    protected string $signature = 'admin:mail {--once : 只处理一封邮件}';
    protected string $description = '通过环境变量配置的 SMTP 服务发送账户邮件';

    public function handle(): int
    {
        do {
            $result = $this->account->deliver();
            if ($result['state'] !== 'empty') {
                $this->json($result);
            }
            if ($this->option('once')) {
                return in_array($result['state'], ['pending', 'failed'], true) ? 1 : 0;
            }
            sleep(2);
        } while (true);
    }
}
