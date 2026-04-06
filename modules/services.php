<?php
// ============================================================
//  modules/services.php — 宿 / 修練所 / 武器屋 / 道具屋
// ============================================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../session.php';

// ============================================================
//  宿
// ============================================================

function inn_quote(): int {
    $st = STAGES[player_get()['stage']];
    return rng($st['inn_cost'][0], $st['inn_cost'][1]);
}

function inn_stay(int $cost): array {
    $p = player_get();
    if ($p['money'] < $cost) {
        return ['ok' => false, 'lines' => ["> 金が足りない。(¥{$cost} 必要)"]];
    }
    $p['money'] -= $cost;
    $p['hp'] = $p['max_hp'];
    $p['mp'] = $p['max_mp'];
    player_set($p);
    return ['ok' => true, 'lines' => ["> ¥{$cost} を払って食事した。", "> HP・MP が全回復した。"], 'player' => $p];
}

// ============================================================
//  修練所（全stat一括強化）
// ============================================================

function dojo_train(): array {
    $p    = player_get();
    $cost = STAGES[$p['stage']]['dojo_cost'] * 4;   // 価格4倍

    if ($p['money'] < $cost) {
        return ['ok' => false, 'lines' => ["> 金が足りない。(¥{$cost} 必要)"]];
    }

    $p['money'] -= $cost;
    // ステージ数でスケール: ST1=1-5% / ST2=2-6% / ST3=3-7% / EXはST3扱い
    $stage_key = min($p['stage'], 3);
    $pct = rng($stage_key, $stage_key + 4);

    $lines = ["> 修練。¥{$cost} 消費。(上限+{$pct}%)"];

    // 全statを一括で上昇
    foreach (['atk', 'def', 'agi', 'luk'] as $s) {
        $gain       = max(1, (int)ceil($p[$s] * $pct / 100));
        $p[$s]     += $gain;
        $lines[]    = ">   {$s}: +{$gain}";
    }

    // HP: 上限値を ceil で上昇、現在値は (pct - 0.5)% を floor で回復
    $hp_gain        = max(1, (int)ceil($p['max_hp'] * $pct / 100));
    $p['max_hp']   += $hp_gain;
    $hp_rec         = (int)floor($p['hp'] * ($pct - 0.5) / 100);
    $p['hp']        = min($p['max_hp'], $p['hp'] + $hp_rec);
    $lines[]        = ">   max_hp: +{$hp_gain}  hp回復: +{$hp_rec}";

    // MP: 同様
    $mp_gain        = max(1, (int)ceil($p['max_mp'] * $pct / 100));
    $p['max_mp']   += $mp_gain;
    $mp_rec         = (int)floor($p['mp'] * ($pct - 0.5) / 100);
    $p['mp']        = min($p['max_mp'], $p['mp'] + $mp_rec);
    $lines[]        = ">   max_mp: +{$mp_gain}  mp回復: +{$mp_rec}";

    $p = advance_day($p);
    player_set($p);
    return ['ok' => true, 'lines' => $lines, 'player' => $p];
}

// ============================================================
//  武器屋（CSV経由でマスター参照）
// ============================================================

function weapon_shop_stock(): array {
    if (!empty($_SESSION['weapon_stock'])) return $_SESSION['weapon_stock'];

    $pool = weapons_all();   // config.php の CSV読み込み関数
    shuffle($pool);
    $stock = [];
    foreach (array_slice($pool, 0, 3) as $w) {
        $stock[] = [
            'id'        => $w['id'],
            'name'      => $w['name'],
            'type'      => $w['type'],
            'desc'      => $w['desc'],
            'dmg'       => $w['dmg'],
            'throw_dmg' => $w['throw_dmg'],
            'price'     => rng($w['price'][0], $w['price'][1]),
        ];
    }
    $_SESSION['weapon_stock'] = $stock;
    return $stock;
}

function weapon_shop_refresh(): void { unset($_SESSION['weapon_stock']); }

function weapon_buy(int $idx): array {
    $stock = weapon_shop_stock();
    if (!isset($stock[$idx])) return ['ok' => false, 'lines' => ["> 無効な選択。"]];
    $item = $stock[$idx];
    $p    = player_get();
    if ($p['money'] < $item['price']) {
        return ['ok' => false, 'lines' => ["> 金が足りない。(¥{$item['price']} 必要)"]];
    }
    $p['money'] -= $item['price'];
    $p['weapons'][] = ['id' => $item['id'], 'name' => $item['name']];
    $p = advance_day($p);   // 買い物で1日経過
    player_set($p);
    return [
        'ok'     => true,
        'lines'  => ["> [{$item['name']}] を購入した。¥{$item['price']} 消費。", "> 武装数: ".count($p['weapons'])],
        'player' => $p,
    ];
}

