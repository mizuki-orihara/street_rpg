<?php
// ============================================================
//  api/save.php — セーブ・ロード・スロット管理
//
//  クッキー sd_uuid でプレイヤーを識別。
//  data/saves/{uuid}/
//    story.json   … STORYスロット 3つ
//    pvp.json     … PVPスロット   3つ（外部インポート先）
//
//  アクション一覧:
//    init          起動時: UUID発行/確認 + スロット一覧返却
//    save          現在セッションを指定スロットに書き込み
//    load          指定スロットをセッションに展開
//    slot_delete   指定スロットを null に
//    ng_plus       強くてニューゲーム（引き継ぎ範囲を絞って新規開始）
//    pvp_import    PVP JSONをpvp.jsonとして保存
// ============================================================

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../session.php';

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

function resp(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
//  UUID管理
// ============================================================
const COOKIE_KEY = 'sd_uuid';
const COOKIE_TTL = 60 * 60 * 24 * 90;  // 90日

function get_or_create_uuid(): string {
    if (!empty($_COOKIE[COOKIE_KEY]) && preg_match('/^[0-9a-f\-]{36}$/', $_COOKIE[COOKIE_KEY])) {
        return $_COOKIE[COOKIE_KEY];
    }
    $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
    setcookie(COOKIE_KEY, $uuid, [
        'expires'  => time() + COOKIE_TTL,
        'path'     => '/',
        'samesite' => 'Lax',
        'httponly' => true,
    ]);
    return $uuid;
}

// ============================================================
//  ファイルパス
// ============================================================
function save_dir(string $uuid): string {
    return SAVE_DIR . '/' . $uuid;
}
function story_path(string $uuid): string {
    return save_dir($uuid) . '/story.json';
}
function pvp_path(string $uuid): string {
    return save_dir($uuid) . '/pvp.json';
}

function ensure_dir(string $uuid): void {
    $dir = save_dir($uuid);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// ============================================================
//  story.json 読み書き
// ============================================================
function story_load(string $uuid): array {
    $path = story_path($uuid);
    if (!file_exists($path)) {
        return ['updated_at' => null, 'slots' => [
            'story_1' => null,
            'story_2' => null,
            'story_3' => null,
        ]];
    }
    return json_decode(file_get_contents($path), true) ?? [];
}

function story_save(string $uuid, array $data): void {
    ensure_dir($uuid);
    $data['updated_at'] = date('c');
    file_put_contents(story_path($uuid), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// ============================================================
//  スロット概要（一覧表示用）
// ============================================================
function slot_summary(?array $player, string $slot_id): array {
    if (!$player) return ['slot' => $slot_id, 'empty' => true];
    return [
        'slot'       => $slot_id,
        'empty'      => false,
        'stage'      => $player['stage']      ?? 1,
        'day'        => $player['day']         ?? 1,
        'money'      => $player['money']       ?? 0,
        'hp'         => $player['hp']          ?? 0,
        'max_hp'     => $player['max_hp']      ?? 0,
        'updated_at' => $player['_saved_at']   ?? null,
        'ng_plus'    => $player['_ng_plus']    ?? 0,   // 周回数
        'cleared'    => $player['_cleared']    ?? false,
    ];
}

// ============================================================
//  空きスロット自動選択
// ============================================================
function find_empty_story_slot(array $story): ?string {
    foreach (['story_1', 'story_2', 'story_3'] as $s) {
        if (empty($story['slots'][$s])) return $s;
    }
    return null;  // 全埋まり
}

// ============================================================
//  強くてニューゲーム用引き継ぎ変換
// ============================================================
function ng_plus_inherit(array $src): array {
    // 引き継ぎ可: ステータス / money / 護符・スモークボムのみ
    $inherit_effects = ['gold_fever', 'escape'];
    $items = array_values(array_filter(
        $src['items'] ?? [],
        fn($it) => in_array($it['effect'] ?? '', $inherit_effects)
    ));

    return [
        // ステータス引き継ぎ
        'hp'       => $src['max_hp'],   // 全回復状態で引き継ぎ
        'max_hp'   => $src['max_hp'],
        'mp'       => $src['max_mp'],
        'max_mp'   => $src['max_mp'],
        'atk'      => $src['atk'],
        'def'      => $src['def'],
        'agi'      => $src['agi'],
        'luk'      => $src['luk'],
        'money'    => $src['money'],
        // 引き継ぎアイテム
        'items'    => $items,
        // リセット項目
        'weapons'        => [],
        'armor'          => null,
        'temp_atk'       => 0,
        'temp_def'       => 0,
        'stage'          => 1,
        'day'            => 1,
        'battle'         => null,
        'gold_fever_days' => $src['gold_fever_days'] ?? 0,  // 護符日数は引き継ぎ
        'reward_mult'    => 1.0,
        'boss_progress'  => 0,
        'boss_ready'     => false,
        'reward_bonus'   => 0.0,
        'civilian_dodge' => [],
        // 周回カウント
        '_ng_plus'   => ($src['_ng_plus'] ?? 0) + 1,
        '_cleared'   => false,
        '_saved_at'  => date('c'),
    ];
}

// ============================================================
//  ルーティング
// ============================================================
switch ($action) {

    // ---- 起動時: UUID確認 + スロット一覧 ----
    case 'init': {
        $uuid  = get_or_create_uuid();
        $story = story_load($uuid);

        // PVP概要も返す（pvp.jsonがあれば）
        $pvp_meta = null;
        $pvp_path = pvp_path($uuid);
        if (file_exists($pvp_path)) {
            $pvp = json_decode(file_get_contents($pvp_path), true);
            $pvp_meta = [
                'creator'    => $pvp['creator']    ?? '',
                'creator_id' => $pvp['creator_id'] ?? '',
                'updated_at' => $pvp['updated_at'] ?? null,
                'slots'      => array_map(
                    fn($s, $id) => slot_summary($s, $id),
                    $pvp['slots'],
                    array_keys($pvp['slots'])
                ),
            ];
        }

        $summaries = [];
        foreach (['story_1', 'story_2', 'story_3'] as $s) {
            $summaries[] = slot_summary($story['slots'][$s] ?? null, $s);
        }

        resp([
            'ok'        => true,
            'uuid'      => $uuid,
            'story'     => $summaries,
            'pvp'       => $pvp_meta,
            'auto_slot' => find_empty_story_slot($story),  // nullなら全埋まり
        ]);
    }

    // ---- セーブ（マップ遷移時に自動で呼ぶ） ----
    case 'save': {
        $uuid = get_or_create_uuid();
        $slot = $input['slot'] ?? '';
        if (!preg_match('/^story_[123]$/', $slot)) {
            resp(['ok' => false, 'error' => 'invalid slot: ' . $slot]);
        }
        $p = player_get();
        if (!$p) resp(['ok' => false, 'error' => 'no session']);

        $p['_saved_at'] = date('c');

        $story = story_load($uuid);
        $story['slots'][$slot] = $p;
        story_save($uuid, $story);

        resp(['ok' => true, 'slot' => $slot, 'saved_at' => $p['_saved_at']]);
    }

    // ---- ロード（起動時のみ） ----
    case 'load': {
        $uuid = get_or_create_uuid();
        $slot = $input['slot'] ?? '';
        if (!preg_match('/^story_[123]$/', $slot)) {
            resp(['ok' => false, 'error' => 'invalid slot']);
        }
        $story = story_load($uuid);
        $p = $story['slots'][$slot] ?? null;
        if (!$p) resp(['ok' => false, 'error' => 'slot empty']);

        player_set($p);
        resp(['ok' => true, 'slot' => $slot, 'player' => $p]);
    }

    // ---- スロット削除 ----
    case 'slot_delete': {
        $uuid = get_or_create_uuid();
        $slot = $input['slot'] ?? '';
        if (!preg_match('/^story_[123]$/', $slot)) {
            resp(['ok' => false, 'error' => 'invalid slot']);
        }
        $story = story_load($uuid);
        $story['slots'][$slot] = null;
        story_save($uuid, $story);
        resp(['ok' => true, 'slot' => $slot]);
    }

    // ---- 強くてニューゲーム ----
    case 'ng_plus': {
        $uuid      = get_or_create_uuid();
        $src_slot  = $input['src_slot']  ?? '';   // 引き継ぎ元
        $dest_slot = $input['dest_slot'] ?? '';   // 書き込み先

        if (!preg_match('/^story_[123]$/', $src_slot) ||
            !preg_match('/^story_[123]$/', $dest_slot)) {
            resp(['ok' => false, 'error' => 'invalid slot']);
        }

        $story = story_load($uuid);
        $src   = $story['slots'][$src_slot] ?? null;
        if (!$src) resp(['ok' => false, 'error' => 'src slot empty']);
        if (empty($src['_cleared'])) resp(['ok' => false, 'error' => 'src not cleared']);

        $new_player = ng_plus_inherit($src);
        player_set($new_player);

        $story['slots'][$dest_slot] = $new_player;
        story_save($uuid, $story);

        resp(['ok' => true, 'slot' => $dest_slot, 'player' => $new_player]);
    }

    // ---- PVP JSONインポート ----
    case 'pvp_import': {
        $uuid    = get_or_create_uuid();
        $pvp_raw = $input['pvp'] ?? null;
        if (!$pvp_raw || !isset($pvp_raw['characters'])) {
            resp(['ok' => false, 'error' => 'invalid pvp data']);
        }

        // creator_id バリデーション（32文字hex）
        $creator_id = $pvp_raw['creator_id'] ?? '';
        if (!preg_match('/^[0-9a-f]{32}$/', $creator_id)) {
            resp(['ok' => false, 'error' => 'invalid creator_id']);
        }

        // char_a/b/c → pvp_1/2/3 に変換
        $char_map = ['char_a' => 'pvp_1', 'char_b' => 'pvp_2', 'char_c' => 'pvp_3'];
        $slots    = [];
        foreach ($char_map as $char_key => $slot_key) {
            $ch = $pvp_raw['characters'][$char_key] ?? null;
            if (!$ch) { $slots[$slot_key] = null; continue; }

            // items: id配列 → {id,name,effect,value} に変換
            $items = [];
            foreach ($ch['items'] ?? [] as $item_id) {
                $master = get_item($item_id);
                if ($master) {
                    $items[] = [
                        'id'     => $master['id'],
                        'name'   => $master['name'],
                        'effect' => $master['effect'],
                        'value'  => $master['value'],
                    ];
                }
            }

            $hp = (int)($ch['hp'] ?? 100);
            $mp = (int)($ch['mp'] ?? 10);
            $slots[$slot_key] = [
                // PVP JSONのフィールドをゲーム内部形式に変換
                'hp'             => $hp,
                'max_hp'         => $hp,
                'mp'             => $mp,
                'max_mp'         => $mp,
                'atk'            => (int)($ch['atk']  ?? 16),
                'def'            => (int)($ch['def']  ?? 16),
                'agi'            => (int)($ch['agl']  ?? 16),  // agl→agi
                'luk'            => (int)($ch['luk']  ?? 16),
                'money'          => (int)($ch['gold'] ?? 0),   // gold→money
                'items'          => $items,
                'weapons'        => [],
                'armor'          => null,
                'temp_atk'       => 0,
                'temp_def'       => 0,
                'stage'          => 1,
                'day'            => 1,
                'battle'         => null,
                'gold_fever_days'=> 0,
                'reward_mult'    => 1.0,
                'boss_progress'  => 0,
                'boss_ready'     => false,
                'reward_bonus'   => 0.0,
                'civilian_dodge' => [],
                '_pvp_name'      => $ch['name'] ?? '',
            ];
        }

        $pvp_data = [
            'creator'    => mb_substr($pvp_raw['creator'] ?? '', 0, 32),
            'creator_id' => $creator_id,
            'updated_at' => date('c'),
            'slots'      => $slots,
        ];

        ensure_dir($uuid);
        file_put_contents(pvp_path($uuid), json_encode($pvp_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        resp(['ok' => true, 'slots' => array_map(
            fn($s, $id) => slot_summary($s, $id),
            $slots,
            array_keys($slots)
        )]);
    }

    default:
        resp(['ok' => false, 'error' => 'unknown action: ' . $action]);
}
