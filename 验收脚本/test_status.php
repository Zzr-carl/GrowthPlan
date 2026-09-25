<?php
/**
 * 状态语义回归：已过期只应出现在「未完成」的计划上
 * 五种情形 + done 优先级最高
 */
require_once '/tmp/tcsite/config.inc.php';
require_once '/tmp/tcsite/usr/plugins/GrowthPlan/Plugin.php';

$now = mktime(0, 0, 0, 9, 25, 2026); // 今天 2026-09-25

$cases = [
    ['未完成 + 已超期(auto)',      ['start_date' => '2026-01-01', 'end_date' => '2026-06-30', 'status' => 'auto'],  '已过期'],
    ['未完成 + 超期但标了已完成',   ['start_date' => '2026-01-01', 'end_date' => '2026-06-30', 'status' => 'done'],  '已完成'],
    ['未完成 + 进行中',            ['start_date' => '2026-07-01', 'end_date' => '2026-12-31', 'status' => 'auto'],  '进行中'],
    ['未完成 + 未开始',            ['start_date' => '2026-10-01', 'end_date' => '2026-12-31', 'status' => 'auto'],  '未开始'],
    ['已完成 + 进行中(手动 done)', ['start_date' => '2026-07-01', 'end_date' => '2026-12-31', 'status' => 'done'],  '已完成'],
    ['即将到期(30天内)',           ['start_date' => '2026-07-01', 'end_date' => '2026-10-10', 'status' => 'auto'],  '即将到期'],
];

$pass = 0;
$fail = 0;
foreach ($cases as [$name, $plan, $want]) {
    $got = GrowthPlan_Plugin::statusLabel(GrowthPlan_Plugin::status($plan, $now));
    $ok = ($got === $want);
    $ok ? $pass++ : $fail++;
    printf("%s  %-26s → %s (期望 %s)\n", $ok ? 'PASS' : 'FAIL', $name, $got, $want);
}
printf("\n%d PASS / %d FAIL\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
