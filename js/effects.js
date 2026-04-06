// ============================================================
//  effects.js — ターミナルUI / API通信 / ゲームフロー制御
// ============================================================

const API = 'api/action.php';

const SAVE_API = 'api/save.php';

let G = {
    screen: 'title',
    player: null,
    mob:    null,
    pendingStats: null,
    rerolls: 0,
    innCost: 0,
    weaponStock: [],
    itemStock: [],
    // セーブ関連
    activeSlot:  null,   // 現在プレイ中のstoryスロット ('story_1'等)
    storySlots:  [],     // 起動時に取得したスロット概要
    uuid:        null,
};

// ============================================================
//  ログ出力 — textarea + canvas CRTオーバーレイ方式
// ============================================================

// ---- 設定 ----
const LOG_MAX_LINES = 40;   // 20 / 40 / 60 で切替可（将来: 設定画面から変更）

const logArea     = () => document.getElementById('log-area');
const logTextarea = () => document.getElementById('log-textarea');

let typeQueue = [];
let typing    = false;
let _logLines = [];   // 現在のログ行を配列で管理

function print(text, cls = 'prompt', delay = 0) {
    typeQueue.push({ text, delay });
    if (!typing) drainQueue();
}

function printBlank() { print(''); }

async function drainQueue() {
    typing = true;
    while (typeQueue.length > 0) {
        const { text, delay } = typeQueue.shift();
        await printLine(text, delay);
    }
    typing = false;
    scrollBottom();
}

function printLine(text, delay) {
    return new Promise(resolve => {
        setTimeout(() => {
            _logLines.push(text);
            // 上限超えたら古い行を削除
            if (_logLines.length > LOG_MAX_LINES) {
                _logLines = _logLines.slice(_logLines.length - LOG_MAX_LINES);
            }
            logTextarea().value = _logLines.join("\n");

            scrollBottom();
            resolve();
        }, delay);
    });
}

function printLines(lines, cls = 'prompt', baseDelay = 0, step = 40) {
    lines.forEach((l, i) => print(l, cls, baseDelay + i * step));
}

function scrollBottom() {
    const ta = logTextarea();
    ta.scrollTop = ta.scrollHeight;
}

function clearLog() {
    _logLines = [];
    logTextarea().value = '';
    typeQueue = [];
    typing    = false;
    clearScene();
}

// ============================================================
//  シーン画像管理
// ============================================================

// 背景画像テーブル（素材未配置時は空文字のまま → 非表示）
const SCENE_BG = {
    map_1:      'img/bg/alley.png',
    map_2:      'img/bg/downtown.png',
    map_3:      'img/bg/docks.png',
    fight_1:    'img/bg/alley_fight.png',
    fight_2:    'img/bg/downtown_fight.png',
    fight_3:    'img/bg/docks_fight.png',
    boss_1:     'img/bg/alley_boss.png',
    boss_2:     'img/bg/downtown_boss.png',
    boss_3:     'img/bg/docks_boss.png',
    restaurant: 'img/bg/restaurant.png',   // 宿→飲食店
    dojo:       'img/bg/dojo.png',
    weapon:     'img/bg/weapon_shop.png',
    armor:      'img/bg/armor_shop.png',
    item:       'img/bg/item_shop.png',
    informer:   'img/bg/informer.png',     // 情報屋イベント
};

// ボス画像テーブル
const BOSS_IMG = {
    1: 'img/mob/boss_crow.png',
    2: 'img/mob/boss_snake.png',
    3: 'img/mob/boss_kraken.png',
};

// プレイヤー画像
const PLAYER_IMG = {
    normal:  'img/player/normal.png',
    damaged: 'img/player/damaged.png',
};

// NPCプール定義（入店ごとにランダム選択）
const NPC_POOL = {
    restaurant: ['img/npc/ff_staff_a.png', 'img/npc/ff_staff_b.png', 'img/npc/ff_staff_c.png'],
    shop:       ['img/npc/shop_a.png',     'img/npc/shop_b.png',     'img/npc/shop_c.png'],
    item:       ['img/npc/item_staff_a.png', 'img/npc/item_staff_b.png'],
    dojo:       ['img/npc/master.png'],
    informer:   ['img/npc/informer.png'],
};

/** NPCプールからランダム1枚を返す */
function pickNPC(pool) {
    const arr = NPC_POOL[pool] || [];
    if (!arr.length) return '';
    return arr[Math.floor(Math.random() * arr.length)];
}

/**
 * レイヤーに画像をセット。ファイルが存在しなければ非表示のまま。
 */
function setLayer(id, src) {
    const el = document.getElementById(id);
    if (!el || !src) return;
    const img = new Image();
    img.onload = () => {
        el.style.backgroundImage = `url('${src}')`;
        el.classList.add('visible');
    };
    img.onerror = () => {
        // ファイル未配置 — 何もしない（非表示のまま）
    };
    img.src = src;
}

function clearLayer(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('visible');
    el.style.backgroundImage = '';
}

/**
 * シーンをセット
 * @param {object} opts - { bgKey, mob, player }
 *   bgKey:  SCENE_BGのキー
 *   mob:    MOB画像パス（省略可）
 *   player: プレイヤー画像パス（省略可）
 */
function setScene({ bgKey = '', mob = '', player = '' } = {}) {
    const bgSrc = SCENE_BG[bgKey] || '';
    setLayer('scene-bg',     bgSrc);
    setLayer('scene-mob',    mob);
    setLayer('scene-player', player);
    // 画像が1枚でもあればlog-areaにhas-sceneを付与
    if (bgSrc || mob || player) {
        logArea().classList.add('has-scene');
    }
}

function clearScene() {
    clearLayer('scene-bg');
    clearLayer('scene-mob');
    clearLayer('scene-player');
    logArea().classList.remove('has-scene');
}

// ============================================================
//  コマンドエリア
// ============================================================
function setCommands(title, buttons) {
    document.getElementById('cmd-title').textContent = title;
    const area = document.getElementById('cmd-buttons');
    area.innerHTML = '';
    buttons.forEach(b => {
        const btn = document.createElement('button');
        btn.className = 'cmd-btn' + (b.cls ? ' ' + b.cls : '');
        btn.textContent = b.label;
        if (b.disabled) btn.disabled = true;
        btn.onclick = b.action;
        area.appendChild(btn);
    });
}

