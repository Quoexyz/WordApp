<?php
/**
 * lib.php —— 基础设施：路径常量、词表加载、SQLite 连接与建表。
 * 只被 api.php 引入，不直接对外暴露。
 */
declare(strict_types=1);

// ---------- 路径 ----------

define('APP_DIR', __DIR__);
define('DATA_DIR', APP_DIR . DIRECTORY_SEPARATOR . 'data');
define('WORDS_FILE', DATA_DIR . DIRECTORY_SEPARATOR . 'words.json');
define('DB_FILE', DATA_DIR . DIRECTORY_SEPARATOR . 'progress.sqlite');

// ---------- 可调开关 ----------

/**
 * 词表体积约 550KB，默认在 PHP 层做 gzip。若服务器已启用 mod_deflate /
 * Nginx gzip 并且你更希望交给 Web 服务器处理，把这里改为 false。
 */
const APP_GZIP = true;

/** 大于该字节数的响应才考虑压缩（小 JSON 压缩反而更慢）。 */
const GZIP_MIN_BYTES = 32768;


// ---------- 模板用的小工具 ----------

/**
 * 静态资源版本号 = 文件修改时间的摘要。文件一改 URL 就变，
 * 于是可以在 .htaccess 里给 css/js 设很长的缓存而不怕拿到旧版本。
 * 用普通函数而不是箭头函数，箭头函数要 PHP 7.4。
 */
function asset_ver($file)
{
    return is_file($file) ? substr(md5((string)filemtime($file)), 0, 8) : '0';
}

// ---------- 版本 ----------

/**
 * 版本号。升级完打开页面看一眼就能确认新文件真的传上去了，
 * 不用去猜是不是还跑着旧代码（登录页和页面源码里都能看到）。
 */
const APP_VERSION = '2.3.3';


// ---------- 词状态 ----------

const STATUS_NEW = 0;      // 未学过
const STATUS_KNOWN = 1;    // 认识（DONE 后标记，移出主循环）
const STATUS_UNKNOWN = 2;  // 不认识（手动标记，仍留在主循环）

/** 合法的状态取值，用于入口校验。 */
const VALID_STATUS = [STATUS_NEW, STATUS_KNOWN, STATUS_UNKNOWN];


// ============================================================
// 词表
// ============================================================

/** 进程内缓存，避免同一请求里重复读盘。 */
function load_words(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    if (!is_file(WORDS_FILE)) {
        fail('词表文件不存在：' . WORDS_FILE . '。请先运行 tools/build_words.py 生成。', 500);
    }
    $raw = file_get_contents(WORDS_FILE);
    if ($raw === false) {
        fail('词表文件无法读取：' . WORDS_FILE, 500);
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['words']) || !is_array($data['words'])) {
        fail('词表文件格式不正确，请重新运行 tools/build_words.py。', 500);
    }
    $cache = $data;
    return $cache;
}

/** 词表内容指纹，词表重建后它会变，前端据此判断缓存是否过期。 */
function words_version(): string
{
    $words = load_words();
    return (string)($words['version'] ?? '0');
}

/** 词条总数。 */
function words_count(): int
{
    $words = load_words();
    return (int)($words['count'] ?? count($words['words']));
}


// ============================================================
// 数据库
// ============================================================

