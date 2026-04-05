<?php
// ============================================================
//  modules/chargen.php — キャラクター生成
// ============================================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../session.php';

function chargen_roll(): array {
    return [
        'hp'    => rng(100, 250),
        'mp'    => rng(10, 120),
        'atk'   => rng(16, 32),
        'def'   => rng(16, 32),
        'agi'   => rng(16, 32),
        'luk'   => rng(16, 32),
        'money' => rng(100, 2500),
    ];
}

function chargen_confirm(array $stats): array {
    $p = player_new($stats);
    player_set($p);
    return $p;
}

function chargen_comment(array $stats): string {
    $score  = $stats['hp'] + $stats['mp'] * 2 + $stats['atk'] * 3
            + $stats['def'] * 3 + $stats['agi'] * 2 + $stats['luk']
            + (int)($stats['money'] / 20);
    $rating = player_rating($score);
    return [
        'S' => '最強の素質を持つ男が生まれた。',
        'A' => 'かなりの器量。路地裏でも頭一つ抜ける。',
        'B' => '平均以上。鍛えれば化けるかもしれない。',
        'C' => '普通の男。腕でカバーするしかない。',
        'D' => '弱い。だが、生きることを諦めていない。',
    ][$rating];
}