function disableCommands() {
    document.querySelectorAll('.cmd-btn').forEach(b => b.disabled = true);
}

// ============================================================
//  ステータスバー
// ============================================================
function updateHUD(p) {
    if (!p) return;
    G.player = p;
    const hpPct  = Math.round(p.hp / p.max_hp * 100);
    const hpCls  = hpPct <= 25 ? 'danger' : hpPct <= 50 ? 'warn' : '';
    const fevDays = p.gold_fever_days || 0;
    const fevCls  = fevDays > 0 ? 'active' : '';
    const armorBonus = p.armor ? p.armor.def_bonus : 0;
    const effDef     = p.def + armorBonus + (p.temp_def || 0);
    const armorName  = p.armor ? p.armor.name : 'なし';
    document.getElementById('status-bar').innerHTML = `
      <div class="status-row">
        <span class="stat-item ${hpCls}">HP:<span>${p.hp}/${p.max_hp}</span></span>
        <span class="stat-item">MP:<span>${p.mp}/${p.max_mp}</span></span>
        <span class="stat-item">¥<span>${p.money.toLocaleString()}</span></span>
        <span class="stat-item">DAY:<span>${p.day || 1}</span></span>
        <span class="stat-item ${fevCls}">護符:<span>${fevDays > 0 ? '残'+fevDays+'日' : 'OFF'}</span></span>
      </div>
      <div class="status-row">
        <span class="stat-item">ATK:<span>${p.atk}</span></span>
        <span class="stat-item">DEF:<span>${effDef}${armorBonus > 0 ? '(+'+armorBonus+')' : ''}</span></span>
        <span class="stat-item">AGI:<span>${p.agi}</span></span>
        <span class="stat-item">LUK:<span>${p.luk}</span></span>
        <span class="stat-item">STG:<span>${p.stage}</span></span>
        ${(p.ex_stage||0)>0 ? `<span class="stat-item warn">EX:<span>${p.ex_stage}</span></span>` : ''}
        ${(p.ex_depth_max||0)>0 ? `<span class="stat-item">BEST EX:<span>${p.ex_depth_max}</span></span>` : ''}
        <span class="stat-item">武器:<span>${p.weapons.length}</span></span>
        <span class="stat-item">防具:<span>${p.armor ? p.armor.name + ' [' + (p.armor.durability ?? '?') + ']' : '—'}</span></span>
        <span class="stat-item">道具:<span>${p.items.length}</span></span>
      </div>
    `;
}

// ============================================================
//  API 呼び出し
// ============================================================
async function api(action, extra = {}) {
    try {
        const res = await fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, ...extra }),
        });
        return await res.json();
    } catch (e) {
        print('> [ERROR] 通信失敗: ' + e.message, 'bad');
        return null;
    }
}

async function saveApi(action, extra = {}) {
    try {
        const res = await fetch(SAVE_API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, ...extra }),
        });
        return await res.json();
    } catch (e) {
        console.warn('[SAVE] 通信失敗:', e.message);
        return null;
    }
}

/** マップ遷移時に自動セーブ（非同期・エラーは握りつぶす） */
async function autoSave() {
    if (!G.activeSlot) return;
    await saveApi('save', { slot: G.activeSlot });
}

// ============================================================
//  タイトル
// ============================================================
async function showTitle() {
    G.screen = 'title';
    G.activeSlot = null;
    clearLog();
    document.getElementById('status-bar').innerHTML = '';

    const lines = [
        '╔══════════════════════════════════════╗',
        '║                                      ║',
        '║    S T R E E T   D O G S             ║',
        '║                                      ║',
        '║    路地裏サバイバル RPG               ║',
        '║                                      ║',
        '╚══════════════════════════════════════╝',
        '',
        '> システム起動完了。',
        '> バージョン 0.4.0',
        '',
    ];
    lines.forEach((l, i) => print(l, i < 7 ? 'header' : 'info', i * 50));

    // セーブデータ取得
    const saveData = await saveApi('init');
    if (saveData?.ok) {
        G.uuid       = saveData.uuid;
        G.storySlots = saveData.story;
    }

    const hasSave = G.storySlots.some(s => !s.empty);
    const clearedSlots = G.storySlots.filter(s => !s.empty && s.cleared);

    setTimeout(() => {
        const btns = [
            { label: '[ NEW GAME ]',  action: () => startNewGame(false) },
        ];
        if (clearedSlots.length > 0) {
            btns.push({ label: '[ NG+ ]', action: startNgPlus, cls: 'amber' });
        }
        if (hasSave) {
            btns.push({ label: '[ CONTINUE ]', action: showContinueSlots });
        }
        setCommands('コマンドを入力してください', btns);
    }, lines.length * 50 + 200);
}

// ============================================================
//  スロット選択（NEW GAME / NG+共通）
// ============================================================
async function startNewGame(isNgPlus, srcSlot = null) {
    // 空きスロットを自動選択、全埋まりなら選択UIへ
    const saveData = await saveApi('init');
    if (!saveData?.ok) return;
    G.storySlots = saveData.story;

    const autoSlot = saveData.auto_slot;
    if (autoSlot) {
        // 空きあり → スキップして直接開始
        G.activeSlot = autoSlot;
        if (isNgPlus && srcSlot) {
            await doNgPlus(srcSlot, autoSlot);
        } else {
            startChargen();
        }
    } else {
        // 全埋まり → 上書き先を選ばせる
        showSlotSelect(isNgPlus, srcSlot);
    }
}

