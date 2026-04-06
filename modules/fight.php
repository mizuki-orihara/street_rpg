<?php
// ============================================================
//  modules/fight.php — 戦闘エンジン
//
//  ダメージフロー:
//    ① 攻撃基礎値（atk±ブレ + 武器合算）
//    ② - 守備側DEF = 2次ダメ（min 0）
//    ③ × AGI比クリティカル倍率 = 最終ダメ
//
//  報酬倍率（reward_mult）累積:
//    初期値 1.0 / 護符あり +1.0 / 上限 4.2 / 下限 0.0
//    毎ターン:
//      プレイヤーがEXクリ      → +0.8
//      プレイヤーが見切り/EX見切り（回避成功） → +0.8
//      プレイヤーが見切られ/EX見切られ        → -0.2
// ============================================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../session.php';

const REWARD_MULT_MAX  = 4.2;
const REWARD_MULT_MIN  = -1.2;
const AGI_RATIO_NORMAL = 1.25;
const AGI_RATIO_EX     = 1.5;

const HIT_EX_CRIT  = 'ex_crit';
const HIT_CRIT     = 'crit';
const HIT_NORMAL   = 'normal';
const HIT_PARRY    = 'parry';
const HIT_EX_PARRY = 'ex_parry';

const DMG_MULT = [
    HIT_EX_CRIT  => 2.0,
    HIT_CRIT     => 1.5,
    HIT_NORMAL   => 1.0,
    HIT_PARRY    => 0.5,
    HIT_EX_PARRY => 0.25,
];

// 攻撃側として判定された結果 → 報酬倍率加減算
const REWARD_ATK_DELTA = [
    HIT_EX_CRIT  => +0.8,   // EXクリ: ちょろまかし成功
    HIT_CRIT     =>  0.0,
    HIT_NORMAL   =>  0.0,
    HIT_PARRY    => -0.2,   // 見切られ
    HIT_EX_PARRY => -0.2,   // EX見切られ
];

// 守備側として判定された結果 → 報酬倍率加減算
const REWARD_DEF_DELTA = [
    HIT_EX_CRIT  =>  0.0,
    HIT_CRIT     =>  0.0,
    HIT_NORMAL   =>  0.0,
    HIT_PARRY    => +0.8,   // 見切り成功
    HIT_EX_PARRY => +0.8,   // EX見切り成功
];

// ============================================================
//  AGI比判定: 攻撃側AGI / 守備側AGI
// ============================================================
function _agi_judge(int $atk_agi, int $def_agi): string {
    $ratio = ($def_agi > 0) ? $atk_agi / $def_agi : 2.0;
    if ($ratio > AGI_RATIO_EX)     return HIT_EX_CRIT;
    if ($ratio > AGI_RATIO_NORMAL) return HIT_CRIT;

    $inv = ($atk_agi > 0) ? $def_agi / $atk_agi : 2.0;
    if ($inv > AGI_RATIO_EX)     return HIT_EX_PARRY;
    if ($inv > AGI_RATIO_NORMAL) return HIT_PARRY;

    return HIT_NORMAL;
}

function _judge_label(string $j): string {
    return match($j) {
        HIT_EX_CRIT  => '【EXクリティカル!!】',
        HIT_CRIT     => '【クリティカル!】',
        HIT_PARRY    => '【見切り】',
        HIT_EX_PARRY => '【EX見切り!!】',
        default      => '',
    };
}

function _clamp_mult(float $v): float {
    return round(min(REWARD_MULT_MAX, max(REWARD_MULT_MIN, $v)), 1);
}

// ============================================================
//  戦闘初期化
// ============================================================
function fight_init(bool $is_boss = false): array {
    $p  = player_get();

    // EXステージ中はSTAGE定数の範囲外なのでST3テーブルで代用
    $stage_key = min($p['stage'], count(STAGES));
    $st = STAGES[$stage_key];

    if ($is_boss) {
        if (!empty($p['ex_stage'])) {
            // EXボス = プレイヤークローン × 1.3倍
            $mul  = 1.3;
            $name = 'SHADOW ' . ($p['name'] ?? '名無し');
            $hp   = max(1, (int)floor($p['max_hp'] * $mul));
            $mob  = [
                'name'     => $name,
                'hp'       => $hp,
                'max_hp'   => $hp,
                'atk'      => max(1, (int)floor($p['atk']  * $mul)),
                'def'      => max(1, (int)floor($p['def']  * $mul)),
                'agi'      => max(1, (int)floor($p['agi']  * $mul)),
                'is_boss'  => true,
                'is_ex'    => true,
            ];
        } else {
            $mob = [
                'name'    => $st['boss']['name'],
                'hp'      => $st['boss']['hp'],
                'max_hp'  => $st['boss']['hp'],
                'atk'     => $st['boss']['atk'],
                'def'     => $st['boss']['def'],
                'agi'     => $st['boss']['agi'] ?? 20,
                'is_boss' => true,
                'is_ex'   => false,
            ];
        }
    } else {
        $mob = mob_encounter($stage_key);
    }

    // 報酬倍率リセット（護符は戦闘中無関与・終了後に加算）
    $p['reward_mult'] = 1.0;

    // ---- 敵のアイテム所持抽選（ランク0・ボスは対象外）----
    if (empty($mob['is_jk']) && empty($mob['is_boss']) && empty($mob['is_ex'])) {
        $mob['mob_items'] = _mob_item_draw($mob);
    }

    $p['battle'] = $mob;
    player_set($p);
    return $mob;
}

