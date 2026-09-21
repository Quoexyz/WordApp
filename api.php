<?php
/**
 * api.php —— 前端唯一的数据出入口，全部返回 JSON。
 *
 *   GET  api.php?action=words              全量词表（带 ETag，浏览器会缓存）
 *   GET  api.php?action=state              进度 + 统计
 *   POST api.php?action=mark   {id, status}  设置某个词的认识状态（同时维护复习牌组）
 *   POST api.php?action=view   {ids: [..]}   批量累加浏览次数（CONTINUE 时调用）
 *   POST api.php?action=reset  {}            清空全部进度（含复习牌组与历史）
 *
 *   GET  api.php?action=srs-state?tz=-480   复习模式首页：牌组统计 + 下一张卡
 *   POST api.php?action=srs-grade {id, grade, tz}   评分（0 忘了 / 1 记得 / 2 简单）
 *   POST api.php?action=srs-undo  {tz}      撤销最近一次评分
 *   POST api.php?action=srs-reset {tz}      重置复习进度（牌组按当前标注重建）
 */
declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/lib.php';

// 接口同样要过密码门：否则直接请求 api.php 就能读写进度、绕过页面。
// 未登录回 401 JSON，前端收到会跳回登录页。
require_api_login();

header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {

        // ---- 全量词表 ----------------------------------------------------
        // 体积约 550KB，内容只在词表重建时变化。用版本号做 ETag + no-cache，
        // 浏览器每次都会带 If-None-Match 来问一次，命中就只回 304，代价极小；
        // 词表重建后 ETag 变化，缓存自动失效。
        case 'words': {
            $data = load_words();
            json_out([
                'ok'      => true,
                'version' => (string)$data['version'],
                'source'  => (string)($data['source'] ?? ''),
                'built_at' => (string)($data['built_at'] ?? ''),
                'count'   => words_count(),
                'words'   => $data['words'],
            ], words_version(), 0);
        }

        // ---- 进度 --------------------------------------------------------
        case 'state': {
            json_out([
                'ok'      => true,
                'version' => words_version(),
                'count'   => words_count(),
                'rows'    => progress_rows(),   // [[id, status, views], ...]
                'stats'   => stats(),
                'last_id' => load_position(),   // 上次看到第几个词，0 = 没有记录
            ], null);
        }

        // ---- 记住位置 ----------------------------------------------------
        // CONTINUE 不改任何状态，只移动光标；不记下来的话下次打开就回到开头了。
        case 'pos': {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                fail('pos 需要 POST 请求。', 405);
            }
            $in = json_in();
            if (!isset($in['id'])) {
                fail('缺少参数 id。');
            }
            json_out(['ok' => true, 'saved' => save_position((int)$in['id'])]);
        }

        // ---- 标记状态 ----------------------------------------------------
        case 'mark': {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                fail('mark 需要 POST 请求。', 405);
            }
            $in = json_in();
            if (!isset($in['id'])) {
                fail('缺少参数 id。');
            }
            $status = isset($in['status']) ? (int)$in['status'] : STATUS_KNOWN;
            $res = set_status((int)$in['id'], $status);
            // srs_created / srs_removed 告诉前端牌组刚发生了什么，用于提示
            json_out([
                'ok'           => true,
                'id'           => (int)$in['id'],
                'status'       => $status,
                'stats'        => $res['stats'],
                'srs_created'  => $res['srs_created'],
                'srs_removed'  => $res['srs_removed'],
            ]);
        }

        // ---- 浏览计数 ----------------------------------------------------
        case 'view': {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                fail('view 需要 POST 请求。', 405);
            }
            $in  = json_in();
            $ids = $in['ids'] ?? [];
            if (!is_array($ids)) {
                fail('参数 ids 应为数组。');
            }
            json_out(['ok' => true, 'applied' => add_views($ids)]);
        }

        // ---- 重置 --------------------------------------------------------
        case 'reset': {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                fail('reset 需要 POST 请求。', 405);
            }
            json_out(['ok' => true, 'stats' => reset_progress()]);
        }

        // ---- 复习模式 ----------------------------------------------------
        case 'srs-state': {
            $dayStart = srs_day_start($_GET['tz'] ?? '');
            list($card, $info) = srs_pick_card($dayStart);
            json_out([
                'ok'      => true,
                'stats'   => srs_deck_stats($dayStart),
                'limits'  => srs_limits($dayStart),
                'card'    => $card === null ? null : srs_card_payload($card),
                'why'     => $info['why'],
                'waiting' => $info['waiting'],
                'queue'   => srs_queue_preview($dayStart),
            ]);
        }

        // 今日上限：只作用于当前学习日，跨天自动失效。
        // 传 null 表示清除覆盖、回到默认；传 0 是明确设成 0。
        case 'srs-limits': {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                fail('srs-limits 需要 POST 请求。', 405);
            }
            $in  = json_in();
            $dayStart = srs_day_start(isset($in['tz']) ? $in['tz'] : '');
            $touched = false;
            if (array_key_exists('new', $in)) {
                srs_set_day_limit($dayStart, 'new', $in['new']);
                $touched = true;
            }
            if (array_key_exists('review', $in)) {
                srs_set_day_limit($dayStart, 'review', $in['review']);
                $touched = true;
            }
            if (!$touched) {
                fail('至少要传 new 或 review 其中一个。');
            }
            $res = srs_after_action($dayStart, '今日上限已更新');
            $res['ok'] = true;
            json_out($res);
        }

        case 'srs-grade': {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                fail('srs-grade 需要 POST 请求。', 405);
            }
            $in = json_in();
            if (!isset($in['id'])) {
                fail('缺少参数 id。');
            }
            $grade = isset($in['grade']) ? (int)$in['grade'] : -1;
            $res = srs_grade((int)$in['id'], $grade, srs_day_start(isset($in['tz']) ? $in['tz'] : ''));
            $res['ok'] = true;
            json_out($res);
        }

        case 'srs-undo': {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                fail('srs-undo 需要 POST 请求。', 405);
            }
            $in = json_in();
            $res = srs_undo(srs_day_start(isset($in['tz']) ? $in['tz'] : ''));
            $res['ok'] = true;
            json_out($res);
        }

        case 'srs-reset': {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                fail('srs-reset 需要 POST 请求。', 405);
            }
            $in = json_in();
            $res = srs_reset(srs_day_start(isset($in['tz']) ? $in['tz'] : ''));
            $res['ok'] = true;
            json_out($res);
        }

        default:
            fail('未知的 action：' . htmlspecialchars((string)$action, ENT_QUOTES), 404);
    }
} catch (Throwable $e) {
    fail('服务器错误：' . $e->getMessage(), 500);
}