function showSlotSelect(isNgPlus, srcSlot) {
    clearLog();
    print('> セーブスロットを選択してください。', 'cyan');
    print('> ※ 選択したスロットに上書きされます。', 'warn');
    printBlank();
    G.storySlots.forEach(s => {
        if (s.empty) {
            print(`> ${s.slot.toUpperCase()}  [空]`, 'dim');
        } else {
            print(`> ${s.slot.toUpperCase()}  ST${s.stage} DAY${s.day}  ¥${s.money.toLocaleString()}  ${s.updated_at ? s.updated_at.slice(0,10) : ''}${s.ng_plus > 0 ? '  NG+'+s.ng_plus : ''}`, 'prompt');
        }
    });
    printBlank();
    const btns = G.storySlots.map(s => ({
        label:  s.slot.replace('story_', 'SLOT '),
        cls:    s.empty ? '' : 'amber',
        action: async () => {
            G.activeSlot = s.slot;
            if (isNgPlus && srcSlot) {
                await doNgPlus(srcSlot, s.slot);
            } else {
                startChargen();
            }
        },
    }));
    btns.push({ label: '[戻る]', action: showTitle });
    setCommands('どのスロットに保存する？', btns);
}

// ============================================================
//  強くてニューゲーム
// ============================================================
function startNgPlus() {
    clearLog();
    print('> 引き継ぎ元を選択してください。', 'cyan');
    print('> クリア済みスロットのみ選択可能。', 'info');
    printBlank();
    const cleared = G.storySlots.filter(s => !s.empty && s.cleared);
    cleared.forEach(s => {
        print(`> ${s.slot.toUpperCase()}  ST${s.stage} DAY${s.day}  NG+${s.ng_plus || 0}`, 'prompt');
    });
    printBlank();
    const btns = cleared.map(s => ({
        label:  s.slot.replace('story_', 'SLOT ') + (s.ng_plus > 0 ? ' NG+'+s.ng_plus : ''),
        cls:    'amber',
        action: () => startNewGame(true, s.slot),
    }));
    btns.push({ label: '[戻る]', action: showTitle });
    setCommands('引き継ぎ元', btns);
}

async function doNgPlus(srcSlot, destSlot) {
    disableCommands();
    const data = await saveApi('ng_plus', { src_slot: srcSlot, dest_slot: destSlot });
    if (!data?.ok) {
        print('> [ERROR] 引き継ぎ失敗: ' + (data?.error || ''), 'bad');
        return;
    }
    // PHPセッションへの展開はsave.php側で完了済み。HUDだけ更新する。
    updateHUD(data.player);
    print('> 引き継ぎ完了。新たな戦いが始まる。', 'good');
    printBlank();
    setTimeout(() => showMap(), 800);
}

// ============================================================
//  続きから（ロード）
// ============================================================
function showContinueSlots() {
    clearLog();
    print('> セーブデータを選択してください。', 'cyan');
    printBlank();
    const filled = G.storySlots.filter(s => !s.empty);
    filled.forEach(s => {
        print(`> ${s.slot.toUpperCase()}  ST${s.stage} DAY${s.day}  HP:${s.hp}/${s.max_hp}  ¥${s.money.toLocaleString()}`, 'prompt');
        print(`>   保存日時: ${s.updated_at ? s.updated_at.slice(0,16).replace('T',' ') : '不明'}${s.ng_plus > 0 ? '  NG+'+s.ng_plus : ''}`, 'dim');
    });
    printBlank();
    const btns = filled.map(s => ({
        label:  s.slot.replace('story_', 'SLOT '),
        action: async () => {
            disableCommands();
            const data = await saveApi('load', { slot: s.slot });
            if (!data?.ok) { print('> ロード失敗。', 'bad'); return; }
            G.activeSlot = s.slot;
            updateHUD(data.player);
            print('> データをロードした。', 'good');
            setTimeout(() => showMap(), 600);
        },
    }));
    btns.push({ label: '[戻る]', action: showTitle });
    setCommands('どのデータをロードする？', btns);
}

// ============================================================
//  キャラ生成
// ============================================================
async function startChargen() {
    G.screen = 'chargen';
    G.rerolls = 0;
    clearLog();
    print('> キャラクター生成を開始する。', 'cyan');
    print('> ダイスを振れ。', 'info', 60);
    printBlank();
    await doRoll();
}

async function doRoll() {
    disableCommands();
    clearLog();
    const data = await api('roll');
    if (!data) return;
    G.pendingStats = data.stats;
    G.rerolls++;

    const s = data.stats;
    const entries = [
        ['hp',    s.hp,    100, 250],
        ['mp',    s.mp,    10,  120],
        ['atk',   s.atk,   16,  32],
        ['def',   s.def,   16,  32],
        ['agi',   s.agi,   16,  32],
        ['luk',   s.luk,   16,  32],
        ['money', s.money, 100, 2500],
    ];

    print(`> ──── ROLL #${G.rerolls} ────`, 'dim');
    entries.forEach(([key, val, min, max], i) => {
        const filled   = Math.round((val - min) / (max - min) * 20);
        const bar      = '█'.repeat(filled) + '░'.repeat(20 - filled);
        const label    = key.toUpperCase().padEnd(6);
        const dispVal  = key === 'money' ? ('¥' + val.toLocaleString()).padStart(8) : String(val).padStart(4);
        print(`> ${label} ${dispVal}  [${bar}]`, 'prompt', i * 40);
    });

    const ratingColor = { S:'bad', A:'warn', B:'good', C:'prompt', D:'dim' };
    print(`> `, 'blank', entries.length * 40 + 20);
    print(`> SCORE: ${data.score}  RATING: [ ${data.rating} ]`, ratingColor[data.rating] || 'prompt', entries.length * 40 + 60);
    print(`> ${data.comment}`, 'info', entries.length * 40 + 100);
    printBlank();

    setTimeout(() => {
        setCommands('このキャラで始めるか？', [
            { label: '[ CONFIRM ]', action: confirmChar, cls: 'amber' },
            { label: '[ REROLL ]',  action: doRoll },
        ]);
    }, entries.length * 40 + 300);
}

async function confirmChar() {
    disableCommands();
    const data = await api('confirm', { stats: G.pendingStats });
    if (!data?.ok) return;
    updateHUD(data.player);
    print('> キャラクター確定。', 'good');
    print('> 路地裏へ足を踏み入れた。', 'info', 80);
    printBlank();
    // 新規作成直後に初回セーブ
    await autoSave();
    setTimeout(() => showMap(), 600);
}

