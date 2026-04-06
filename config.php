<?php
// ============================================================
//  config.php — マスターデータ・定数
//  武器/アイテムは data/*.csv から読み込む
// ============================================================

define('GAME_TITLE', 'STREET DOGS');
define('SESSION_KEY', 'sd_player');
define('DATA_DIR',  __DIR__ . '/data');
defined('SAVE_DIR') || define('SAVE_DIR',  __DIR__ . '/data/saves');

// ステージ定義（変更頻度低いのでPHP直書き）
define('STAGES', [
    1 => [
        'name'      => '路地裏',
        'sub'       => 'Back Alley',
        'mob_hp'    => [20, 60],
        'mob_atk'   => [8, 18],
        'mob_def'   => [2, 8],
        'mob_agi'   => [8, 18],
        'inn_cost'  => [30, 80],
        'dojo_cost' => 50,
        'boss' => [
            'name' => '裏番長 CROW',
            'hp'   => 200,
            'atk'  => 28,
            'def'  => 10,
            'agi'  => 22,
        ],
    ],
    2 => [
        'name'      => '繁華街',
        'sub'       => 'Downtown',
        'mob_hp'    => [50, 120],
        'mob_atk'   => [18, 30],
        'mob_def'   => [6, 14],
        'mob_agi'   => [14, 26],
        'inn_cost'  => [80, 180],
        'dojo_cost' => 100,
        'boss' => [
            'name' => '地区ボス SNAKE',
            'hp'   => 350,
            'atk'  => 42,
            'def'  => 18,
            'agi'  => 34,
        ],
    ],
    3 => [
        'name'      => 'ドック',
        'sub'       => 'The Docks',
        'mob_hp'    => [100, 200],
        'mob_atk'   => [28, 45],
        'mob_def'   => [12, 22],
        'mob_agi'   => [20, 34],
        'inn_cost'  => [150, 300],
        'dojo_cost' => 150,
        'boss' => [
            'name' => '港湾王 KRAKEN',
            'hp'   => 550,
            'atk'  => 60,
            'def'  => 28,
            'agi'  => 48,
        ],
    ],
]);

// ステージごとの出現ランクテーブル
// ST1: rank1・2、ST2: rank1〜3、ST3: rank2〜4
define('STAGE_MOB_RANKS', [
    1 => [1, 2],
    2 => [1, 2, 3],
    3 => [2, 3, 4],
]);

// ============================================================
//  CSV 読み込みキャッシュ（リクエスト内メモリキャッシュ）
// ============================================================
$_MASTER_CACHE = [];

/**
 * CSVを連想配列の配列として返す（ヘッダ行をキーに使用）
 */
function csv_load(string $filename): array {
    global $_MASTER_CACHE;
    if (isset($_MASTER_CACHE[$filename])) {
        return $_MASTER_CACHE[$filename];
    }

    $path = DATA_DIR . '/' . $filename;
    if (!file_exists($path)) {
        trigger_error("CSV not found: {$path}", E_USER_WARNING);
        return [];
    }

    $rows = [];
    $fh   = fopen($path, 'r');
    $headers = fgetcsv($fh);  // 1行目はヘッダ

    while (($row = fgetcsv($fh)) !== false) {
        // 空行スキップ
        if (count($row) < 2 || trim($row[0]) === '') continue;
        $rows[] = array_combine($headers, $row);
    }
    fclose($fh);

    $_MASTER_CACHE[$filename] = $rows;
    return $rows;
}

/**
 * 武器マスター全件
 */
function weapons_all(): array {
    $rows = csv_load('weapons.csv');
    return array_map(function($r) {
        return [
            'id'        => $r['id'],
            'name'      => $r['name'],
            'type'      => $r['type'],
            'dmg'       => [(int)$r['dmg_min'], (int)$r['dmg_max']],
            'throw_dmg' => ($r['throw_dmg_min'] !== '') ? [(int)$r['throw_dmg_min'], (int)$r['throw_dmg_max']] : null,
            'price'     => [(int)$r['price_min'], (int)$r['price_max']],
            'desc'      => $r['desc'],
        ];
    }, $rows);
}

/**
 * アイテムマスター全件
 */
function items_all(): array {
    $rows = csv_load('items.csv');
    return array_map(function($r) {
        return [
            'id'     => $r['id'],
            'name'   => $r['name'],
            'effect' => $r['effect'],
            'value'  => (int)$r['value'],
            'price'  => [(int)$r['price_min'], (int)$r['price_max']],
            'desc'   => $r['desc'],
            'shop'   => $r['shop'] ?? 'item',  // food=飲食店 / item=道具屋
        ];
    }, $rows);
}

/**
 * ID指定で武器1件取得
 */
function get_weapon(string $id): ?array {
    foreach (weapons_all() as $w) {
        if ($w['id'] === $id) return $w;
    }
    return null;
}

/**
 * ID指定でアイテム1件取得
 */
function get_item(string $id): ?array {
    foreach (items_all() as $it) {
        if ($it['id'] === $id) return $it;
    }
    return null;
}

/**
 * 防具マスター全件
 */
function armors_all(): array {
    $rows = csv_load('armors.csv');
    return array_map(function($r) {
        return [
            'id'         => $r['id'],
            'name'       => $r['name'],
            'def_bonus'  => (int)$r['def_bonus'],
            'durability' => isset($r['durability']) && $r['durability'] !== ''
                            ? (int)$r['durability']
                            : (int)$r['def_bonus'],  // 旧CSV互換: def_bonusと同値
            'price'      => [(int)$r['price_min'], (int)$r['price_max']],
            'desc'       => $r['desc'],
        ];
    }, $rows);
}

/**
 * MOBマスター全件
 */