/**
 * 取得 PDO 连接（单例），首次调用时自动建目录与建表。
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!extension_loaded('pdo_sqlite')) {
        fail('PHP 缺少 pdo_sqlite 扩展，请在服务器上启用它。', 500);
    }
    if (!is_dir(DATA_DIR) && !@mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
        fail('无法创建数据目录：' . DATA_DIR . '，请检查目录权限。', 500);
    }

    $fresh = !is_file(DB_FILE);
    try {
        $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        fail('无法打开数据库 ' . DB_FILE . '：' . $e->getMessage() . '（通常是目录不可写）', 500);
    }

    // WAL 提升并发读写表现；busy_timeout 让并发写入排队而不是直接报错。
    // 个别虚拟主机 / 网络文件系统不支持 WAL，失败就退回默认日志模式。
    try {
        $pdo->exec('PRAGMA journal_mode = WAL');
    } catch (PDOException $e) {
        // 忽略：不影响功能，只是并发写入时更容易撞锁
    }
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA synchronous = NORMAL');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS progress (
            word_id    INTEGER PRIMARY KEY,
            status     INTEGER NOT NULL DEFAULT 0,
            views      INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS meta (
            k TEXT PRIMARY KEY,
            v TEXT
        )'
    );

    // ---- SRS（复习模式）表 ------------------------------------------------
    // 记住不变式：srs_card 里有行 ⟺ 这个词当前标为「不认识」。
    // 牌组不是一份拷贝，它就是 progress 里 status=2 的词 —— 所以老库升级时
    // 要给已标为不认识的词补建卡片，否则牌组会是空的。
    $hadSrs = false;
    try {
        $hadSrs = (bool)$pdo->query(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'srs_card'"
        )->fetchColumn();
    } catch (PDOException $e) {
        // 拿不到就当没有，下面 CREATE 会兜底
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS srs_card (
            word_id    INTEGER PRIMARY KEY,
            ease       REAL    NOT NULL DEFAULT 2.5,
            interval_d REAL    NOT NULL DEFAULT 0,
            stage      INTEGER NOT NULL DEFAULT 0,
            reps       INTEGER NOT NULL DEFAULT 0,
            lapses     INTEGER NOT NULL DEFAULT 0,
            due        INTEGER NOT NULL DEFAULT 0,
            introduced INTEGER,
            updated_at INTEGER NOT NULL DEFAULT 0
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS srs_log (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            word_id     INTEGER NOT NULL,
            grade       INTEGER NOT NULL,
            b_ease REAL, b_interval REAL, b_stage INTEGER, b_reps INTEGER, b_lapses INTEGER, b_due INTEGER,
            a_ease REAL, a_interval REAL, a_stage INTEGER, a_reps INTEGER, a_lapses INTEGER, a_due INTEGER,
            reviewed_at INTEGER NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS srs_meta (
            k TEXT PRIMARY KEY,
            v TEXT
        )'
    );
    // 「只对今天生效」的上限覆盖。按学习日存，跨天自然失效 —— 不用手动清。
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS srs_day_limit (
            day_start INTEGER NOT NULL,
            kind      TEXT    NOT NULL,
            val       INTEGER NOT NULL,
            PRIMARY KEY (day_start, kind)
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_srs_card_due ON srs_card (stage, due)');

    if (!$hadSrs) {
        // 首次引入复习系统：给存量「不认识」补建卡片（一次性迁移）。
        // 时间戳直接拼 int，没有注入面。
        $now = (int)time();
        $pdo->exec(
            "INSERT OR IGNORE INTO srs_card
                (word_id, ease, interval_d, stage, reps, lapses, due, introduced, updated_at)
             SELECT word_id, 2.5, 0, 0, 0, 0, $now, NULL, $now
             FROM progress WHERE status = 2"
        );
    }

    // 默认配置：缺了才写。放在 if 外面是为了让从 v2.0.0 升上来的库也能看见这两个可调项，
    // 而不是只在首次建表时写一次（否则老库里查不到 rollover_hour）。
    $pdo->prepare('INSERT OR IGNORE INTO srs_meta (k, v) VALUES (?, ?)')
        ->execute(['daily_new', '30']);
    $pdo->prepare('INSERT OR IGNORE INTO srs_meta (k, v) VALUES (?, ?)')
        ->execute(['daily_review', '-1']);
    $pdo->prepare('INSERT OR IGNORE INTO srs_meta (k, v) VALUES (?, ?)')
        ->execute(['rollover_hour', (string)SRS_ROLLOVER_DEFAULT]);

    // 一次性数据迁移：「复习上限」的「不限」从 0 改成 -1（把 0 让给「今天不做复习」）
    srs_migrate_limits($pdo);

    if ($fresh) {
        $pdo->prepare('INSERT OR REPLACE INTO meta (k, v) VALUES (?, ?)')
            ->execute(['created_at', date('c')]);
    }
    // 记录当前词表指纹，便于换词表时发现进度错位
    $pdo->prepare('INSERT OR REPLACE INTO meta (k, v) VALUES (?, ?)')
        ->execute(['words_version', words_version()]);

    return $pdo;
}

/**
 * 「有则更新、无则插入」的通用写法。
 * 刻意不用 SQLite 3.24+ 才支持的 ON CONFLICT ... DO UPDATE，
 * 老版本 SQLite 的虚拟主机上这条语句会直接报语法错。
 */
function upsert(PDO $pdo, string $updateSql, array $updateArgs, string $insertSql, array $insertArgs): void
{
    $stmt = $pdo->prepare($updateSql);
    $stmt->execute($updateArgs);
    if ($stmt->rowCount() === 0) {
        $pdo->prepare($insertSql)->execute($insertArgs);
    }
}

/**
 * 读取进度，只返回有内容的行（稀疏）。
 * 返回 [ [word_id, status, views], ... ]，按 word_id 升序。
 */
function progress_rows(): array
{
    $sql = 'SELECT word_id, status, views FROM progress
            WHERE status <> 0 OR views > 0
            ORDER BY word_id';
    $rows = [];
    foreach (db()->query($sql) as $r) {
        $rows[] = [(int)$r['word_id'], (int)$r['status'], (int)$r['views']];
    }
    return $rows;
}