// ============================================================
//  マップ
// ============================================================
async function showMap() {
    G.screen = 'map';
    await autoSave();   // マップ遷移 = セーブ確定
    const data = await api('map');
    if (!data) return;
    updateHUD(data.player);
    const st = data.stage_info;

    clearLog();
    const isEX    = (data.player.ex_stage || 0) > 0;
    const exDepth = data.player.ex_stage || 0;
    const bgKey   = isEX ? `fight_3` : `map_${data.player.stage}`;  // EX中はST3背景流用
    setScene({ bgKey });
    if (isEX) {
        print(`> ════ EX${exDepth} : STAGE ${data.player.stage} ════`, 'header');
        print(`> 最大到達EX: ${data.player.ex_depth_max || 0}`, 'warn');
    } else {
        print(`> ═══ STAGE ${data.player.stage}: ${st.name} ═══`, 'header');
    }
    printBlank();

    data.nodes.forEach((node, i) => {
        print(`> ${node.tag.padEnd(10)} ${node.name}`, node.danger ? 'bad' : 'prompt', 60 + i * 40);
        print(`>            ${node.desc}`, 'info', 80 + i * 40);
        if (node.cost) print(`>            ${node.cost}`, 'warn', 90 + i * 40);
    });

    printBlank();
    if (data.player.weapons.length > 0)
        print(`> 武装: ${data.player.weapons.map(w => w.name).join(' / ')}`, 'info');
    if (data.player.items.length > 0)
        print(`> 道具: ${data.player.items.map(it => it.name).join(' / ')}`, 'info');
    printBlank();

    setTimeout(() => {
        const bossNode  = data.nodes.find(n => n.id === 'boss');
        const bossReady = bossNode && bossNode.boss_ready;
        setCommands('どこへ向かう？', [
            { label: '[FIGHT]',  action: startFight },
            { label: '[EAT]',    action: startInn },
            { label: '[TRAIN]',  action: startDojo },
            { label: '[WEAPON]', action: startWeaponShop },
            { label: '[ARMOR]',  action: startArmorShop },
            { label: '[ITEM]',   action: startItemShop },
            { label: isEX ? `[EX BOSS]` : (bossReady ? '[BOSS]' : '[BOSS 🔒]'),
              action: startBoss, cls: 'danger', disabled: !isEX && !bossReady },
            { label: '[STATUS]', action: showStatus },
        ]);
    }, 60 + data.nodes.length * 40 + 400);
}

function showStatus() {
    const p = G.player;
    if (!p) return;
    const armorBonus = p.armor ? p.armor.def_bonus : 0;
    const effDef     = p.def + armorBonus + (p.temp_def || 0);
    clearLog();
    print('> ──── STATUS ────', 'cyan');
    print(`> HP  ${p.hp}/${p.max_hp}  MP  ${p.mp}/${p.max_mp}`, 'prompt');
    print(`> ATK ${p.atk}  DEF ${effDef}${armorBonus > 0 ? '(素'+p.def+'+防具'+armorBonus+')' : ''}  AGI ${p.agi}  LUK ${p.luk}`, 'prompt');
    print(`> ¥${p.money.toLocaleString()}  STAGE ${p.stage}  DAY ${p.day || 1}`, 'prompt');
    print(`> 防具: ${p.armor ? p.armor.name + ' (DEF+' + p.armor.def_bonus + ' 耐久:' + (p.armor.durability ?? '?') + ')' : 'なし'}`, 'info');
    if (p.weapons.length) print(`> 武器: ${p.weapons.map(w => w.name).join(', ')}`, 'info');
    printBlank();

    // アイテム使用UI
    if (p.items.length > 0) {
        print(`> ── アイテム (${p.items.length}/3) ──`, 'cyan');
        p.items.forEach((it, i) => {
            print(`> [${i+1}] ${it.name}  ${it.effect.startsWith('perm') ? '永続強化' : it.effect === 'escape' ? '戦闘用' : '使用可'}`, 'prompt', i * 30);
        });
        printBlank();
        const btns = p.items.map((it, i) => ({
            label:    `[使う: ${it.name}]`,
            action:   () => doUseItem(i),
            disabled: it.effect === 'escape',  // スモークボムは戦闘中のみ
            cls:      it.effect.startsWith('perm') ? 'amber' : '',
        }));
        btns.push({ label: '[閉じる]', action: showMap });
        setCommands('どれを使う？', btns);
    } else {
        print('> アイテム: なし', 'dim');
        printBlank();
        setCommands('', [{ label: '[閉じる]', action: showMap }]);
    }
}

async function doUseItem(idx) {
    disableCommands();
    const data = await api('item_use', { idx });
    if (!data) return;
    if (data.ok) updateHUD(data.player);
    data.lines.forEach((l, i) => print(l, data.ok ? 'good' : 'bad', i * 50));
    setTimeout(() => showStatus(), data.lines.length * 50 + 400);
}

// ============================================================
//  ストリートファイト
// ============================================================
async function startFight() {
    G.screen = 'fight';
    clearLog();
    print('> 路地裏を歩いていると……', 'info');
    const data = await api('fight_start');
    if (!data) return;
    updateHUD(data.player);
    G.mob = data.mob;
    // シーンセット（MOB画像はAPIから返ったimgパスを使用）
    setScene({
        bgKey:  `fight_${data.player.stage}`,
        mob:    data.mob.img ? `img/${data.mob.img}` : '',
        player: PLAYER_IMG.normal,
    });
    if (data.mob.is_jk) {
        print(`> 【${data.mob.name}】と鉢合わせた。`, 'warn', 80);
        print(`> ……手を出すな。`, 'cyan', 160);
    } else {
        print(`> 【${data.mob.name}】が現れた！`, 'warn', 80);
        print(`> HP: ${data.mob.hp}  ATK: ${data.mob.atk}  DEF: ${data.mob.def}`, 'info', 160);
    }
    printBlank();
    setTimeout(() => showFightCommands(), 400);
}