// ============================================================
//  戦闘開始時: 敵アイテム所持抽選
//  ロール1: mob_item_chance% でアイテムを持つか
//  ロール2: enemy_use=1 のアイテムからランダム1種選択
// ============================================================
function _mob_item_draw(array $mob): array {
    $chance = $mob['mob_item_chance'] ?? 0;
    if ($chance <= 0 || rng(1, 100) > $chance) return [];

    // enemy_use=1 のアイテム候補
    $pool = array_values(array_filter(items_all(), fn($it) => (int)($it['enemy_use'] ?? 0) === 1));
    if (empty($pool)) return [];

    $item = $pool[rng(0, count($pool) - 1)];
    return [[
        'id'     => $item['id'],
        'name'   => $item['name'],
        'effect' => $item['effect'],
        'value'  => $item['value'],
    ]];
}

// ============================================================
//  1ターン処理
// ============================================================
function fight_action(string $action): array {
    $p   = player_get();
    $mob = $p['battle'];
    $lines  = [];
    $result = 'continue';

    // ---- JK専用処理 ----
    if (!empty($mob['is_jk'])) {
        return _fight_action_jk($p, $mob, $action);
    }

    $p_atk = eff_atk($p);
    $p_def = eff_def($p);
    $p_agi = $p['agi'];
    $m_agi = $mob['agi'];

    // ---- 先攻後攻 ----
    if ($p_agi > $m_agi)      $player_first = true;
    elseif ($p_agi < $m_agi) $player_first = false;
    else                      $player_first = (bool)rng(0, 1);
    $order = $player_first ? '先攻' : '後攻';
    $lines[] = "> AGI 自:{$p_agi} vs 敵:{$m_agi} → {$order}";

    $player_dmg = 0;
    $defending  = false;
    $atk_judge  = HIT_NORMAL;
    $did_attack = false;

    // ---- プレイヤーのアクション ----
    switch ($action) {

        case 'attack':
            $weapon_bonus = _weapon_melee_bonus($p);
            $base         = max(0, rng($p_atk - 4, $p_atk + 6) + $weapon_bonus);
            $after_def    = max(0, $base - $mob['def']);
            $atk_judge    = _agi_judge($p_agi, $m_agi);
            $player_dmg   = max(1, (int)round($after_def * DMG_MULT[$atk_judge]));
            $did_attack   = true;
            [$p, $throw_lines] = _consume_throw_weapons($p);
            $lines = array_merge($lines, $throw_lines);
            $label = _judge_label($atk_judge);
            $lines[] = "> 攻撃: {$base} - DEF:{$mob['def']} = {$after_def}" . ($label ? " {$label}" : '') . " → {$player_dmg} ダメージ。";
            break;

        case 'throw':
            [$p, $raw, $throw_lines] = _do_throw($p);
            $lines = array_merge($lines, $throw_lines);
            if ($raw > 0) {
                $after_def  = max(0, $raw - $mob['def']);
                $atk_judge  = _agi_judge($p_agi, $m_agi);
                $player_dmg = max(1, (int)round($after_def * DMG_MULT[$atk_judge]));
                $did_attack = true;
                $label = _judge_label($atk_judge);
                $lines[] = "> 投擲: {$raw} - DEF:{$mob['def']} = {$after_def}" . ($label ? " {$label}" : '') . " → {$player_dmg} ダメージ。";
            }
            break;

        case 'skill':
            if ($p['mp'] < 10) { $lines[] = "> MPが足りない。"; break; }
            $p['mp']   -= 10;
            $base       = max(0, rng($p_atk, $p_atk * 2));
            $after_def  = max(0, $base - $mob['def']);
            $atk_judge  = _agi_judge($p_agi, $m_agi);
            $player_dmg = max(1, (int)round($after_def * DMG_MULT[$atk_judge]));
            $did_attack = true;
            $label = _judge_label($atk_judge);
            $lines[] = "> スキル発動！ MP-10。{$base} - DEF:{$mob['def']} = {$after_def}" . ($label ? " {$label}" : '') . " → {$player_dmg} ダメージ。";
            break;

        case 'defend':
            $defending = true;
            $lines[] = "> 防御構え。";
            break;

        case 'item':
            // 戦闘中はスモークボムのみ使用可（他はマップ画面から）
            $smoke_idx = _find_item_idx($p, 'escape');
            if ($smoke_idx !== false) {
                $item = $p['items'][$smoke_idx];
                array_splice($p['items'], $smoke_idx, 1);
                [$p, $item_lines] = _use_item($p, $item);
                $lines = array_merge($lines, $item_lines);
            } else {
                $lines[] = "> アイテムは戦闘外で使用する。スモークボムのみ使用可。";
            }
            break;
            break;

        case 'run':
            if (!empty($mob['is_boss'])) { $lines[] = "> ボスから逃げることはできない。"; break; }
            $smoke_idx = _find_item_idx($p, 'escape');
            if ($smoke_idx !== false) {
                array_splice($p['items'], $smoke_idx, 1);
                $lines[] = "> スモークボムを使って逃走した！";
                $p['battle'] = null; $p['reward_mult'] = 1.0;
                $p = advance_day($p);
                player_set($p);
                return ['lines' => $lines, 'result' => 'escape', 'player' => $p];
            }
            $chance = 30 + $p_agi - $mob['atk'];
            if (rng(1, 100) <= max(10, $chance)) {
                $lines[] = "> 逃げ出した！";
                $p['battle'] = null; $p['reward_mult'] = 1.0;
                $p = advance_day($p);
                player_set($p);
                return ['lines' => $lines, 'result' => 'escape', 'player' => $p];
            }
            $lines[] = "> 逃走失敗！";
            break;
    }

    // ---- 報酬倍率: 攻撃結果を加算 ----
    if ($did_attack) {
        $delta = REWARD_ATK_DELTA[$atk_judge];
        if ($delta != 0.0) {
            $p['reward_mult'] = _clamp_mult($p['reward_mult'] + $delta);
            $sign = $delta > 0 ? '+' : '';
            $lines[] = "> [倍率] 攻撃 {$sign}" . number_format($delta, 1) . " → ×" . number_format($p['reward_mult'], 1);
        }
    }

    // ---- 先攻ならここで敵HPに反映・勝利判定 ----
    if ($player_first && $player_dmg > 0) {
        $mob['hp'] = max(0, $mob['hp'] - $player_dmg);
    }
    if ($player_first && $mob['hp'] <= 0) {
        return _resolve_win($p, $mob, $lines);
    }

    // ---- 敵のターン ----
    if ($action !== 'run') {
        [$mob, $lines, $p, $mob_escaped] = _mob_turn($mob, $p, $p_def, $p_agi, $defending, $lines);

        // 敵が逃走した場合
        if ($mob_escaped) {
            $p['battle'] = null; $p['reward_mult'] = 1.0;
            $p = advance_day($p);
            player_set($p);
            return ['lines' => $lines, 'result' => 'mob_escape', 'player' => $p];
        }
    }

    // ---- 後攻ならここで敵HPに反映・勝利判定 ----
    if (!$player_first && $player_dmg > 0) {
        $mob['hp'] = max(0, $mob['hp'] - $player_dmg);
    }

    // ---- 死亡判定 ----
    if ($p['hp'] <= 0) {
        $lines[] = "> 力尽きた……";

        // ボス戦 & お守り所持 → 自動発動
        if (!empty($mob['is_boss'])) {
            $omamori_idx = _find_item_idx($p, 'omamori');
            if ($omamori_idx !== false) {
                array_splice($p['items'], $omamori_idx, 1);
                $lines[] = "> 【お守り】が砕けた！";

                $penalty = max(500, min(3000, (int)floor($p['money'] * 0.10)));

                // 所持金がペナルティ最低額に満たない → ゲームオーバー確定
                if ($p['money'] < 500) {
                    $lines[] = "> ……金もない。もう終わりだ。";
                    $p['battle'] = null; $p['reward_mult'] = 1.0;
                    player_set($p);
                    return ['lines' => $lines, 'result' => 'game_over_poor', 'player' => $p];
                }

                // 生還
                $p['money'] -= $penalty;
                $p['hp']     = 1;
                $lines[] = "> 九死に一生を得た。";
                $lines[] = "> ペナルティ: ¥{$penalty} 徴収。";
                $p['battle'] = null; $p['reward_mult'] = 1.0;
                $p = advance_day($p);
                player_set($p);
                return ['lines' => $lines, 'result' => 'omamori_save', 'player' => $p];
            }
        }

        $p['battle'] = null; $p['reward_mult'] = 1.0;
        player_set($p);
        return ['lines' => $lines, 'result' => 'lose', 'player' => $p];
    }

    if (!$player_first && $mob['hp'] <= 0) {
        return _resolve_win($p, $mob, $lines);
    }

    $p['battle'] = $mob;
    player_set($p);
    return ['lines' => $lines, 'result' => $result, 'player' => $p, 'mob' => $mob];
}

