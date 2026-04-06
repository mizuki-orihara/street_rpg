<?php
// ============================================================
//  session.php — セッション管理・プレイヤー状態
// ============================================================

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function player_get(): ?array {
    return $_SESSION[SESSION_KEY] ?? null;
}

function player_set(array $p): void {
    $_SESSION[SESSION_KEY] = $p;
}

function player_clear(): void {
    unset($_SESSION[SESSION_KEY]);
}

function player_new(array $stats): array {
    return [
        'hp'       => $stats['hp'],
        'max_hp'   => $stats['hp'],
        'mp'       => $stats['mp'],
        'max_mp'   => $stats['mp'],
        'atk'      => $stats['atk'],
        'def'      => $stats['def'],
        'agi'      => $stats['agi'],
        'luk'      => $stats['luk'],
        'money'    => $stats['money'],
        'stage'          => 1,
        'weapons'        => [],
        'items'          => [],
        'armor'          => null,   // 装備中の防具 {id, name, def_bonus}
        'temp_atk'       => 0,
        'temp_def'       => 0,
        'battle'          => null,
        'day'             => 1,
        'gold_fever_days' => 0,
        'reward_mult'     => 1.0,
        'boss_progress'   => 0,      // ボス解放ポイント（10以上で解放、ボス討伐でリセット）
        'boss_ready'      => false,  // ボス挑戦可能フラグ
        'reward_bonus'   => 0.0,  // 戦闘中の報酬倍率累計（戦闘開始時リセット）
        'civilian_dodge'  => [],     // ランク0MOB種別ごとの回避カウント ['jk'=>0, 'cvg'=>0, ...]
        'ex_stage'        => 0,      // 現在のEX周回数（0=EX未突入）
        'ex_depth_max'    => 0,      // 最大到達EX数（スコア用）
    ];
}

function player_score(array $p): int {
    return $p['hp'] + $p['mp'] * 2 + $p['atk'] * 3
         + $p['def'] * 3 + $p['agi'] * 2 + $p['luk']
         + (int)($p['money'] / 20);
}

function player_rating(int $score): string {
    if ($score >= 600) return 'S';
    if ($score >= 450) return 'A';
    if ($score >= 320) return 'B';
    if ($score >= 200) return 'C';
    return 'D';
}

function hp_pct(array $p): int {
    return $p['max_hp'] > 0 ? (int)($p['hp'] / $p['max_hp'] * 100) : 0;
}

function eff_atk(array $p): int { return $p['atk'] + $p['temp_atk']; }
function eff_def(array $p): int {
    $armor_bonus = $p['armor']['def_bonus'] ?? 0;
    return $p['def'] + $p['temp_def'] + $armor_bonus;
}

/**
 * 1日経過処理。護符の残り日数を減らし、切れたらitemsから削除してセーブ。
 * 戦闘・修練・ショップ購入時に呼ぶ。
 */
function advance_day(array $p): array {
    $p['day'] = ($p['day'] ?? 1) + 1;
    if (!empty($p['gold_fever_days'])) {
        $p['gold_fever_days'] = max(0, $p['gold_fever_days'] - 1);
        // 期限切れになったらitemsから護符を全て除去
        if ($p['gold_fever_days'] === 0) {
            $p['items'] = array_values(array_filter(
                $p['items'],
                fn($it) => $it['effect'] !== 'gold_fever'
            ));
        }
    }
    return $p;
}