function showFightCommands() {
    const p        = G.player;
    const mob      = G.mob;
    const isJK     = mob && mob.is_jk;
    const hasThrow = p.weapons.some(w => ['knife','bullet'].includes(w.id));
    const hasMp    = p.mp >= 10;
    const hasSmoke = p.items.some(it => it.effect === 'escape');

    if (isJK) {
        setCommands(`【${mob.name}】 ─ 手を出すな`, [
            { label: '[ATTACK ⚠]', action: () => doFightAction('attack'), cls: 'danger' },
            { label: '[THROW ⚠]',  action: () => doFightAction('throw'),  cls: 'danger', disabled: !hasThrow },
            { label: '[SKILL ⚠]',  action: () => doFightAction('skill'),  cls: 'danger', disabled: !hasMp },
            { label: '[DEFEND]',   action: () => doFightAction('defend') },
            { label: hasSmoke ? '[SMOKE]' : '[SMOKE 🔒]',
                                   action: () => doFightAction('item'),   disabled: !hasSmoke },
            { label: '[RUN]',      action: () => doFightAction('run') },
        ]);
    } else {
        setCommands(`vs 【${mob.name}】 HP:${mob.hp}/${mob.max_hp}`, [
            { label: '[ATTACK]',                    action: () => doFightAction('attack') },
            { label: '[THROW]',                     action: () => doFightAction('throw'),  disabled: !hasThrow },
            { label: '[SKILL]',                     action: () => doFightAction('skill'),  disabled: !hasMp },
            { label: '[DEFEND]',                    action: () => doFightAction('defend') },
            { label: hasSmoke ? '[SMOKE]' : '[SMOKE 🔒]',
                                                    action: () => doFightAction('item'),   disabled: !hasSmoke },
            { label: '[RUN]',                       action: () => doFightAction('run') },
        ]);
    }
}

async function doFightAction(cmd) {
    disableCommands();
    const data = await api('fight_action', { cmd });
    if (!data) return;
    updateHUD(data.player);
    G.player = data.player;
    if (data.mob) G.mob = data.mob;

    // 被弾があればプレイヤー画像をdamagedに切り替え（一時的）
    const tookDamage = data.lines.some(l => l.includes('ダメージ') && l.includes(data.mob?.name || '___'));
    if (tookDamage) {
        setLayer('scene-player', PLAYER_IMG.damaged);
        setTimeout(() => setLayer('scene-player', PLAYER_IMG.normal), 600);
    }

    data.lines.forEach((l, i) => {
        const cls = l.includes('ダメージ')    ? 'warn'
                  : l.includes('倒した')      ? 'good'
                  : l.includes('力尽き')      ? 'bad'
                  : l.includes('通報')        ? 'bad'
                  : l.includes('罰金')        ? 'bad'
                  : l.includes('警察')        ? 'bad'
                  : l.includes('回避')        ? 'cyan'
                  : l.includes('クリティカル') ? 'good'
                  : l.includes('見切り')      ? 'cyan'
                  : 'prompt';
        print(l, cls, i * 60);
    });

    // 連打防止: 次コマンド表示までの最低待機時間を延長
    const baseDelay = data.lines.length * 60;
    const antiSpam  = 700;   // ms — ボタン再表示の最低待機
    const delay     = Math.max(baseDelay, antiSpam) + 200;

    setTimeout(() => {
        switch (data.result) {
            case 'win':
                printBlank();
                if (data.boss_unlocked) {
                    clearLog();
                    setScene({ bgKey: 'informer', mob: pickNPC('informer') });
                    print('> ════════════════════════', 'cyan');
                    print('> 【情報屋】', 'cyan');
                    print('> ……居場所を突き止めた。', 'cyan');
                    print('> ボスへの挑戦が解放された。', 'good');
                    print('> ════════════════════════', 'cyan');
                    // 確認ボタンを押すまで自動遷移しない
                    setTimeout(() => setCommands('', [
                        { label: '[確認]', action: showMap, cls: 'amber' }
                    ]), 800);
                } else {
                    print('> 勝利。マップに戻る。', 'good');
                    setTimeout(() => showMap(), 800);
                }
                break;
            case 'ex_win':
                printBlank();
                print(`> EX${data.player.ex_stage - 1} クリア。ST1へ。`, 'good');
                setTimeout(() => showMap(), 1200);
                break;
            case 'omamori_save':
                printBlank();
                print('> マップに戻る。', 'warn');
                setTimeout(() => showMap(), 1000);
                break;
            case 'mob_escape':
                printBlank();
                print('> 敵が逃げ出した。', 'warn');
                setTimeout(() => showMap(), 800);
                break;
            case 'game_over_poor':
                setTimeout(() => gameOverPoor(data.player), 800);
                break;
            case 'lose': gameOver(); break;
            case 'jk_penalty':
                printBlank();
                print('> マップに戻る。', 'warn');
                setTimeout(() => showMap(), 800);
                break;
            case 'escape':
                printBlank();
                print('> 逃走成功。', 'warn');
                setTimeout(() => showMap(), 600);
                break;
            case 'boss_win':
                printBlank();
                setTimeout(() => showMap(), 1200);
                break;
            case 'game_clear': setTimeout(() => enterEX(data.player), 1000); break;
            default: setTimeout(() => showFightCommands(), antiSpam); break;
        }
    }, delay);
}

// ============================================================
//  ボス戦
// ============================================================
async function startBoss() {
    G.screen = 'fight';
    clearLog();
    const data = await api('boss_start');
    if (!data) return;
    updateHUD(data.player);
    G.mob = data.mob;
    const isEX = data.mob.is_ex;
    setScene({
        bgKey:  isEX ? 'boss_3' : `boss_${data.player.stage}`,
        mob:    isEX ? (PLAYER_IMG.normal) : (BOSS_IMG[data.player.stage] || ''),
        player: PLAYER_IMG.normal,
    });
    print('> ───────────────────────────', isEX ? 'bad' : 'dim');
    if (isEX) {
        const exN = data.player.ex_stage || 1;
        print(`> EX${exN} BOSS`, 'bad');
        print(`> 【${data.mob.name}】`, 'bad');
        print(`> ── お前自身だ。`, 'warn');
    } else {
        print(`> BOSS ENCOUNTER`, 'bad');
        print(`> 【${data.mob.name}】`, 'bad');
    }
    print(`> HP: ${data.mob.hp}  ATK: ${data.mob.atk}  DEF: ${data.mob.def}`, 'info');
    print('> ───────────────────────────', 'dim');
    printBlank();
    setTimeout(() => showFightCommands(), 600);
}