// ============================================================
//  JK専用処理
// ============================================================
// civilian_dodge 閾値（全種共通）
const CIVILIAN_DODGE_THRESHOLD = 5;

function _fight_action_jk(array $p, array $mob, string $action): array {
    $lines  = [];
    $mob_id = $mob['id'] ?? 'jk';   // mob_encounter()で付与したid

    // civilian_dodge 初期化ガード
    if (!isset($p['civilian_dodge']) || !is_array($p['civilian_dodge'])) {
        $p['civilian_dodge'] = [];
    }
    if (!isset($p['civilian_dodge'][$mob_id])) {
        $p['civilian_dodge'][$mob_id] = 0;
    }

    switch ($action) {
        case 'attack':
        case 'throw':
        case 'skill':
            // 攻撃系 → 罰金 + そのidのカウントリセット
            $fine = $p['stage'] * 200;
            $lines[] = "> っ……なにしてんだ！";
            $lines[] = "> 通報された。警察が来た。";
            $lines[] = "> 罰金 ¥{$fine}。";
            $p['money'] = max(0, $p['money'] - $fine);
            if ($p['money'] === 0) $lines[] = "> 所持金が底をついた。";

            // カウントリセット
            $prev = $p['civilian_dodge'][$mob_id];
            $p['civilian_dodge'][$mob_id] = 0;
            if ($prev > 0) {
                $lines[] = "> [{$mob['name']}] への信頼が消えた。(回避 {$prev} → 0)";
            }

            $p['battle']      = null;
            $p['reward_mult'] = 1.0;
            $p = advance_day($p);
            player_set($p);
            return ['lines' => $lines, 'result' => 'jk_penalty', 'player' => $p];

        case 'run':
            // 逃走 → 回避カウント加算 → 閾値チェック
            $p['civilian_dodge'][$mob_id]++;
            $count = $p['civilian_dodge'][$mob_id];
            $lines[] = "> 足早にその場を離れた。";
            $lines[] = "> [{$mob['name']}] 回避: {$count}/" . CIVILIAN_DODGE_THRESHOLD;

            [$p, $reward_lines] = _civilian_dodge_check($p, $mob_id, $mob['name']);
            $lines = array_merge($lines, $reward_lines);

            $p['battle']      = null;
            $p['reward_mult'] = 1.0;
            $p = advance_day($p);
            player_set($p);
            return ['lines' => $lines, 'result' => 'escape', 'player' => $p];

        case 'defend':
            // 防御（様子見）→ 回避カウント加算
            $p['civilian_dodge'][$mob_id]++;
            $count = $p['civilian_dodge'][$mob_id];
            $lines[] = "> ……関わらない方がいい。";
            $lines[] = "> [{$mob['name']}] 回避: {$count}/" . CIVILIAN_DODGE_THRESHOLD;

            [$p, $reward_lines] = _civilian_dodge_check($p, $mob_id, $mob['name']);
            $lines = array_merge($lines, $reward_lines);

            player_set($p);
            // 防御はその場でターン終了せず継続（まだ画面にいる）
            return ['lines' => $lines, 'result' => 'continue', 'player' => $p, 'mob' => $mob];

        case 'item':
            if (empty($p['items'])) {
                $lines[] = "> アイテムがない。";
            } else {
                $item = array_shift($p['items']);
                [$p, $item_lines] = _use_item($p, $item);
                $lines = array_merge($lines, $item_lines);
            }
            player_set($p);
            return ['lines' => $lines, 'result' => 'continue', 'player' => $p, 'mob' => $mob];
    }

    player_set($p);
    return ['lines' => $lines, 'result' => 'continue', 'player' => $p, 'mob' => $mob];
}