/** 汇总统计，用于顶部的进度条与侧栏筛选计数。 */
function stats(): array
{
    $row = db()->query(
        'SELECT
            SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS known,
            SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) AS unknown,
            SUM(CASE WHEN views  > 0 THEN 1 ELSE 0 END) AS touched
         FROM progress'
    )->fetch();
    if (!is_array($row)) {
        $row = [];   // 空表理论上也会返回一行全 NULL，这里只是兜底
    }

    $known   = (int)($row['known'] ?? 0);
    $unknown = (int)($row['unknown'] ?? 0);
    $total   = words_count();

    return [
        'total'   => $total,
        'known'   => $known,
        'unknown' => $unknown,
        'touched' => (int)($row['touched'] ?? 0),
        'new'     => max(0, $total - $known - $unknown),
        // 注意：left 是「还没标认识的词数」= 未标注 + 不认识，**不等于界面上的「未标注」**。
        // 未标注请看 new。留 left 只是给测试一个「还剩多少没认识」的指标。
        'left'    => max(0, $total - $known),
    ];
}

/**
 * 设置单个词的状态，并维护复习牌组的不变式：
 *   标为「不认识」→ 没有卡片就建一张；离开「不认识」→ 删掉卡片。
 * 返回 [stats, srs_created, srs_removed]。
 */
function set_status(int $wordId, int $status): array
{
    if (!in_array($status, VALID_STATUS, true)) {
        fail('非法的状态值：' . $status);
    }
    if ($wordId < 1 || $wordId > words_count()) {
        fail('词条编号越界：' . $wordId);
    }

    $ts = date('c');
    upsert(
        db(),
        'UPDATE progress SET status = :st, updated_at = :ts WHERE word_id = :id',
        [':st' => $status, ':ts' => $ts, ':id' => $wordId],
        'INSERT INTO progress (word_id, status, views, updated_at) VALUES (:id, :st, 0, :ts)',
        [':id' => $wordId, ':st' => $status, ':ts' => $ts]
    );

    // ---- 派生牌组：这就是那条「非一次性的单向管道」 ----
    $srsCreated = false;
    $srsRemoved = false;
    if ($status === STATUS_UNKNOWN) {
        $now = (int)time();
        $ins = db()->prepare(
            'INSERT OR IGNORE INTO srs_card
                (word_id, ease, interval_d, stage, reps, lapses, due, introduced, updated_at)
             VALUES (:id, 2.5, 0, 0, 0, 0, :now, NULL, :now)'
        );
        $ins->execute([':id' => $wordId, ':now' => $now]);
        $srsCreated = $ins->rowCount() === 1;   // 已有卡片时为 0
    } else {
        $del = db()->prepare('DELETE FROM srs_card WHERE word_id = :id');
        $del->execute([':id' => $wordId]);
        $srsRemoved = $del->rowCount() > 0;
    }

    return ['stats' => stats(), 'srs_created' => $srsCreated, 'srs_removed' => $srsRemoved];
}

/**
 * 批量累加浏览次数（CONTINUE 时调用）。用一条 SQL 完成，避免 N 次往返。
 * $ids 是 1-based 的词条编号数组。
 */