// ============================================================
//  宿
// ============================================================
async function startInn() {
    G.screen = 'inn';
    clearLog();
    setScene({ bgKey: 'restaurant', mob: pickNPC('restaurant') });
    print('> 飲食店に入った。', 'info');
    const data = await api('inn_quote');
    if (!data) return;
    G.innCost = data.cost;
    updateHUD(data.player);
    printBlank();
    print(`> メニュー（定食）: ¥${data.cost.toLocaleString()}`, 'warn');
    print(`> 食事すれば HP・MP が全回復する。`, 'info');
    printBlank();
    print(`> お土産コーナーあり。アイテム所持: ${data.player.items.length}/3`, 'dim');
    printBlank();
    const canAfford = data.player.money >= data.cost;
    setCommands('どうする？', [
        { label: `[食事する ¥${data.cost.toLocaleString()}]`, action: doInnStay, disabled: !canAfford, cls: 'amber' },
        { label: '[お土産を見る]', action: startFoodShop },
        { label: '[戻る]', action: showMap },
    ]);
}

async function doInnStay() {
    disableCommands();
    const data = await api('inn_stay');
    if (!data) return;
    if (data.ok) updateHUD(data.player);
    data.lines.forEach((l, i) => print(l, data.ok ? 'good' : 'bad', i * 60));
    setTimeout(() => startInn(), data.lines.length * 60 + 500);
}

async function startFoodShop() {
    G.screen = 'inn';
    clearLog();
    setScene({ bgKey: 'restaurant', mob: pickNPC('restaurant') });
    const data = await api('food_stock');
    if (!data) return;
    updateHUD(data.player);
    const p = data.player;

    print('> お土産コーナー。', 'info');
    print(`> アイテム所持: ${p.items.length}/3`, 'dim');
    printBlank();
    data.stock.forEach((it, i) => {
        print(`> [${i+1}] ${it.name.padEnd(12)} ¥${String(it.price).padStart(5)}  ${it.desc}`, 'prompt', i * 50);
    });
    printBlank();
    if (p.items.length > 0)
        print(`> 所持: ${p.items.map(it => it.name).join(' / ')}`, 'dim');
    printBlank();

    const full = p.items.length >= 3;
    const btns = data.stock.map((it, i) => ({
        label:    `[買う${i+1}: ¥${it.price}]`,
        action:   () => doBuyFood(i),
        disabled: full || p.money < it.price,
        cls:      'amber',
    }));
    btns.push({ label: '[戻る]', action: startInn });
    setTimeout(() => setCommands(full ? 'アイテム満杯' : 'お土産を買う？', btns), data.stock.length * 50 + 300);
}

async function doBuyFood(idx) {
    disableCommands();
    const data = await api('food_buy', { idx });
    if (!data) return;
    if (data.ok) updateHUD(data.player);
    data.lines.forEach((l, i) => print(l, data.ok ? 'good' : 'bad', i * 40));
    setTimeout(() => startFoodShop(), data.lines.length * 40 + 400);
}

// ============================================================
// ============================================================
//  修練所（全stat一括強化）
// ============================================================
async function startDojo() {
    G.screen = 'dojo';
    clearLog();
    setScene({ bgKey: 'dojo', mob: pickNPC('dojo') });
    const data = await api('dojo_info');
    if (!data) return;
    updateHUD(data.player);
    const p    = data.player;
    const cost = data.cost;   // action.phpでdojo_cost*4済みの値が返る

    print('> 修練所に入った。', 'info');
    printBlank();
    print(`> 1回 ¥${cost} で全ステータスを一括強化する。`, 'info');
    print(`> 上限値 +1〜5%（ランダム）。HP/MPは微回復。`, 'info');
    printBlank();
    print(`> HP:${p.hp}/${p.max_hp}  MP:${p.mp}/${p.max_mp}  ATK:${p.atk}  DEF:${p.def}  AGI:${p.agi}  LUK:${p.luk}`, 'prompt');
    printBlank();

    const canAfford = p.money >= cost;
    setTimeout(() => setCommands(`¥${cost}/回 ─ 全強化`, [
        { label: `[鍛える ¥${cost}]`, action: doDojoTrain, disabled: !canAfford, cls: 'amber' },
        { label: '[戻る]', action: showMap },
    ]), 400);
}

async function doDojoTrain() {
    disableCommands();
    const data = await api('dojo_train');
    if (!data) return;
    if (data.ok) updateHUD(data.player);
    data.lines.forEach((l, i) => print(l, data.ok ? 'good' : 'bad', i * 40));
    setTimeout(() => startDojo(), data.lines.length * 40 + 400);
}

// ============================================================
//  武器屋
// ============================================================
async function startWeaponShop() {
    G.screen = 'weapon';
    clearLog();
    setScene({ bgKey: 'weapon', mob: pickNPC('shop') });
    const data = await api('weapon_stock');
    if (!data) return;
    updateHUD(data.player);
    G.weaponStock = data.stock;

    print('> 武器屋に入った。', 'info');
    print('> 武器は重ね持ち可。ボス撃破後は没収される。', 'info');
    printBlank();

    data.stock.forEach((w, i) => {
        const dmgStr   = w.dmg[1] > 0 ? `DMG:${w.dmg[0]}-${w.dmg[1]}` : '投擲専用';
        const throwStr = w.throw_dmg ? ` 投:${w.throw_dmg[0]}-${w.throw_dmg[1]}` : '';
        print(`> [${i+1}] ${w.name.padEnd(8)} ¥${String(w.price).padStart(5)}  ${dmgStr}${throwStr}`, 'prompt', i * 50);
        print(`>     ${w.desc}`, 'info', i * 50 + 20);
    });

    printBlank();
    print(`> 現在の武装: ${data.player.weapons.length > 0 ? data.player.weapons.map(w => w.name).join(' / ') : 'なし'}`, 'dim');
    printBlank();

    const p    = data.player;
    const btns = data.stock.map((w, i) => ({
        label:    `[買う${i+1}: ¥${w.price}]`,
        action:   () => doBuyWeapon(i),
        disabled: p.money < w.price,
        cls:      'amber',
    }));
    btns.push({ label: '[戻る]', action: showMap });
    setTimeout(() => setCommands('どれを買う？', btns), data.stock.length * 50 + 300);
}