/**
 * civilian_dodge 閾値チェック＆報酬付与
 * 閾値に達したらアイテムを1個付与してカウントリセット
 * @return array [$p, $lines]
 */
function _civilian_dodge_check(array $p, string $mob_id, string $mob_name): array {
    $lines = [];
    if ($p['civilian_dodge'][$mob_id] < CIVILIAN_DODGE_THRESHOLD) {
        return [$p, $lines];
    }

    // 閾値到達 → ランダム報酬（護符 / スモークボム / お守り）
    $rewards = [
        ['id' => 'gold_fever', 'name' => '金運の護符',   'effect' => 'gold_fever', 'value' => 5],
        ['id' => 'smoke',      'name' => 'スモークボム',  'effect' => 'escape',     'value' => 0],
        ['id' => 'omamori',    'name' => 'お守り',        'effect' => 'omamori',    'value' => 0],
    ];
    $reward = $rewards[rng(0, count($rewards) - 1)];

    $lines[] = "> ────────────────────";
    $lines[] = "> 【{$mob_name}】との縁が繋がった。";

    if (count($p['items']) < 3) {
        $p['items'][] = $reward;
        $lines[] = "> [{$reward['name']}] を受け取った！";
    } else {
        // アイテム満杯なら金で代替（適当な換算額）
        $cash = rng(150, 300);
        $p['money'] += $cash;
        $lines[] = "> アイテムが満杯のため ¥{$cash} を受け取った。";
    }

    // カウントリセット
    $p['civilian_dodge'][$mob_id] = 0;
    $lines[] = "> ────────────────────";

    return [$p, $lines];
}