// ============================================================
//  道具屋（CSV経由でマスター参照）
// ============================================================

function item_shop_stock(): array {
    if (!empty($_SESSION['item_stock'])) return $_SESSION['item_stock'];

    // shop='item'のみ（サプリ・護符）
    $pool = array_values(array_filter(items_all(), fn($it) => ($it['shop'] ?? 'item') === 'item'));
    shuffle($pool);
    $stock = [];
    foreach (array_slice($pool, 0, 3) as $it) {
        $stock[] = [
            'id'     => $it['id'],
            'name'   => $it['name'],
            'effect' => $it['effect'],
            'value'  => $it['value'],
            'desc'   => $it['desc'],
            'price'  => rng($it['price'][0], $it['price'][1]),
        ];
    }
    $_SESSION['item_stock'] = $stock;
    return $stock;
}

function item_shop_refresh(): void { unset($_SESSION['item_stock']); }

function item_buy(int $idx): array {
    $stock = item_shop_stock();
    if (!isset($stock[$idx])) return ['ok' => false, 'lines' => ["> 無効な選択。"]];
    $item = $stock[$idx];
    $p    = player_get();
    if (count($p['items']) >= 3) {
        return ['ok' => false, 'lines' => ["> アイテムは3個まで。使ってから買え。"]];
    }
    if ($p['money'] < $item['price']) {
        return ['ok' => false, 'lines' => ["> 金が足りない。(¥{$item['price']} 必要)"]];
    }
    $p['money'] -= $item['price'];
    $p['items'][] = ['id' => $item['id'], 'name' => $item['name'], 'effect' => $item['effect'], 'value' => $item['value']];
    $p = advance_day($p);
    player_set($p);
    return [
        'ok'    => true,
        'lines' => ["> [{$item['name']}] を購入した。¥{$item['price']} 消費。"],
        'player'=> $p,
    ];
}

// ============================================================
//  飲食店お土産（shop='food'のアイテム）
// ============================================================

function food_shop_stock(): array {
    if (!empty($_SESSION['food_stock'])) return $_SESSION['food_stock'];

    $pool = array_values(array_filter(items_all(), fn($it) => ($it['shop'] ?? 'item') === 'food'));
    shuffle($pool);
    $stock = [];
    foreach (array_slice($pool, 0, 3) as $it) {
        $stock[] = [
            'id'     => $it['id'],
            'name'   => $it['name'],
            'effect' => $it['effect'],
            'value'  => $it['value'],
            'desc'   => $it['desc'],
            'price'  => rng($it['price'][0], $it['price'][1]),
        ];
    }
    $_SESSION['food_stock'] = $stock;
    return $stock;
}

function food_shop_refresh(): void { unset($_SESSION['food_stock']); }

function food_buy(int $idx): array {
    $stock = food_shop_stock();
    if (!isset($stock[$idx])) return ['ok' => false, 'lines' => ["> 無効な選択。"]];
    $item = $stock[$idx];
    $p    = player_get();
    if (count($p['items']) >= 3) {
        return ['ok' => false, 'lines' => ["> アイテムは3個まで。使ってから買え。"]];
    }
    if ($p['money'] < $item['price']) {
        return ['ok' => false, 'lines' => ["> 金が足りない。(¥{$item['price']} 必要)"]];
    }
    $p['money'] -= $item['price'];
    $p['items'][] = ['id' => $item['id'], 'name' => $item['name'], 'effect' => $item['effect'], 'value' => $item['value']];
    $p = advance_day($p);
    player_set($p);
    return [
        'ok'    => true,
        'lines' => ["> [{$item['name']}] をお土産に買った。¥{$item['price']} 消費。"],
        'player'=> $p,
    ];
}

// ============================================================
//  アイテム使用（マップ画面から）
// ============================================================