async function doBuyWeapon(idx) {
    disableCommands();
    const data = await api('weapon_buy', { idx });
    if (!data) return;
    if (data.ok) updateHUD(data.player);
    data.lines.forEach((l, i) => print(l, data.ok ? 'good' : 'bad', i * 40));
    setTimeout(() => startWeaponShop(), data.lines.length * 40 + 400);
}

// ============================================================
//  道具屋
// ============================================================
async function startItemShop() {
    G.screen = 'item';
    clearLog();
    setScene({ bgKey: 'item', mob: pickNPC('item') });
    const data = await api('item_stock');
    if (!data) return;
    updateHUD(data.player);

    print('> 道具屋に入った。', 'info');
    print('> アイテムはボス撃破後に没収される。', 'info');
    printBlank();

    data.stock.forEach((it, i) => {
        print(`> [${i+1}] ${it.name.padEnd(10)} ¥${String(it.price).padStart(5)}  ${it.desc}`, 'prompt', i * 50);
    });

    printBlank();
    print(`> 所持道具: ${data.player.items.length > 0 ? data.player.items.map(i => i.name).join(' / ') : 'なし'}`, 'dim');
    printBlank();

    const p    = data.player;
    const btns = data.stock.map((it, i) => ({
        label:    `[買う${i+1}: ¥${it.price}]`,
        action:   () => doBuyItem(i),
        disabled: p.money < it.price,
        cls:      'amber',
    }));
    btns.push({ label: '[戻る]', action: showMap });
    setTimeout(() => setCommands('どれを買う？', btns), data.stock.length * 50 + 300);
}

async function doBuyItem(idx) {
    disableCommands();
    const data = await api('item_buy', { idx });
    if (!data) return;
    if (data.ok) updateHUD(data.player);
    data.lines.forEach((l, i) => print(l, data.ok ? 'good' : 'bad', i * 40));
    setTimeout(() => startItemShop(), data.lines.length * 40 + 400);
}

// ============================================================
//  防具屋
// ============================================================
async function startArmorShop() {
    G.screen = 'armor';
    clearLog();
    setScene({ bgKey: 'armor', mob: pickNPC('shop') });
    const data = await api('armor_stock');
    if (!data) return;
    updateHUD(data.player);

    print('> 防具屋に入った。', 'info');
    print('> 防具は1枠のみ。上書き装備でDEFに反映される。', 'info');
    printBlank();

    const p = data.player;
    const currentArmor = p.armor ? `${p.armor.name} (DEF+${p.armor.def_bonus})` : 'なし';
    print(`> 現在の装備: ${currentArmor}`, 'dim');
    printBlank();

    data.stock.forEach((a, i) => {
        print(`> [${i+1}] ${a.name.padEnd(10)} ¥${String(a.price).padStart(5)}  DEF+${a.def_bonus}  耐久:${a.durability}`, 'prompt', i * 50);
        print(`>     ${a.desc}`, 'info', i * 50 + 20);
    });

    printBlank();
    const btns = data.stock.map((a, i) => ({
        label:    `[装備${i+1}: ¥${a.price}]`,
        action:   () => doBuyArmor(i),
        disabled: p.money < a.price,
        cls:      'amber',
    }));
    btns.push({ label: '[戻る]', action: showMap });
    setTimeout(() => setCommands('どれを装備する？', btns), data.stock.length * 50 + 300);
}

async function doBuyArmor(idx) {
    disableCommands();
    const data = await api('armor_buy', { idx });
    if (!data) return;
    if (data.ok) updateHUD(data.player);
    data.lines.forEach((l, i) => print(l, data.ok ? 'good' : 'bad', i * 40));
    setTimeout(() => startArmorShop(), data.lines.length * 40 + 400);
}

// ============================================================
//  ゲームオーバー / クリア
// ============================================================
// ============================================================
//  所持金不足ゲームオーバー → EX到達済みならPVP保存選択
// ============================================================
async function gameOverPoor(player) {
    clearLog();
    document.getElementById('crt-wrap').classList.add('flash');
    setTimeout(() => document.getElementById('crt-wrap').classList.remove('flash'), 300);

    print('> ───────────────────────────', 'bad');
    print('> DEAD — 無一文。', 'bad');
    print('> お前は路地裏に倒れた。', 'bad');
    print('> ───────────────────────────', 'bad');
    printBlank();

    const hasEX = (player.ex_depth_max || 0) > 0;

    if (hasEX) {
        print(`> EX${player.ex_depth_max} まで到達したキャラだ。`, 'cyan');
        print('> PVP用に残すか？', 'cyan');
        printBlank();
        setCommands('このキャラをPVPに残す？', [
            { label: '[残す]',    action: () => showPvpSlotSelect(player), cls: 'amber' },
            { label: '[残さない]', action: () => doGameOverCleanup(player, null) },
        ]);
    } else {
        await doGameOverCleanup(player, null);
    }
}

/** PVPスロット選択画面 */
async function showPvpSlotSelect(player) {
    clearLog();
    print('> PVPスロットを選択してください。', 'cyan');
    printBlank();

    const data = await saveApi('pvp_slot_list');
    if (!data?.ok) { print('> [ERROR] スロット取得失敗。', 'bad'); return; }

    data.slots.forEach(s => {
        if (s.empty) {
            print(`> ${s.slot.toUpperCase()}  [空]`, 'dim');
        } else {
            const name = s.updated_at ? s.updated_at.slice(0,10) : '';
            print(`> ${s.slot.toUpperCase()}  EX${s.ex_depth_max||0}  ${name}`, 'prompt');
        }
    });
    printBlank();

    const btns = data.slots.map(s => ({
        label:  s.slot.replace('pvp_', 'PVP-') + (s.empty ? ' [空]' : ' [上書き]'),
        cls:    s.empty ? '' : 'danger',
        action: async () => {
            if (!s.empty) {
                // 上書き確認
                clearLog();
                print(`> ${s.slot.toUpperCase()} のデータを上書きします。`, 'warn');
                print('> よろしいですか？', 'warn');
                printBlank();
                setCommands('確認', [
                    { label: '[上書きする]', cls: 'danger',
                      action: () => doGameOverCleanup(player, s.slot) },
                    { label: '[戻る]',
                      action: () => showPvpSlotSelect(player) },
                ]);
            } else {
                await doGameOverCleanup(player, s.slot);
            }
        },
    }));
    btns.push({ label: '[保存しない]', action: () => doGameOverCleanup(player, null) });
    setCommands('どのスロットに保存？', btns);
}