// ============================================================
//  勝利処理（共通）
// ============================================================
function _resolve_win(array $p, array $mob, array $lines): array {
    $result = 'win';
    $base   = rng(10, 80) + ($p['stage'] - 1) * 20;

    // 護符所持: 戦闘終了後に+2.0シフト（-1.2〜4.2 → +0.8〜6.2）
    $fever_shift = 0.0;
    if (!empty($p['gold_fever_days'])) {
        $fever_shift = 2.0;
        $p['reward_mult'] = _clamp_mult($p['reward_mult'] + $fever_shift);
    }

    $mult   = $p['reward_mult'];
    $reward = max(0, (int)round($base * $mult));

    $lines[] = "> [{$mob['name']}] を倒した！";
    if ($fever_shift > 0.0) {
        $lines[] = "> [護符] 報酬倍率 +2.0 シフト → ×" . number_format($mult, 1);
    }
    $lines[] = "> 報酬: ¥{$base} × " . number_format($mult, 1) . " = ¥{$reward}";

    // ボス解放: 通常MOBを倒したらboss_ptsを加算
    $boss_unlocked = false;
    if (empty($mob['is_boss']) && empty($mob['is_jk'])) {
        $bpts = (int)($mob['boss_pts'] ?? 0);
        // 初期化ガード（旧セッション互換）
        if (!isset($p['boss_progress']) || is_array($p['boss_progress'])) $p['boss_progress'] = 0;
        if (!isset($p['boss_ready'])    || is_array($p['boss_ready']))    $p['boss_ready']    = false;

        if (!$p['boss_ready'] && $bpts > 0) {
            $p['boss_progress'] = min(10, $p['boss_progress'] + $bpts);
            $prog = $p['boss_progress'];
            if ($prog >= 10) {
                $p['boss_ready']    = true;
                $boss_unlocked      = true;
            } else {
                $lines[] = "> [情報] ボス捜索: {$prog}/10";
            }
        }
    }

    // LUKボーナス抽選: LUK / (stage * 50) %
    $luk_chance = $p['luk'] / ($p['stage'] * 50) * 100;
    if (rng(1, 100) <= (int)$luk_chance) {
        $luk_bonus   = $p['stage'] * 100;
        $reward     += $luk_bonus;
        $lines[]     = "> 【LUK発動】¥{$luk_bonus} ボーナス！";
    }

    $p['money']      += $reward;
    $p['temp_atk']    = 0;
    $p['temp_def']    = 0;
    $p['reward_mult'] = 1.0;

    if (!empty($mob['is_boss'])) {
        $lines[] = "> ===========================";

        if (!empty($mob['is_ex'])) {
            // ---- EXボス撃破 ----
            $p['ex_stage']     = ($p['ex_stage'] ?? 1) + 1;
            $p['ex_depth_max'] = max($p['ex_depth_max'] ?? 0, $p['ex_stage'] - 1);
            $result            = 'ex_win';
            $lines[] = "> EX BOSS [{$mob['name']}] 撃破！";
            $lines[] = "> EX" . ($p['ex_stage'] - 1) . " 到達記録更新。";
            $lines[] = "> 武器・アイテムは没収された。";
            $p['weapons']      = [];
            $p['items']        = [];
            $p['armor']        = null;
            $p['boss_progress']= 0;
            $p['boss_ready']   = false;
            $p['stage']        = 1;   // ST1から再スタート
            $p['hp']           = $p['max_hp'];
            $p['mp']           = $p['max_mp'];
            $lines[] = "> EX" . $p['ex_stage'] . " 開始。ST1から再スタート。";
        } else {
            // ---- 通常ボス撃破 ----
            $result  = 'boss_win';
            $lines[] = "> BOSS [{$mob['name']}] 撃破！";
            $lines[] = "> 武器・アイテムは没収された。";
            $lines[] = "> ステータスは裸で持ち越す。";
            $p['weapons']      = [];
            $p['items']        = [];
            $p['armor']        = null;
            $p['boss_progress']= 0;
            $p['boss_ready']   = false;
            $p['stage']++;
            if ($p['stage'] > count(STAGES)) {
                // ST3撃破 → EXステージへ突入
                $result          = 'game_clear';
                $p['ex_stage']   = 1;
                $p['ex_depth_max']= max($p['ex_depth_max'] ?? 0, 0);
                $lines[] = "> ===========================";
                $lines[] = "> 全ステージ制覇。";
                $lines[] = "> お前が路地裏の王だ。";
            } else {
                $p['hp'] = $p['max_hp'];
                $p['mp'] = $p['max_mp'];
                $lines[] = "> ステージ " . $p['stage'] . " へ。";
            }
        }
    }

    $p['battle'] = null;
    $p = advance_day($p);
    player_set($p);
    return ['lines' => $lines, 'result' => $result, 'player' => $p, 'boss_unlocked' => $boss_unlocked];
}

