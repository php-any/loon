<?php

declare(strict_types=1);

namespace Loom;

/**
 * 框架账号的注册、登录和 Redis 会话。写请求还要核对 CSRF 和 Origin。会话是否有效看 MySQL 里的 auth_version。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $auth = new Auth($database, $requestContext);
 */
final class Auth
{
    private \Loom\Database $database;

    /**
     * 保存数据库和请求上下文。这里不读 Cookie，也不连接 Redis。
     *
     * @param \Loom\Database $database 以后读写用户和登录计数。
     * @param \Loom\RequestContext $requestContext 以后从当前请求取 Cookie、方法和头。
     * @return void 不返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $auth = new Auth($database, $requestContext);
     */
    public function __construct(\Loom\Database $database, \Loom\RequestContext $requestContext)
    {
        $this->database = $database;
        $this->requestContext = $requestContext;
    }

    private RequestContext $requestContext;
    /**
     * 生成会话或 CSRF 使用的随机令牌。
     *
     * 参数：无。
     *
     * @return string 32 字节随机数的 64 字符十六进制表示。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $token = $this->token();
     */
    private function token(): string
    {
        return bin2hex(random_bytes(32));
    }
    /**
     * 从当前请求 Cookie 头解析 loom_session 值。
     *
     * 参数：无。
     *
     * @return string 会话令牌；未提供时为空字符串。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $token = $this->session();
     */
    private function session(): string
    {
        foreach (explode(';', $this->requestContext->current()->header('cookie')) as $cookie) {
            $pair = explode('=', trim($cookie), 2);
            if ($pair[0] === 'loom_session') {
                return $pair[1] ?? '';
            }
        }
        return '';
    }
    /**
     * 通过 Redis 会话及数据库 auth_version 验证当前账号，拒绝过期或已撤销会话。
     *
     * 参数：无。
     *
     * @return array 含 id、name、email、csrf 的用户数组，不含密码或版本字段。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $user = $auth->user();
     */
    public function user(): array
    {
        $token = $this->session();
        if ($token === '') {
            throw new HttpError(401, '登录已过期，请重新登录');
        }
        $raw = \Loom\Redis::command(['GET', \Loom\Redis::key('session:' . hash('sha256', $token))]);
        if ($raw === null) {
            throw new HttpError(401, '登录已过期，请重新登录');
        }
        $session = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        $rows = Models\User::whereId($session['id'])->rows(['id', 'name', 'email', 'auth_version']);
        if ($rows === [] || (int)$rows[0]['auth_version'] !== (int)$session['version']) {
            throw new HttpError(401, '登录已过期，请重新登录');
        }
        $user = $rows[0];
        unset($user['auth_version']);
        $user['id'] = (int)$user['id'];
        $user['csrf'] = $session['csrf'];
        return $user;

    }
    /**
     * 严格比较 Origin 头和当前请求来源，缺失或不匹配时抛出 HTTP 403。
     *
     * 参数：无。
     *
     * @return void 无返回值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $auth->checkOrigin();
     */
    public function checkOrigin(): void
    {
        $request = $this->requestContext->current();
        if ($request->header('origin') !== $request->origin()) {
            throw new HttpError(403, '请求来源不匹配');
        }
    }
    /**
     * 取得当前用户，对非 GET/HEAD 请求额外校验 CSRF 令牌与同源。
     *
     * 参数：无。
     *
     * @return array 通过认证及必要写请求校验的用户数组。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $user = $auth->guard();
     */
    public function guard(): array
    {
        $user = $this->user();
        $request = $this->requestContext->current();
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            if (!hash_equals($user['csrf'], $request->header('x-csrf-token'))) {
                throw new HttpError(403, '请求校验失败，请刷新页面');
            }
            $this->checkOrigin();
        }
        return $user;
    }
    /**
     * 生成 HttpOnly、SameSite=Lax 的会话 Cookie；HTTPS 请求额外附加 Secure。
     *
     * @param string $token 会话原始令牌；清除 Cookie 时传空字符串。
     * @param int $age Cookie Max-Age，单位秒；0 表示立即过期。
     * @return string 可写入 Set-Cookie 的字符串。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $cookie = $this->cookie($token, 86400);
     */
    private function cookie(string $token, int $age): string
    {
        return 'loom_session=' . $token . '; Path=/; Max-Age=' . $age . '; HttpOnly; SameSite=Lax' . (str_starts_with($this->requestContext->current()->origin(), 'https://') ? '; Secure' : '');
    }
    /**
     * 验证请求后删除 Redis 会话，生成立即过期的 Cookie。
     *
     * 参数：无。
     *
     * @return array 仅含 cookie 的数组，调用方写入响应头。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $result = $auth->logout();
     */
    public function logout(): array
    {
        $this->guard();
        $this->deleteSession();
        return ['cookie' => $this->cookie('', 0)];
    }
    /**
     * 按当前会话令牌的 SHA-256 键删除 Redis 记录。
     *
     * 参数：无。
     *
     * @return void 无返回值；Redis 异常向上传播。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $this->deleteSession();
     */
    private function deleteSession(): void
    {
        \Loom\Redis::command(['DEL', \Loom\Redis::key('session:' . hash('sha256', $this->session()))]);
    }
    /**
     * 递增账号认证版本，使全部旧会话失效，不依赖 Redis 当前是否可用。
     *
     * @param int $id 要撤销会话的框架用户主键。
     * @return void 无返回值，参与当前数据库连接事务。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $auth->revoke(1);
     */
    public function revoke(int $id): void
    {
        // 即使 Redis 不可用，版本撤销也参与密码修改或禁用账户的事务。
        Models\User::revokeSessions($this->database, $id);
    }
    /**
     * 校验同源、账号输入和频率后注册用户，密码散列保存并创建新会话。
     *
     * @param string $name 显示名。本方法先 trim，空串或超过 80 字节会在注册分支被拒绝。
     * @param string $email 要注册的邮箱。
     * @param string $password 明文密码，只用来生成散列，不保存原文。
     * @return array 含 cookie、csrf、id 的数组；重复邮箱抛出 HTTP 409。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $result = $auth->register('小明', 'user@example.com', 'example-password');
     */
    public function register(string $name, string $email, string $password): array
    {
        return $this->authenticate(trim($name), $email, $password, true);
    }
    /**
     * 校验同源、频率和密码后替换当前 Redis 会话。
     *
     * @param string $email 用来查找账号的邮箱。
     * @param string $password 用来和库存散列比对的明文密码。
     * @return array 含 cookie、csrf、id 的数组；认证失败抛出 HTTP 401。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $result = $auth->login('user@example.com', 'example-password');
     */
    public function login(string $email, string $password): array
    {
        return $this->authenticate('', $email, $password, false);
    }
    /**
     * 共用注册/登录流程，限制每分钟尝试次数，创建一天有效的会话并清除旧令牌。
     *
     * @param string $name 只在 $register 为 true 时作为显示名。登录时传入的值不检查。
     * @param string $email 邮箱原文。本方法去掉两端空白并转成小写，超过 254 字节或不含 @ 则抛 HTTP 422。
     * @param string $password 明文密码。短于 10 或长于 72 字节抛 HTTP 422。
     * @param bool $register true 插入新用户，false 核对已有密码。
     * @return array 含 cookie、csrf、id 的会话结果数组。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $result = $this->authenticate('', $email, $password, false);
     */
    private function authenticate(string $name, string $email, string $password, bool $register): array
    {
        $this->checkOrigin();
        $email = strtolower(trim($email));
        $now = time();
        if (strlen($email) > 254 || !str_contains($email, '@') || strlen($password) < 10 || strlen($password) > 72) {
            throw new HttpError(422, '请输入有效邮箱和 10–72 字节密码');
        }
        $attempts = Models\AuthLimit::recordAttempt($this->database, hash('sha256', $email), $now);
        if ($attempts > 10) {
            throw new HttpError(429, '尝试过于频繁，请稍后再试');
        }
        if ($register) {
            if ($name === '' || strlen($name) > 80) {
                throw new HttpError(422, '请输入姓名');
            }
            $result = Models\User::insertOrIgnore(['name' => $name, 'email' => $email, 'password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 10])], ['email']);
            if ($result->rowsAffected === 0) {
                throw new HttpError(409, '此邮箱已注册');
            }
            $id = $result->insertId;
        } else {
            $rows = Models\User::whereEmail($email)->rows(['id', 'password', 'auth_version']);
            $hash = $rows[0]['password'] ?? '$2a$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy';
            if (!password_verify($password, $hash) || $rows === []) {
                throw new HttpError(401, '邮箱或密码不正确');
            }
            $id = (int) $rows[0]['id'];
        }
        $token = $this->token();
        $csrf = $this->token();
        $version = $register ? 0 : (int)$rows[0]['auth_version'];
        $this->deleteSession();
        \Loom\Redis::command(['SET', \Loom\Redis::key('session:' . hash('sha256', $token)), json_encode(['id' => $id, 'csrf' => $csrf, 'version' => $version], JSON_THROW_ON_ERROR), 'EX', '86400']);
        return ['cookie' => $this->cookie($token, 86400), 'csrf' => $csrf, 'id' => $id];
    }
}
