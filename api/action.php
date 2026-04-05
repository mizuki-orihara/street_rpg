<?php
// ============================================================
//  api/action.php — fetch API エンドポイント
// ============================================================

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../session.php';
require_once __DIR__ . '/../modules/chargen.php';
require_once __DIR__ . '/../modules/map.php';
require_once __DIR__ . '/../modules/fight.php';
require_once __DIR__ . '/../modules/services.php';

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

function resp(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

switch ($action) {

    // ---- キャラ生成 ----
    case 'roll':
        $stats = chargen_roll();
        $score = $stats['hp'] + $stats['mp'] * 2 + $stats['atk'] * 3
               + $stats['def'] * 3 + $stats['agi'] * 2 + $stats['luk']
               + (int)($stats['money'] / 20);
        resp(['stats' => $stats, 'score' => $score, 'rating' => player_rating($score), 'comment' => chargen_comment($stats)]);

    case 'confirm':
        $stats = $input['stats'] ?? null;
        if (!$stats) resp(['error' => 'no stats']);
        resp(['ok' => true, 'player' => chargen_confirm($stats)]);

    // ---- マップ ----
    case 'map':
        $p = player_get();
        if (!$p) resp(['error' => 'no session']);
        resp(['player' => $p, 'nodes' => map_nodes($p['stage']), 'stage_info' => STAGES[$p['stage']] ?? null]);

    // ---- 戦闘 ----
    case 'fight_start':
        $p = player_get();
        if (!$p) resp(['error' => 'no session']);
        resp(['ok' => true, 'mob' => fight_init(false), 'player' => $p]);

    case 'boss_start':
        $p = player_get();
        if (!$p) resp(['error' => 'no session']);
        resp(['ok' => true, 'mob' => fight_init(true), 'player' => $p]);

    case 'fight_action':
        $p = player_get();
        if (!$p) resp(['error' => 'no session']);
        resp(fight_action($input['cmd'] ?? 'attack'));

    // ---- 宿 ----
    case 'inn_quote':
        $p = player_get();
        if (!$p) resp(['error' => 'no session']);
        $cost = inn_quote();
        $_SESSION['inn_cost'] = $cost;
        resp(['cost' => $cost, 'player' => $p]);

    case 'inn_stay':
        $p = player_get();
        if (!$p) resp(['error' => 'no session']);
        resp(inn_stay($_SESSION['inn_cost'] ?? inn_quote()));

    // ---- 修練所 ----
    case 'dojo_info':
        $p = player_get();
        if (!$p) resp(['error' => 'no session']);
        $cost = STAGES[$p['stage']]['dojo_cost'] * 4;
        resp(['cost' => $cost, 'player' => $p]);

    case 'dojo_train':
        $p = player_get();
        if (!$p) resp(['error' => 'no session']);
        resp(dojo_train());

    // ---- 武器屋 ----
    case 'weapon_stock':
        $p = player_get();
        if (!$p) resp(['error' => 'no session']);
        weapon_shop_refresh();
        resp(['stock' => weapon_shop_stock(), 'player' => $p]);

    case 'weapon_buy':
        $p = player_get();
        if (!$p) resp(['error' => 'no session']);
        resp(weapon_buy((int)($input['idx'] ?? 0)));

    // ---- 道具屋 ----
    case 'item_stock':
        $p = player_get();
        if (!$p) resp(['error' => 'no session']);
        item_shop_refresh();
        resp(['stock' => item_shop_stock(), 'player' => $p]);

    case 'item_buy':
        $p = player_get();
        if (!$p) resp(['error' => 'no session']);
        resp(item_buy((int)($input['idx'] ?? 0)));

    // ---- 飲食店お土産 ----
    case 'food_stock':
        $p = player_get();
        if (!$p) resp(['error' => 'no session']);
        food_shop_refresh();
        resp(['stock' => food_shop_stock(), 'player' => $p]);

    case 'food_buy':
        $p = player_get();
        if (!$p) resp(['error' => 'no session']);
        resp(food_buy((int)($input['idx'] ?? 0)));

    // ---- アイテム使用（マップ画面） ----
    case 'item_use':
        $p = player_get();
        if (!$p) resp(['error' => 'no session']);
        resp(item_use((int)($input['idx'] ?? 0)));

    // ---- 防具屋 ----
    case 'armor_stock':
        $p = player_get();
        if (!$p) resp(['error' => 'no session']);
        armor_shop_refresh();
        resp(['stock' => armor_shop_stock(), 'player' => $p]);

    case 'armor_buy':
        $p = player_get();
        if (!$p) resp(['error' => 'no session']);
        resp(armor_buy((int)($input['idx'] ?? 0)));

    // ---- プレイヤー状態の直接更新（クリア時名前登録等）----
    case 'set_player':
        $p = player_get();
        if (!$p) resp(['error' => 'no session']);
        $new = $input['player'] ?? null;
        if (!$new) resp(['error' => 'no player data']);
        // 安全なフィールドのみ上書き（ステータス系は触らせない）
        foreach (['name', '_cleared', '_ng_plus'] as $key) {
            if (isset($new[$key])) $p[$key] = $new[$key];
        }
        player_set($p);
        resp(['ok' => true, 'player' => $p]);

    // ---- リセット ----
    case 'reset':
        player_clear();
        session_destroy();
        resp(['ok' => true]);

    default:
        resp(['error' => 'unknown action: ' . $action]);
}