function item_use(int $idx): array {
    $p = player_get();
    if (!isset($p['items'][$idx])) return ['ok' => false, 'lines' => ["> 無効な選択。"]];
    $item = $p['items'][$idx];
    array_splice($p['items'], $idx, 1);

    $lines = [];
    switch ($item['effect']) {
        case 'heal':
            $p['hp'] = min($p['max_hp'], $p['hp'] + $item['value']);
            $lines[] = "> [{$item['name']}] 使用。HP +{$item['value']}。(現在: {$p['hp']}/{$p['max_hp']})";
            break;
        case 'mp':
            $p['mp'] = min($p['max_mp'], $p['mp'] + $item['value']);
            $lines[] = "> [{$item['name']}] 使用。MP +{$item['value']}。(現在: {$p['mp']}/{$p['max_mp']})";
            break;
        case 'temp_atk':
            $p['temp_atk'] = ($p['temp_atk'] ?? 0) + $item['value'];
            $lines[] = "> [{$item['name']}] 使用。次戦ATK +{$item['value']}。";
            break;
        case 'temp_def':
            $p['temp_def'] = ($p['temp_def'] ?? 0) + $item['value'];
            $lines[] = "> [{$item['name']}] 使用。次戦DEF +{$item['value']}。";
            break;
        case 'gold_fever':
            $p['gold_fever_days'] = ($p['gold_fever_days'] ?? 0) + $item['value'];
            // reward_multへの即時加算は廃止。戦闘終了後に+2.0シフトで作用する。
            $lines[] = "> [{$item['name']}] 使用。{$item['value']}日間 獲得金ボーナス！";
            $lines[] = "> ※ 効果は次の戦闘勝利後に発動。";
            break;
        case 'perm_atk':
            $p['atk'] += $item['value'];
            $lines[] = "> [{$item['name']}] 使用。ATK +{$item['value']}（永続）。(現在: {$p['atk']})";
            break;
        case 'perm_def':
            $p['def'] += $item['value'];
            $lines[] = "> [{$item['name']}] 使用。DEF +{$item['value']}（永続）。(現在: {$p['def']})";
            break;
        case 'perm_agi':
            $p['agi'] += $item['value'];
            $lines[] = "> [{$item['name']}] 使用。AGI +{$item['value']}（永続）。(現在: {$p['agi']})";
            break;
        case 'perm_luk':
            $p['luk'] += $item['value'];
            $lines[] = "> [{$item['name']}] 使用。LUK +{$item['value']}（永続）。(現在: {$p['luk']})";
            break;
        case 'escape':
        case 'omamori':
            // マップ画面では使えない（ボス戦敗北時に自動発動）
            array_splice($p['items'], $idx, 0, [$item]);  // 戻す
            $lines[] = "> [{$item['name']}] は戦闘中にしか使えない。";
            break;
        default:
            $lines[] = "> [{$item['name']}] は使用できない。";
            break;
    }

    player_set($p);
    return ['ok' => true, 'lines' => $lines, 'player' => $p];
}

// ============================================================
//  防具屋
// ============================================================

function armor_shop_stock(): array {
    if (!empty($_SESSION['armor_stock'])) return $_SESSION['armor_stock'];

    $pool  = armors_all();
    $stock = [];
    foreach ($pool as $a) {
        $stock[] = [
            'id'         => $a['id'],
            'name'       => $a['name'],
            'def_bonus'  => $a['def_bonus'],
            'durability' => $a['durability'],
            'desc'       => $a['desc'],
            'price'      => rng($a['price'][0], $a['price'][1]),
        ];
    }
    $_SESSION['armor_stock'] = $stock;
    return $stock;
}

function armor_shop_refresh(): void { unset($_SESSION['armor_stock']); }

function armor_buy(int $idx): array {
    $stock = armor_shop_stock();
    if (!isset($stock[$idx])) return ['ok' => false, 'lines' => ["> 無効な選択。"]];
    $item = $stock[$idx];
    $p    = player_get();
    if ($p['money'] < $item['price']) {
        return ['ok' => false, 'lines' => ["> 金が足りない。(¥{$item['price']} 必要)"]];
    }
    $old  = $p['armor'];
    $p['money'] -= $item['price'];
    $p['armor']  = [
        'id'         => $item['id'],
        'name'       => $item['name'],
        'def_bonus'  => $item['def_bonus'],
        'durability' => $item['durability'],  // 耐久値はCSVで個別設定
    ];
    $p = advance_day($p);
    player_set($p);
    $lines = ["> [{$item['name']}] を装備した。DEF +{$item['def_bonus']}。¥{$item['price']} 消費。"];
    if ($old) $lines[] = "> 前の装備 [{$old['name']}] は外れた。";
    return ['ok' => true, 'lines' => $lines, 'player' => $p];
}