function add_views(array $ids): int
{
    // 手工过滤而不是 array_filter + 箭头函数：箭头函数要 PHP 7.4，
    // 这里刻意把可运行版本压到 PHP 7.0，照顾老虚拟主机。
    $total = words_count();
    $clean = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id >= 1 && $id <= $total) {
            $clean[$id] = true;
        }
    }
    $ids = array_keys($clean);
    if (!$ids) {
        return 0;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $ts  = date('c');
        $upd = $pdo->prepare('UPDATE progress SET views = views + 1 WHERE word_id = :id');
        $ins = $pdo->prepare(
            'INSERT INTO progress (word_id, status, views, updated_at) VALUES (:id, 0, 1, :ts)'
        );
        foreach ($ids as $id) {
            $upd->execute([':id' => $id]);
            if ($upd->rowCount() === 0) {
                $ins->execute([':id' => $id, ':ts' => $ts]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return count($ids);
}

/** 清空全部进度（词表不动）。牌组派生自标注，所以卡片和历史一起清掉。 */
function reset_progress(): array
{
    $pdo = db();
    $pdo->exec('DELETE FROM progress');
    $pdo->exec("DELETE FROM meta WHERE k = 'last_word_id'");
    $pdo->exec('DELETE FROM srs_card');
    $pdo->exec('DELETE FROM srs_log');
    return stats();
}


// ============================================================
// 上次看到哪了
// ============================================================

/**
 * 记住当前这个词，下次打开从这里继续。
 *
 * 越界时静默返回 false 而不是 fail()：位置是非关键数据，
 * 词表换过之后客户端可能还拿着旧编号，为这个弹报错不值得。
 */
function save_position(int $wordId): bool
{
    if ($wordId < 1 || $wordId > words_count()) {
        return false;
    }
    db()->prepare('INSERT OR REPLACE INTO meta (k, v) VALUES (?, ?)')
        ->execute(['last_word_id', (string)$wordId]);
    return true;
}

/** 读回上次的位置，没存过或已失效则返回 0（调用方按 0 当作「没有记录」）。 */
function load_position(): int
{
    $stored = db()->query("SELECT v FROM meta WHERE k = 'last_word_id'")->fetchColumn();
    $id = (int)$stored;
    return ($id >= 1 && $id <= words_count()) ? $id : 0;
}


// ============================================================
// 复习模式（SM-2 间隔重复）
//
// 牌组不是一份需要同步的拷贝：它就是 progress 里 status=2 的词。
// srs_card 只存调度状态（难度、间隔、到期），不存“在不在牌组里”。
// ============================================================

const SRS_EASE_MIN   = 1.3;    // 难度系数下限（再低间隔就不长了）
const SRS_EASE_MAX   = 3.0;
const SRS_LEARN_STEP = 600;    // 学习阶段步进：10 分钟（按「记得」时用）
const SRS_LAPSE_STEP = 180;    // 按「忘了」之后多久重来：3 分钟。
                               // 比学习步进短 —— 刚失败的卡应该比「勉强记得」的卡回得更早
const SRS_GRADUATE_D = 1;      // 学习毕业的兜底间隔：1 天（没有保留间隔时）
const SRS_EASY_D     = 4;      // 「简单」直接毕业：4 天
const SRS_EASY_BONUS = 1.3;    // 复习阶段按「简单」的额外乘数
const SRS_LAPSE_KEEP = 0.5;    // 复习中忘掉时保留的间隔比例 —— 别从零开始爬
const SRS_IVL_MAX    = 365;    // 间隔上限（天）
const SRS_FUZZ_MIN   = 2;      // 间隔 ≥ 2 天才加 ±5% 抖动
const SRS_QUEUE_CAP  = 60;     // 侧栏「接下来」最多列几张

const SRS_GRADES = [0, 1, 2];  // 0 忘了 / 1 记得 / 2 简单

/**
 * 每日边界落在本地几点。默认凌晨 4 点 —— 和 Anki 一样。
 * 这样 23:00 学到 01:00 算**同一个学习日**，新卡额度不会在半夜被腰斩，
 * 熬夜复习也不会被算成两天。存在 srs_meta 里，可改。
 */
const SRS_ROLLOVER_DEFAULT = 4;

/** 复习配置。缺项或非法值时退回默认。 */
function srs_config(): array
{
    $pdo = db();

    $read = function ($key) use ($pdo) {
        $v = $pdo->query("SELECT v FROM srs_meta WHERE k = " . $pdo->quote($key))->fetchColumn();
        return ($v === false || $v === null) ? null : (int)$v;
    };

    $daily = $read('daily_new');
    if ($daily === null || $daily < 1) {
        $daily = 30;
    }
    // 每日复习上限：**-1 = 不限**（默认）、0 = 今天不做复习、>0 = 上限
    $rev = $read('daily_review');
    if ($rev === null || $rev < -1) {
        $rev = -1;
    }
    $roll = $read('rollover_hour');
    if ($roll === null || $roll < 0 || $roll > 23) {
        $roll = SRS_ROLLOVER_DEFAULT;
    }

    return ['daily_new' => $daily, 'daily_review' => $rev, 'rollover_hour' => $roll];
}

/**
 * 一次性数据迁移：`daily_review` 的「不限」在 v2.3.2 之前是 0，之后是 -1。
 * 老的 0 必须改掉，否则用户升级后会突然「今天不做复习」。
 *
 * 抽成独立函数是为了能在测试里直接调用 —— db() 是单例，一个进程里只初始化一次。
 * 靠 srs_meta 里的 limit_semantics 标记保证只跑一次，不会把用户后来设的 0 冲掉。
 */
function srs_migrate_limits(PDO $pdo): bool
{
    $done = $pdo->query("SELECT v FROM srs_meta WHERE k = 'limit_semantics'")->fetchColumn();
    if ($done !== false && $done !== null) {
        return false;                       // 已经迁移过
    }
    $pdo->exec("UPDATE srs_meta SET v = '-1' WHERE k = 'daily_review' AND v = '0'");
    $pdo->prepare('INSERT OR REPLACE INTO srs_meta (k, v) VALUES (?, ?)')
        ->execute(['limit_semantics', '2']);
    return true;
}

/**
 * 今天实际生效的上限 = 今日覆盖（有就用）> srs_meta 里的长期默认。
 * 覆盖按学习日存，到下一个学习日自动失效。
 */
function srs_limits(int $dayStart): array
{
    $cfg = srs_config();
    $ov  = [];
    foreach (db()->query('SELECT kind, val FROM srs_day_limit WHERE day_start = ' . (int)$dayStart) as $r) {
        $ov[(string)$r['kind']] = (int)$r['val'];
    }

    $newOv = isset($ov['new']);
    $revOv = isset($ov['review']);
    return [
        'new'               => $newOv ? $ov['new'] : $cfg['daily_new'],
        'review'            => $revOv ? $ov['review'] : $cfg['daily_review'],
        'new_default'       => $cfg['daily_new'],
        'review_default'    => $cfg['daily_review'],
        'new_overridden'    => $newOv,
        'review_overridden' => $revOv,
    ];
}

/**
 * 设置「只对今天生效」的上限。
 * $val 传 null 表示清除今天的覆盖（回到默认）；传 0 是明确设成 0（今天不学新卡）。
 */
function srs_set_day_limit(int $dayStart, string $kind, $val): array
{
    if ($kind !== 'new' && $kind !== 'review') {
        fail('未知的上限类型：' . htmlspecialchars($kind, ENT_QUOTES));
    }
    $pdo = db();

    if ($val === null) {
        $pdo->prepare('DELETE FROM srs_day_limit WHERE day_start = :d AND kind = :k')
            ->execute([':d' => $dayStart, ':k' => $kind]);
    } else {
        if (is_bool($val) || !is_numeric($val)) {
            fail('上限值必须是数字或 null：' . htmlspecialchars((string)$val, ENT_QUOTES));
        }
        $val = (int)$val;
        // 只有复习上限有「不限」这个值（-1）；新卡必须给个数字，0 = 今天不学
        $min = ($kind === 'review') ? -1 : 0;
        if ($val < $min || $val > 9999) {
            fail('上限值超出范围（' . $min . '–9999）：' . $val);
        }
        $pdo->prepare('INSERT OR REPLACE INTO srs_day_limit (day_start, kind, val) VALUES (:d, :k, :v)')
            ->execute([':d' => $dayStart, ':k' => $kind, ':v' => $val]);
    }

    // 顺手清掉过期行，这张表永远只留最近 30 天
    $pdo->exec('DELETE FROM srs_day_limit WHERE day_start < ' . ((int)$dayStart - 30 * 86400));

    return srs_limits($dayStart);
}

/**
 * 算「当前学习日」的起点（UTC 时间戳）。
 * 参数是 JS 的 getTimezoneOffset()（UTC − 本地，分钟；东八区是 -480）。
 *
 * 日界线在本地 $rollover_hour 点：先把时间整体后移 $rollover_hour 小时，
 * 取整到天后，再把小时数加回来。
 */
function srs_day_start($tzParam): int
{
    if (!is_scalar($tzParam)) {
        $tzParam = 0;
    }
    $off = (int)$tzParam;
    if ($off <= -840 || $off >= 840) {
        $off = 0;                       // 非法值退回 UTC
    }
    $roll  = srs_config()['rollover_hour'];
    $local = time() - $off * 60 - $roll * 3600;
    return intdiv($local, 86400) * 86400 + $roll * 3600 + $off * 60;
}

/** 夹紧间隔并（可选地）加抖动。抖动让卡片不会在同一天集中到期。 */
function srs_clamp_fuzz($days, bool $fuzz)
{
    $days = max(1.0, min((float)SRS_IVL_MAX, (float)$days));
    if ($fuzz && $days >= SRS_FUZZ_MIN) {
        $days *= 0.95 + (mt_rand(0, 1000) / 1000) * 0.10;
    }
    return round($days, 3);
}

/**
 * SM-2 核心，纯函数：给定卡片当前状态和评分，算出下一个状态。不碰数据库。
 * $fuzz = false 时结果完全确定 —— 按钮上的间隔预览和单元测试都用它，
 * 真正落库时才带抖动。
 */
function srs_next_state(array $card, int $grade, int $now, bool $fuzz = true): array
{
    $ease   = (float)$card['ease'];
    $ivl    = (float)$card['interval_d'];
    $stage  = (int)$card['stage'];
    $reps   = (int)$card['reps'];
    $lapses = (int)$card['lapses'];

    if ($grade === 0) {
        // 忘了：回学习阶段，3 分钟后重来（比「记得」的 10 分钟短 —— 失败的卡回得更早）。
        // 间隔不全清：复习中忘掉时保留一半，别让练到 39 天的卡跌回 1 天重爬。
        // 学习阶段再忘一次会在这个保留值上再砍半，连续失败就一路缩下去。
        $ease = max(SRS_EASE_MIN, $ease - 0.20);
        if ($stage === 2 || $ivl > 0) {
            $ivl = max(1.0, round($ivl * SRS_LAPSE_KEEP, 3));
        } else {
            $ivl = 0;
        }
        $stage   = 1;
        $reps    = 0;
        $lapses += 1;
        $due     = $now + SRS_LAPSE_STEP;
    } elseif ($grade === 2) {
        // 简单：新卡/学习中直接毕业成 4 天；复习中间隔 × ease × 1.3。
        // 学习阶段若带着「忘掉后保留的间隔」，取它和 4 天里较大的那个，不要平白丢掉。
        $ease = min(SRS_EASE_MAX, $ease + 0.15);
        $ivl  = ($stage === 2) ? $ivl * $ease * SRS_EASY_BONUS
                               : max((float)SRS_EASY_D, $ivl);
        $stage = 2;
        $reps += 1;
        $ivl = srs_clamp_fuzz($ivl, $fuzz);
        $due = $now + (int)round($ivl * 86400);
    } else {
        // 记得
        if ($stage === 0) {
            $stage = 1;                              // 新卡第一次见 → 进学习阶段
            $due   = $now + SRS_LEARN_STEP;
        } elseif ($stage === 1) {
            // 学习步进完成 → 毕业。带着保留间隔就沿用（忘了之后重学的出口），
            // 否则用兜底的 1 天。
            $stage = 2;
            $ivl   = srs_clamp_fuzz($ivl > 0 ? $ivl : (float)SRS_GRADUATE_D, $fuzz);
            $due   = $now + (int)round($ivl * 86400);
            $reps += 1;
        } else {
            $ivl = srs_clamp_fuzz(max($ivl, 1.0) * $ease, $fuzz);
            $due = $now + (int)round($ivl * 86400);
            $reps += 1;
        }
    }

    return [
        'ease'       => round($ease, 4),
        'interval_d' => $ivl,
        'stage'      => $stage,
        'reps'       => $reps,
        'lapses'     => $lapses,
        'due'        => $due,
    ];
}

/** 把“多久之后”格式化成给人看的字符串。 */
function srs_format_due_delta(int $seconds): string
{
    if ($seconds < 3600) {
        return max(1, (int)round($seconds / 60)) . ' 分钟';
    }
    if ($seconds < 86400) {
        return round($seconds / 3600, 1) . ' 小时';
    }
    $days = $seconds / 86400;
    if ($days < 60) {
        return round($days, 1) . ' 天';
    }
    if ($days < 365) {
        return round($days / 30, 1) . ' 个月';
    }
    return round($days / 365, 1) . ' 年';
}

/** 牌组统计（复习模式侧栏与顶栏用）。上限取「今天实际生效」的那一组。 */
function srs_deck_stats(int $dayStart): array
{
    $pdo = db();
    $now = time();
    $limits = srs_limits($dayStart);

    $one = function ($sql) use ($pdo) {
        return (int)$pdo->query($sql)->fetchColumn();
    };

    $total      = $one('SELECT COUNT(*) FROM srs_card');
    $new        = $one('SELECT COUNT(*) FROM srs_card WHERE stage = 0');
    $learning   = $one('SELECT COUNT(*) FROM srs_card WHERE stage = 1');
    $review     = $one('SELECT COUNT(*) FROM srs_card WHERE stage = 2');
    $dueNow     = $one("SELECT COUNT(*) FROM srs_card WHERE stage IN (1,2) AND due <= $now");
    $due24h     = $one('SELECT COUNT(*) FROM srs_card WHERE stage IN (1,2) AND due > ' . $now
                    . ' AND due <= ' . ($now + 86400));
    $introduced = $one("SELECT COUNT(*) FROM srs_card WHERE introduced IS NOT NULL AND introduced >= $dayStart");
    $reviewedAll = $one("SELECT COUNT(*) FROM srs_log WHERE reviewed_at >= $dayStart");

    // 今天评分的次数里，减去「新卡首次评分」就是今天真正做掉的复习张数
    // （新卡首评会同时写进 srs_log 并把 introduced 打上，所以直接相减即可）
    $reviewedReview = max(0, $reviewedAll - $introduced);

    return [
        'total'      => $total,
        'new'        => $new,
        'learning'   => $learning,
        'review'     => $review,
        'due_now'    => $dueNow,
        'due_24h'    => $due24h,
        'introduced_today'      => $introduced,
        'reviewed_today'        => $reviewedAll,
        'reviewed_review_today' => $reviewedReview,
        'daily_new'    => $limits['new'],
        'new_left'     => max(0, min($limits['new'] - $introduced, $new)),
        'daily_review' => $limits['review'],
        // -1 = 不限；0 = 额度用完（含「今天不做复习」）；>0 = 还剩几张
        'review_left'  => $limits['review'] < 0 ? -1 : max(0, $limits['review'] - $reviewedReview),
    ];
}

/**
 * 挑下一张该复习的卡。
 * 顺序：学习中已到期（最急）→ 复习中已到期（最老的优先）→ 新卡。
 * 学习中的卡**不受任何上限约束** —— 它们是 10 分钟步进该回来的，卡住会打断学习流程。
 * 复习卡受今日复习上限、新卡受今日新卡上限。
 * 返回 [卡片行|null, ['why' => 原因, 'waiting' => ['next_due' => ts]|null]]。
 */
function srs_pick_card(int $dayStart): array
{
    $pdo = db();
    $now = time();
    $stats = srs_deck_stats($dayStart);

    $pick = function ($where) use ($pdo) {
        $row = $pdo->query("SELECT * FROM srs_card WHERE $where LIMIT 1")->fetch();
        return is_array($row) ? $row : null;
    };

    $why  = 'learning';
    $card = $pick("stage = 1 AND due <= $now ORDER BY due ASC, word_id ASC");

    // review_left = -1 表示不限
    if ($card === null && ($stats['review_left'] < 0 || $stats['review_left'] > 0)) {
        $why  = 'review';
        $card = $pick("stage = 2 AND due <= $now ORDER BY due ASC, word_id ASC");
    }
    if ($card === null && $stats['new_left'] > 0) {
        $why  = 'new';
        $card = $pick('stage = 0 ORDER BY word_id ASC');
    }

    $waiting = null;
    if ($card === null) {
        // 被「今日复习上限」挡住的到期卡有几张 —— 要告诉前端，
        // 否则用户看到「休息一下」会以为今天没事了，其实是额度用完了
        $blocked = 0;
        if ($stats['review_left'] === 0) {
            $blocked = (int)$pdo->query(
                "SELECT COUNT(*) FROM srs_card WHERE stage = 2 AND due <= $now"
            )->fetchColumn();
        }

        $next = $pdo->query("SELECT MIN(due) FROM srs_card WHERE due > $now")->fetchColumn();
        if ($next !== null && $next !== false && (int)$next > $now) {
            $next = (int)$next;
            // 顺带告诉前端「到那一刻会回来几张」，好把等待文案说清楚
            $readyThen = (int)$pdo->query(
                "SELECT COUNT(*) FROM srs_card WHERE due > $now AND due <= " . ($next + 60)
            )->fetchColumn();
            $waiting = ['next_due' => $next, 'ready_then' => $readyThen, 'blocked' => $blocked];
        }
    }
    return [$card, ['why' => $why, 'waiting' => $waiting]];
}

/** 组装给前端的卡片数据：词条文本 + 三个按钮各自的间隔预览。 */
function srs_card_payload(array $card): array
{
    $words = load_words();
    $idx   = (int)$card['word_id'] - 1;
    $w     = isset($words['words'][$idx]) ? $words['words'][$idx] : ['', '', ''];
    $now   = time();

    $previews = [];
    foreach (SRS_GRADES as $g) {
        $next = srs_next_state($card, $g, $now, false);
        $previews[] = srs_format_due_delta(max(0, $next['due'] - $now));
    }

    return [
        'id'     => (int)$card['word_id'],
        'w'      => $w[0],
        'p'      => $w[1],
        'd'      => $w[2],
        'stage'  => (int)$card['stage'],
        'reps'   => (int)$card['reps'],
        'lapses' => (int)$card['lapses'],
        'prev'   => $previews,
    ];
}

/** 评分/撤销/重置之后，把“接下来该干什么”和统计一起打包。 */
function srs_after_action(int $dayStart, $note = ''): array
{
    list($card, $info) = srs_pick_card($dayStart);
    return [
        'stats'   => srs_deck_stats($dayStart),
        'limits'  => srs_limits($dayStart),
        'card'    => $card === null ? null : srs_card_payload($card),
        'queue'   => srs_queue_preview($dayStart),
        'why'     => $info['why'],
        'waiting' => $info['waiting'],
        'note'    => (string)$note,
    ];
}

/** 评分：应用 SM-2，更新卡片并写日志。 */
function srs_grade(int $wordId, int $grade, int $dayStart): array
{
    if (!in_array($grade, SRS_GRADES, true)) {
        fail('非法的评分：' . $grade);
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM srs_card WHERE word_id = :id');
    $stmt->execute([':id' => $wordId]);
    $card = $stmt->fetch();
    if (!is_array($card)) {
        fail('这个词不在复习牌组里（可能刚被改回认识/未标注）');
    }

    $now  = time();
    $next = srs_next_state($card, $grade, $now, true);

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE srs_card SET ease = :e, interval_d = :i, stage = :st,
                    reps = :r, lapses = :l, due = :d, updated_at = :u
             WHERE word_id = :id'
        )->execute([
            ':e'  => $next['ease'],
            ':i'  => $next['interval_d'],
            ':st' => $next['stage'],
            ':r'  => $next['reps'],
            ':l'  => $next['lapses'],
            ':d'  => $next['due'],
            ':u'  => $now,
            ':id' => $wordId,
        ]);

        if ($card['introduced'] === null) {
            // 第一次评分才算“今天引入的新卡”——只翻面没评分的不占额度
            $pdo->prepare('UPDATE srs_card SET introduced = :u WHERE word_id = :id')
                ->execute([':u' => $now, ':id' => $wordId]);
        }

        $pdo->prepare(
            'INSERT INTO srs_log (word_id, grade,
                b_ease, b_interval, b_stage, b_reps, b_lapses, b_due,
                a_ease, a_interval, a_stage, a_reps, a_lapses, a_due, reviewed_at)
             VALUES (:wid, :g, :be, :bi, :bs, :br, :bl, :bd,
                     :ae, :ai, :as, :ar, :al, :ad, :ts)'
        )->execute([
            ':wid' => $wordId, ':g' => $grade,
            ':be' => $card['ease'], ':bi' => $card['interval_d'], ':bs' => $card['stage'],
            ':br' => $card['reps'], ':bl' => $card['lapses'], ':bd' => $card['due'],
            ':ae' => $next['ease'], ':ai' => $next['interval_d'], ':as' => $next['stage'],
            ':ar' => $next['reps'], ':al' => $next['lapses'], ':ad' => $next['due'],
            ':ts' => $now,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return srs_after_action($dayStart, srs_format_due_delta(max(0, $next['due'] - $now)));
}

/** 撤销最近一次评分：把日志里的 before 值写回卡片。 */
function srs_undo(int $dayStart): array
{
    $pdo = db();
    $row = $pdo->query('SELECT * FROM srs_log ORDER BY id DESC LIMIT 1')->fetch();
    if (!is_array($row)) {
        fail('还没有可撤销的评分');
    }

    $pdo->beginTransaction();
    try {
        // 卡片可能已经因为词被改回「认识」而删除 —— 那就只清掉这条记录
        $exists = $pdo->prepare('SELECT 1 FROM srs_card WHERE word_id = :id');
        $exists->execute([':id' => (int)$row['word_id']]);
        if ($exists->fetchColumn()) {
            $pdo->prepare(
                'UPDATE srs_card SET ease = :e, interval_d = :i, stage = :st,
                        reps = :r, lapses = :l, due = :d, updated_at = :u
                 WHERE word_id = :id'
            )->execute([
                ':e'  => $row['b_ease'],
                ':i'  => $row['b_interval'],
                ':st' => $row['b_stage'],
                ':r'  => $row['b_reps'],
                ':l'  => $row['b_lapses'],
                ':d'  => $row['b_due'],
                ':u'  => time(),
                ':id' => (int)$row['word_id'],
            ]);
        }
        $pdo->prepare('DELETE FROM srs_log WHERE id = :id')
            ->execute([':id' => (int)$row['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return srs_after_action($dayStart, '已撤销');
}

/**
 * 重置复习进度：清空调度状态与历史。
 * 牌组派生自标注，清完的下一秒就按当前「不认识」自动重建（全部变回新卡）
 * —— 原设计里的“重置并加载”合成这一个动作。
 */
function srs_reset(int $dayStart): array
{
    $pdo = db();
    $pdo->exec('DELETE FROM srs_card');
    $pdo->exec('DELETE FROM srs_log');
    $now = (int)time();
    $pdo->exec(
        "INSERT OR IGNORE INTO srs_card
            (word_id, ease, interval_d, stage, reps, lapses, due, introduced, updated_at)
         SELECT word_id, 2.5, 0, 0, 0, 0, $now, NULL, $now
         FROM progress WHERE status = 2"
    );
    return srs_after_action($dayStart, '复习进度已重置');
}

/** 侧栏「接下来」预览：到期卡在前，新卡受每日上限约束。 */
function srs_queue_preview(int $dayStart): array
{
    $now   = time();
    $stats = srs_deck_stats($dayStart);
    $out   = [];
    foreach (db()->query(
        "SELECT word_id, stage FROM srs_card
         WHERE stage IN (1,2) AND due <= $now
         ORDER BY due ASC, word_id ASC LIMIT " . (int)SRS_QUEUE_CAP
    ) as $r) {
        $out[] = [(int)$r['word_id'], (int)$r['stage']];
    }
    if (count($out) < SRS_QUEUE_CAP && $stats['new_left'] > 0) {
        $left = (int)SRS_QUEUE_CAP - count($out);
        foreach (db()->query(
            'SELECT word_id, stage FROM srs_card WHERE stage = 0
             ORDER BY word_id ASC LIMIT ' . min($left, $stats['new_left'])
        ) as $r) {
            $out[] = [(int)$r['word_id'], (int)$r['stage']];
        }
    }
    return $out;
}


// ============================================================
// 请求 / 响应
// ============================================================

/** 统一错误出口：以 JSON 报错并结束。 */
function fail(string $message, int $code = 400): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        ['ok' => false, 'error' => $message],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

/**
 * 输出 JSON。大响应自动 gzip，并带 ETag 支持 304。
 * $etag 传内容指纹启用协商缓存（客户端带 If-None-Match 命中即返回 304），传 null 关闭。
 * $maxAge > 0 时用 public,max-age；否则用 no-cache（缓存但每次校验）。
 *
 * 参数刻意不写 ?string 类型声明，那是 PHP 7.1 才有的语法。
 */
function json_out(array $data, $etag = null, $maxAge = 0): void
{
    $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        fail('响应序列化失败：' . json_last_error_msg(), 500);
    }

    if ($etag !== null) {
        $etag = '"' . $etag . '"';
        header('ETag: ' . $etag);
        $inm = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($inm !== '' && trim($inm) === $etag) {
            http_response_code(304);
            exit;
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: ' . ($maxAge > 0 ? 'public, max-age=' . $maxAge : 'no-cache'));
    header('X-Content-Type-Options: nosniff');

    $accept = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
    $canGzip = APP_GZIP
        && strlen($body) >= GZIP_MIN_BYTES
        && function_exists('gzencode')
        && stripos($accept, 'gzip') !== false;

    if ($canGzip) {
        $gz = gzencode($body, 6);
        if ($gz !== false) {
            header('Content-Encoding: gzip');
            header('Vary: Accept-Encoding');
            $body = $gz;
        }
    }

    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

/** 读取请求体里的 JSON 参数（POST）。 */
function json_in(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return $_POST;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        fail('请求体不是合法的 JSON。');
    }
    return $data;
}