// ============================================================
//  内部ヘルパー
// ============================================================

function _weapon_melee_bonus(array $p): int {
    $bonus = 0;
    foreach ($p['weapons'] as $w) {
        $m = get_weapon($w['id']);
        if ($m && $m['dmg'][1] > 0) $bonus += rng($m['dmg'][0], $m['dmg'][1]);
    }
    return $bonus;
}

function _consume_throw_weapons(array $p): array {
    $lines = []; $keep = [];
    foreach ($p['weapons'] as $w) {
        $m = get_weapon($w['id']);
        if ($m && $m['type'] === 'throw') {
            $lines[] = "> [{$w['name']}] 自動投擲 → 消費した。";
        } else {
            $keep[] = $w;
        }
    }
    $p['weapons'] = $keep;
    return [$p, $lines];
}

function _do_throw(array $p): array {
    $lines = []; $dmg = 0;
    foreach ($p['weapons'] as $i => $w) {
        $m = get_weapon($w['id']);
        if ($m && $m['type'] === 'throw') {
            $td  = $m['throw_dmg'] ?? $m['dmg'];
            $dmg = rng($td[0], $td[1]);
            $lines[] = "> [{$w['name']}] 投擲！ 消費した。";
            array_splice($p['weapons'], $i, 1);
            break;
        }
    }
    if ($dmg === 0 && empty($lines)) $lines[] = "> 投擲できる武器がない。";
    return [$p, $dmg, $lines];
}

function _use_item(array $p, array $item): array {
    $lines = [];
    switch ($item['effect']) {
        case 'heal':
            $p['hp'] = min($p['max_hp'], $p['hp'] + $item['value']);
            $lines[] = "> [{$item['name']}] 使用。HP +{$item['value']}。"; break;
        case 'mp':
            $p['mp'] = min($p['max_mp'], $p['mp'] + $item['value']);
            $lines[] = "> [{$item['name']}] 使用。MP +{$item['value']}。"; break;
        case 'temp_atk':
            $p['temp_atk'] += $item['value'];
            $lines[] = "> [{$item['name']}] 使用。ATK +{$item['value']}（一時的）。"; break;
        case 'temp_def':
            $p['temp_def'] += $item['value'];
            $lines[] = "> [{$item['name']}] 使用。DEF +{$item['value']}（一時的）。"; break;
        case 'escape':
            $lines[] = "> [{$item['name']}] はここでは使えない（逃走コマンドで使用）。";
            array_unshift($p['items'], $item); break;
        case 'gold_fever':
            $p['gold_fever_days'] = ($p['gold_fever_days'] ?? 0) + $item['value'];
            // 戦闘中使用 → gold_fever_days加算のみ。倍率シフトは勝利時に適用。
            $lines[] = "> [{$item['name']}] 使用。{$item['value']}日間 獲得金ボーナス！";
            $lines[] = "> ※ 効果は戦闘勝利後に発動。";
            break;
    }
    return [$p, $lines];
}

