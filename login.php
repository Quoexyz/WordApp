<?php
/**
 * login.php —— 密码输入页。只有一个输入框，没有用户名。
 *
 * 退出登录也走这里：login.php?action=logout
 */
declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/lib.php';      // 只用 asset_ver()，不会碰词表和数据库

// ---- 退出 ----
if ((string)($_GET['action'] ?? '') === 'logout') {
    auth_logout();
    header('Location: login.php');
    exit;
}

// ---- 已登录直接进去 ----
if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';

// ---- 校验密码 ----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $password = isset($_POST['password']) && is_string($_POST['password'])
        ? $_POST['password']
        : '';
    if (auth_login($password)) {
        header('Location: index.php');
        exit;
    }
    $error = '密码不对。';
}

$usingDefault = auth_using_default_password();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="app-version" content="<?= APP_VERSION ?>">
<title>需要密码 · 雅思背单词</title>
<script>
(function () {
    try {
        var t = localStorage.getItem('ielts.theme');
        if (t !== 'light' && t !== 'dark') {
            t = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        document.documentElement.dataset.theme = t;
    } catch (e) {
        document.documentElement.dataset.theme = 'light';
    }
})();
</script>
<link rel="stylesheet" href="assets/app.css?v=<?= asset_ver(__DIR__ . '/assets/app.css') ?>">
</head>
<body class="login-page">

<form class="login-box" method="post" action="login.php">
    <div class="login-brand"><span class="brand-mark">IELTS</span> 背单词</div>

    <h1>输入访问密码</h1>

    <?php if ($usingDefault): ?>
      <p class="login-warn">
        你还在用默认密码 <code>ielts</code>，请编辑 <code>config.php</code> 改掉它。
      </p>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
      <p class="login-error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <label class="login-label" for="password">密码</label>
    <input class="login-input" type="password" id="password" name="password"
           autocomplete="current-password" autofocus required>

    <button class="login-btn" type="submit">进入</button>

    <p class="login-note">
        登录状态在这台设备上保留 <?= (int)AUTH_DAYS ?> 天。
        忘了密码就编辑服务器上的 <code>config.php</code>。
        <span class="login-ver">v<?= APP_VERSION ?></span>
    </p>
</form>

</body>
</html>
