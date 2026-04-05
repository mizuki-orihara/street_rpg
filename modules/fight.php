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
    $st = STAGES[$p['stage']];

    if ($is_boss) {
        $mob = [
            'name'    => $st['boss']['name'],
            'hp'      => $st['boss']['hp'],
            'max_hp'  => $st['boss']['hp'],
            'atk'     => $st['boss']['atk'],
            'def'     => $st['boss']['def'],
            'agi'     => $st['boss']['agi'] ?? 20,
            'is_boss' => true,
        ];
    } else {
        $mob = mob_encounter($p['stage']);
    }

    // 報酬倍率リセット。護符あり: 即 +1.0
    $p['reward_mult'] = 1.0;
    if (!empty($p['gold_fever_days'])) {
        $p['reward_mult'] = 2.0;
    }

    $p['battle'] = $mob;
    player_set($p);
    return $mob;
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

    // ---- 敵の反撃 ----
    if ($action !== 'run') {
        $m_base   = max(0, $mob['atk'] + rng(-4, 4));
        $m_after  = max(0, $m_base - $p_def);
        if ($defending) $m_after = (int)($m_after / 2);

        // 敵攻撃に対するAGI判定（守備側がプレイヤー）
        $def_judge = _agi_judge($m_agi, $p_agi);
        $def_label = _judge_label($def_judge);

        // 見切り系はプレイヤーが回避 = ダメージ0
        $m_dmg = in_array($def_judge, [HIT_PARRY, HIT_EX_PARRY])
               ? 0
               : (int)round($m_after * DMG_MULT[$def_judge]);

        if ($m_dmg > 0) {
            $defend_note = $defending ? ' [防御半減]' : '';
            $lines[] = "> [{$mob['name']}] 攻撃: {$m_base} - DEF:{$p_def} = {$m_after}{$defend_note}" . ($def_label ? " {$def_label}" : '') . " → {$m_dmg} ダメージ。";
            $p['hp'] = max(0, $p['hp'] - $m_dmg);

            // 防具の耐久減算（敵ATKの1/4、端数切り捨て・最低1）
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
        } else {
            $msg = $def_label ? "> {$def_label} [{$mob['name']}] の攻撃を見切った！" : "> [{$mob['name']}] の攻撃を完全に弾いた。";
            $lines[] = $msg;
        }

        // 報酬倍率: 防御結果を加算
        $def_delta = REWARD_DEF_DELTA[$def_judge];
        if ($def_delta != 0.0) {
            $p['reward_mult'] = _clamp_mult($p['reward_mult'] + $def_delta);
            $sign = $def_delta > 0 ? '+' : '';
            $lines[] = "> [倍率] 防御 {$sign}" . number_format($def_delta, 1) . " → ×" . number_format($p['reward_mult'], 1);
        }
    }

    // ---- 後攻ならここで敵HPに反映・勝利判定 ----
    if (!$player_first && $player_dmg > 0) {
        $mob['hp'] = max(0, $mob['hp'] - $player_dmg);
    }

    // ---- 死亡判定 ----
    if ($p['hp'] <= 0) {
        $lines[] = "> 力尽きた……";
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
function _fight_action_jk(array $p, array $mob, string $action): array {
    $lines = [];

    switch ($action) {
        case 'attack':
        case 'throw':
        case 'skill':
            // 攻撃系 → 罰金
            $fine = $p['stage'] * 200;
            $lines[] = "> っ……なにしてんだ！";
            $lines[] = "> 通報された。警察が来た。";
            $lines[] = "> 罰金 ¥{$fine}。";
            $p['money'] = max(0, $p['money'] - $fine);
            if ($p['money'] === 0) $lines[] = "> 所持金が底をついた。";
            $p['battle']      = null;
            $p['reward_mult'] = 1.0;
            $p = advance_day($p);
            player_set($p);
            return ['lines' => $lines, 'result' => 'jk_penalty', 'player' => $p];

        case 'run':
            $lines[] = "> 足早にその場を離れた。";
            $p['battle']      = null;
            $p['reward_mult'] = 1.0;
            $p = advance_day($p);
            player_set($p);
            return ['lines' => $lines, 'result' => 'escape', 'player' => $p];

        case 'defend':
            $lines[] = "> ……なにもしない方がいい。";
            player_set($p);
            return ['lines' => $lines, 'result' => 'continue', 'player' => $p, 'mob' => $mob];

        case 'item':
            if (empty($p['items'])) { $lines[] = "> アイテムがない。"; }
            else {
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

// ============================================================
//  勝利処理（共通）
// ============================================================
function _resolve_win(array $p, array $mob, array $lines): array {
    $result = 'win';
    $base   = rng(10, 80) + ($p['stage'] - 1) * 20;
    $mult   = $p['reward_mult'];
    $reward = max(0, (int)round($base * $mult));

    $lines[] = "> [{$mob['name']}] を倒した！";
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
        $result  = 'boss_win';
        $lines[] = "> ===========================";
        $lines[] = "> BOSS [{$mob['name']}] 撃破！";
        $lines[] = "> 武器・アイテムは没収された。";
        $lines[] = "> ステータスは裸で持ち越す。";
        $p['weapons']      = [];
        $p['items']        = [];
        $p['armor']        = null;
        $p['boss_progress']= 0;      // 次ステージ用にリセット
        $p['boss_ready']   = false;
        $p['stage']++;
        if ($p['stage'] > count(STAGES)) {
            $result  = 'game_clear';
            $lines[] = "> ===========================";
            $lines[] = "> 全ステージ制覇。";
            $lines[] = "> お前が路地裏の王だ。";
        } else {
            $p['hp'] = $p['max_hp'];
            $p['mp'] = $p['max_mp'];
            $lines[] = "> ステージ " . $p['stage'] . " へ。";
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
            // 戦闘中に使った場合も即 +1.0
            $p['reward_mult'] = _clamp_mult(($p['reward_mult'] ?? 1.0) + 1.0);
            $lines[] = "> [{$item['name']}] 使用。{$item['value']}日間 獲得金ボーナス！ 現倍率: ×" . number_format($p['reward_mult'], 1);
            break;
    }
    return [$p, $lines];
}

function _find_item_idx(array $p, string $effect): int|false {
    foreach ($p['items'] as $i => $item) {
        if ($item['effect'] === $effect) return $i;
    }
    return false;
}