function mobs_all(): array {
    $rows = csv_load('mobs.csv');
    return array_map(function($r) {
        $t1 = $r['tendency_1'] ?? '';
        $t2 = $r['tendency_2'] ?? '';
        return [
            'id'              => $r['id'],
            'name'            => $r['name'],
            'rank'            => (int)$r['rank'],
            'hp_mul'          => (float)$r['hp_mul'],
            'atk_mul'         => (float)$r['atk_mul'],
            'def_mul'         => (float)$r['def_mul'],
            'agi_mul'         => (float)$r['agi_mul'],
            'note'            => $r['note'],
            'img'             => $r['img'] ?? '',
            'boss_pts'        => (int)($r['boss_pts'] ?? 0),
            // 行動傾向
            'tendency_1'      => $t1,
            'tendency_2'      => $t2,
            'action_weights'  => mob_action_weights($t1, $t2),
            // 報酬・アイテム関連
            'gold_base'       => $r['gold_base'] !== '' ? (int)$r['gold_base'] : null,
            'mob_item_chance' => (int)($r['mob_item_chance'] ?? 0),
            'mob_use_chance'  => (int)($r['mob_use_chance']  ?? 0),
        ];
    }, $rows);
}

// ============================================================
//  tendency → 行動重みテーブル生成
//
//  行動キー: attack / guard / flee / skill / buff / debuff
//
//  ベース値（傾向なし）:
//    attack:40 guard:20 flee:10 skill:15 buff:10 debuff:5
//
//  tendency_1（主軸）の対象キーに +40 加算
//  tendency_2（副軸）の対象キーに +20 加算
//  → 合計が行動抽選の分母になる
// ============================================================
function mob_action_weights(string $t1, string $t2): array {
    $base = [
        'attack' => 40,
        'guard'  => 20,
        'flee'   => 10,
        'skill'  => 15,
        'buff'   => 10,
        'debuff' =>  5,
    ];
    $valid = array_keys($base);

    // 主軸 +40
    if (in_array($t1, $valid)) $base[$t1] += 40;
    // 副軸 +20
    if (in_array($t2, $valid)) $base[$t2] += 20;

    return $base;
}

/**
 * 重みテーブルから1つの行動をランダム抽選する
 * fight.php の敵行動決定で呼ぶ
 *
 * @param  array  $weights  ['attack'=>N, 'guard'=>M, ...]
 * @return string           選ばれた行動キー
 */
function mob_action_roll(array $weights): string {
    $total = array_sum($weights);
    $roll  = rng(1, $total);
    $cum   = 0;
    foreach ($weights as $action => $w) {
        $cum += $w;
        if ($roll <= $cum) return $action;
    }
    return 'attack';  // fallback
}

/**
 * ステージに応じたMOBを1体抽選して生成して返す
 * ステータスはステージ基準値 × 各MOBの乗数で確定
 */
function mob_encounter(int $stage): array {
    $st    = STAGES[$stage];
    $ranks = STAGE_MOB_RANKS[$stage];

    // JK出現判定: 15%の確率でJKをエンカウント
    $jk_pool = array_values(array_filter(mobs_all(), fn($m) => $m['rank'] === 0));
    if (!empty($jk_pool) && rng(1, 100) <= 15) {
        $template = $jk_pool[0];
        $hp = max(1, rng(8, 15));
        return [
            'name'     => $template['name'],
            'rank'     => 0,
            'img'      => $template['img'],
            'hp'       => $hp,
            'max_hp'   => $hp,
            'atk'      => 0,
            'def'      => rng(2, 5),
            'agi'      => rng(12, 20),
            'is_boss'  => false,
            'is_jk'    => true,
            'boss_pts' => 0,
        ];
    }

    // 通常MOB抽選
    $pool     = array_values(array_filter(mobs_all(), fn($m) => in_array($m['rank'], $ranks)));
    $template = $pool[rng(0, count($pool) - 1)];

    // ステージ基準値の中央値 × 乗数 → min/maxのレンジを作る
    $base_hp  = (int)(($st['mob_hp'][0]  + $st['mob_hp'][1])  / 2 * $template['hp_mul']);
    $base_atk = (int)(($st['mob_atk'][0] + $st['mob_atk'][1]) / 2 * $template['atk_mul']);
    $base_def = (int)(($st['mob_def'][0] + $st['mob_def'][1]) / 2 * $template['def_mul']);
    $base_agi = (int)(($st['mob_agi'][0] + $st['mob_agi'][1]) / 2 * $template['agi_mul']);

    // ±15%のブレを加えて最終値を確定
    $spread = function(int $base): int {
        return max(1, rng((int)($base * 0.85), (int)($base * 1.15)));
    };

    $hp = $spread($base_hp);
    $rank = $template['rank'];
    return [
        'id'              => $template['id'],
        'name'            => $template['name'],
        'rank'            => $rank,
        'img'             => $template['img'],
        'hp'              => $hp,
        'max_hp'          => $hp,
        'atk'             => $spread($base_atk),
        'def'             => $spread($base_def),
        'agi'             => $spread($base_agi),
        'is_boss'         => false,
        'is_jk'           => false,
        'boss_pts'        => $template['boss_pts'],
        // 行動制御
        'action_weights'  => $template['action_weights'],
        'mob_item_chance' => $template['mob_item_chance'],
        'mob_use_chance'  => $template['mob_use_chance'],
        'mob_items'       => [],   // 戦闘開始時の抽選で設定（fight.php側）
        // 報酬
        'gold_base'       => $template['gold_base'],
    ];
}

// ============================================================
//  共通ユーティリティ
// ============================================================
function rng(int $min, int $max): int {
    return random_int($min, $max);
}
