<?php
// ============================================================
//  modules/map.php — エリアマップ
// ============================================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../session.php';

function map_nodes(int $stage): array {
    $st          = STAGES[$stage];
    $p           = player_get();
    $inn_preview = rng($st['inn_cost'][0], $st['inn_cost'][1]);

    // ボス解放状態確認（旧セッション互換ガード）
    $boss_ready    = is_array($p['boss_ready'])    ? false : ($p['boss_ready']    ?? false);
    $boss_progress = is_array($p['boss_progress']) ? 0     : (int)($p['boss_progress'] ?? 0);
    $boss_desc = $boss_ready
        ? '【'.$st['boss']['name'].'】HP:'.$st['boss']['hp'].' ATK:'.$st['boss']['atk']
        : '情報収集中… '.$boss_progress.'/10';
    $boss_cost = $boss_ready ? null : '※ボス捜索が必要';

    return [
        ['id'=>'fight',       'name'=>'ストリートファイト', 'tag'=>'[FIGHT]',  'desc'=>'モブと殴り合う。金が手に入る。',           'cost'=>null,                              'danger'=>false],
        ['id'=>'inn',         'name'=>'飲食店',           'tag'=>'[EAT]',    'desc'=>'金を払って全回復。',                        'cost'=>'¥'.number_format($inn_preview).'前後', 'danger'=>false],
        ['id'=>'dojo',        'name'=>'修練所',            'tag'=>'[TRAIN]',  'desc'=>'金をステータスへ変換。何回でも可。',          'cost'=>'¥'.$st['dojo_cost'].'/回',         'danger'=>false],
        ['id'=>'weapon_shop', 'name'=>'武器屋',            'tag'=>'[WEAPON]', 'desc'=>'ランダム3品。重ね持ち可。ボス後没収。',       'cost'=>null,                              'danger'=>false],
        ['id'=>'armor_shop',  'name'=>'防具屋',            'tag'=>'[ARMOR]',  'desc'=>'防具1枠。被弾で耐久減少。ボス後没収。',       'cost'=>null,                              'danger'=>false],
        ['id'=>'item_shop',   'name'=>'道具屋',            'tag'=>'[ITEM]',   'desc'=>'回復薬・補助アイテム。ボス後没収。',          'cost'=>null,                              'danger'=>false],
        ['id'=>'boss',        'name'=>'AREA BOSS',        'tag'=>'[BOSS]',   'desc'=>$boss_desc, 'cost'=>$boss_cost,             'danger'=>$boss_ready, 'boss_ready'=>$boss_ready],
    ];
}