// ============================================================
//  敵ターン処理（4段階優先順位）
//
//  1. 投擲武器あり       → 無条件投擲・消費
//  2. 回復薬あり & HP低  → use_chance×(1-HP率) で判定
//  3. スモーク & flee & HP50%以下 → 逃走
//  4. いずれも非該当     → action_weights テーブルで抽選
// ============================================================
function _mob_turn(array $mob, array $p, int $p_def, int $p_agi, bool $defending, array $lines): array {
    $mob_escaped = false;
    $m_agi       = $mob['agi'];
    $did_attack  = false;   // 攻撃処理をここで行ったか
    $m_dmg       = 0;
    $def_judge   = HIT_NORMAL;

    // ---- 優先1: 投擲武器 ----
    $throw_idx = _mob_throw_idx($mob);
    if ($throw_idx !== false) {
        $weapon   = $mob['mob_items'][$throw_idx];
        $td       = get_weapon($weapon['id']);
        $throw_rng = $td ? $td['throw_dmg'] ?? $td['dmg'] : [5, 12];
        $raw      = rng($throw_rng[0], $throw_rng[1]);
        $after    = max(0, $raw - $p_def);
        $def_judge = _agi_judge($m_agi, $p_agi);
        $m_dmg    = in_array($def_judge, [HIT_PARRY, HIT_EX_PARRY])
                  ? 0 : max(1, (int)round($after * DMG_MULT[$def_judge]));
        $label    = _judge_label($def_judge);
        $lines[]  = "> [{$mob['name']}] 投擲！ [{$weapon['name']}] 消費。";
        if ($m_dmg > 0) {
            $lines[] = ">  {$raw} - DEF:{$p_def} = {$after}" . ($label ? " {$label}" : '') . " → {$m_dmg} ダメージ。";
        } else {
            $lines[] = "> " . ($label ? "{$label} " : '') . "投擲を見切った！";
        }
        // 消費
        array_splice($mob['mob_items'], $throw_idx, 1);
        $did_attack = true;

    // ---- 優先2: 回復薬 ----
    } elseif (_mob_try_heal($mob, $lines)) {
        // _mob_try_heal 内でHP回復・消費・ログ追記済み
        // 参照渡しできないのでmobを再取得する形で対応
        [$mob, $lines] = _mob_do_heal($mob, $lines);
        // 回復したターンは攻撃しない
        goto mob_turn_end;

    // ---- 優先3: スモークボム（逃走） ----
    } elseif (_mob_try_smoke($mob)) {
        $smoke_idx = _mob_item_idx($mob, 'escape');
        array_splice($mob['mob_items'], $smoke_idx, 1);
        $lines[]     = "> [{$mob['name']}] スモークボムを投げて逃げた！";
        $mob_escaped = true;
        goto mob_turn_end;

    // ---- 優先4: tendency テーブルで抽選 ----
    } else {
        $weights = $mob['action_weights'] ?? ['attack' => 100];
        // flee傾向で所持アイテムなし・スモークなし → flee選択時も通常逃走判定
        $chosen = mob_action_roll($weights);

        if ($chosen === 'flee') {
            // 逃走試行（スモークなし版）
            $flee_chance = min(80, ($mob['mob_item_chance'] ?? 0) + $m_agi - $p_agi);
            if (rng(1, 100) <= max(10, $flee_chance)) {
                $lines[]     = "> [{$mob['name']}] 逃げ出した！";
                $mob_escaped = true;
                goto mob_turn_end;
            }
            $lines[] = "> [{$mob['name']}] 逃走失敗。";
            // 逃走失敗 → 通常攻撃にフォールバック
            $chosen = 'attack';
        }

        if ($chosen === 'guard') {
            $lines[]    = "> [{$mob['name']}] 守りを固めた。";
            // guard: 次の被ダメを半減するフラグ（簡易実装: このターンはDEF+50%相当）
            $mob['_guarding'] = true;
            goto mob_turn_end;
        }

        if ($chosen === 'buff') {
            $gain = rng(2, 6);
            $mob['atk'] += $gain;
            $lines[] = "> [{$mob['name']}] 気合を入れた。ATK +{$gain}。";
            goto mob_turn_end;
        }

        if ($chosen === 'debuff') {
            $drain = rng(2, 5);
            $p['temp_def'] = ($p['temp_def'] ?? 0) - $drain;
            $lines[] = "> [{$mob['name']}] 揺さぶりをかけた。DEF -{$drain}（一時的）。";
            goto mob_turn_end;
        }

        if ($chosen === 'skill') {
            // skill: ATKの1.5〜2.0倍の強攻撃
            $skill_mult = rng(150, 200) / 100;
            $m_base     = max(0, (int)round($mob['atk'] * $skill_mult) + rng(-2, 2));
            $lines[]    = "> [{$mob['name']}] 渾身の一撃！";
            $def_judge  = _agi_judge($m_agi, $p_agi);
            $after      = max(0, $m_base - $p_def);
            if ($defending) $after = (int)($after / 2);
            $label      = _judge_label($def_judge);
            $m_dmg      = in_array($def_judge, [HIT_PARRY, HIT_EX_PARRY])
                        ? 0 : max(1, (int)round($after * DMG_MULT[$def_judge]));
            if ($m_dmg > 0) {
                $defend_note = $defending ? ' [防御半減]' : '';
                $lines[] = ">  {$m_base} - DEF:{$p_def} = {$after}{$defend_note}" . ($label ? " {$label}" : '') . " → {$m_dmg} ダメージ。";
            } else {
                $lines[] = "> " . ($label ? "{$label} " : '') . "見切った！";
            }
            $did_attack = true;
            goto mob_apply_damage;
        }

        // attack（デフォルト）
        $m_base    = max(0, $mob['atk'] + rng(-4, 4));
        $def_judge = _agi_judge($m_agi, $p_agi);
        $after     = max(0, $m_base - $p_def);
        if ($defending) $after = (int)($after / 2);
        $label     = _judge_label($def_judge);
        $m_dmg     = in_array($def_judge, [HIT_PARRY, HIT_EX_PARRY])
                   ? 0 : (int)round($after * DMG_MULT[$def_judge]);
        $did_attack = true;

        if ($m_dmg > 0) {
            $defend_note = $defending ? ' [防御半減]' : '';
            $lines[] = "> [{$mob['name']}] 攻撃: {$m_base} - DEF:{$p_def} = {$after}{$defend_note}" . ($label ? " {$label}" : '') . " → {$m_dmg} ダメージ。";
        } else {
            $lines[] = $label
                ? "> {$label} [{$mob['name']}] の攻撃を見切った！"
                : "> [{$mob['name']}] の攻撃を完全に弾いた。";
        }
    }

    mob_apply_damage:
    // ---- ダメージ適用 ----
    if ($m_dmg > 0) {
        $p['hp'] = max(0, $p['hp'] - $m_dmg);

        // 防具耐久減算
        if (!empty($p['armor'])) {
            $dur_dmg = max(1, (int)floor($mob['atk'] / 4));
            $p['armor']['durability'] -= $dur_dmg;
            if ($p['armor']['durability'] <= 0) {
                $lines[] = "> 【{$p['armor']['name']}】が破損して使えなくなった！";
                $p['armor'] = null;
            } else {
                $lines[] = "> [{$p['armor']['name']}] 耐久: {$p['armor']['durability']}";
            }
        }
    }

    // ---- 報酬倍率: 防御結果を加算 ----
    if ($did_attack) {
        $def_delta = REWARD_DEF_DELTA[$def_judge];
        if ($def_delta != 0.0) {
            $p['reward_mult'] = _clamp_mult($p['reward_mult'] + $def_delta);
            $sign = $def_delta > 0 ? '+' : '';
            $lines[] = "> [倍率] 防御 {$sign}" . number_format($def_delta, 1) . " → ×" . number_format($p['reward_mult'], 1);
        }
    }

    mob_turn_end:
    return [$mob, $lines, $p, $mob_escaped];
}

