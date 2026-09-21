/* ==========================================================================
   雅思背单词 —— 交互逻辑
   status[] 记录每个词的认识状态，words[] 是词表本体。
   主循环：cursor 在词表里前进，遇到 known 就跳过，走到末尾回到开头（循环）。
   ========================================================================== */
(function () {
    'use strict';

    var API = 'api.php';
    var NEW = 0;            // 未标注（还没标过）
    var KNOWN = 1;          // 认识（DONE 标记，移出主循环）
    var UNKNOWN = 2;        // 不认识（手动标记，仍留在主循环）
    // 叫法统一成「未标注」：侧栏筛选、状态胶囊、图例、提示都用同一个词
    var STATUS_TEXT = { 0: '未标注', 1: '认识', 2: '不认识' };

    // 行高从 CSS 变量读，保证虚拟列表与样式永远一致
    var ROW_H = parseFloat(
        getComputedStyle(document.documentElement).getPropertyValue('--row-h')
    ) || 46;

    // ==================== DOM ====================
    var $ = function (id) { return document.getElementById(id); };

    var card = $('card'), cardPos = $('cardPos'), cardStatus = $('cardStatus');
    var cardWord = $('cardWord'), cardIpa = $('cardIpa'), cardDef = $('cardDef'), cardFoot = $('cardFoot');

    var btnDone = $('btnDone'), btnContinue = $('btnContinue'), btnMask = $('btnMask');
    var btnUnknown = $('btnUnknown');
    var btnRestart = $('btnRestart'), btnTheme = $('btnTheme'), btnReset = $('btnReset'), btnSidebar = $('btnSidebar');

    var progressFill = $('progressFill'), progressText = $('progressText');
    var progressUnknownFill = $('progressUnknownFill');

    var sidebar = $('sidebar'), scrim = $('scrim'), search = $('search'), filters = $('filters');
    var listCount = $('listCount'), viewport = $('listViewport'), spacer = $('listSpacer'), layer = $('listLayer');

    var modeSwitch = $('modeSwitch');
    var rsidebar = $('rsidebar'), rStats = $('rStats'), rQueue = $('rQueue');
    var rcard = $('rcard'), rPos = $('rPos'), rStage = $('rStage');
    var rWord = $('rWord'), rIpa = $('rIpa'), rDef = $('rDef'), rFoot = $('rFoot');
    var btnRMask = $('btnRMask');
    var btnForgot = $('btnForgot'), btnGood = $('btnGood'), btnEasy = $('btnEasy');
    var pvForgot = $('pvForgot'), pvGood = $('pvGood'), pvEasy = $('pvEasy');
    var btnUndo = $('btnUndo'), btnSrsReset = $('btnSrsReset');
    var setNew = $('setNew'), setRev = $('setRev');

    var toastEl = $('toast'), confirmEl = $('confirm');
    var confirmTitle = $('confirmTitle'), confirmBody = $('confirmBody');

    // ==================== 状态 ====================
    var words = [];          // [[word, ipa, def], ...]
    var status = null;       // Uint8Array
    var views = null;        // Uint16Array
    var cursor = 0;          // 当前词下标；-1 表示已无未标注的词
    var ready = false;
    var maskDef = false;     // 遮住释义模式
    var peeked = false;      // 当前词被点开偷看过

    var filter = 'all', query = '', filtered = [], rendered = [-1, -1];

    var viewQueue = new Set(), flushTimer = null, warned = false;
    var posPending = null, posTimer = null;
    var authRedirecting = false;

    // 模式：标注 / 复习。记住上一次用的（localStorage），刷新不丢
    var mode = 'triage';
    var rData = null;         // 复习模式当前从服务端拿到的状态
    var rmask = true;         // 复习卡默认遮住释义（和标注页不同：那边默认显示）
    var rpeeked = false;      // 这一张被手动取消过模糊
    var waitTick = null, waitRetry = null, waitFails = 0;   // 等待下一张到期的倒计时


    // ==================== 工具 ====================

    /** 登录过期时后端回 401，直接跳回登录页，不要弹一堆看不懂的报错 */
    function checkAuth(res) {
        if (res.status === 401) {
            authRedirecting = true;
            location.href = 'login.php';
            throw new Error('登录已过期');
        }
        return res;
    }

    function toast(msg, kind) {
        if (authRedirecting) return;
        toastEl.textContent = msg;
        toastEl.dataset.kind = kind || '';
        toastEl.classList.add('is-show');
        clearTimeout(toast._t);
        toast._t = setTimeout(function () { toastEl.classList.remove('is-show'); }, 1400);
    }

    /** 键盘触发时给按钮一次高亮，让按键与点击的反馈一致 */
    function flash(btn) {
        btn.classList.add('flash-hint');
        setTimeout(function () { btn.classList.remove('flash-hint'); }, 150);
    }

    function post(action, body) {
        return fetch(API + '?action=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body || {}),
            keepalive: true
        }).then(checkAuth).then(function (res) { return res.json(); }).then(function (data) {
            if (!data.ok) throw new Error(data.error || '请求失败');
            return data;
        });
    }

    function get(action, extra) {
        return fetch(API + '?action=' + action + (extra || '')).then(checkAuth).then(function (res) {
            if (!res.ok) throw new Error('接口 ' + action + ' 返回 HTTP ' + res.status);
            return res.json();
        }).then(function (data) {
            if (!data.ok) throw new Error(data.error || '请求失败');
            return data;
        });
    }

    /** 后台请求失败只提示一次，避免刷屏 */
    function quiet(promise, what) {
        promise.catch(function (err) {
            console.error(err);
            if (authRedirecting || warned) return;
            warned = true;
            toast('保存失败（' + what + '）：' + err.message, 'error');
        });
    }


    // ==================== 主循环 ====================

    /**
     * 从 from 之后找下一个「未标注」的词，走到末尾回到开头；全部标完返回 -1。
     *
     * 循环只走未标注的词：认识的已经离开，「不认识」的归复习模式管 ——
     * 标注循环再把它们端出来，就会和复习重叠（两个模式必须独立）。
     */
    function nextUnlearned(from) {
        var n = words.length;
        for (var step = 1; step <= n; step++) {
            var i = (from + step) % n;
            if (status[i] === NEW) return i;
        }
        return -1;
    }

    /** 还没标注的词数（标注循环的剩余量，不含已进复习牌组的不认识）。 */
    function countLeft() {
        var left = 0;
        for (var i = 0; i < status.length; i++) if (status[i] === NEW) left++;
        return left;
    }


    // ==================== 卡片 ====================

    function setDisabled(btn, off) {
        btn.disabled = off;
        btn.style.opacity = off ? '.5' : '';
        btn.style.pointerEvents = off ? 'none' : '';
    }

    function renderCard() {
        if (!ready) {
            cardWord.textContent = '加载中…';
            cardIpa.textContent = '';
            cardDef.textContent = '';
            cardPos.textContent = '#—';
            cardStatus.textContent = '—';
            cardStatus.removeAttribute('data-status');
            cardFoot.textContent = '';
            return;
        }

        // 全部标注完成（没有「未标注」的词了）
        if (cursor < 0) {
            var nKnown = 0, nUnknown = 0;
            for (var q = 0; q < status.length; q++) {
                if (status[q] === KNOWN) nKnown++;
                else if (status[q] === UNKNOWN) nUnknown++;
            }
            card.classList.add('is-empty');
            cardPos.textContent = '#' + words.length;
            cardStatus.textContent = '标注完成';
            cardStatus.dataset.status = 'known';
            cardWord.textContent = '全部标注完成';
            cardIpa.textContent = '';
            cardDef.textContent = '词表里的 ' + words.length.toLocaleString() + ' 个词都标完了：'
                + '认识 ' + nKnown.toLocaleString() + ' 个'
                + (nUnknown ? '，不认识 ' + nUnknown.toLocaleString() + ' 个（在复习牌组里）' : '')
                + '。要改标注去右侧词表；改回「未标注」的词会重新进入循环。';
            cardFoot.textContent = nUnknown ? '按 M 进入复习模式' : '按 M 去复习，或按 R 从头检查一遍';
            btnMask.style.visibility = 'hidden';
            setDisabled(btnDone, true);
            setDisabled(btnContinue, true);
            return;
        }

        card.classList.remove('is-empty');
        btnMask.style.visibility = '';
        setDisabled(btnDone, false);
        setDisabled(btnContinue, false);

        var w = words[cursor], s = status[cursor];
        cardPos.textContent = '#' + (cursor + 1);
        cardStatus.textContent = STATUS_TEXT[s] || '未标注';
        if (s === NEW) cardStatus.removeAttribute('data-status');
        else cardStatus.dataset.status = (s === KNOWN ? 'known' : 'unknown');

        cardWord.textContent = w[0] || '—';
        cardIpa.textContent = w[1] || '';
        cardDef.textContent = w[2] || '（无释义）';

        peeked = false;
        applyMask();

        var v = views[cursor] || 0;
        cardFoot.textContent = '还剩 ' + countLeft().toLocaleString() + ' 个未标注'
            + (v > 0 ? ' · 这个词看过 ' + v + ' 次' : '');
    }

    /** 这一张现在是不是遮着的（遮罩偏好 + 这张有没有被偷看过） */
    function triageMasked() {
        return maskDef && !peeked && cursor >= 0;
    }

    function applyMask() {
        cardDef.classList.toggle('is-masked', triageMasked());
        // 按钮代表的是「遮罩模式开没开」（偏好），不是这一张当下糊没糊。
        // 偷看是临时的：看完按钮仍写着「释义已遮」，因为模式确实还开着。
        btnMask.classList.toggle('is-on', maskDef);
        btnMask.textContent = maskDef ? '释义已遮' : '遮住释义';
    }

    /** 遮罩开关：切换偏好。重新打开时把这一张的偷看状态清掉，让它真的遮上。 */
    function toggleMask() {
        maskDef = !maskDef;
        if (maskDef) peeked = false;
        applyMask();
    }

    /** 换词入场动画；DONE 额外叠一层绿色闪光 */
    function playFx(known) {
        card.classList.remove('fx-in', 'fx-flash');
        void card.offsetWidth;                 // 强制重排，让动画能重播
        card.classList.add('fx-in');
        if (known) card.classList.add('fx-flash');
    }

    // 两个动画时长不同，必须按名字分别清理，否则短的会提前掐掉长的
    card.addEventListener('animationend', function (e) {
        if (e.animationName === 'cardIn') card.classList.remove('fx-in');
        if (e.animationName === 'knownFlash') card.classList.remove('fx-flash');
    });

    function moveTo(index, known) {
        cursor = index;
        renderCard();
        playFx(known);
        syncListSelection();
        queuePos(index);
    }


    // ==================== 记住位置 ====================
    // CONTINUE 不改任何状态，只移动光标。位置不记下来的话，
    // 一刷新就回到开头了 —— 连续翻了几百个词再刷新会非常难受。

    function queuePos(index) {
        if (index < 0) return;              // 全部完成时不覆盖已有的位置
        posPending = index + 1;
        if (!posTimer) posTimer = setTimeout(flushPos, 400);
    }

    function flushPos() {
        clearTimeout(posTimer);
        posTimer = null;
        if (posPending === null) return;
        var id = posPending;
        posPending = null;
        // 位置丢了只是体验损失，不值得弹报错打扰人，静默失败即可
        post('pos', { id: id }).catch(function (err) {
            console.error('保存位置失败', err);
        });
    }


    // ==================== 两个主操作 ====================

    function doDone() {
        if (!ready || cursor < 0) return;
        var i = cursor;
        var wasUnknown = status[i] === UNKNOWN;       // 有复习卡的话，这步会把它移除
        flash(btnDone);
        toast('✓ ' + words[i][0] + ' 已标记为认识' + (wasUnknown ? '，复习卡已移除' : ''), 'known');
        changeStatus(i, KNOWN, true);          // 先落状态，再算下一个
        moveTo(nextUnlearned(i), true);
    }

    function doContinue() {
        if (!ready || cursor < 0) return;
        var i = cursor;
        flash(btnContinue);
        queueView(i);
        moveTo(nextUnlearned(i), false);
    }

    /**
     * 标记为不认识，并跳到下一个未标注的词。
     * 和 DONE 的区别：这个词**仍然留在循环里**，之后还会再遇到。
     */
    function doUnknown() {
        if (!ready || cursor < 0) return;
        var i = cursor;
        flashPurple();
        var already = status[i] === UNKNOWN;
        toast(already ? words[i][0] + ' 已经标记过了'
                      : '已标记为不认识：' + words[i][0] + '，进入复习', 'unknown');
        changeStatus(i, UNKNOWN, true);
        moveTo(nextUnlearned(i), false);
    }

    /**
     * 紫色闪烁。先去类再强制重排，动画才会重新播 ——
     * 否则连按两次只有第一次有反馈。
     */
    function flashPurple() {
        btnUnknown.classList.remove('fx-purple');
        void btnUnknown.offsetWidth;
        btnUnknown.classList.add('fx-purple');
    }

    function queueView(i) {
        viewQueue.add(i + 1);
        views[i] = Math.min(65535, (views[i] || 0) + 1);
        if (!flushTimer) flushTimer = setTimeout(flushViews, 4000);
    }

    function flushViews() {
        clearTimeout(flushTimer);
        flushTimer = null;
        if (!viewQueue.size) return;
        var ids = Array.from(viewQueue);
        viewQueue.clear();
        quiet(post('view', { ids: ids }), '浏览计数');
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { flushViews(); flushPos(); }
    });
    window.addEventListener('pagehide', function () { flushViews(); flushPos(); });


    // ==================== 统计 ====================

    function refreshStats() {
        var known = 0, unknown = 0;
        for (var i = 0; i < status.length; i++) {
            if (status[i] === KNOWN) known++;
            else if (status[i] === UNKNOWN) unknown++;
        }
        var total = words.length;
        var knownPct = total ? (known / total * 100).toFixed(2) : '0';
        var unknownPct = total ? (unknown / total * 100).toFixed(2) : '0';

        // 绿色「已认识」从左边长出来，淡紫「不认识」从右边长出来 —— 宽度由 CSS 的
        // left/right 锚定决定，这里只给宽度。两者互斥，加起来不会超过 100%。
        progressFill.style.width = knownPct + '%';
        progressUnknownFill.style.width = unknownPct + '%';

        // 这一行只报两个数字，不认识数量跟在后面（不单独着色，
        // 它对应的紫色段已经在进度条上了）。
        progressText.textContent = '已认识 ' + known.toLocaleString() + ' / ' + total.toLocaleString()
            + '（' + (total ? (known / total * 100).toFixed(1) : '0.0') + '%）'
            + (unknown ? '　不认识 ' + unknown.toLocaleString() : '');
    }


    // ==================== 侧边词表 ====================

    function rebuildFiltered() {
        var q = query.trim().toLowerCase(), out = [];
        for (var i = 0; i < words.length; i++) {
            var s = status[i];
            // 「未标注」= 只有 NEW。认识的已离开，不认识的在复习牌组里，都不算未标注。
            if (filter === 'left' && s !== NEW) continue;
            if (filter === 'known' && s !== KNOWN) continue;
            if (filter === 'unknown' && s !== UNKNOWN) continue;
            if (q && words[i][0].toLowerCase().indexOf(q) < 0
                  && words[i][2].toLowerCase().indexOf(q) < 0) continue;
            out.push(i);
        }
        filtered = out;
    }

    function mkAct(act, glyph, title, on) {
        var b = document.createElement('button');
        b.className = 'row-act on-' + act + (on ? ' is-on' : '');
        b.textContent = glyph;
        b.title = title;
        b.dataset.act = act;
        return b;
    }

    function rowEl(i) {
        var w = words[i], s = status[i];
        var el = document.createElement('div');
        el.className = 'row' + (i === cursor ? ' is-current' : '');
        el.dataset.i = String(i);
        el.title = '点击跳到这个词';

        var main = document.createElement('div');
        main.className = 'row-main';
        var wd = document.createElement('div');
        wd.className = 'row-word';
        wd.textContent = w[0];
        var sub = document.createElement('div');
        sub.className = 'row-sub';
        sub.textContent = w[2] || '';
        main.appendChild(wd);
        main.appendChild(sub);

        var state = document.createElement('div');
        state.className = 'row-state';

        var v = views[i] || 0;
        if (v >= 2) {
            var cnt = document.createElement('span');
            cnt.className = 'row-views';
            cnt.textContent = '×' + v;
            cnt.title = '看过 ' + v + ' 次';
            state.appendChild(cnt);
        }

        var dot = document.createElement('span');
        dot.className = 'row-dot';
        dot.dataset.status = s === KNOWN ? 'known' : (s === UNKNOWN ? 'unknown' : 'new');
        dot.title = STATUS_TEXT[s] || '未标注';
        state.appendChild(dot);

        state.appendChild(mkAct('known', '✓', '标记为「认识」（再点一次取消）', s === KNOWN));
        state.appendChild(mkAct('unknown', '✕', '标记为「不认识」（再点一次取消）', s === UNKNOWN));

        el.appendChild(main);
        el.appendChild(state);
        return el;
    }

    /** 虚拟列表：只渲染视口内的行，9390 条也秒开 */
    function layoutList(force) {
        spacer.style.height = (filtered.length * ROW_H) + 'px';
        listCount.textContent = '显示 ' + filtered.length.toLocaleString()
            + ' / ' + words.length.toLocaleString() + ' 条';

        if (!filtered.length) {
            layer.style.transform = 'translateY(0)';
            layer.innerHTML = '<div class="list-empty">没有匹配的词</div>';
            rendered = [-1, -1];
            return;
        }

        var st = viewport.scrollTop, vh = viewport.clientHeight || 400;
        var start = Math.max(0, Math.floor(st / ROW_H) - 5);
        var end = Math.min(filtered.length, Math.ceil((st + vh) / ROW_H) + 5);
        if (!force && start === rendered[0] && end === rendered[1]) return;
        rendered = [start, end];

        var frag = document.createDocumentFragment();
        for (var k = start; k < end; k++) frag.appendChild(rowEl(filtered[k]));
        layer.style.transform = 'translateY(' + (start * ROW_H) + 'px)';
        layer.textContent = '';
        layer.appendChild(frag);
    }

    /** 只改一行，避免整表重绘 */
    function updateRow(i) {
        var el = layer.querySelector('.row[data-i="' + i + '"]');
        if (!el) return;
        var s = status[i];
        var dot = el.querySelector('.row-dot');
        if (dot) {
            dot.dataset.status = s === KNOWN ? 'known' : (s === UNKNOWN ? 'unknown' : 'new');
            dot.title = STATUS_TEXT[s] || '未标注';
        }
        el.querySelector('[data-act="known"]').classList.toggle('is-on', s === KNOWN);
        el.querySelector('[data-act="unknown"]').classList.toggle('is-on', s === UNKNOWN);
    }

    function syncListSelection() {
        Array.prototype.forEach.call(layer.querySelectorAll('.row.is-current'), function (el) {
            el.classList.remove('is-current');
        });
        if (cursor < 0) return;

        var k = filtered.indexOf(cursor);
        if (k < 0) return;                       // 当前词被筛选条件排除了

        var vh = viewport.clientHeight, top = k * ROW_H, st = viewport.scrollTop;
        if (top < st || top + ROW_H > st + vh) {
            viewport.scrollTop = Math.max(0, top - (vh - ROW_H) / 2);
            layoutList(true);                    // 改变滚动位置后立刻重绘，高亮行才在 DOM 里
        }
        var el = layer.querySelector('.row[data-i="' + cursor + '"]');
        if (el) el.classList.add('is-current');
    }

    /** 统一的改状态入口：本地先改、再异步落库 */
    function changeStatus(i, nextStatus, silent) {
        var prev = status[i];
        if (prev === nextStatus) return;
        status[i] = nextStatus;
        refreshStats();
        updateRow(i);

        // 当前筛选条件不再包含这一行 -> 重排
        var stillMatch = !(filter === 'left' && nextStatus !== NEW)
            && !(filter === 'known' && nextStatus !== KNOWN)
            && !(filter === 'unknown' && nextStatus !== UNKNOWN);
        if (!stillMatch) {
            rebuildFiltered();
            layoutList(true);
        }

        if (i === cursor) { renderCard(); syncListSelection(); }

        if (!silent) {
            // 顺带告知牌组发生了什么 —— 这是两个模式之间唯一的“管道”反馈
            var msg = words[i][0] + ' → ' + STATUS_TEXT[nextStatus];
            if (nextStatus === UNKNOWN && prev !== UNKNOWN) msg += '，已加入复习';
            if (nextStatus !== UNKNOWN && prev === UNKNOWN) msg += '，复习卡已移除';
            toast(msg, nextStatus === KNOWN ? 'known' : (nextStatus === UNKNOWN ? 'unknown' : ''));
        }
        quiet(post('mark', { id: i + 1, status: nextStatus }), '标记');

        // 全部标完的状态下，把某个词改回「未标注」才重新进入标注循环；
        // 改成「不认识」只是进复习牌组，标注循环不再管它
        if (cursor < 0 && nextStatus === NEW) moveTo(i, false);
    }

    // 侧栏点击
    layer.addEventListener('click', function (e) {
        var node = e.target;
        var row = node.closest ? node.closest('.row') : null;
        if (!row) return;
        var i = parseInt(row.dataset.i, 10);
        var act = node.dataset ? node.dataset.act : null;

        if (act === 'known') { changeStatus(i, status[i] === KNOWN ? NEW : KNOWN); return; }
        if (act === 'unknown') { changeStatus(i, status[i] === UNKNOWN ? NEW : UNKNOWN); return; }

        moveTo(i, false);        // 点行体：跳到这个词
        closeSidebar();
    });

    viewport.addEventListener('scroll', function () { layoutList(false); }, { passive: true });

    if (window.ResizeObserver) {
        new ResizeObserver(function () { layoutList(true); }).observe(viewport);
    } else {
        window.addEventListener('resize', function () { layoutList(true); });
    }

    filters.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.filter') : null;
        if (!btn) return;
        filter = btn.dataset.filter;
        Array.prototype.forEach.call(filters.children, function (b) {
            b.classList.toggle('is-active', b === btn);
        });
        rebuildFiltered();
        viewport.scrollTop = 0;
        layoutList(true);
    });

    var searchTimer = null;
    search.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () {
            query = search.value;
            rebuildFiltered();
            viewport.scrollTop = 0;
            layoutList(true);
        }, 120);
    });


    // ==================== 侧栏抽屉 ====================

    // 标注和复习各有各的侧栏，抽屉开关作用于当前可见的那个
    function activeSidebar() {
        return mode === 'review' ? rsidebar : sidebar;
    }

    function openSidebar()  { activeSidebar().classList.add('is-open'); scrim.classList.add('is-open'); }
    function closeSidebar() {
        sidebar.classList.remove('is-open');
        rsidebar.classList.remove('is-open');
        scrim.classList.remove('is-open');
    }
    function toggleSidebar() {
        if (activeSidebar().classList.contains('is-open')) closeSidebar();
        else openSidebar();
    }

    btnSidebar.addEventListener('click', toggleSidebar);
    scrim.addEventListener('click', closeSidebar);


    // ==================== 主题 / 遮释义 ====================

    function toggleTheme() {
        var next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = next;
        try { localStorage.setItem('ielts.theme', next); } catch (err) { /* 隐私模式忽略 */ }
        layoutList(true);
    }

    btnTheme.addEventListener('click', function (e) { e.currentTarget.blur(); toggleTheme(); });

    btnMask.addEventListener('click', function (e) {
        e.currentTarget.blur();
        toggleMask();
    });

    cardDef.addEventListener('click', function () {
        if (!triageMasked()) return;
        peeked = true;
        applyMask();
    });


    // ==================== 从头开始 / 重置 ====================

    function restart() {
        if (!ready) return;
        var i = nextUnlearned(-1);
        if (i < 0) { toast('全部词都已标注完', 'known'); moveTo(-1, false); return; }
        moveTo(i, false);
        toast('从 #' + (i + 1) + ' 开始');
    }

    btnRestart.addEventListener('click', function (e) { e.currentTarget.blur(); restart(); });

    // 通用确认弹层：主重置和复习重置都要用
    var confirmAction = null;

    function showConfirm(title, body, action) {
        confirmTitle.textContent = title;
        confirmBody.textContent = body;
        confirmAction = action;
        confirmEl.hidden = false;
        $('confirmYes').focus();
    }

    btnReset.addEventListener('click', function (e) {
        e.currentTarget.blur();
        showConfirm(
            '清空全部进度？',
            '所有词的“认识 / 不认识”标记、复习牌组和复习历史都会被删除，回到全新状态。'
            + '此操作不可撤销，词表本身不受影响。',
            function () {
                post('reset', {}).then(function () {
                    status.fill(NEW);
                    views.fill(0);
                    viewQueue.clear();
                    refreshStats();
                    rebuildFiltered();
                    viewport.scrollTop = 0;
                    layoutList(true);
                    moveTo(nextUnlearned(-1), false);
                    toast('进度已清空');
                    if (mode === 'review') refreshReview();   // 牌组空了，复习页要跟着变
                }).catch(function (err) { toast('清空失败：' + err.message, 'error'); });
            }
        );
    });

    function closeConfirm() { confirmEl.hidden = true; confirmAction = null; }

    $('confirmNo').addEventListener('click', closeConfirm);
    confirmEl.addEventListener('click', function (e) { if (e.target === confirmEl) closeConfirm(); });

    $('confirmYes').addEventListener('click', function () {
        var fn = confirmAction;
        closeConfirm();
        if (typeof fn === 'function') fn();
    });


    // ==================== 按钮 ====================

    btnDone.addEventListener('click', function (e) { e.currentTarget.blur(); doDone(); });
    btnContinue.addEventListener('click', function (e) { e.currentTarget.blur(); doContinue(); });
    btnUnknown.addEventListener('click', function (e) { e.currentTarget.blur(); doUnknown(); });

    // 动画播完把类摘掉，保持 DOM 干净
    btnUnknown.addEventListener('animationend', function () {
        btnUnknown.classList.remove('fx-purple');
    });


    // ==================== 复习模式 ====================
    //
    // 队列由服务端驱动：进入模式时 GET srs-state 拿“下一张卡 + 统计”，
    // 每次评分 POST srs-grade，响应里直接带下一张。SM-2 只在服务端实现一份，
    // 前端不做本地排队，不会出现两边算法不一致的问题。

    var TZ_PARAM = '&tz=' + (new Date().getTimezoneOffset());

    var STAGE_TEXT = { 0: '新卡', 1: '学习中', 2: '复习中' };

    function srsCall(action) {
        return post(action, { tz: new Date().getTimezoneOffset() });
    }

    function switchMode(next) {
        if (mode === next) return;
        mode = next;
        document.documentElement.dataset.mode = next;
        stopWaitTimer();          // 离开复习模式就别再后台刷新了
        try { localStorage.setItem('ielts.mode', next); } catch (err) { /* 隐私模式忽略 */ }
        updateModeButtons();
        closeSidebar();
        if (next === 'review') refreshReview();
    }

    function updateModeButtons() {
        if (!modeSwitch) return;
        Array.prototype.forEach.call(modeSwitch.querySelectorAll('.mode-btn'), function (b) {
            b.classList.toggle('is-active', b.dataset.mode === mode);
        });
    }

    modeSwitch.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.mode-btn') : null;
        if (btn) switchMode(btn.dataset.mode);
    });

    function refreshReview() {
        if (!ready) return;
        get('srs-state', TZ_PARAM).then(function (data) {
            rData = data;
            renderReview();
        }).catch(function (err) {
            toast('复习数据加载失败：' + err.message, 'error');
        });
    }

    function renderReview() {
        if (!rData) return;
        stopWaitTimer();
        renderReviewStats(rData.stats);

        var c = rData.card;
        rpeeked = false;                 // 每张卡重新回到「遮住」状态

        if (c) {
            waitFails = 0;
            rPos.textContent = '#' + c.id;
            rStage.textContent = STAGE_TEXT[c.stage] || '新卡';
            // 阶段只是个标签，不套状态色 —— 「学习中 / 复习中」都不是负面状态，
            // 给它们染上琥珀或绿色会误导
            rStage.removeAttribute('data-status');
            rWord.textContent = c.w;
            rIpa.textContent = c.p || '';
            rDef.textContent = c.d || '（无释义）';
            pvForgot.textContent = c.prev[0];
            pvGood.textContent = c.prev[1];
            pvEasy.textContent = c.prev[2];
            applyRMask();
            playReviewFx();
        } else if (rData.waiting && rData.waiting.next_due) {
            // 这一轮做完了，但还有卡没过到期时间（典型：刚按「记得」的卡几分钟后回来）。
            // 不是「今天完成」——所以给倒计时，到点自动拉取，不需要手动刷新。
            rPos.textContent = '';
            rStage.textContent = '等待';
            rStage.removeAttribute('data-status');
            var n = rData.waiting.ready_then || 1;
            var blocked = rData.waiting.blocked || 0;
            rIpa.textContent = n > 1 ? ('还有 ' + n + ' 张排队中') : '下一张马上回来';
            rDef.textContent = '';
            rFoot.textContent = '';
            pvForgot.textContent = pvGood.textContent = pvEasy.textContent = '—';
            applyRMask();
            armWaitTimer(rData.waiting.next_due, blocked > 0
                ? '今天还有 ' + blocked + ' 张到期的没做 —— 想继续就把侧栏的今日上限调大'
                : '');
        } else {
            rPos.textContent = '';
            rStage.textContent = rData.stats && rData.stats.total > 0 ? '今日完成' : '空牌组';
            rStage.removeAttribute('data-status');
            rWord.textContent = (rData.stats && rData.stats.total > 0) ? '今日复习完成' : '还没有可复习的词';
            rIpa.textContent = '';
            var s = rData.stats || { introduced_today: 0, reviewed_review_today: 0, due_24h: 0, total: 0 };
            if (s.total > 0) {
                var msg = '今天新学 ' + s.introduced_today + ' 张、复习 ' + (s.reviewed_review_today || 0) + ' 张。';
                // 因为「今日复习上限」停下时要说清楚，否则明明还有到期的卡却显示完成，像是坏了
                if (s.daily_review >= 0 && s.review_left === 0 && s.due_now > 0) {
                    msg += '今天还有 ' + s.due_now + ' 张到期的没做 —— 想继续就把侧栏的今日上限调大。';
                } else {
                    if (s.due_24h > 0) msg += '未来 24 小时还有 ' + s.due_24h + ' 张到期。';
                    msg += '回到标注模式继续标注，明天再来。';
                }
                rDef.textContent = msg;
            } else {
                rDef.textContent = '在标注模式里按 3（或点「标记为不认识」），词就会进入这里。';
            }
            rFoot.textContent = '';
            pvForgot.textContent = pvGood.textContent = pvEasy.textContent = '—';
            applyRMask();
        }
    }

    /** 这一张现在是不是遮着的 */
    function reviewMasked() {
        return rmask && !rpeeked && !!(rData && rData.card);
    }

    /**
     * 遮罩状态。和标注页同一套做法：模糊而不是藏起来，
     * 所以三个评分键从一开始就可用 —— 不用先「翻面」就能评分。
     */
    function applyRMask() {
        var c = rData && rData.card;
        rDef.classList.toggle('is-masked', reviewMasked());
        // 同标注页：按钮跟着遮罩偏好走，不跟着这一张的临时偷看走
        btnRMask.classList.toggle('is-on', rmask);
        btnRMask.textContent = rmask ? '释义已遮' : '遮住释义';
        btnRMask.style.visibility = c ? '' : 'hidden';

        // 有卡就能评分；没有卡（等待/完成）才禁用
        btnForgot.disabled = btnGood.disabled = btnEasy.disabled = !c;

        paintReviewFoot();
    }

    /** 遮罩开关：切换偏好。重新打开时清掉这一张的偷看，让它真的糊上。 */
    function toggleReviewMask() {
        rmask = !rmask;
        if (rmask) rpeeked = false;
        applyRMask();
    }

    /** 脚注：遮着的时候放那句「先回想」提示，取消模糊后换成卡片统计 */
    function paintReviewFoot() {
        var c = rData && rData.card;
        if (!c) { rFoot.textContent = ''; return; }
        if (reviewMasked()) {
            rFoot.textContent = '先回想意思，按 空格 或点释义取消模糊；想好了也可以直接按 1 / 2 / 3 评分';
            return;
        }
        rFoot.textContent = c.lapses > 0
            ? '这张卡忘了 ' + c.lapses + ' 次' + (c.reps > 0 ? '，连对 ' + c.reps + ' 次' : '')
            : (c.reps > 0 ? '连对 ' + c.reps + ' 次' : '');
    }

    /** 空格 / 点释义：取消模糊看一眼（不改遮罩偏好） */
    function peekReview() {
        if (!reviewMasked()) return;
        rpeeked = true;
        applyRMask();
    }

    function playReviewFx() {
        rcard.classList.remove('fx-in');
        void rcard.offsetWidth;
        rcard.classList.add('fx-in');
    }

    rcard.addEventListener('animationend', function () { rcard.classList.remove('fx-in'); });

    btnRMask.addEventListener('click', function (e) {
        e.currentTarget.blur();
        toggleReviewMask();
    });

    rDef.addEventListener('click', peekReview);
    rcard.addEventListener('click', function (e) {
        if (mode !== 'review' || !reviewMasked()) return;
        if (e.target.closest && e.target.closest('button')) return;
        peekReview();                      // 点卡片任意处都能取消模糊，手机上更好按
    });

    /** 时间戳转“x 分钟后”这类文案 */
    function fmtTs(ts) {
        var d = Math.round((ts * 1000 - Date.now()) / 1000);
        if (d < 60) return '马上';
        if (d < 3600) return Math.round(d / 60) + ' 分钟后';
        if (d < 86400) return Math.round(d / 3600) + ' 小时后';
        return Math.round(d / 86400) + ' 天后';
    }

    /** 秒数 → m:ss / h:mm:ss */
    function fmtCountdown(sec) {
        sec = Math.max(0, Math.round(sec));
        var m = Math.floor(sec / 60), s = sec % 60;
        if (m < 60) return m + ':' + (s < 10 ? '0' : '') + s;
        return Math.floor(m / 60) + ':' + ('0' + (m % 60)).slice(-2) + ':' + ('0' + s).slice(-2);
    }

    function stopWaitTimer() {
        if (waitTick) { clearInterval(waitTick); waitTick = null; }
        if (waitRetry) { clearTimeout(waitRetry); waitRetry = null; }
    }

    /**
     * 等待下一张到期：每秒刷新倒计时，到点自动拉取 —— 用户不用手动刷新。
     *
     * 有个坑要防：客户端和服务端时钟可能差几秒。如果客户端先到点、服务端说还没到期，
     * 拿回来的 next_due 和上次一样，直接重试就变成空转打接口。
     * 所以连续几次没结果就退到「手动刷新」，不再自动重试。
     */
    function armWaitTimer(nextDue, note) {
        stopWaitTimer();
        var tip = note || '这一轮做完了，休息一下';

        function paint() {
            var left = nextDue - Date.now() / 1000;
            if (left > 0) {
                rWord.textContent = fmtCountdown(left);
                rFoot.textContent = tip;
                return false;
            }
            return true;
        }

        paint();                                   // 先画一次，别让用户等 1 秒才看到倒计时

        waitTick = setInterval(function () {
            if (!paint()) return;
            stopWaitTimer();
            if (waitFails >= 3) {
                rWord.textContent = '时间到了';
                rFoot.textContent = '刷新页面即可继续';
                return;
            }
            waitFails++;
            rWord.textContent = '马上回来…';
            waitRetry = setTimeout(refreshReview, 1200 * waitFails);
        }, 1000);
    }

    function renderReviewStats(s) {
        if (!s) return;
        var lines = [];
        lines.push('<span class="rstat-line">牌组 <b>' + s.total + '</b> 张'
            + '（新 <b>' + s.new + '</b> · 学习中 <b>' + s.learning + '</b> · 复习 <b>' + s.review + '</b>）</span>');
        lines.push('<span class="rstat-line">今天：新学 <b>' + s.introduced_today + '/' + s.daily_new + '</b>'
            + ' · 已复习 <b>' + s.reviewed_review_today
            + (s.daily_review >= 0 ? '/' + s.daily_review : '') + '</b> 张</span>');
        lines.push('<span class="rstat-line">当前到期 <b>' + s.due_now + '</b> 张'
            + (s.due_24h > 0 ? ' · 未来 24h 再到 ' + s.due_24h + ' 张' : '') + '</span>');
        rStats.innerHTML = lines.join('');

        renderLimits();

        // 接下来会遇到的卡（侧栏）
        var q = (rData && rData.queue) || [];
        if (!q.length) {
            rQueue.innerHTML = '<div class="rq-empty">没有排队的卡</div>';
            return;
        }
        var html = [];
        for (var k = 0; k < q.length; k++) {
            var w = words[q[k][0] - 1];
            if (!w) continue;
            html.push('<div class="rq-row"><span class="rq-word"></span>'
                + '<span class="rq-stage">' + (STAGE_TEXT[q[k][1]] || '') + '</span></div>');
        }
        rQueue.innerHTML = html.join('');
        // 词单独填，避免 innerHTML 拼接转义问题
        var rows = rQueue.querySelectorAll('.rq-row .rq-word');
        for (var j = 0; j < rows.length && j < q.length; j++) {
            var ww = words[q[j][0] - 1];
            if (ww) rows[j].textContent = ww[0];
        }
    }

    /** 把服务端「今天实际生效的上限」回填到输入框 */
    function renderLimits() {
        var lim = rData && rData.limits;
        if (!lim) return;
        setNew.value = lim.new;
        setRev.value = lim.review;
    }

    /**
     * 保存今日上限。清空输入框 → 传 null → 清除覆盖、回到默认。
     * 正在看某张卡时不把卡换掉，只更新侧栏数字 —— 免得设置一下卡片突然跳走。
     */
    function saveLimits(payload) {
        if (mode !== 'review') return;
        payload.tz = new Date().getTimezoneOffset();

        post('srs-limits', payload).then(function (res) {
            var keep = rData && rData.card;
            rData = {
                stats: res.stats, limits: res.limits, queue: res.queue,
                card: keep ? rData.card : res.card,
                why: res.why, waiting: res.waiting
            };
            if (keep) {
                renderReviewStats(res.stats);
                paintReviewFoot();
            } else {
                renderReview();
            }
            toast('今日上限已更新');
        }).catch(function (err) {
            toast('设置失败：' + err.message, 'error');
            renderLimits();                 // 失败就把输入框退回服务端的值
        });
    }

    function onLimitChange(input, kind) {
        var raw = String(input.value).trim();
        var payload = {};
        if (raw === '') {
            payload[kind] = null;           // 清空 = 恢复默认
        } else {
            var n = parseInt(raw, 10);
            // 复习上限的「不限」是 -1；新卡没有「不限」，最小 0
            var min = (kind === 'review') ? -1 : 0;
            if (isNaN(n) || n < min) n = min;
            if (n > 9999) n = 9999;
            payload[kind] = n;
        }
        saveLimits(payload);
    }

    setNew.addEventListener('change', function () { onLimitChange(setNew, 'new'); });
    setRev.addEventListener('change', function () { onLimitChange(setRev, 'review'); });

    function doReviewGrade(grade) {
        // 不要求先取消模糊 —— 想起来就能直接评分，这是刻意的
        if (mode !== 'review' || !rData || !rData.card) return;
        flash(grade === 0 ? btnForgot : (grade === 2 ? btnEasy : btnGood));
        post('srs-grade', {
            id: rData.card.id,
            grade: grade,
            tz: new Date().getTimezoneOffset()
        }).then(function (res) {
            rData = {
                card: res.card, why: res.why, waiting: res.waiting,
                queue: res.queue, stats: res.stats, limits: res.limits
            };
            renderReview();
            // 「忘了」的提示也用紫色（和按钮一致），不用琥珀色
            if (res.note) toast('下次：' + res.note, grade === 0 ? 'purple' : 'known');
        }).catch(function (err) {
            toast('评分失败：' + err.message, 'error');
        });
    }

    function doReviewUndo() {
        if (mode !== 'review') return;
        srsCall('srs-undo').then(function (res) {
            rData = {
                card: res.card, why: res.why, waiting: res.waiting,
                queue: res.queue, stats: res.stats, limits: res.limits
            };
            renderReview();
            toast('已撤销上一次评分');
        }).catch(function (err) {
            toast(err.message || '没有可撤销的', 'error');
        });
    }

    btnForgot.addEventListener('click', function (e) { e.currentTarget.blur(); doReviewGrade(0); });
    btnGood.addEventListener('click',   function (e) { e.currentTarget.blur(); doReviewGrade(1); });
    btnEasy.addEventListener('click',   function (e) { e.currentTarget.blur(); doReviewGrade(2); });
    btnUndo.addEventListener('click',   function (e) { e.currentTarget.blur(); doReviewUndo(); });

    btnSrsReset.addEventListener('click', function (e) {
        e.currentTarget.blur();
        showConfirm(
            '重置复习进度？',
            '所有卡片的间隔、难度和复习历史会被清空，全部回到新卡状态，从今天重新排期。'
            + '「不认识」的标注本身不受影响。',
            function () {
                srsCall('srs-reset').then(function (res) {
                    rData = {
                        card: res.card, why: res.why, waiting: res.waiting,
                        queue: res.queue, stats: res.stats, limits: res.limits
                    };
                    renderReview();
                    toast('复习进度已重置');
                }).catch(function (err) { toast('重置失败：' + err.message, 'error'); });
            }
        );
    });


    // ==================== 键盘 ====================

    document.addEventListener('keydown', function (e) {
        var tag = (e.target.tagName || '').toLowerCase();
        var typing = tag === 'input' || tag === 'textarea';

        if (e.key === 'Escape') {
            if (typing) e.target.blur();
            else if (!confirmEl.hidden) closeConfirm();
            else closeSidebar();
            return;
        }
        if (typing || e.ctrlKey || e.metaKey || e.altKey) return;

        if (!confirmEl.hidden) {
            if (e.key === 'Enter') { e.preventDefault(); $('confirmYes').click(); }
            return;
        }

        // 焦点在按钮上时把 Enter / Space 让给浏览器的原生激活，避免触发两次
        var onButton = document.activeElement && document.activeElement.tagName === 'BUTTON';
        if (onButton && (e.key === 'Enter' || e.key === ' ')) return;

        var k = e.key.toLowerCase();

        // 两个模式共用的键
        if (k === 'm') { e.preventDefault(); switchMode(mode === 'review' ? 'triage' : 'review'); return; }
        if (k === 't') { e.preventDefault(); toggleTheme(); return; }
        if (k === 'l') { e.preventDefault(); toggleSidebar(); return; }

        // ---- 复习模式 ----
        if (mode === 'review') {
            if (k === ' ') {
                e.preventDefault();
                // 遮着就取消模糊看一眼；已经看到了就当作「记得」，省一次按键
                if (reviewMasked()) peekReview();
                else doReviewGrade(1);
                return;
            }
            if (e.key === 'Enter') { e.preventDefault(); doReviewGrade(1); return; }
            if (k === '1') { e.preventDefault(); doReviewGrade(0); return; }
            if (k === '2') { e.preventDefault(); doReviewGrade(1); return; }
            if (k === '3') { e.preventDefault(); doReviewGrade(2); return; }
            if (k === 'h') { e.preventDefault(); toggleReviewMask(); return; }
            if (k === 'u') { e.preventDefault(); doReviewUndo(); return; }
            return;
        }

        // ---- 标注模式 ----
        // 主快捷键是 1 / 2 / 3，方向键作为位置上的直觉补充
        if (k === '1' || e.key === 'ArrowRight') { e.preventDefault(); doDone(); return; }
        if (k === '2' || e.key === 'ArrowLeft' || k === ' ' || e.key === 'Enter') {
            e.preventDefault(); doContinue(); return;
        }
        if (k === '3') { e.preventDefault(); doUnknown(); return; }
        if (k === 'r') { e.preventDefault(); restart(); return; }
        if (k === 'h') { e.preventDefault(); toggleMask(); return; }
        if (k === '/') { e.preventDefault(); openSidebar(); search.focus(); search.select(); return; }
    });


    // ==================== 启动 ====================

    Promise.all([get('words'), get('state')]).then(function (res) {
        var wd = res[0], st = res[1];
        words = wd.words;

        if (st.version && wd.version && st.version !== wd.version) {
            toast('词表已更新，原进度可能错位', 'error');
        }

        status = new Uint8Array(words.length);
        views = new Uint16Array(words.length);
        st.rows.forEach(function (r) {
            var i = r[0] - 1;
            if (i < 0 || i >= words.length) return;
            status[i] = r[1];
            views[i] = Math.min(65535, r[2]);
        });

        ready = true;
        refreshStats();
        rebuildFiltered();

        // 从上次的位置继续。存档的那个词如果已经变成「认识」（可能是别的设备标的），
        // 就顺着往下找第一个未标注的；存档失效则退回第一个未标注的词。
        var saved = Math.floor(Number(st.last_id) || 0);
        var start;
        if (saved >= 1 && saved <= words.length) {
            var si = saved - 1;
            // 存档的位置只有还「未标注」才能直接落上去。
            // 它可能已经被标完了 —— 标成不认识就归复习模式管了，不能再出现在标注卡片上。
            start = status[si] === NEW ? si : nextUnlearned(si);
        } else {
            start = nextUnlearned(-1);
        }

        cursor = start;
        layoutList(true);
        renderCard();
        syncListSelection();

        // 记住上一次用的模式。首屏内联脚本已经设过 data-mode 避免闪烁，
        // 这里补上按钮高亮，并把复习数据拉下来
        var savedMode = 'triage';
        try {
            var sm = localStorage.getItem('ielts.mode');
            if (sm === 'review') savedMode = 'review';
        } catch (err) { /* 隐私模式忽略 */ }
        mode = savedMode;
        document.documentElement.dataset.mode = mode;
        updateModeButtons();
        if (mode === 'review') refreshReview();

        // 位置恢复的提示只在标注模式下给 —— 复习模式有自己的“接下来”，
        // 两个模式的续读各自独立，别拿标注的消息去打扰复习
        if (mode === 'triage' && saved >= 1 && start === saved - 1) {
            toast('从上次的 #' + saved + ' 继续');
        }
    }).catch(function (err) {
        console.error(err);
        if (authRedirecting) {
            cardWord.textContent = '正在跳转到登录页…';
            return;
        }
        cardWord.textContent = '加载失败';
        cardIpa.textContent = '';
        cardDef.textContent = err.message;
        toast('数据加载失败：' + err.message, 'error');
    });

})();
