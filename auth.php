<?php
/**
 * auth.php —— 密码门。被 index.php / api.php / login.php 引入。
 *
 * 实现方式是「签名 cookie」而不是 PHP session：
 *   浏览器存的是 HMAC(APP_SECRET + APP_PASSWORD) 的结果，服务端不保存任何会话状态。
 * 好处是不依赖 session 目录、不需要清理过期文件、多台机器也好使。
 * 代价是没法单独踢掉某一台设备 —— 但那本来就要改密码才行，对单人使用刚好够用。
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const AUTH_COOKIE = 'ielts_auth';
const AUTH_DAYS   = 180;

/** 登录令牌。密码或密钥任一改动，所有旧令牌立即失效。 */
function auth_token(): string
{
    return hash_hmac('sha256', 'ielts-auth-v1', APP_SECRET . '|' . APP_PASSWORD);
}

/** 是否还在用 config.php 里的默认密码（仅用于在登录页提醒）。 */
function auth_using_default_password(): bool
{
    return APP_PASSWORD === 'ielts';
}

function auth_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    // 跑在反向代理后面时靠这个头判断
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
        && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    return (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/** cookie 作用域收到应用所在目录，不污染同域名的其它站点。 */
function auth_cookie_path(): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    // CLI / 异常环境下拿不到绝对路径，退回站点根，免得拼出 "Path=./" 这种废 cookie
    if ($script === '' || $script[0] !== '/') {
        return '/';
    }
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    return ($dir === '' ? '/' : $dir . '/');
}

/**
 * 拼出 Set-Cookie 的值。单独抽成纯函数，方便在 CLI 下直接断言内容 ——
 * CLI SAPI 里 header() 是空操作，headers_list() 拿不到东西，只能这样测。
 *
 * 没用 setcookie()：数组形式的选项要 PHP 7.3+，旧写法又没法设置 SameSite。
 * 手工拼可以在 PHP 7.0 上同时拿到 HttpOnly + SameSite + Secure。
 */
function auth_cookie_header(string $value, int $expire): string
{
    $parts = [
        AUTH_COOKIE . '=' . rawurlencode($value),
        'Path=' . auth_cookie_path(),
        'Expires=' . gmdate('D, d M Y H:i:s', $expire) . ' GMT',
        'Max-Age=' . max(0, $expire - time()),
        'HttpOnly',
        'SameSite=Lax',
    ];
    if ($value !== '' && auth_is_https()) {
        $parts[] = 'Secure';
    }
    return implode('; ', $parts);
}

function auth_set_cookie(string $value, int $expire): void
{
    // false = 不要替换掉别的 Set-Cookie
    header('Set-Cookie: ' . auth_cookie_header($value, $expire), false);
}

function is_logged_in(): bool
{
    $cookie = $_COOKIE[AUTH_COOKIE] ?? '';
    if (!is_string($cookie) || $cookie === '') {
        return false;
    }
    // 恒定时间比较。这里其实不算敏感（令牌本身就是哈希），但没坏处。
    return hash_equals(auth_token(), $cookie);
}

/** 校验密码并下发令牌。失败时故意睡一会，拖慢爆破。 */
function auth_login(string $password): bool
{
    if (!hash_equals(APP_PASSWORD, $password)) {
        usleep(600000);          // 0.6 秒
        return false;
    }
    auth_set_cookie(auth_token(), time() + AUTH_DAYS * 86400);
    return true;
}

function auth_logout(): void
{
    auth_set_cookie('', time() - 3600);
}

/** 页面守卫：未登录就跳去 login.php。 */
function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

/** 接口守卫：未登录回 401 JSON。前端收到会跳回登录页，而不是白屏等报错。 */
function require_api_login(): void
{
    if (is_logged_in()) {
        return;
    }
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        ['ok' => false, 'code' => 'unauthorized', 'error' => '未登录或登录已过期'],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}