// ---- 内部ヘルパー: 投擲武器のインデックスを返す ----
function _mob_throw_idx(array $mob): int|false {
    foreach ($mob['mob_items'] ?? [] as $i => $item) {
        $m = get_weapon($item['id'] ?? '');
        if ($m && $m['type'] === 'throw') return $i;
    }
    return false;
}

// ---- 内部ヘルパー: アイテムeffectのインデックスを返す ----
function _mob_item_idx(array $mob, string $effect): int|false {
    foreach ($mob['mob_items'] ?? [] as $i => $item) {
        if (($item['effect'] ?? '') === $effect) return $i;
    }
    return false;
}

// ---- 内部ヘルパー: 回復薬使用判定 ----
function _mob_try_heal(array $mob, array $lines): bool {
    $heal_idx = _mob_item_idx($mob, 'heal');
    if ($heal_idx === false) return false;
    $hp_rate    = $mob['max_hp'] > 0 ? $mob['hp'] / $mob['max_hp'] : 1.0;
    $use_chance = (int)(($mob['mob_use_chance'] ?? 0) * (1 - $hp_rate));
    return rng(1, 100) <= $use_chance;
}

// ---- 内部ヘルパー: 回復実行（mob配列を返す） ----
function _mob_do_heal(array $mob, array $lines): array {
    $heal_idx = _mob_item_idx($mob, 'heal');
    if ($heal_idx === false) return [$mob, $lines];
    $item       = $mob['mob_items'][$heal_idx];
    $heal_val   = $item['value'] ?? 30;
    $mob['hp']  = min($mob['max_hp'], $mob['hp'] + $heal_val);
    $lines[]    = "> [{$mob['name']}] [{$item['name']}] を使った。HP +{$heal_val}。(残HP: {$mob['hp']})";
    array_splice($mob['mob_items'], $heal_idx, 1);
    return [$mob, $lines];
}

// ---- 内部ヘルパー: スモーク逃走判定 ----
function _mob_try_smoke(array $mob): bool {
    if (_mob_item_idx($mob, 'escape') === false) return false;
    $weights  = $mob['action_weights'] ?? [];
    $is_flee  = ($weights['flee'] ?? 0) >= 30;   // flee傾向が主力or副軸
    $hp_rate  = $mob['max_hp'] > 0 ? $mob['hp'] / $mob['max_hp'] : 1.0;
    return $is_flee && $hp_rate <= 0.5;
}

function _find_item_idx(array $p, string $effect): int|false {
    foreach ($p['items'] as $i => $item) {
        if ($item['effect'] === $effect) return $i;
    }
    return false;
}
