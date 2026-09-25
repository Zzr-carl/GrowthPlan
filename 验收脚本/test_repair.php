<?php
/**
 * 验证 repairOrphanTable() / createTables() 的行为契约
 *
 * ==================================================================
 * ⚠️ 这是一个**破坏性测试**，只能在**测试站**上跑！
 * ==================================================================
 * 「表缺失时能重建」这条契约，必须真的把表删掉才测得出来。而
 * growth_plan_posts 里存的是「文章 ↔ 计划」的关联映射 ——
 * 删表 = 丢数据。所以本脚本：
 *
 *   1. 开跑前先把 growth_plan_posts 的**全部数据备份到内存**
 *   2. 中途 DROP / 重建，跑完各条断言
 *   3. **无论中途如何（包括抛异常），都在 finally 里把数据原样写回**
 *
 * 即便如此，也**请只在测试站跑**：生产站上万一中断在「已 DROP、未恢复」
 * 的窗口里，那张表就是空的了。想在生产环境验证，用插件后台的
 * 「检查并修复数据表」按钮 —— 它走的是同一条自愈逻辑，但不删数据。
 *
 * 要保住的契约：
 *   A. 表缺失时，createTables() 能把它建出来，规格完整（列 + 索引）
 *   B. 表健康时，createTables() 是空操作，绝不动数据
 *   C. 反复执行不报错
 *   D. 修复动作不误伤其余 5 张表
 * ==================================================================
 */
require_once '/tmp/tcsite/config.inc.php';
require_once '/tmp/tcsite/usr/plugins/GrowthPlan/Plugin.php';

use Typecho\Db;

$db = Db::get();
$prefix = $db->getPrefix();
$full = $prefix . 'growth_plan_posts';

$m = new ReflectionMethod('GrowthPlan_Plugin', 'createTables');
$m->setAccessible(true);

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $extra = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        printf("  [PASS] %-44s %s\n", $label, $extra);
    } else {
        $fail++;
        printf("  [FAIL] %-44s %s\n", $label, $extra);
    }
}

function columns(\Typecho\Db $db, string $full): array
{
    if ($full === '' ) {
        return [];
    }
    try {
        $rows = $db->query('SHOW COLUMNS FROM `' . $full . '`');
    } catch (\Throwable $e) {
        return [];                       // 表不存在
    }
    $out = [];
    while ($r = $rows->fetch(\PDO::FETCH_ASSOC)) {
        $out[] = $r['Field'];
    }
    return $out;
}
function indexes(\Typecho\Db $db, string $full): array
{
    try {
        $rows = $db->query('SHOW INDEX FROM `' . $full . '`');
    } catch (\Throwable $e) {
        return [];
    }
    $out = [];
    while ($r = $rows->fetch(\PDO::FETCH_ASSOC)) {
        $out[$r['Key_name']] = true;
    }
    return array_keys($out);
}

$expectCols = ['id', 'post_id', 'plan_id', 'created'];

/* ---- 0. 先备份真实数据 ---- */
$backup = [];
$hasTable = columns($db, $full) !== [];
if ($hasTable) {
    $rows = $db->query('SELECT post_id, plan_id, created FROM `' . $full . '`');
    while ($r = $rows->fetch(\PDO::FETCH_ASSOC)) {
        $backup[] = [(int) $r['post_id'], (int) $r['plan_id'], (int) $r['created']];
    }
}
echo "== 0. 备份现有打卡映射： " . count($backup) . " 条 ==\n";
check('备份已保存（跑完会原样写回）', true, count($backup) . ' 条');

try {
    echo "\n== A. 表缺失时能重建出完整规格 ==\n";
    $db->query('DROP TABLE IF EXISTS `' . $full . '`', Db::WRITE, '');
    $m->invoke(null);                              // 修复动作
    $cols = columns($db, $full);
    check('表已建出', $cols !== [], '列: ' . implode(',', $cols));
    check('列完全一致', $cols === $expectCols, implode(',', $cols));
    $idx = indexes($db, $full);
    check('索引齐全', in_array('PRIMARY', $idx, true) && in_array('uq_pp', $idx, true)
        && in_array('idx_pp_plan', $idx, true), implode(',', $idx));

    echo "\n== B. 表健康时是空操作，不动数据 ==\n";
    $db->query('INSERT INTO `' . $full . '` (post_id, plan_id, created) VALUES (901, 6, 111)', Db::WRITE, '');
    $db->query('INSERT INTO `' . $full . '` (post_id, plan_id, created) VALUES (902, 6, 222)', Db::WRITE, '');
    $m->invoke(null);
    $n = $db->query('SELECT COUNT(*) AS n FROM `' . $full . '`')->fetch(\PDO::FETCH_ASSOC)['n'];
    check('数据未被误删', (int) $n === 2, 'n=' . $n);
    check('列仍一致', columns($db, $full) === $expectCols);
    $m->invoke(null);
    $n2 = $db->query('SELECT COUNT(*) AS n FROM `' . $full . '`')->fetch(\PDO::FETCH_ASSOC)['n'];
    check('反复执行仍稳定', (int) $n2 === 2, 'n=' . $n2);

    echo "\n== C. 幂等且不抛异常（连跑 3 次）==\n";
    $err = '';
    for ($i = 1; $i <= 3; $i++) {
        try {
            $m->invoke(null);
        } catch (\Throwable $e) {
            $err = $e->getMessage();
            break;
        }
    }
    check('连跑 3 次无异常', $err === '', $err);

    echo "\n== D. 其余 5 张表也都健在（修复不能误伤）==\n";
    foreach (['growth_categories', 'growth_plans', 'growth_logs', 'growth_log_images',
              'growth_rounds'] as $t) {
        $c = columns($db, $prefix . $t);
        check($t, count($c) > 2, count($c) . ' 列');
    }
} finally {
    /* ---- 收尾：把测试写入的行清掉，再把真实数据原样恢复 ---- */
    try {
        $db->query('DELETE FROM `' . $full . '` WHERE post_id IN (901, 902)', Db::WRITE, '');
        if ($backup === []) {
            echo "\n== 收尾：原本就没有数据，删掉测试行即可 ==\n";
        } else {
            $db->query('DELETE FROM `' . $full . '`', Db::WRITE, '');
            foreach ($backup as $row) {
                $db->query(
                    'INSERT INTO `' . $full . '` (post_id, plan_id, created) VALUES ('
                    . $row[0] . ',' . $row[1] . ',' . $row[2] . ')',
                    Db::WRITE,
                    ''
                );
            }
            $n = $db->query('SELECT COUNT(*) AS n FROM `' . $full . '`')
                    ->fetch(\PDO::FETCH_ASSOC)['n'];
            echo "\n== 收尾：真实打卡映射已还原 " . $n . ' / ' . count($backup) . " 条 ==\n";
            check('数据完整还原', (int) $n === count($backup),
                'n=' . $n . ' expect=' . count($backup));
        }
    } catch (\Throwable $e) {
        echo "  [FATAL] 收尾恢复失败，请手工检查 " . $full . '：' . $e->getMessage() . "\n";
    }
}

printf("\n===== %d PASS / %d FAIL =====\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