/** STORYスロット消去 + 必要ならPVP保存 → タイトルへ */
async function doGameOverCleanup(player, pvpSlot) {
    disableCommands();
    await saveApi('gameover_save_pvp', {
        story_slot: G.activeSlot,
        pvp_slot:   pvpSlot || '',
    });
    if (pvpSlot) {
        print(`> [${pvpSlot.toUpperCase()}] に保存した。`, 'good');
        printBlank();
    }
    print('> セーブデータを消去した。', 'dim');
    printBlank();
    setTimeout(async () => { await api('reset'); showTitle(); }, 1200);
}

function gameOver() {
    clearLog();
    document.getElementById('crt-wrap').classList.add('flash');
    setTimeout(() => document.getElementById('crt-wrap').classList.remove('flash'), 300);
    const lines = [
        '> ───────────────────────────',
        '> DEAD',
        '> ',
        '> お前は路地裏に倒れた。',
        '> 名前も残らない。',
        '> ───────────────────────────',
    ];
    lines.forEach((l, i) => print(l, 'bad', i * 80));
    setTimeout(() => {
        setCommands('', [{ label: '[RETRY]', action: async () => { await api('reset'); showTitle(); } }]);
    }, lines.length * 80 + 300);
}

/** ST3クリア後 → EX突入演出 → gameClear（名前入力）へ */
function enterEX(player) {
    clearLog();
    print('> ═══════════════════════════════════', 'good');
    print('> 全ステージ制覇。', 'good');
    print('> お前が路地裏の王だ。', 'good');
    print('> ───────────────────────────', 'dim');
    print('> ……しかし、路地裏に終わりはない。', 'warn');
    print('> お前自身の影が立ちはだかる。', 'warn');
    print('> ───────────────────────────', 'dim');
    print('> EXステージ解放。', 'cyan');
    print('> ボスはお前自身だ。', 'cyan');
    printBlank();
    setTimeout(() => gameClear(), 2000);
}

function gameClear() {
    clearLog();
    const p = G.player;
    const lines = [
        '> ═══════════════════════════════════',
        '> GAME CLEAR',
        '> ',
        '> 全ステージ制覇。',
        '> お前が路地裏の王だ。',
        '> ',
        `> 最終HP: ${p.hp}/${p.max_hp}`,
        `> 所持金: ¥${p.money.toLocaleString()}`,
        '> ═══════════════════════════════════',
        '> ',
    ];
    lines.forEach((l, i) => print(l, 'good', i * 100));
    setTimeout(() => showNameInput(), lines.length * 100 + 400);
}

/** クリア時キャラ名入力UI */
function showNameInput() {
    print('> このキャラクターに名前をつけろ。', 'cyan');
    printBlank();

    // CRTを薄くして入力を見やすくする
    const canvas = document.getElementById('crt-canvas');
    if (canvas) canvas.style.opacity = '0.15';

    // cmdエリアにinputを埋め込む
    const area  = document.getElementById('cmd-buttons');
    const title = document.getElementById('cmd-title');
    title.textContent = '名前を入力（最大16文字）';
    area.innerHTML = '';

    const input = document.createElement('input');
    input.type        = 'text';
    input.maxLength   = 16;
    input.placeholder = '名無し';
    input.className   = 'name-input';
    input.autocomplete = 'off';
    input.spellcheck  = false;

    const btn = document.createElement('button');
    btn.className   = 'cmd-btn amber';
    btn.textContent = '[ 決定 ]';

    const confirm = async () => {
        const name = input.value.trim() || '名無し';
        // CRT戻す
        if (canvas) canvas.style.opacity = '';
        await doNameConfirm(name);
    };

    btn.onclick       = confirm;
    input.onkeydown   = (e) => { if (e.key === 'Enter') confirm(); };

    area.appendChild(input);
    area.appendChild(btn);

    // スマホでは少し待ってからfocus（仮想KBが出るタイミングを確保）
    setTimeout(() => input.focus(), 100);
}

async function doNameConfirm(name) {
    disableCommands();
    print(`> 名前: 【${name}】`, 'good');
    printBlank();

    // playerに名前・クリアフラグをセット → セーブ
    const p = G.player;
    p.name     = name;
    p._cleared = true;
    G.player   = p;

    // PHPセッションにも反映（set_playerアクション）
    await api('set_player', { player: p });
    await autoSave();

    print('> セーブした。', 'dim');
    printBlank();

    setTimeout(() => {
        setCommands('', [{
            label:  '[もう一度]',
            action: async () => { await api('reset'); showTitle(); },
        }]);
    }, 600);
}

// ============================================================
//  CRT canvas オーバーレイ描画
//  スキャンライン + ビネット をrAFで常時描画
// ============================================================
function initCRT() {
    const canvas = document.getElementById('crt-canvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');

    function resize() {
        const area = logArea();
        canvas.width  = area.offsetWidth;
        canvas.height = area.offsetHeight;
    }
    resize();
    new ResizeObserver(resize).observe(logArea());

    function draw() {
        const w = canvas.width;
        const h = canvas.height;
        ctx.clearRect(0, 0, w, h);

        // ---- スキャンライン ----
        const lineH = 3;
        ctx.fillStyle = 'rgba(0,0,0,0.22)';
        for (let y = 0; y < h; y += lineH) {
            ctx.fillRect(0, y, w, 1);
        }

        // ---- ビネット ----
        const grad = ctx.createRadialGradient(w/2, h/2, h*0.28, w/2, h/2, h*0.85);
        grad.addColorStop(0, 'rgba(0,0,0,0)');
        grad.addColorStop(1, 'rgba(0,0,0,0.62)');
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, w, h);

        requestAnimationFrame(draw);
    }
    draw();
}

// ============================================================
//  起動
// ============================================================
document.addEventListener('DOMContentLoaded', () => {
    initCRT();
    showTitle();
});
