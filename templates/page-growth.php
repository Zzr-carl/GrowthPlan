<?php
/**
 * 成长计划模板（主题模板）
 *
 * 使用方式：
 *  1. 把本文件复制到当前主题目录 usr/themes/<你的主题>/page-growth.php
 *  2. 后台「管理 → 独立页面 → 新增」，标题「成长计划」，缩略名 growth，
 *     自定义模板选「成长计划」
 *     （正常情况下 GrowthPlan 插件会自动创建这个页面，来这里确认一下即可）
 *
 * 视图：时间线。
 *  - 按「计划类型」分组，每个类型一条纵向时间轴；
 *  - 每条计划是轴上一个节点：圆点 + 年份徽标 + 卡片；
 *  - 卡片里有进度条（按起止日期算 已过天数 / 总天数）、
 *    剩余天数倒计时、起止日期与描述；
 *  - 状态自动区分：未开始 / 进行中 / 即将到期 / 已过期 / 已完成，
 *    用节点颜色与徽标颜色区分；
 *  - 时间轴上「进行中」的节点会有一圈呼吸光环。
 *
 * 性能要点：无论有多少类型、多少计划，本模板总共只查 2 次库
 * （类型 1 次 + 计划 1 次），在 PHP 里按 cid 分组，
 * 不做「每个类型查一次计划」的 N+1 查询。
 *
 * 无依赖：不引外部字体 / 图标库 / JS 库，纯 CSS 渲染。
 *
 * [Sunny 适配 2026-09-22]
 *  - 放置位置：usr/themes/Sunny/template/page-growth.php（由页面预设 cat_page-growth 渲染）
 *  - 已移除 $this->need('header.php') / footer：模板由主题内嵌渲染，不重复输出页头页脚
 *  - 夜间模式：原 prefers-color-scheme 改为 html.darkmode（跟随站点亮暗开关，不跟系统）
 *  - 视觉：Lo-Fi zine 手帐风（2026-09-22 晚：纸面/胶带/票根/Riso 撞色；只动样式层）
 *
 * @package GrowthPlan
 */

use TypechoPlugin\GrowthPlan\Plugin;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/** 插件是否已安装并启用（未启用时类不存在，isFrontEnabled 会安全返回 false） */
$gpAvailable = class_exists(Plugin::class) && Plugin::isFrontEnabled();

$gpNow = time();

/** 分组后的数据：[ ['id','name','description','color','plans'=>[...]], ... ] */
$gpGroups = [];
$gpTotal = 0;
$gpParentCount = 0;
$gpChildCount = 0;
$gpYears = [];
$gpPlans = [];
/** 归档计划（页面底部归档区块 + 它自己的弹窗用），结构同 $gpPlans */
$gpArchivedPlans = [];

/**
 * 日志与配图
 *
 * 结构：$gpLogs[planId] = [ ['id','log_date','content','images'=>[...]], ... ]
 */
$gpLogs = [];

/** 历史轮次与累计天数：[planId => [round,...]] / [planId => ['total'=>,'gone'=>]] */
$gpRounds = [];
$gpCarry = [];

/** 文章打卡：[planId => [postRow,...]]，postRow 含 permalink / excerpt */
$gpPosts = [];

/** 每个计划挂了几篇文章（卡片角标用） */
$gpPostCounts = [];

/** 归档区里有多少条（底部归档区块的数字） */
$gpArchivedCount = 0;

/**
 * 年份筛选：?gp_year=2026，仅接受 4 位数字，其它一律当「全部」
 *
 * 注意：这里刻意不用 `year` 这个参数名。Typecho 的 Widget\Archive::singleHandle()
 * 会把 `year`（以及 month / day / cid / slug）当成内置的「按时间归档」条件，
 * 于是 `?year=2025` 会被拼进 contents.created 的区间查询，导致本来存在的页面
 * 直接查不到而 404。加前缀 gp_ 可彻底避开这套保留字。
 */
$gpYearParam = '';
if (!empty($_GET['gp_year']) && preg_match('/^\d{4}$/', (string) $_GET['gp_year'])) {
    $gpYearParam = (string) $_GET['gp_year'];
}
$gpYearFilterOn = Plugin::setting('year_filter', '1') === '1';

/**
 * 归档：归档的计划从主区摘出去，收进页面底部的「归档」区块（常驻）。
 * 数据完整保留、随时可以恢复；整条子树跟着父计划一起归档（管理端保证）。
 * 不再用 ?gp_archived= 切换视图 —— 归档区改为
 * 页面底部常驻区块；「已完成」不参与归档（完成项继续留在时间线里）。
 */

/** 每个状态的计数，用于页头概览 */
$gpCounts = [
    'pending'  => 0,
    'active'   => 0,
    'soon'     => 0,
    'expired'  => 0,
    'done'     => 0,
    'archived' => 0,
];

if ($gpAvailable) {
    try {
        $gpDb = \Typecho\Db::get();

        $gpCats = $gpDb->fetchAll(
            $gpDb->select()->from('table.growth_categories')
                ->order('sort', \Typecho\Db::SORT_ASC)
                ->order('id', \Typecho\Db::SORT_ASC)
        );

        // 一次拿全部计划：按年份倒序（近的年份在前），同年按截止日期升序
        // （离到期越近越靠前），再按 sort、id 兜底，随后在内存里按 cid 分组。
        $gpPlans = $gpDb->fetchAll(
            $gpDb->select()->from('table.growth_plans')
                ->order('year', \Typecho\Db::SORT_DESC)
                ->order('end_date', \Typecho\Db::SORT_ASC)
                ->order('sort', \Typecho\Db::SORT_ASC)
                ->order('id', \Typecho\Db::SORT_ASC)
        );

        // 归档拆分：主区与底部归档区在这里一次分家。
        // 过滤必须放在最前面：年份列表、总数、状态计数三者都从主区
        // $gpPlans 派生，先在这里筛掉，三处口径自然一致。
        // 若只筛分桶，年份 chip 会冒出「只含归档数据」的年份，点进去却是空的。
        // 归档从「切换视图」改为「页面底部常驻区块」：
        // 归档的整条子树从主区分出去、单独渲染在最下面；主区只留未归档的，
        // 「已完成」不参与（完成项继续留在主线 / 时间线里）。
        $gpArchivedPlans = array_values(array_filter($gpPlans, static function ($plan) {
            return (int) ($plan['archived'] ?? 0) === 1;
        }));
        $gpPlans = array_values(array_filter($gpPlans, static function ($plan) {
            return (int) ($plan['archived'] ?? 0) !== 1;
        }));

        // 年份筛选：年份列表与筛选都在内存里完成
        foreach ($gpPlans as $gpPlan) {
            $year = (int) $gpPlan['year'];
            if ($year > 0 && !in_array($year, $gpYears, true)) {
                $gpYears[] = $year;
            }
        }

        rsort($gpYears);

        // 底部归档区块的条数：全量已在内存里，直接数，不再单独查。
        // 原来「归档 ∪ 已完成」的两次 COUNT 查询撤掉。
        $gpArchivedCount = count($gpArchivedPlans);

        // 按 cid 归拢；未知 cid（类型已被删但数据尚未归零）一律并入未分类
        $gpKnown = [];
        foreach ($gpCats as $gpCat) {
            $gpKnown[(int) $gpCat['id']] = true;
        }

        $gpBuckets = [];
        foreach ($gpPlans as $gpPlan) {
            $year = (int) $gpPlan['year'];
            // 年份筛选：非目标年份的直接跳过，不入桶
            if ($gpYearFilterOn && $gpYearParam !== '' && (string) $year !== $gpYearParam) {
                continue;
            }

            $cid = (int) $gpPlan['cid'];
            if ($cid !== 0 && !isset($gpKnown[$cid])) {
                $cid = 0;
            }
            $gpBuckets[$cid][] = $gpPlan;
        }

        $gpTotal = 0;
        foreach ($gpBuckets as $gpBucket) {
            $gpTotal += count($gpBucket);
        }

        // 长计划 / 子计划拆分计数（跟随筛选后可见的计划，与上方统计口径一致）
        $gpParentCount = 0;
        $gpChildCount = 0;
        foreach ($gpBuckets as $gpBucket) {
            foreach ($gpBucket as $gpPlanSplit) {
                if ((int) $gpPlanSplit['parent_id'] > 0) {
                    $gpChildCount++;
                } else {
                    $gpParentCount++;
                }
            }
        }

        // 页头统计只跟「筛选后可见的计划」一致，避免数字对不上
        foreach ($gpBuckets as $gpBucket) {
            foreach ($gpBucket as $gpPlan) {
                $st = Plugin::status($gpPlan, $gpNow);
                if (isset($gpCounts[$st])) {
                    $gpCounts[$st]++;
                }
            }
        }

        foreach ($gpCats as $gpCat) {
            $cid = (int) $gpCat['id'];
            if (empty($gpBuckets[$cid])) {
                // 该类型下没有计划时不输出空分组标题
                continue;
            }
            $gpGroups[] = [
                'id'          => $cid,
                'name'        => (string) $gpCat['name'],
                'description' => (string) ($gpCat['description'] ?? ''),
                'color'       => Plugin::normalizeColor($gpCat['color'] ?? ''),
                'plans'       => $gpBuckets[$cid],
            ];
        }

        // 未分类计划单独一组，固定排在最后
        if (!empty($gpBuckets[0])) {
            $gpGroups[] = [
                'id'          => 0,
                'name'        => '未分类',
                'description' => '',
                'color'       => '',
                'plans'       => $gpBuckets[0],
            ];
        }

        // 日志与配图：全站只再查 2 次，在内存里挂到对应的计划上。
        // 没有计划时这两次查询直接跳过。
        // 查询范围 = 主区 ∪ 归档区：
        // 底部归档区的卡片和弹窗也要渲染日志/轮次/打卡，不能只查主区 id。
        if (!empty($gpPlans) || !empty($gpArchivedPlans)) {
            $gpPlanIds = [];
            foreach ([$gpPlans, $gpArchivedPlans] as $gpPlanSet) {
                foreach ($gpPlanSet as $gpPlan) {
                    $gpPlanIds[] = (int) $gpPlan['id'];
                }
            }

            // 历史轮次 + 累计天数，同样一次查完在内存里分组
            $gpRounds = Plugin::roundsForPlans($gpPlanIds);
            $gpCarry = Plugin::carryForPlans($gpPlanIds);

            $gpLogRows = $gpDb->fetchAll(
                $gpDb->select()->from('table.growth_logs')
                    ->order('log_date', \Typecho\Db::SORT_DESC)
                    ->order('sort', \Typecho\Db::SORT_ASC)
                    ->order('id', \Typecho\Db::SORT_ASC)
            );

            $gpLogIds = [];
            foreach ($gpLogRows as $gpLogRow) {
                $gpLogIds[] = (int) $gpLogRow['id'];
            }

            $gpImageMap = [];
            if (!empty($gpLogIds)) {
                $gpImageRows = $gpDb->fetchAll(
                    $gpDb->select()->from('table.growth_log_images')
                        ->order('sort', \Typecho\Db::SORT_ASC)
                        ->order('id', \Typecho\Db::SORT_ASC)
                );
                foreach ($gpImageRows as $gpImageRow) {
                    $gpImageMap[(int) $gpImageRow['log_id']][] = $gpImageRow;
                }
            }

            foreach ($gpLogRows as $gpLogRow) {
                $gpLogRow['images'] = $gpImageMap[(int) $gpLogRow['id']] ?? [];
                $gpLogs[(int) $gpLogRow['plan_id']][] = $gpLogRow;
            }

            // 文章打卡：把「已发布、且关联了本页计划」的文章一次性查出来，
            // 按 plan_id 分组挂上。节点数据（标题/日期/状态）实时来自 contents，
            // 这里只拿映射 + 由插件算好的 permalink / excerpt。
            $gpPosts = Plugin::postsForPlans($gpPlanIds);

            // 每个计划挂了几篇文章（卡片角标用）
            $gpPostCounts = [];
            foreach ($gpPosts as $gpPid => $gpPostList) {
                $gpPostCounts[$gpPid] = count($gpPostList);
            }
        }
    } catch (\Throwable $e) {
        $gpAvailable = false;
        $gpGroups = [];
        $gpTotal = 0;
        $gpArchivedPlans = [];
    }
}

$gpShowStatus = Plugin::setting('show_status', '1') === '1';
$gpOfflineTip = (string) Plugin::setting('offline_tip', '成长计划功能未启用，请稍后再来。');
$gpEmptyTip = (string) Plugin::setting('empty_tip', '还没有添加任何成长计划。');

/**
 * 时间线首尾「开始 / 结束」节点的颜色，后台可配。
 *
 * ⚠️ 这两色要拿去做 CSS 自定义属性，必须过一遍 normalizeColor()。
 *    后台存的是用户手填的字串，normalizeColor() 只放行 #rgb / #rrggbb，
 *    其它一律回落默认色 —— 否则 `red; background:url(...)` 这类输入
 *    会直接把 CSS 变量污染掉（注入 + 样式崩坏双重风险）。
 */
$gpNodeStartColor = Plugin::normalizeColor(
    Plugin::setting('node_start_color', ''),
    '#2f7d5a'
);
$gpNodeEndColor = Plugin::normalizeColor(
    Plugin::setting('node_end_color', ''),
    '#3b6ea5'
);

/** 当前页面地址（保留其它 query，供年份筛选链接使用） */
$gpBaseUrl = (string) $this->permalink;

/**
 * 拼一个保留当前筛选状态的链接
 *
 * 年份筛选的当前值作为默认值带进去。
 * 归档切换视图已移除，闭包只剩年份参数。
 *
 * @param array $params ['gp_year'=>string|null]
 * @return string
 */
$gpLink = static function (array $params) use ($gpBaseUrl, $gpYearParam): string {
    $year = array_key_exists('gp_year', $params) ? (string) $params['gp_year'] : $gpYearParam;

    $query = [];
    if ($year !== '') {
        $query['gp_year'] = $year;
    }

    if (empty($query)) {
        return $gpBaseUrl;
    }

    return $gpBaseUrl . (strpos($gpBaseUrl, '?') === false ? '?' : '&') . http_build_query($query);
};

/**
 * 兼容旧调用：年份筛选链接
 *
 * @param string $year 空字符串代表「全部」
 * @return string
 */
$gpYearLink = static function (string $year) use ($gpLink): string {
    return $gpLink(['gp_year' => $year]);
};

/**
 * 把一条计划下的三类「条目」混排成一条按日期正序的时间线
 *
 * 三类条目：
 *   child —— 子计划，日期取 start_date
 *   log   —— 日志，日期取 log_date
 *   round —— 历史轮次（续期封存的旧档期），日期取 start_date
 *
 * ⚠️ 为什么必须重排而不是复用现有顺序：
 *   日志来自 `ORDER BY log_date DESC`（降序），轮次来自 `round_no ASC`，
 *   子计划来自 `ORDER BY year DESC, end_date ASC`（压根没按 start_date 排）。
 *   三者的天然顺序各不相同，混到一起必然是乱的，只能在这里统一排。
 *
 * 排序规则（按优先级）：
 *   1. 没有有效日期的排最后（用 parseDate() 判 null，空串和 '0000-00-00' 都算无日期）
 *   2. 日期升序，旧 → 新
 *   3. 同一天按 kind 排：轮次 → 子计划 → 日志
 *      理由：轮次是「这一期从哪天开始」的框架事件，先立框架再看细节；
 *            日志是「某天的随手记录」，沉在当天最下面最自然。
 *   4. 同一天同一类，保持输入顺序（$seq 是显式序号，不依赖 PHP 的排序稳定性）
 *
 * @param array $childPlans [plan,...]
 * @param array $planLogs   [log,...]
 * @param array $planRounds [round,...]
 * @return array [ ['kind'=>'child'|'log'|'round', 'date'=>string, 'ts'=>?int, 'seq'=>int, 'data'=>array], ... ]
 */
if (!function_exists('gp_timeline_items')) {
    function gp_timeline_items(array $childPlans, array $planLogs, array $planRounds, array $planPosts = []): array
    {
        $items = [];
        $seq = 0;

        foreach ($planRounds as $round) {
            $date = (string) ($round['start_date'] ?? '');
            $items[] = [
                'kind' => 'round',
                'date' => $date,
                'ts'   => Plugin::parseDate($date !== '' ? $date : null),
                'seq'  => $seq++,
                'data' => $round,
            ];
        }

        foreach ($childPlans as $child) {
            $date = (string) ($child['start_date'] ?? '');
            $items[] = [
                'kind' => 'child',
                'date' => $date,
                'ts'   => Plugin::parseDate($date !== '' ? $date : null),
                'seq'  => $seq++,
                'data' => $child,
            ];
        }

        foreach ($planLogs as $log) {
            $date = (string) ($log['log_date'] ?? '');
            $items[] = [
                'kind' => 'log',
                'date' => $date,
                'ts'   => Plugin::parseDate($date !== '' ? $date : null),
                'seq'  => $seq++,
                'data' => $log,
            ];
        }

        // 文章打卡：日期取文章发布时间（created）。没有发布时间的不该出现
        // （postsForPlans 只返回已发布文章），这里再兜底一次。
        foreach ($planPosts as $post) {
            $created = (int) ($post['created'] ?? 0);
            $date = $created > 0 ? date('Y-m-d', $created) : '';
            $items[] = [
                'kind' => 'post',
                'date' => $date,
                'ts'   => $created > 0 ? $created : null,
                'seq'  => $seq++,
                'data' => $post,
            ];
        }

        // 同类内谁先出现谁在前，所以拿输入序做最后的兜底键
        // post 排在 log 之后：打卡和日志都是「某天的记录」，日志沉底稍显自然，
        // 但同天打卡紧贴日志也合理 —— 这里定为最后一类。
        $kindRank = ['round' => 0, 'child' => 1, 'log' => 2, 'post' => 3];

        usort($items, static function (array $a, array $b) use ($kindRank): int {
            $an = null === $a['ts'];
            $bn = null === $b['ts'];

            // 1) 无日期沉底
            if ($an !== $bn) {
                return $an ? 1 : -1;
            }
            // 两个都没日期：直接按输入序，不必再比 kind
            if ($an && $bn) {
                return $a['seq'] <=> $b['seq'];
            }
            // 2) 时间戳升序
            if ($a['ts'] !== $b['ts']) {
                return $a['ts'] <=> $b['ts'];
            }
            // 3) 同日：kind 优先级
            $ra = $kindRank[$a['kind']] ?? 9;
            $rb = $kindRank[$b['kind']] ?? 9;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            // 4) 同日同类：输入序
            return $a['seq'] <=> $b['seq'];
        });

        return $items;
    }
}

/**
 * 生成一个全局唯一的模态 id
 *
 * ⚠️ 为什么不能直接用 plan_id 拼字符串：
 *   1. 主时间线和归档区是同一个页面按 ?gp_archived= 切换的，但同时渲染时
 *      同一个 id 会撞；
 *   2. 卡片网格里「计划卡片」和它的「时间线模态」用的是两套 id 命名空间。
 * 所以统一走计数器，保证一页之内绝对不重。
 *
 * @return string 形如 gp-m-7
 */
if (!function_exists('gp_uid')) {
    function gp_uid(): string
    {
        static $n = 0;
        return 'gp-m' . (++$n);
    }
}

/**
 * 时间线日期列用的紧凑日期：2026.01.15
 *
 * ⚠️ 为什么不用 cnDate() 的「2026年1月15日」：
 *   那个格式在卡片范围、弹窗头部里读起来很顺，但排进**左对齐的日期列**
 *   就太宽了 —— 「2026年12月28日」在 11.5px 下约 82px，比日期列本身还宽，
 *   会直接怼到圆点上。列里要的是「扫一眼能对齐」的等宽短格式。
 *
 * @param string|null $date Y-m-d
 * @return string 形如 2026.01.15；非法日期返回 ''
 */
if (!function_exists('gp_short_date')) {
    function gp_short_date($date): string
    {
        $date = trim((string) $date);
        if ($date === '') {
            return '';
        }
        $ts = strtotime($date);
        if ($ts === false) {
            return '';
        }
        return date('Y.m.d', $ts);
    }
}

/**
 * 时间线的层级几何常量（中线布局：主干居中，记录在左、打卡在右）
 *
 * ==================================================================
 * 布局长什么样
 * ==================================================================
 *
 *      ┌────────────┬──────────────┐  │  ┌──────────────┐
 *      │  日期列     │  左侧节点卡片 │  │  │  右侧打卡卡片 │
 *      └────────────┴──────────────┘  │  └──────────────┘
 *                                   主干
 *
 * 一行 = 四段，从左到右：
 *   [date-w 日期列][left-w 左卡片]  [rail 位置的主干]  [right-w 右卡片]
 *
 *   · 左侧：**记录类**节点 —— 日志（感想）、轮次、子计划、开始 / 结束
 *   · 右侧：**打卡类**节点 —— 文章打卡
 *
 * ==================================================================
 * 几何变量（四个 + 一个比例）
 * ==================================================================
 *   --gp-date-w   日期列宽度（**只有左侧节点**用；右侧打卡日期写在卡片里）
 *   --gp-rail     主干竖线在本级 <ul> 坐标系里的 x，也是圆点中心的 x
 *   --gp-pad      左卡片右缘与主干之间的**额外留白**
 *                 （0 = 卡片贴着连接线，只在想让左侧更透气时才加大）
 *   --gp-right-w  右卡片右边界到 ul 右缘的距离
 *
 * 推导（⚠️ 严格按这套，混用就是下一个 1px bug）：
 *   主干竖线 left（ul 坐标） = rail
 *   圆点 left（li 坐标）     = rail - 半径              （li 满宽，左缘与 ul 对齐）
 *   左卡片左缘（ul 坐标）    = date-w + 10
 *   左卡片右缘（ul 坐标）    = rail - link - 4.5 - pad
 *   右卡片左缘（ul 坐标）    = rail + link + 4.5
 *   连接线长度              = link（固定值，不随层级变）
 *
 * ⚠️ 连接线**不能**做成 pad-rail 的差。
 *    中线布局下这个差动辄两三百像素，直接当线长会画出一条横贯半屏的直线，
 *    把「从属关系」的小短线变成视觉噪音。所以线长固定 22px。
 *
 * ==================================================================
 * 四条硬约束（改数值时必须同时满足）
 * ==================================================================
 *   1. date-w + 10 < 左卡片右缘     日期列与左卡片不重叠
 *   2. rail - link - 4.5 - pad - (date-w+10) ≥ 220   左卡片可读宽度
 *   3. ul宽 - rail - link - 4.5 ≥ 200                右卡片可读宽度
 *   4. date-w + 4.5 < rail           日期列不越过主干
 *
 * ==================================================================
 * 为什么第 1 层给这么大
 * ==================================================================
 * 中线布局把一行的宽度需求**翻了一倍**（原来只有右边一半在用）。
 * 弹窗可用宽 988px（面板 1040 减左右内边距 52）：
 *   日期列 78 + 左卡片 ~315 + [主干] + 右卡 ~490 ≈ 943px，还剩几十像素余量。
 * 所以弹窗必须放宽到 1040px —— 这是「一栏变两栏」的硬成本，不是随意定的。
 *
 * 越深越窄：子时间线嵌在父卡片里，每层都吃掉卡片内边距。
 * 第 3 层起砍掉日期列（show_date=false）并把 rail 往左收；
 * 日期信息在卡片内部的 .gp-tl-when / .gp-tl-meta 里本来就有，不丢内容。
 */
if (!defined('GP_TL_GEOMETRY')) {
    define('GP_TL_GEOMETRY', true);
    // [日期列宽, 主干x(rail), 左卡片留白(pad), 右卡片右边界(rightW), 是否显示日期列]
    //
    // 第 1 层（本版重算）：0 / 50% / 0 / 0
    //   date-w=0：本版没有左侧日期列 —— 日志的日期已挪进卡片右下角。
    //   rail='50%'：⚠️ **主干居中，是真·几何居中**，不是"某个偏左的固定值"。
    //             用户要的是「竖线在正中间、日志/文章左右对称分离」的经典时间线。
    //             踩过两次的坑：一开始 rail=420（78 日期列挤的），后来砍掉日期列
    //             又改成 330 —— 都是**偏左的固定值**（330/1040 只有 32%），
    //             左边日志卡 303px、右边文章卡 683px，完全不对称，
    //             用户看到的一直是「线偏左、左边挤、右边空」。
    //             改成 50% 后左右各占一半（ulW=1040 时各 493px），永远对称，
    //             面板宽度怎么变都自动居中。
    //   pad=0    ：左卡片直接顶到连接线
    //   rightW=0 ：右卡片一路铺到 ul 右缘
    //
    // 第 2 层（2026-09-25 用户要求改版）：子时间线**也走中线布局** ——
    //   用户反馈「后台写的日志应该显示在左边」：原来单侧全右挂会把日志
    //   和打卡挤到同一侧，看不出「日志左 / 打卡右」的分工。
    //   实测子 <ul> 有 458px 宽，50% 居中后两侧各 ~193px 卡片，可读。
    //   第 3 层及更深仍退回单侧（见 CSS「单侧化」块）——
    //   再深一层卡片会塌到 120px 以下。
    //   date-w 全部为 0：日期列已废弃（日期进卡片）。
    $GLOBALS['gp_tl_geometry'] = [
        // [date-w, rail, pad, right-w, show-date]
        // ⚠️ rail 可以是 px 数字，也可以是 '50%' 这类百分比字符串（内联时原样输出）
        /* ⚠️ 第 1 层的 rightW 现在是 **18**（本版改，原来是 0）。
           原值 0 = 右卡片一路铺到 ul 右缘，看起来「有边」。但实测：
             - 右卡片右缘算出 1046px，**超出 ul 的 1040** —— 被面板裁掉；
             - 左侧日志卡从 15px 起（因为别处留了边），右侧却贴在 1040，
               左右不对称，中线布局的「对称感」直接破了。
           给右缘留 18px 之后，左右各让出约 18px 呼吸位，
           卡片不再顶边、不再溢出，左右宽度也回到同一量级。
           18 这个数 = 左侧卡片本来就有的视觉边距，取齐它。 */
        /* 主干从 50% 左移到 30%：
           中线布局下左列常年空着（日志不是每天写），主干停在正中会
           让「主干 + 右侧卡片」整体显得偏右、左半屏大片留白。
           用户要求「把整个时间线往左移」，故 rail 收到 30%。 */
        1 => [0, '18%', 0, 18, true],
        2 => [0, '18%', 0, 18, true],
        3 => [0, 10,  0, 0, false],
        4 => [0, 10,  0, 0, false],
    ];
    // 连接线（横向短线）长度：左/右共用同一个值，由 CSS 消费。
    // 固定而不算出来的理由见上方注释第 2 段。
    $GLOBALS['gp_tl_link'] = 22;
}

/**
 * 取某一层的时间线几何参数
 *
 * @param int $depth 从 1 开始；超过 4 层一律按第 4 层处理（不再继续缩）
 * @return array [date_w, rail, pad, right_w, show_date]
 */
if (!function_exists('gp_tl_geometry')) {
    function gp_tl_geometry(int $depth): array
    {
        $table = $GLOBALS['gp_tl_geometry'];
        if ($depth < 1) {
            $depth = 1;
        }
        if ($depth > 4) {
            $depth = 4;
        }
        return $table[$depth];
    }
}

/**
 * 取连接线长度（左、右共用）
 *
 * @return int px
 */
if (!function_exists('gp_tl_link')) {
    function gp_tl_link(): int
    {
        return (int) ($GLOBALS['gp_tl_link'] ?? 22);
    }
}

if (!function_exists('gp_band_color')) {
    /**
     * 胶囊区段调色板：子计划按**创建顺序**（id 升序）依次取色。
     * 刻意不放蓝色系 —— 蓝色是「没有子计划覆盖」的默认底色，
     * 子计划再撞蓝色就分不清「哪段有主」了。
     */
    function gp_band_color(int $i): string
    {
        $palette = ['#14b8a6', '#f59e0b', '#f43f5e', '#22c55e', '#f97316', '#ec4899'];
        return $palette[$i % count($palette)];
    }
}

/**
 * 渲染一条完整的时间线（含首尾的「开始 / 结束」节点）
 *
 * 结构是一条纵向主干 + 若干节点，每个子节点可以在原地展开出自己的
 * 一条平行子主干（见 gp_render_tl_node() 的 child 分支）。
 *
 * ⚠️ 首尾为什么要各加一个「开始 / 结束」节点：
 *   计划本身是有起止的，但起止日期如果只写在卡片头部，读的人看到的是
 *   「一堆节点悬在半空」，说不清这条线从哪里来到哪里去。
 *   首尾各钉一个节点，这条线才有明确的起点和终点 —— 也就是用户要的
 *   「大计划从头到尾一整条」。
 *
 * @param array $plan     这条时间线所属的计划行（取它的起止日期做首尾节点）
 * @param array $items    gp_timeline_items() 产出的混排条目
 * @param array $ctx      now / show_status / children / logs / rounds / carry
 *                        / child_counts / log_counts / start_color / end_color
 * @param int   $depth    层级，1 起
 */
if (!function_exists('gp_render_timeline')) {
    function gp_render_timeline(array $plan, array $items, array $ctx, int $depth = 1): void
    {
        list($dateW, $rail, $pad, $rightW, $showDate) = gp_tl_geometry($depth);

        $startCn = Plugin::cnDate($plan['start_date'] ?? null);
        $endCn = Plugin::cnDate($plan['end_date'] ?? null);
        $startRaw = (string) ($plan['start_date'] ?? '');
        $endRaw = (string) ($plan['end_date'] ?? '');

        $startColor = (string) ($ctx['start_color'] ?? '#2f7d5a');
        $endColor = (string) ($ctx['end_color'] ?? '#3b6ea5');

        /* ---------- 胶囊区段：子计划把自己的颜色刷上主干 ----------
           规则（用户 2026-09 定）：
           - 主干是竖向空胶囊，内部默认蓝色斜条纹（见 .gp-tl::before）；
           - 每条子计划在它的 [start_date, end_date] 区间内刷自己的颜色，
             没设截止日期的刷到线尾；
           - 后添加的子计划**从它自己的开始日期起**覆盖旧颜色
             （同日开始时 id 大者赢 —— id 随创建递增）；
           - 没被子计划覆盖的区间露出默认蓝条纹。
           实现：纯 CSS、零 JS —— 每条目算出所属区段色，li 用
           ::before 在自己的行高内刷 --gp-seg，相邻行的段上下相接
           就是连续色带；胶囊壳（边框 + 默认条纹）由 .gp-tl::before 画。 */
        $segBands = [];
        $segPal = (array) (($ctx['children'][(int) $plan['id']] ?? []));
        usort($segPal, static function ($a, $b) {
            return (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
        });
        foreach ($segPal as $si => $sc) {
            $segBands[] = [
                'start' => Plugin::parseDate((string) ($sc['start_date'] ?? '')),
                'end'   => Plugin::parseDate((string) ($sc['end_date'] ?? '')),
                'id'    => (int) ($sc['id'] ?? 0),
                'color' => gp_band_color((int) $si),
            ];
        }
        /* 按开始日期升序（同日按 id 升序）—— 覆盖判定的遍历顺序：
           后开始的、同日里后创建的排在后面，循环里直接「后来者覆盖」。 */
        usort($segBands, static function ($a, $b) {
            return [$a['start'], $a['id']] <=> [$b['start'], $b['id']];
        });
        ?>
        <?php
        /**
         * ⚠️ 几何变量为什么写成 var(--gp-*-base, 默认值) 两层壳
         *
         * 内联样式的优先级**高于任何选择器**，所以 CSS 里写
         *     .gp-tl{--gp-rail:9px}            （窄屏「去列化」）
         *     .gp-tl.gp-tl-d1{--gp-rail:64px}  （中间档收窄）
         * 都会被内联值盖掉，一个字都不生效 —— 而且不报错、不显形。
         * 老版本的窄屏几何调整就是这样一直静默失效的。
         *
         * 现在：内联只提供**基准值**（--gp-*-base），实际值（--gp-*）
         * 在 CSS 里写成 var(--gp-*-base, 兜底)，于是媒体查询可以正常覆盖。
         */
        ?>
        <ul class="gp-tl gp-tl-d<?php echo (int) $depth; ?>"
            style="--gp-date-w-base:<?php echo (int) $dateW; ?>px;--gp-rail-base:<?php
                /* rail 支持两种值：数字（输出 px）或百分比字符串（原样输出）。
                   第 1 层现在传 '50%'（主干真·居中），子层传数字（10px）。
                   不能写 (int) —— 会把 '50%' 截成 50。 */
                echo (is_string($rail) && substr($rail, -1) === '%')
                    ? $rail
                    : ((int) $rail) . 'px';
                ?>;--gp-pad-base:<?php echo (int) $pad; ?>px;--gp-right-w-base:<?php
                echo (int) $rightW; ?>px;--gp-link-base:<?php echo gp_tl_link(); ?>px">
            <?php
            /* 开始节点：钉在这条线的最顶端。
               哪怕计划没设起始日期也要渲染 —— 它是这条线的「头」，
               缺了它下面所有节点的相对位置就没了参照。 */
            ?>
            <li class="gp-tl-item gp-tl-node gp-tl-edge is-start side-c"
                style="--gp-node-color:<?php echo Plugin::e($startColor); ?>">
                <span class="gp-tl-dot gp-tl-dot-edge" aria-hidden="true"></span>
                <span class="gp-tl-card is-edge">
                    <span class="gp-tl-head">
                        <span class="gp-tl-kind is-edge">开始</span>
                    </span>
                    <?php if ($showDate && $startRaw !== ''): ?>
                        <?php
                        /* 日期作为路牌的副行，**放进卡片内**。
                           早期版本把它挂在 <li> 级、再绝对定位到卡片下方，
                           结果是「卡片 72px、日期 71px、胶囊 9px 三者同心」——
                           胶囊正好从日期文字正中穿过去，把「0」「4」切成两半
                           （截图肉眼可见）。
                           现在改成卡内副行：卡片的 white-space:nowrap 保证
                           「开始」+ 日期一行排开，卡片宽度跟着内容走，
                           胶囊只与「开始」二字相交（字比胶囊宽，骑在杆上的
                           效果反而正确），日期被推到卡片右侧、彻底避开胶囊。
                           ⚠️ 这正是「卡片压线」类路牌的标准做法：牌子够宽，
                              骑在杆上，副行文字自然落在杆的一侧。 */
                        ?>
                        <span class="gp-tl-when is-edge"><time
                            datetime="<?php echo Plugin::e($startRaw); ?>"><?php
                            echo gp_short_date($startRaw);
                        ?></time></span>
                    <?php endif; ?>
                </span>
            </li>

            <?php foreach ($items as $item): ?>
                <?php
                /* 该条目落在哪个子计划的区段里：后开始的覆盖先开始的。
                   条目无日期 → 不上色（露出默认蓝条纹）。 */
                $segColor = '';
                if (null !== $item['ts']) {
                    foreach ($segBands as $sb) {
                        if (null !== $sb['start']
                            && $item['ts'] >= $sb['start']
                            && (null === $sb['end'] || $item['ts'] <= $sb['end'])) {
                            $segColor = $sb['color'];
                        }
                    }
                }
                $segStyle = $segColor !== ''
                    ? '--gp-seg:repeating-linear-gradient(-45deg, rgba(255,255,255,.32) 0 2px, rgba(255,255,255,0) 2px 5px),'
                      . $segColor
                    : '';
                // ⚠️ 这里必须是 array_merge()，不能写 $ctx + ['depth' => $depth]。
                //    PHP 的 `+` 对字符串键是**左优先**：左边已有的键不会被右边覆盖。
                //    而 $ctx 是从上一层透传下来的，里面已经带着上一层的 depth，
                //    于是「孙计划」那一层拿到的还是 depth=1，渲染出来永远是 d2
                //    —— 层级压根不递涨，几何/日期列全错，而且完全不报错。
                //    array_merge() 才是「后者覆盖前者」，这里要的就是覆盖。
                gp_render_tl_node(
                    (string) $item['kind'],
                    $item['data'],
                    array_merge($ctx, ['depth' => $depth, 'seg_style' => $segStyle])
                );
                ?>
            <?php endforeach; ?>

            <?php if (empty($items)): ?>
                <li class="gp-tl-item gp-tl-node gp-tl-empty-node side-r">
                    <span class="gp-tl-dot gp-tl-dot-log" aria-hidden="true"></span>
                    <span class="gp-tl-card is-plain">
                        <span class="gp-tl-text">这条线还没有任何记录或子计划。</span>
                    </span>
                </li>
            <?php endif; ?>

            <li class="gp-tl-item gp-tl-node gp-tl-edge is-end side-c"
                style="--gp-node-color:<?php echo Plugin::e($endColor); ?>">
                <span class="gp-tl-dot gp-tl-dot-edge" aria-hidden="true"></span>
                <span class="gp-tl-card is-edge">
                    <span class="gp-tl-head">
                        <span class="gp-tl-kind is-edge">结束</span>
                    </span>
                    <?php if ($showDate && $endRaw !== ''): ?>
                        <?php /* 日期作为路牌副行放进卡片内 —— 见「开始」处注释。 */ ?>
                        <span class="gp-tl-when is-edge"><time
                            datetime="<?php echo Plugin::e($endRaw); ?>"><?php
                            echo gp_short_date($endRaw);
                        ?></time></span>
                    <?php endif; ?>
                </span>
            </li>
        </ul>
        <?php
    }
}

/**
 * 渲染时间线上的一枚节点（圆点 + 一整张卡片）
 *
 * 交互：能展开的节点用原生 <details>/<summary>，零 JS、可键盘操作。
 *   子计划 → 展开出它自己的一条平行子时间线（含它自己的首尾节点）
 *   日志   → 展开出配图缩略图
 *   轮次   → 展开出轮次备注
 *
 * ⚠️ 三条硬约束（都实测过，违反任意一条就展不开且完全不报错）：
 *   1. summary 里**不能有 <a>**。测过：点 <a> 时 details.open 保持 false，
 *      且 location.hash 被改掉 —— 链接把点击抢走了。所以卡片必须是
 *      <summary> 本身，不能是 <a href="#modal">。
 *   2. summary 必须是 details 的**第一个子元素**，前面插任何东西折叠都会失效。
 *   3. summary{display:flex} 会让原生三角消失，必须自己画箭头。
 *
 * 没有额外内容的节点（无图日志 / 无下级子计划 / 无备注轮次）不出 <details>：
 *   给它一个展开箭头却点开什么都没有，比不给箭头更让人困惑。
 *
 * @param string $kind   'log' | 'child' | 'round'
 * @param array  $item   条目数据（log 行 / plan 行 / round 行）
 * @param array  $extra  now / show_status / children / logs / rounds / carry
 *                       / child_counts / log_counts / depth / start_color / end_color
 */
if (!function_exists('gp_render_tl_node')) {
    function gp_render_tl_node(string $kind, array $item, array $extra = []): void
    {
        $now = (int) ($extra['now'] ?? time());
        $showStatus = (bool) ($extra['show_status'] ?? true);
        $depth = (int) ($extra['depth'] ?? 1);
        list(, , , $showDate) = gp_tl_geometry($depth);

        if ($kind === 'log') {
            $date = (string) ($item['log_date'] ?? '');
            $body = trim((string) ($item['content'] ?? ''));
            $images = $item['images'] ?? [];
            /* ⚠️⚠️ 日志节点**不再有「展开」这一层**（本版改）。
               以前是 <details open>：有图时默认展开、点一下能收起。
               现在按需求改成「有图就直接把缩略图铺在卡片里」——
               缩略图点击放大（由 CSS 的 :target 大图层负责，见 .gp-tl-shot a）。
               这样日志就是一张**自包含的小卡片**：正文 + 缩略图 + 右下角日期，
               没有任何折叠交互，读起来和右侧文章卡是同一个「一屏看尽」的节奏。 */
            $hasImages = !empty($images);
            ?>
            <li class="gp-tl-item gp-tl-node gp-tl-log gp-log side-r"<?php
                echo ($extra['seg_style'] ?? '') !== ''
                    ? ' style="' . Plugin::e($extra['seg_style']) . '"' : '';
                ?> data-plan-id="<?php echo (int) ($item['plan_id'] ?? 0); ?>">
                <span class="gp-tl-dot gp-tl-dot-log" aria-hidden="true"></span>
                <?php /* ⚠️ 日期不再放左侧日期列（该列本版已整列砍掉），
                         改为卡片内部的右下角。见 .gp-tl-card-date。 */ ?>
                <span class="gp-tl-card is-log is-plain">
                    <?php if ($body !== ''): ?>
                        <span class="gp-tl-text"><?php echo nl2br(Plugin::e($body)); ?></span>
                    <?php endif; ?>
                    <?php if ($hasImages): ?>
                        <span class="gp-tl-shots">
                            <?php foreach ($images as $image): ?>
                                <?php
                                $imgUrl = trim((string) ($image['url'] ?? ''));
                                $imgCap = trim((string) ($image['caption'] ?? ''));
                                if ($imgUrl === '') {
                                    continue;
                                }
                                ?>
                                <?php /* 缩略图：用 :target 做点击放大（零 JS）。
                                         每张图一个唯一 id，点开铺成大图层。 */ ?>
                                <a class="gp-tl-shot" href="#<?php echo Plugin::e($extra['modal_id'] ?? 'gp'); ?>-shot-<?php echo (int) ($image['id'] ?? 0); ?>">
                                    <img src="<?php echo Plugin::e($imgUrl); ?>"
                                         alt="<?php echo Plugin::e($imgCap !== '' ? $imgCap : '记录配图'); ?>"
                                         loading="lazy"/>
                                </a>
                            <?php endforeach; ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($date !== ''): ?>
                        <time class="gp-tl-card-date"
                              datetime="<?php echo Plugin::e($date); ?>"><?php
                            echo gp_short_date($date);
                        ?></time>
                    <?php endif; ?>
                </span>
            </li>
            <?php
            return;
        }

        if ($kind === 'post') {
            // $item 就是文章行本身（调用方传的是 $item['data']），
            // 别再取一层 ['data']，否则拿到 null。
            $post = $item;
            $title = trim((string) ($post['title'] ?? ''));
            if ($title === '') {
                $title = '未命名文章';
            }
            $permalink = (string) ($post['permalink'] ?? '');
            $excerpt = trim((string) ($post['excerpt'] ?? ''));
            // 打卡日期 = 文章发布时间，按 Y-m-d 展示
            $postCreated = (int) ($post['created'] ?? 0);
            $postDate = $postCreated > 0 ? date('Y-m-d', $postCreated) : '';
            // 总是可展开：至少给一个「阅读全文」入口；有摘要时顺带展示摘要。
            // 注意：summary 里不能放 <a>（会抢走点击导致展不开），
            // 文章链接只能放在展开区，符合上面的硬约束。
            $canOpen = $permalink !== '' || $excerpt !== '';
            ?>
            <li class="gp-tl-item gp-tl-node gp-tl-post side-r"<?php
                echo ($extra['seg_style'] ?? '') !== ''
                    ? ' style="' . Plugin::e($extra['seg_style']) . '"' : '';
                ?>>
                <span class="gp-tl-dot gp-tl-dot-post" aria-hidden="true"></span>
                <?php if ($canOpen): ?>
                <details class="gp-tl-details">
                    <summary class="gp-tl-card">
                <?php else: ?>
                <span class="gp-tl-card is-plain">
                <?php endif; ?>
                        <span class="gp-tl-head">
                            <span class="gp-tl-kind is-post">打卡</span>
                            <?php if ($postDate !== ''): ?>
                                <time class="gp-tl-card-date"
                                      datetime="<?php echo Plugin::e($postDate); ?>"><?php
                                    echo gp_short_date($postDate);
                                ?></time>
                            <?php endif; ?>
                        </span>
                        <span class="gp-tl-title"><?php echo Plugin::e($title); ?></span>
                        <?php if ($canOpen): ?>
                            <span class="gp-tl-chev" aria-hidden="true"></span>
                        <?php endif; ?>
                <?php if ($canOpen): ?>
                    </summary>
                    <div class="gp-tl-open">
                        <?php if ($excerpt !== ''): ?>
                            <span class="gp-tl-text"><?php echo Plugin::e($excerpt); ?></span>
                        <?php endif; ?>
                        <?php if ($permalink !== ''): ?>
                            <a class="gp-tl-post-link" href="<?php echo Plugin::e($permalink); ?>"
                               target="_blank" rel="noopener">阅读全文 →</a>
                        <?php endif; ?>
                    </div>
                </details>
                <?php else: ?>
                </span>
                <?php endif; ?>
            </li>
            <?php
            return;
        }

        if ($kind === 'round') {
            $rNo = (int) ($item['round_no'] ?? 0);
            $rTitle = trim((string) ($item['title'] ?? ''));
            $rStart = Plugin::cnDate($item['start_date'] ?? null);
            $rEnd = Plugin::cnDate($item['end_date'] ?? null);
            $rTotal = (int) ($item['days_total'] ?? 0);
            $rGone = (int) ($item['days_gone'] ?? 0);
            $rNote = trim((string) ($item['note'] ?? ''));
            $rPercent = $rTotal > 0 ? (int) round($rGone / $rTotal * 100) : 0;
            $rPercent = max(0, min(100, $rPercent));
            $canOpen = $rNote !== '';
            ?>
            <li class="gp-tl-item gp-tl-node gp-tl-round side-r"<?php
                echo ($extra['seg_style'] ?? '') !== ''
                    ? ' style="' . Plugin::e($extra['seg_style']) . '"' : '';
                ?>>
                <span class="gp-tl-dot gp-tl-dot-round" aria-hidden="true"></span>
                <?php /* 轮次的日期在卡片头（.gp-tl-when 起止区间），
                   不再挂 li 级日期列 —— 日期列本版已砍（date-w=0），
                   挂上去只会渲染到容器外（实测落在 -99px）。 */ ?>
                <?php if ($canOpen): ?>
                <details class="gp-tl-details">
                    <summary class="gp-tl-card">
                <?php else: ?>
                <span class="gp-tl-card is-plain">
                <?php endif; ?>
                        <span class="gp-tl-head">
                            <span class="gp-tl-kind">第 <?php echo $rNo; ?> 轮</span>
                            <?php if ($rStart !== '' || $rEnd !== ''): ?>
                                <span class="gp-tl-when"><?php
                                    echo $rStart !== '' ? $rStart : '未设起始';
                                ?> — <?php
                                    echo $rEnd !== '' ? $rEnd : '未设截止';
                                ?></span>
                            <?php endif; ?>
                        </span>
                        <?php if ($rTitle !== ''): ?>
                            <span class="gp-tl-text"><?php echo Plugin::e($rTitle); ?></span>
                        <?php endif; ?>
                        <span class="gp-tl-meter"><span style="width:<?php echo $rPercent; ?>%"></span></span>
                        <span class="gp-tl-meta">共 <?php echo $rTotal; ?> 天，已过 <?php echo $rGone; ?>
                            ｜<?php echo $rPercent; ?>%</span>
                        <?php if ($canOpen): ?>
                            <span class="gp-tl-chev" aria-hidden="true"></span>
                        <?php endif; ?>
                <?php if ($canOpen): ?>
                    </summary>
                    <div class="gp-tl-open">
                        <p class="gp-tl-open-text"><?php echo nl2br(Plugin::e($rNote)); ?></p>
                    </div>
                </details>
                <?php else: ?>
                </span>
                <?php endif; ?>
            </li>
            <?php
            return;
        }

        // ---- child：子计划节点 ----
        $pid = (int) ($item['id'] ?? 0);
        $title = trim((string) ($item['title'] ?? ''));
        if ($title === '') {
            $title = '未命名子计划';
        }
        $startCn = Plugin::cnDate($item['start_date'] ?? null);
        $endCn = Plugin::cnDate($item['end_date'] ?? null);
        $startRawChild = (string) ($item['start_date'] ?? '');
        $desc = trim((string) ($item['description'] ?? ''));
        $childStatus = Plugin::status($item, $now);
        $childProgress = Plugin::progress(
            Plugin::applyCarry($item, $extra['carry'][$pid] ?? []),
            $now
        );
        // 已完成铺满，归档不算完成
        $childPercent = $childStatus === 'done' ? 100 : (int) $childProgress['percent'];
        $subCount = (int) ($extra['child_counts'][$pid] ?? 0);
        $subLogCount = (int) ($extra['log_counts'][$pid] ?? 0);
        $subRounds = $extra['rounds'][$pid] ?? [];

        // 子计划节点默认只显示「日期 + 标题」，
        // 点开（原生 details）才出现它的内容：状态 / 描述 / 进度 / 起止，
        // 以及它自己的下级时间线（有下级内容时）。默认视图保持一条干净的主时间线。
        $ownItems = gp_timeline_items(
            $extra['children'][$pid] ?? [],
            $extra['logs'][$pid] ?? [],
            $subRounds,
            $extra['posts'][$pid] ?? []
        );
        ?>
        <li class="gp-tl-item gp-tl-node gp-tl-child side-r is-<?php echo Plugin::e($childStatus); ?>"<?php
            echo ($extra['seg_style'] ?? '') !== ''
                ? ' style="' . Plugin::e($extra['seg_style']) . '"' : '';
            ?>>
            <span class="gp-tl-dot gp-tl-dot-child" aria-hidden="true"></span>
            <?php
            /* 子计划**默认展开**，而且不给折叠开关。
               理由：点进计划的人本来就是要看「这条支线做了什么」，
               再让他多一次点击才能看到内容，纯属多一道手续。
               所以这里用 <details open> 保留可折叠的语义与可访问性结构
               （万一以后要改成可折叠，改一个属性即可），但视觉上不出现
               箭头、也不提示「可点击」——它看起来就是一张铺开的卡片。
               ⚠️ 硬约束：<summary> 必须是 <details> 的第一个子元素；
               这里把 summary 做成纯标题行，内容全部放在 .gp-tl-open 里。 */
            ?>
            <details class="gp-tl-details gp-tl-details-open" open>
                <summary class="gp-tl-card is-open">
                    <?php if ($startRawChild !== ''): ?>
                        <time class="gp-tl-card-date"
                              datetime="<?php echo Plugin::e($startRawChild); ?>"><?php
                            echo gp_short_date($startRawChild);
                        ?></time>
                    <?php endif; ?>
                    <span class="gp-tl-title"><?php echo Plugin::e($title); ?></span>
                </summary>
                <div class="gp-tl-open">
                    <div class="gp-tl-sub">
                        <span class="gp-tl-head">
                            <?php
                            /* 层级标签跟随时间线深度：
                               d1 线里的子节点 = 子计划，d2 线里的 = 孙计划，
                               再深 = 曾孙 / 玄孙。原来一律写死「子计划」，
                               孙计划节点和子计划节点长得一模一样，用户反馈
                               「分不清哪个是子计划的打卡、哪个是孙计划」。 */
                            $depthLabel = [1 => '子计划', 2 => '孙计划', 3 => '曾孙计划', 4 => '玄孙计划'][$depth] ?? '玄孙计划';
                            ?>
                            <span class="gp-tl-kind"><?php echo Plugin::e($depthLabel); ?></span>
                            <?php if ($showStatus): ?>
                                <span class="gp-tag gp-tag-<?php echo Plugin::e($childStatus); ?>"><?php
                                    echo Plugin::e(Plugin::statusLabel($childStatus));
                                ?></span>
                            <?php endif; ?>
                        </span>
                        <?php if ($desc !== ''): ?>
                            <span class="gp-tl-text"><?php echo nl2br(Plugin::e($desc)); ?></span>
                        <?php endif; ?>
                        <span class="gp-tl-meter"><span style="width:<?php echo $childPercent; ?>%"></span></span>
                        <span class="gp-tl-meta">
                            <?php echo $childPercent; ?>%
                            <?php if ($startCn !== '' || $endCn !== ''): ?>
                                ｜<?php echo $startCn !== '' ? $startCn : '未设起'; ?>
                                — <?php echo $endCn !== '' ? $endCn : '未设止'; ?>
                            <?php endif; ?>
                            <?php if ($subCount > 0 || $subLogCount > 0): ?>
                                ｜<?php
                                $bits = [];
                                if ($subCount > 0) {
                                    $bits[] = '子 ' . $subCount;
                                }
                                if ($subLogCount > 0) {
                                    $bits[] = '志 ' . $subLogCount;
                                }
                                echo implode(' · ', $bits);
                                ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php if (!empty($ownItems)): ?>
                    <?php
                    // 子计划自己那条线，层级 +1。
                    // 递归没有深度上限：数据里成环的话 planTree() 上游已经剪掉，
                    // 但这里还是套一层 seen 防环，避免上游哪天漏判直接打爆内存。
                    $seen = $extra['seen'] ?? [];
                    if (!isset($seen[$pid])) {
                        $seen[$pid] = true;
                        // ⚠️ 同样是 array_merge() 而不是 `+`：
                        //    用 `+` 的话上层传下来的 seen（项更少）会盖掉这份新的，
                        //    防环登记等于白做。
                        gp_render_timeline(
                            $item,
                            $ownItems,
                            array_merge($extra, ['seen' => $seen]),
                            $depth + 1
                        );
                    }
                    ?>
                    <?php endif; ?>
                </div>
            </details>
        </li>
        <?php
    }
}

/**
 * 渲染「卡片网格」里的一张计划卡
 *
 * 结构与相册那种一致：一张卡 = 一张封面 + 标题/年份/状态 + 进度。
 * 整张卡是一个 <a>，点它就把地址改成 #gp-plan-N，
 * 对应的时间线模态被 :target 点亮。全程零 JS。
 *
 * ⚠️ 卡片里不再有任何可折叠结构（原来的 <details> 已整体替掉）。
 *   卡片是「入口」，不是「容器」——展开后的东西一律去模态里看。
 *
 * @param array $plan    计划行
 * @param array $ctx     上下文：now / show_status / cover_color / log_count / child_count
 */
if (!function_exists('gp_render_card')) {
    function gp_render_card(array $plan, array $ctx = []): string
    {
        $now = (int) ($ctx['now'] ?? time());
        $showStatus = (bool) ($ctx['show_status'] ?? true);
        $coverColor = (string) ($ctx['cover_color'] ?? '');

        $pid = (int) $plan['id'];
        $title = trim((string) $plan['title']);
        if ($title === '') {
            $title = '未命名计划';
        }
        $year = (int) $plan['year'];
        $status = Plugin::status($plan, $now);
        $progress = Plugin::progress($plan, $now);
        $percent = $status === 'done' ? 100 : (int) $progress['percent'];
        $cover = Plugin::cover($plan);
        $isArchived = (int) ($plan['archived'] ?? 0) === 1;

        $startCn = Plugin::cnDate($plan['start_date'] ?? null);
        $endCn = Plugin::cnDate($plan['end_date'] ?? null);
        $logCount = (int) ($ctx['log_count'] ?? 0);
        $childCount = (int) ($ctx['child_count'] ?? 0);
        $postCount = (int) ($ctx['post_count'] ?? 0);
        $hasRounds = !empty($ctx['has_rounds']);

        // ⚠️ 模态 id 由调用方算好传进来，这里绝不能自己再调 gp_uid()。
        //   卡片和它的模态必须指同一个 id，各自取号会各拿一个，永远对不上，
        //   表现就是「点卡片没反应」——而且完全不报错，极难查。
        $modalId = (string) ($ctx['modal_id'] ?? '');
        if ($modalId === '') {
            // 兜底：调用方没传就自己取一个，至少不会输出空 href="#"
            $modalId = gp_uid();
        }

        // 卡面用类型色描边，让「这张卡属于哪个类型」在网格里一眼可辨。
        // 没有封面图时，同一个颜色还要用来画占位块的竖条。
        $catStyle = $coverColor !== ''
            ? ' --gp-cat:' . $coverColor . ';'
            : '';

        ob_start();
        ?>
        <div class="gp-card-item is-<?php echo Plugin::e($status); ?><?php
            echo $isArchived ? ' is-archived' : '';
        ?>"<?php echo $catStyle !== '' ? ' style="' . $catStyle . '"' : ''; ?>>
            <a class="gp-card" href="#<?php echo Plugin::e($modalId); ?>">
                <span class="gp-card-cover">
                    <?php if ($cover !== ''): ?>
                        <img src="<?php echo Plugin::e($cover); ?>" alt="" loading="lazy"/>
                    <?php else: ?>
                        <?php
                        // 占位：类型色竖条 + 计划首字，比一块死灰更有信息量。
                        // 不用 color-mix()：Safari 16.2- / Chrome 111- 不认，
                        // 用 opacity 叠淡色最稳。
                        ?>
                        <span class="gp-card-cover-ph" aria-hidden="true">
                            <span class="gp-card-cover-mark"></span>
                            <span class="gp-card-cover-ini"><?php
                                echo Plugin::e(mb_substr($title, 0, 1));
                            ?></span>
                        </span>
                    <?php endif; ?>
                    <span class="gp-card-badges">
                        <?php if ($showStatus): ?>
                            <span class="gp-tag gp-tag-<?php echo Plugin::e($status); ?>"><?php
                                echo Plugin::e(Plugin::statusLabel($status));
                            ?></span>
                        <?php endif; ?>
                        <?php if ($isArchived): ?>
                            <span class="gp-tag gp-tag-archived">已归档</span>
                        <?php endif; ?>
                    </span>
                </span>

                <span class="gp-card-main">
                    <span class="gp-card-title"><?php echo Plugin::e($title); ?></span>

                    <?php if ($startCn !== '' || $endCn !== ''): ?>
                        <span class="gp-card-range">
                            <?php echo $startCn !== '' ? $startCn : '未设起始'; ?>
                            <span class="gp-range-sep">—</span>
                            <?php echo $endCn !== '' ? $endCn : '未设截止'; ?>
                        </span>
                    <?php endif; ?>

                    <span class="gp-card-bar">
                        <span class="gp-card-fill" style="width:<?php echo $percent; ?>%"></span>
                    </span>

                    <span class="gp-card-foot">
                        <span class="gp-card-num"><?php echo $percent; ?>%</span>
                        <span class="gp-card-left"><?php echo Plugin::e(Plugin::leftText($progress)); ?></span>
                        <?php if ($childCount > 0 || $logCount > 0 || $hasRounds || $postCount > 0): ?>
                            <?php
                            $bits = [];
                            if ($hasRounds) {
                                $bits[] = '轮次';
                            }
                            if ($childCount > 0) {
                                $bits[] = '子 ' . $childCount;
                            }
                            if ($logCount > 0) {
                                $bits[] = '志 ' . $logCount;
                            }
                            if ($postCount > 0) {
                                $bits[] = '打卡 ' . $postCount;
                            }
                            ?>
                            <span class="gp-card-count"><?php echo implode(' · ', $bits); ?></span>
                        <?php endif; ?>
                    </span>
                </span>
            </a>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}

/**
 * 渲染一个「时间线模态」——点卡片后弹出的悬浮窗
 *
 * 纯 CSS：`.gp-modal:target` 控制显隐，遮罩是 `.gp-modal > .gp-modal-veil`
 * 那层，点它（href="#gp-grid"）就回到网格，等于关闭。
 * 面板顶部有 ✕，同样指回网格。按浏览器返回键也能关。
 *
 * 窗内的时间线是**可原地展开的树状结构**：子计划/日志点一下就在它下面
 * 长出一条平行的子时间线，不再套第二层弹窗 —— 见 gp_render_timeline()。
 *
 * ⚠️ 这个 div 必须放在 grid 的兄弟位置，不能嵌在 <a> 里：
 *   HTML 规范禁止 <a> 嵌套交互式内容，Chromium 会把 DOM 拆开，
 *   模态跑到奇怪的地方甚至被丢到 <body> 之外。
 *
 * @param array $plan     主计划行（模态标题用）
 * @param array $ctx      now / show_status / children / logs / rounds / carry
 *                        / child_counts / log_counts / modal_id
 *                        / start_color / end_color
 */
if (!function_exists('gp_render_timeline_modal')) {
    function gp_render_timeline_modal(array $plan, array $ctx = []): void
    {
        $now = (int) ($ctx['now'] ?? time());
        $showStatus = (bool) ($ctx['show_status'] ?? true);
        $modalId = (string) ($ctx['modal_id'] ?? '');
        if ($modalId === '') {
            return;
        }

        $pid = (int) $plan['id'];
        $title = trim((string) $plan['title']);
        if ($title === '') {
            $title = '未命名计划';
        }
        $year = (int) $plan['year'];
        $desc = trim((string) ($plan['description'] ?? ''));
        $startCn = Plugin::cnDate($plan['start_date'] ?? null);
        $endCn = Plugin::cnDate($plan['end_date'] ?? null);
        $status = Plugin::status($plan, $now);
        $progress = Plugin::progress(
            Plugin::applyCarry($plan, ($ctx['carry'][$pid] ?? [])),
            $now
        );
        $percent = $status === 'done' ? 100 : (int) $progress['percent'];
        $cover = Plugin::cover($plan);
        $planRounds = $ctx['rounds'][$pid] ?? [];
        $planLogs = $ctx['logs'][$pid] ?? [];
        $childPlans = $ctx['children'][$pid] ?? [];
        $planPosts = $ctx['posts'][$pid] ?? [];

        // 混排条目由调用方算好传进来；没传就现算。
        // 现在没有二级模态了，节点不再需要 id，所以只算 items 一项。
        $items = $ctx['items'] ?? gp_timeline_items($childPlans, $planLogs, $planRounds, $planPosts);
        ?>
        <div class="gp-modal gp-modal-lv1" id="<?php echo Plugin::e($modalId); ?>">
            <?php
            // 遮罩本身就是关闭按钮。用 <a> 而不是 <div>：
            // 纯 CSS 方案里「关」和「开」本质都是改 :target，
            // 一个指向网格锚点的链接就够了，还自带键盘可聚焦。
            ?>
            <a class="gp-modal-veil" href="#gp-grid" aria-label="关闭"></a>
            <div class="gp-modal-panel" role="dialog" aria-modal="true"
                 aria-label="<?php echo Plugin::e($title); ?> 的时间线">
                <a class="gp-modal-x" href="#gp-grid" aria-label="关闭">✕</a>

                <header class="gp-modal-head">
                    <?php if ($cover !== ''): ?>
                        <span class="gp-modal-cover">
                            <img src="<?php echo Plugin::e($cover); ?>" alt="" loading="lazy"/>
                        </span>
                    <?php endif; ?>
                    <span class="gp-modal-meta">
                        <span class="gp-modal-title"><?php echo Plugin::e($title); ?></span>
                        <span class="gp-modal-sub">
                            <?php if ($year > 0): ?>
                                <span class="gp-year"><?php echo $year; ?>年</span>
                            <?php endif; ?>
                            <?php if ($showStatus): ?>
                                <span class="gp-tag gp-tag-<?php echo Plugin::e($status); ?>"><?php
                                    echo Plugin::e(Plugin::statusLabel($status));
                                ?></span>
                            <?php endif; ?>
                            <?php if ($startCn !== '' || $endCn !== ''): ?>
                                <span class="gp-modal-range">
                                    <?php echo $startCn !== '' ? $startCn : '未设起始'; ?>
                                    <span class="gp-range-sep">—</span>
                                    <?php echo $endCn !== '' ? $endCn : '未设截止'; ?>
                                </span>
                            <?php endif; ?>
                        </span>
                        <span class="gp-modal-prog">
                            <span class="gp-card-bar">
                                <span class="gp-card-fill" style="width:<?php echo $percent; ?>%"></span>
                            </span>
                            <span class="gp-modal-num"><?php echo $percent; ?>%
                                · <?php echo Plugin::e(Plugin::leftText($progress)); ?></span>
                        </span>
                    </span>
                </header>

                <?php if ($desc !== ''): ?>
                    <p class="gp-modal-desc"><?php echo nl2br(Plugin::e($desc)); ?></p>
                <?php endif; ?>

                <?php if (!empty($planRounds)): ?>
                    <p class="gp-modal-cum">
                        累计 <?php echo (int) $progress['cumulative_days']; ?> 天
                        ／总计 <?php echo (int) $progress['cumulative_total']; ?> 天
                        ｜<strong><?php echo (int) $progress['cumulative_percent']; ?>%</strong>
                    </p>
                <?php endif; ?>

                <?php
                // 整条时间线（含首尾的「开始 / 结束」节点）交给 gp_render_timeline()。
                // 空列表也照常渲染：首尾两个节点就是这条线的骨架，
                // 没有它们这条线就悬在半空，看不出从哪来到哪去。
                gp_render_timeline($plan, $items, [
                    'now'          => $now,
                    'show_status'  => $showStatus,
                    'modal_id'     => $modalId,
                    'children'     => $ctx['children'] ?? [],
                    'logs'         => $ctx['logs'] ?? [],
                    'rounds'       => $ctx['rounds'] ?? [],
                    'posts'        => $ctx['posts'] ?? [],
                    'carry'        => $ctx['carry'] ?? [],
                    'child_counts' => $ctx['child_counts'] ?? [],
                    'log_counts'   => $ctx['log_counts'] ?? [],
                    'start_color'  => $ctx['start_color'] ?? '#2f7d5a',
                    'end_color'    => $ctx['end_color'] ?? '#3b6ea5',
                ], 1);
                ?>
            </div>

            <?php
            /* ------------------------------------------------------------------
               配图大图层（纯 CSS :target 放大）
               ------------------------------------------------------------------
               日志小卡片里的缩略图是一个 <a href="#gp-shot-<id>">。
               点它 = 把 :target 切到对应的 .gp-lightbox 上，于是这一层显示出来。
               点大图任意处 / 右上角 ✕ = 回到面板锚点，层就收回。
               ⚠️ id 统一带弹窗前缀（gp-m1-shot-3）：
                  同一批大图层会被每个弹窗各渲染一份（数据是全量传入的），
                  不带前缀时全局撞 id（实测 3 个 gp-shot-1），:target 只解析到
                  第一份 → 在别的窗里点缩略图就「没反应」。前缀唯一化后，
                  每张图在各自窗里都有自己的一份，:has 规则再保住宿主窗。

               ⚠️ 为什么不用 <details>？
                   <details> 需要「点 summary」，而缩略图本身是个链接（要能点开），
                   两者语义冲突。:target 天然就是「点链接 → 显示目标」，最贴合。
               ⚠️ 关闭锚点回 #<modalId>：回到本面板而不是网格，
                   这样关掉大图后**时间线还开着**，不会把人甩回列表。
               ------------------------------------------------------------------ */
            $shotIds = [];
            /* ⚠️ $ctx['logs'] 是**按 plan_id 分组的二维结构**：
                   $ctx['logs'][$planId] = [ logRow, logRow, ... ]
               不是一维的日志列表。所以要下钻两层才拿到 images。
               （踩过一次：只钻一层时 $logRow['images'] 恒为空，
                 整个大图层一个都不渲染，而且不报错。） */
            foreach (($ctx['logs'] ?? []) as $logRowsOfPlan) {
                if (!is_array($logRowsOfPlan)) {
                    continue;
                }
                foreach ($logRowsOfPlan as $logRow) {
                    foreach (($logRow['images'] ?? []) as $imgRow) {
                        $sid = (int) ($imgRow['id'] ?? 0);
                        if ($sid > 0 && !isset($shotIds[$sid])) {
                            $shotIds[$sid] = $imgRow;
                        }
                    }
                }
            }
            foreach ($shotIds as $sid => $imgRow):
                $imgUrl = trim((string) ($imgRow['url'] ?? ''));
                $imgCap = trim((string) ($imgRow['caption'] ?? ''));
                if ($imgUrl === '') {
                    continue;
                }
                ?>
                <div class="gp-lightbox" id="<?php echo Plugin::e($modalId); ?>-shot-<?php echo $sid; ?>">
                    <a class="gp-lightbox-veil" href="#<?php echo Plugin::e($modalId); ?>"
                       aria-label="关闭大图"></a>
                    <span class="gp-lightbox-box">
                        <img src="<?php echo Plugin::e($imgUrl); ?>"
                             alt="<?php echo Plugin::e($imgCap !== '' ? $imgCap : '记录配图'); ?>"/>
                        <?php if ($imgCap !== ''): ?>
                            <span class="gp-lightbox-cap"><?php echo Plugin::e($imgCap); ?></span>
                        <?php endif; ?>
                    </span>
                    <a class="gp-lightbox-x" href="#<?php echo Plugin::e($modalId); ?>"
                       aria-label="关闭大图">✕</a>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }
}


// [Sunny 适配] 不调用主题 header（由页面预设机制内嵌渲染）
?>

<style>
/* ------------------------------------------------------------------
   GrowthPlan 前台样式（卡片网格 + 时间线弹窗 + 窗内原地展开）

   交互模型（全 CSS，零 JS）：
     1. 卡片网格           .gp-grid          —— 每条计划一张卡
     2. 点卡片 → 时间线弹窗 .gp-modal:target —— 只有一级，没有二级窗
     3. 窗内子计划 / 日志    <details>         —— 点一下**原地**展开出一条
                                               平行的子时间线，不弹新窗
     4. 关闭弹窗          遮罩 / ✕ 都是指向 #gp-grid 的链接

   为什么弹窗仍用 :target：
     - 不引任何外部资源，首屏少一次 JS 解析；
     - 浏览器返回键天然能「关弹窗」，符合直觉；
     - 每个弹窗都能被直接分享（带 #gp-m3 的链接直接打开）；
     - 代价是同一时刻只能有一个 :target，而这正好是弹窗该有的行为。

   为什么窗内展开用 <details> 而不是再来一层 :target：
     - :target 全局唯一，开子层必然关掉父层，做不出「父线还在、
       子线挂在下面」的并列效果；
     - <details> 的展开/收起是浏览器原生行为，键盘 Enter/Space 直接可用，
       不写一行 JS 就有完整的可访问性；
     - 代价是做不了「同时只展开一个」的手风琴 —— 纯 CSS 无解，接受
       多个同时展开（读长计划时其实是优点）。
   ------------------------------------------------------------------ */
/* 关闭锚点：零尺寸、不可见，只为给 href="#gp-grid" 一个落点。
   没有它，浏览器找不到锚点会把页面滚到最底部。 */
.gp-close-anchor{
  display:block;
  width:0;
  height:0;
  overflow:hidden;
}

.gp-wrap{
  /* [Lo-Fi zine 2026-09-22] 纸面 + 墨色 + 印刷偏移阴影 */
  --gp-paper:#EDE4CF;
  --gp-paper-2:#F8F2E2;
  --gp-paper-3:#E3D7BB;
  --gp-tape:rgba(178,156,112,.55);
  --gp-offsh:rgba(43,36,28,.15);
  --gp-ro-blue:#0F6BA8;
  --gp-ro-pink:#E84C9E;
  --gp-hand:"LXGW WenKai","KaiTi","楷体","Noto Serif SC",serif;
  --gp-serif:"Songti SC","Noto Serif SC","SimSun","宋体",serif;
  --gp-mono:"Courier New",ui-monospace,"SFMono-Regular",Courier,monospace;
  --gp-ink:#241E17;
  --gp-ink-2:#6E5F4B;
  --gp-ink-3:#9C8B70;
  --gp-line:rgba(43,36,28,.16);
  --gp-line-2:rgba(43,36,28,.30);
  --gp-bg-soft:rgba(43,36,28,.05);
  --gp-bg-card:#F8F2E2;
  --gp-radius:6px;
  --gp-radius-sm:4px;
  --gp-shadow:2px 2px 0 rgba(43,36,28,.14);
  --gp-shadow-hover:3px 4px 0 rgba(43,36,28,.22);

  /* 状态色（Riso 墨色化） */
  --gp-active:#1F7A4C;
  --gp-active-soft:rgba(0,169,92,.14);
  --gp-soon:#B26A1E;
  --gp-soon-soft:rgba(255,108,47,.15);
  --gp-expired:#AF3E2E;
  --gp-expired-soft:rgba(255,102,94,.16);
  --gp-pending:#7C6E58;
  --gp-pending-soft:rgba(124,110,88,.16);
  --gp-done:#15659E;
  --gp-done-soft:rgba(15,107,168,.13);
  /* 归档是正交于时间状态的一维，用中性暖灰把它和「进行中」区分开 */
  --gp-archived:#7C6E58;
  --gp-archived-soft:rgba(124,110,88,.13);
  --gp-round-line:rgba(43,36,28,.16);
  --gp-round-ink:#7C6E58;

  --gp-accent:#0F6BA8;
  /* 模态遮罩 / 面板底色：面板必须是「不透明」的，
     否则透过它会看到底下的网格，两层字叠在一起完全没法读 */
  --gp-veil:rgba(32,26,19,.60);
  --gp-panel:#F8F2E2;
  --gp-panel-ph:#E3D7BB;
  --gp-chip-on:#F8F2E2;

  max-width:1060px;
  margin:0 auto;
  padding:8px 0 56px;
  color:var(--gp-ink);
  font-size:15px;
  line-height:1.7;
  -webkit-font-smoothing:antialiased;
}

/* 夜间模式跟随站点开关（html.darkmode），仅调变量，不额外请求任何资源。
   [Sunny 适配] 原 prefers-color-scheme 已改：系统深色 + 站点白天主题会误刷浅色文字 */
html.darkmode .gp-wrap{
  --gp-paper:#211B14;
  --gp-paper-2:#2A2318;
  --gp-paper-3:#332B1D;
  --gp-tape:rgba(150,128,86,.45);
  --gp-offsh:rgba(0,0,0,.45);
  --gp-ro-blue:#7FB3DC;
  --gp-ro-pink:#F27CB6;
  --gp-ink:#EDE4CF;
  --gp-ink-2:#C6B896;
  --gp-ink-3:#93856A;
  --gp-line:rgba(237,228,207,.14);
  --gp-line-2:rgba(237,228,207,.28);
  --gp-bg-soft:rgba(237,228,207,.06);
  --gp-bg-card:rgba(237,228,207,.05);
  --gp-shadow:2px 2px 0 rgba(0,0,0,.5);
  --gp-shadow-hover:3px 4px 0 rgba(0,0,0,.62);

  --gp-active:#6CC79A;
  --gp-active-soft:rgba(108,199,154,.15);
  --gp-soon:#E0B169;
  --gp-soon-soft:rgba(224,177,105,.16);
  --gp-expired:#E08F86;
  --gp-expired-soft:rgba(224,143,134,.15);
  --gp-pending:#9AA2AD;
  --gp-pending-soft:rgba(154,162,173,.16);
  --gp-done:#84B3E6;
  --gp-done-soft:rgba(132,179,230,.14);

  --gp-archived:#9AA2AD;
  --gp-archived-soft:rgba(154,162,173,.16);
  --gp-round-line:rgba(237,228,207,.16);
  --gp-round-ink:#9AA2AD;

  --gp-accent:#84B3E6;
  --gp-chip-on:#241E17;
  --gp-veil:rgba(0,0,0,.70);
  --gp-panel:#2A2318;
  --gp-panel-ph:#332B1D;

  background:
    radial-gradient(rgba(237,228,207,.05) 1px, transparent 1.2px) 0 0/6px 6px,
    linear-gradient(180deg, rgba(237,228,207,.04), rgba(237,228,207,0) 42%),
    var(--gp-paper);
  border:1px solid var(--gp-line);
  border-radius:3px;
  padding:26px 30px 60px;
  box-shadow:10px 12px 0 rgba(0,0,0,.28);
}

/* --------------------------- 页头 --------------------------- */
.gp-head{
  margin:0 0 30px;
  padding-bottom:22px;
  border-bottom:1px solid var(--gp-line);
}
.gp-head h1{
  margin:0 0 10px;
  font-size:30px;
  line-height:1.25;
  font-weight:650;
  letter-spacing:-.02em;
}
.gp-head p{
  margin:0;
  color:var(--gp-ink-2);
  font-size:14px;
}

/* 概览小统计 */
.gp-overview{
  display:flex;
  flex-wrap:wrap;
  gap:8px;
  margin:16px 0 0;
  padding:0;
  list-style:none;
}
.gp-overview li{
  display:inline-flex;
  align-items:baseline;
  gap:6px;
  padding:5px 11px;
  border:1px solid var(--gp-line);
  border-radius:999px;
  background:var(--gp-bg-soft);
  font-size:12.5px;
  color:var(--gp-ink-2);
}
.gp-overview b{
  font-size:14px;
  font-weight:650;
  font-variant-numeric:tabular-nums;
  color:var(--gp-ink);
}
.gp-overview .is-active b   { color:var(--gp-active); }
.gp-overview .is-soon b     { color:var(--gp-soon); }
.gp-overview .is-expired b  { color:var(--gp-expired); }
.gp-overview .is-done b     { color:var(--gp-done); }

/* --------------------------- 年份 / 归档筛选 --------------------------- */
.gp-filters{
  display:flex;
  align-items:center;
  flex-wrap:wrap;
  gap:8px;
  margin:0 0 32px;
}
.gp-filters-label{
  font-size:12.5px;
  color:var(--gp-ink-3);
  letter-spacing:.04em;
  margin-right:2px;
}
.gp-chip{
  display:inline-block;
  padding:4px 12px;
  border:1px solid var(--gp-line);
  border-radius:999px;
  background:transparent;
  color:var(--gp-ink-2);
  font-size:13px;
  text-decoration:none;
  transition:background .16s ease, border-color .16s ease, color .16s ease;
}
.gp-chip:hover{
  border-color:var(--gp-line-2);
  background:var(--gp-bg-soft);
  color:var(--gp-ink);
}
.gp-chip.is-current{
  border-color:transparent;
  background:var(--gp-ink);
  /* 不要写死 #fff：深色模式下 --gp-ink 是浅色，白底白字会完全看不见。
     用 --gp-chip-on 表示「选中态胶囊上的文字色」，两个模式各自定义。 */
  color:var(--gp-chip-on,#fff);
}
.gp-chip em{
  font-style:normal;
  font-size:11.5px;
  opacity:.65;
  margin-left:3px;
}
/* .gp-filters-archive 已随视图切换一起移除 */

/* --------------------------- 归档 --------------------------- */
/* 归档卡片整体压淡：它是「收起来的历史」，不该和进行中的计划抢注意力 */
.gp-card-item.is-archived .gp-card{
  background:var(--gp-bg-soft);
  box-shadow:none;
}
.gp-card-item.is-archived .gp-card-title{ color:var(--gp-ink-2); }
.gp-card-item.is-archived .gp-card-cover img,
.gp-card-item.is-archived .gp-card-cover-ph{ filter:grayscale(.7); opacity:.8; }
.gp-tag-archived{
  background:var(--gp-archived-soft);
  color:var(--gp-archived);
}

/* 归档区：常驻页面底部。和上面的分类分组用
   一条虚线 + 更大间距隔开，读起来是「页脚性质」的一块。 */
.gp-group-archived{
  margin-top:64px;
  padding-top:30px;
  border-top:1px dashed var(--gp-line-2);
}

/* --------------------------- 分组 --------------------------- */
.gp-group{ margin-bottom:52px; }
.gp-group:last-child{ margin-bottom:0; }

.gp-group-head{
  display:flex;
  align-items:baseline;
  gap:12px;
  margin:0 0 6px;
}
.gp-group-head h2{
  margin:0;
  font-size:19px;
  font-weight:620;
  letter-spacing:-.01em;
}
/* 类型颜色小圆点，颜色非法时回落到中性灰 */
.gp-dot{
  display:inline-block;
  width:9px;
  height:9px;
  margin-right:9px;
  vertical-align:1px;
  border-radius:50%;
  background:var(--gp-cat, var(--gp-ink-3));
  box-shadow:0 0 0 3px var(--gp-cat-soft, var(--gp-bg-soft));
}
.gp-group-count{
  font-size:12.5px;
  color:var(--gp-ink-3);
  font-variant-numeric:tabular-nums;
}
.gp-group-desc{
  margin:0 0 22px;
  color:var(--gp-ink-2);
  font-size:13.5px;
}

/* --------------------------- 卡片网格 ---------------------------
   auto-fill + minmax 让列数随宽度自适应，不需要任何媒体查询。
   minmax 的 240px 是按「封面 16:10 + 两行文字」算出来的最小可读宽度，
   比这更窄标题就会频繁折成三行，卡片高度参差不齐，网格看起来就散了。 */
/* auto-fill + minmax 让列数随宽度自适应，不需要媒体查询。
   ⚠️ 用 auto-fill 而不是 auto-fit：
   auto-fit 会把空轨道「折叠掉」，于是只剩 1 条计划时，
   那一张卡会被拉伸占满整个 1060px 宽 —— 封面被拉成超宽横幅，
   和「卡片」这个形态完全不搭。
   auto-fill 保留空轨道，卡片宽度始终由 minmax 的下限决定，
   卡片少时就是「左边几张、右边留白」，这才是网格该有的样子。 */
.gp-grid{
  display:grid;
  grid-template-columns:repeat(auto-fill, minmax(228px, 1fr));
  gap:18px;
  margin:0;
  padding:0;
  list-style:none;
}

.gp-card-item{ min-width:0; }

/* 整张卡就是一个链接：点哪儿都能打开时间线。
   用 <a> 而不是 JS 绑定，所以中键新开标签页、右键复制链接、
   键盘 Tab 聚焦全部天然可用。 */
.gp-card{
  display:flex;
  flex-direction:column;
  height:100%;
  border:1px solid var(--gp-line);
  border-radius:var(--gp-radius);
  background:var(--gp-bg-card);
  box-shadow:var(--gp-shadow);
  overflow:hidden;
  text-decoration:none;
  color:inherit;
  transition:box-shadow .18s ease, border-color .18s ease, transform .18s ease;
}
.gp-card:hover{
  box-shadow:var(--gp-shadow-hover);
  border-color:var(--gp-line-2);
  transform:translateY(-2px);
}
.gp-card:focus-visible{
  outline:2px solid var(--gp-cat, var(--gp-accent));
  outline-offset:2px;
}
/* 过期卡片整体压淡：状态徽标已经说清楚了，这里只是补一层视觉权重 */
.gp-card-item.is-expired .gp-card{ opacity:.86; }

/* ---- 卡面 ---- */
.gp-card-cover{
  position:relative;
  display:block;
  aspect-ratio:16/10;
  background:var(--gp-panel-ph);
  overflow:hidden;
}
.gp-card-cover img{
  display:block;
  width:100%;
  height:100%;
  object-fit:cover;
  transition:transform .35s cubic-bezier(.22,.7,.3,1);
}
.gp-card:hover .gp-card-cover img{ transform:scale(1.04); }

/* 无封面占位：类型色竖条 + 计划首字。
   不用 color-mix()：Safari 16.2 以下、Chrome 111 以下都不认，
   用 opacity 叠淡色最稳。

   注意这里也要垫一层同款深色渐变（见下面 ::after）：
   徽标是「白底黑字」，如果直接落在浅灰占位块上，
   白底和浅灰底几乎同色，徽标会糊成一片看不出边界，
   而且和「有封面的卡片」视觉上不成一套。
   垫了渐变之后，占位卡和真封面卡的徽标区完全一致。 */
.gp-card-cover-ph{
  position:absolute;
  inset:0;
  display:flex;
  align-items:center;
  justify-content:center;
  background:var(--gp-panel-ph);
}
.gp-card-cover-ph::after{
  content:"";
  position:absolute;
  left:0;
  right:0;
  bottom:0;
  height:56px;
  background:linear-gradient(to top, rgba(37,31,24,.42), rgba(37,31,24,0));
}
.gp-card-cover-mark{
  position:absolute;
  left:0;
  top:0;
  bottom:0;
  width:5px;
  z-index:1;
  background:var(--gp-cat, var(--gp-ink-3));
  opacity:.55;
}
.gp-card-cover-ini{
  position:relative;
  z-index:1;
  font-size:34px;
  font-weight:650;
  line-height:1;
  color:var(--gp-cat, var(--gp-ink-3));
  opacity:.5;
  user-select:none;
}
/* 渐变托底必须压在「竖条 + 首字」之上、徽标之下。
   ::after 默认在后代里靠上，所以给首字显式 z-index:1，
   渐变保持默认（auto，等于 0），堆叠顺序就是 渐变 < 首字 < 徽标。 */
.gp-card-cover-ph::after{ z-index:0; }

/* 年份 / 状态徽标压在卡面左下角，用渐变垫一层保证可读性 —— 
   封面图可能是任意颜色，直接放浅色徽标会瞎。 */
.gp-card-badges{
  position:absolute;
  left:0;
  right:0;
  bottom:0;
  z-index:2;
  display:flex;
  align-items:center;
  flex-wrap:wrap;
  gap:6px;
  padding:20px 11px 10px;
  background:linear-gradient(to top, rgba(37,31,24,.62), rgba(37,31,24,0));
}
/* ⚠️ 徽标压在渐变上，不能沿用「淡色底 + 深色字」那套。
   状态色（如 --gp-active 的绿）在半透明底上会让底下的深色渐变透上来，
   结果是深绿字压深灰底 —— 几乎看不见（第一版就是这样）。
   卡面上这三枚徽标统一改成「白底/近白底 + 固定深字」，
   用 --gp-chip-on 之外的硬编码深色，因为这里的底永远是白色，
   不受深色模式影响（渐变始终是黑的，徽标始终是白的）。 */
.gp-card-badges .gp-year,
.gp-card-badges .gp-tag{
  background:#F8F2E2;
  border-color:transparent;
  color:#241E17;
  box-shadow:1px 1px 0 rgba(37,31,24,.45);
}
.gp-card-badges .gp-tag-archived{
  background:rgba(248,242,226,.85);
  color:#6E5F4B;
}

/* ---- 卡身 ---- */
.gp-card-main{
  display:flex;
  flex-direction:column;
  gap:7px;
  padding:13px 14px 14px;
  flex:1 1 auto;
  min-width:0;
}
.gp-card-title{
  display:block;
  font-size:15.5px;
  font-weight:620;
  line-height:1.42;
  letter-spacing:-.005em;
  /* 长标题压到两行，超出才省略。一行太短会浪费卡面宽度，
     不限制又会让个别卡片把整行网格撑高。 */
  display:-webkit-box;
  -webkit-line-clamp:2;
  -webkit-box-orient:vertical;
  overflow:hidden;
}
.gp-card-range{
  display:block;
  color:var(--gp-ink-3);
  font-size:11.5px;
  font-variant-numeric:tabular-nums;
}

.gp-card-bar{
  display:block;
  height:5px;
  border-radius:999px;
  background:var(--gp-bg-soft);
  box-shadow:inset 0 0 0 1px var(--gp-line);
  overflow:hidden;
}
.gp-card-fill{
  display:block;
  height:100%;
  border-radius:999px;
  background:var(--gp-cat, var(--gp-accent));
  transition:width .3s ease;
}
.gp-card-item.is-soon    .gp-card-fill{ background:var(--gp-soon); }
.gp-card-item.is-expired .gp-card-fill{ background:var(--gp-expired); }
.gp-card-item.is-done    .gp-card-fill{ background:var(--gp-done); }
.gp-card-item.is-pending .gp-card-fill{ background:var(--gp-pending); }

.gp-card-foot{
  display:flex;
  align-items:baseline;
  gap:7px;
  flex-wrap:wrap;
  font-size:11.5px;
  color:var(--gp-ink-2);
  font-variant-numeric:tabular-nums;
  margin-top:auto;
}
.gp-card-num{
  font-weight:650;
  color:var(--gp-ink);
}
.gp-card-item.is-soon    .gp-card-num{ color:var(--gp-soon); }
.gp-card-item.is-expired .gp-card-num{ color:var(--gp-expired); }
.gp-card-item.is-done    .gp-card-num{ color:var(--gp-done); }
.gp-card-left{ color:var(--gp-ink-3); }
.gp-card-count{
  margin-left:auto;
  padding:1px 7px;
  border-radius:999px;
  background:var(--gp-bg-soft);
  border:1px solid var(--gp-line);
  font-size:10.5px;
  color:var(--gp-ink-3);
  white-space:nowrap;
}

/* --------------------------- 年份 / 状态徽标 --------------------------- */
.gp-year{
  display:inline-block;
  padding:1px 8px;
  border-radius:6px;
  background:var(--gp-bg-soft);
  border:1px solid var(--gp-line);
  color:var(--gp-ink-2);
  font-size:11.5px;
  font-variant-numeric:tabular-nums;
  letter-spacing:.02em;
}
.gp-tag{
  display:inline-block;
  padding:2px 9px;
  border-radius:999px;
  font-size:11.5px;
  font-weight:600;
  letter-spacing:.02em;
  white-space:nowrap;
}
.gp-tag-active  { background:var(--gp-active-soft);  color:var(--gp-active); }
.gp-tag-soon    { background:var(--gp-soon-soft);    color:var(--gp-soon); }
.gp-tag-expired { background:var(--gp-expired-soft); color:var(--gp-expired); }
.gp-tag-pending { background:var(--gp-pending-soft); color:var(--gp-pending); }
.gp-tag-done    { background:var(--gp-done-soft);    color:var(--gp-done); }

.gp-range-sep{ margin:0 6px; opacity:.6; }

/* --------------------------- 时间线弹窗 ---------------------------
   现在只剩**一级**弹窗：卡片网格 → 点卡片弹出这条计划的时间线。
   时间线内部的子计划 / 日志一律**原地展开**（<details>），不再套第二层窗，
   所以这里不再有 lv1 / lv2 之分。

   ⚠️ 关键点：默认 display:none + 只有 :target 时才 flex。
   不要试图用 visibility/opacity 做过渡，那样隐藏的模态仍会
   参与 Tab 键的焦点顺序，键盘用户会掉进一个看不见的弹窗里。

   ⚠️ 类名仍然保留 gp-modal-lv1，但那只是为了和「曾经存在过的二级窗」
   区分开的历史痕迹；现在全页只有一种模态，选择器写 .gp-modal 即可。 */
.gp-modal{
  display:none;
  position:fixed;
  inset:0;
  z-index:900;
  align-items:center;
  justify-content:center;
  padding:24px 18px;
}
.gp-modal:target{ display:flex; }
/* ---------- 点缩略图放大时宿主窗保持显示 ----------
   点缩略图 = 把 :target 切到窗内的 .gp-lightbox；如果不补下面这条，
   .gp-modal:target 失配、整窗（连同大图层）一起被 display:none 藏掉，
   表现为「点缩略图没反应」（用户报的 bug）。:has 只会点亮包含该大图层的窗。 */
.gp-modal:has(.gp-lightbox:target){ display:flex; }

/* 遮罩：点它关闭。它是 <a>，所以天然可键盘聚焦 + 可访问。 */
.gp-modal-veil{
  position:absolute;
  inset:0;
  background:var(--gp-veil);
  backdrop-filter:blur(3px);
  -webkit-backdrop-filter:blur(3px);
  border:0;
  text-decoration:none;
}
.gp-modal-veil:focus-visible{ outline:none; }

.gp-modal-panel{
  position:relative;
  z-index:1;
  display:flex;
  flex-direction:column;
  width:100%;
  /* ⚠️ 中线布局要求面板够宽 —— 这是从「单侧时间线」改成「主干居中、
     左右分侧」之后必须跟着改的一个数。
     算一下最低需求：日期列 78 + 左卡 280 + 主干留白 40 + 右卡 280 ≈ 680，
     再加上面板左右内边距 52 与滚动条，660px 会把两侧卡片各压到 186px，
     正文根本读不了。给到 min(1040px, 92vw)：宽屏时左右各约 280px+
     且留出呼吸感，60% 屏宽以下自动退让。 */
  max-width:min(1040px, 92vw);
  max-height:min(84vh, 900px);
  overflow-y:auto;
  /* overscroll-behavior 防止滚到边界后把背后页面一起带着滚 */
  overscroll-behavior:contain;
  padding:24px 26px 26px;
  border-radius:18px;
  background:var(--gp-panel);
  border:1px solid var(--gp-line);
  box-shadow:0 32px 80px -24px rgba(0,0,0,.5), 0 4px 14px rgba(0,0,0,.16);
  animation:gp-pop .2s ease;
}
@keyframes gp-pop{
  from{ opacity:0; transform:translateY(10px) scale(.985); }
  to  { opacity:1; transform:none; }
}
/* 关闭按钮。固定在面板右上角，不随内容滚动 —— 
   否则长内容一滚，用户得先把页面滚回顶部才能关。 */
.gp-modal-x{
  position:absolute;
  top:10px;
  right:12px;
  z-index:2;
  display:flex;
  align-items:center;
  justify-content:center;
  width:30px;
  height:30px;
  border-radius:50%;
  background:var(--gp-bg-soft);
  color:var(--gp-ink-2);
  font-size:14px;
  line-height:1;
  text-decoration:none;
  transition:background .16s ease, color .16s ease;
}
.gp-modal-x:hover{
  background:var(--gp-ink);
  color:var(--gp-chip-on,#fff);
}

/* --------------------------- 时间线模态的内容 --------------------------- */
.gp-modal-head{
  display:flex;
  align-items:flex-start;
  gap:14px;
  padding-right:34px;
  margin-bottom:16px;
}
.gp-modal-cover{
  flex:none;
  width:92px;
  aspect-ratio:16/10;
  border-radius:9px;
  overflow:hidden;
  border:1px solid var(--gp-line);
  background:var(--gp-panel-ph);
}
.gp-modal-cover img{
  display:block;
  width:100%;
  height:100%;
  object-fit:cover;
}
.gp-modal-meta{
  display:flex;
  flex-direction:column;
  gap:7px;
  min-width:0;
}
.gp-modal-title{
  font-size:19px;
  font-weight:650;
  line-height:1.35;
  letter-spacing:-.01em;
}
.gp-modal-sub{
  display:flex;
  align-items:center;
  flex-wrap:wrap;
  gap:7px;
  font-size:12px;
}
.gp-modal-range{
  color:var(--gp-ink-3);
  font-variant-numeric:tabular-nums;
}
.gp-modal-prog{
  display:flex;
  align-items:center;
  gap:9px;
  margin-top:2px;
}
.gp-modal-prog .gp-card-bar{ flex:1 1 auto; min-width:80px; }
.gp-modal-num{
  flex:none;
  font-size:12px;
  font-weight:620;
  color:var(--gp-ink-2);
  font-variant-numeric:tabular-nums;
}

.gp-modal-desc{
  margin:0 0 14px;
  color:var(--gp-ink-2);
  font-size:13.5px;
  line-height:1.75;
}
.gp-modal-cum{
  margin:0 0 18px;
  font-size:12px;
  color:var(--gp-ink-3);
  font-variant-numeric:tabular-nums;
}
.gp-modal-cum strong{ color:var(--gp-ink); }

/* ==================================================================
   时间线：主干居中，记录在左、打卡在右

   一条主干竖线立在正中间，两侧挂卡片：
     左 = 记录类节点（日志 / 感想、轮次、子计划、开始 / 结束）
     右 = 打卡类节点（文章打卡）

   ------------------------------------------------------------------
   几何：四个变量，改层级只改这四个
   ------------------------------------------------------------------
     --gp-date-w    日期列宽度（只有左侧节点用）
     --gp-rail      主干在本级 <ul> 坐标系里的 x，也是圆点中心的 x
     --gp-pad       左侧卡片的**左边界**
     --gp-right-w   右侧卡片的**右边界到 ul 右缘的距离**
     --gp-link      横向连接线长度（左右共用）

   坐标系约定（⚠️ 定死，混用就是下一个 1px bug）：
     所有变量都相对**本级 <ul>**。<li> 的左边界在 ul 坐标系里等于
     --gp-pad，所以换算到 <li> 上时一律要减去 --gp-pad。
     而右侧卡片更麻烦：<li> 仍是满宽的（从 pad 到 ul 右缘），
     右侧卡片要靠绝对定位钉到 <li> 的右边，所以它的 right 是
     ul 右缘往左数 --gp-right-w，换算到 <li> 上仍是 --gp-right-w
     （因为 <li> 的右边界和 ul 的右边界重合）。

   推导：
     圆点中心（ul 坐标）    = rail
     圆点 left（li 坐标）   = rail - pad - 圆点半径
     竖线 left（ul 坐标）   = rail
     左卡片：钉在 li 的左边界，右缘 = li宽 - (rail-pad) - link
     左连接线：从圆点左缘往左 link 长，再往左接卡片
     右卡片：绝对定位，left = rail-pad + link
     右连接线：从圆点右缘往右 link 长，再接卡片

   ⚠️ 连接线固定长度，**不是** pad-rail 的差。
      中线布局里 rail 和 pad 的差动辄上百像素，直接拿它当线长会画出
      一条横贯半屏的直线，喧宾夺主。线长固定 = 视觉上「节点就在主干旁」。

   ------------------------------------------------------------------
   四条硬约束（改数值时必须同时满足）
   ------------------------------------------------------------------
     1. date-w + 4.5 < rail            日期列右缘要给圆点留位
     2. rail - pad ≥ 13                rail 要落在 li 内部（否则圆点跑到 li 外）
     3. ul宽 - rail ≥ right-w         右边卡片放得下
     4. 左卡片宽度 ≥ 220px            否则正文没法读
   ================================================================== */
.gp-tl{
  position:relative;
  list-style:none;
  margin:6px 0 0;
  /* 从「基准值」派生「实际值」。⚠️ 两层别合并：
       --gp-*-base  由 gp_render_timeline() 内联写死（PHP 按层级算）
       --gp-*       在 CSS 里可被任意断点覆盖（窄屏 / 中间档）
     直接写死 --gp-rail:300px 会把 PHP 算出的层级值全抹平。 */
  --gp-date-w:var(--gp-date-w-base, 78px);
  --gp-rail:var(--gp-rail-base, 300px);
  /* ⚠️ 兜底值原来写 400px，纯属把 --gp-rail 的兜底抄错了一行。
     pad 的真实语义是「卡片与连接线之间额外留的白」，几何表里各层都传 0，
     所以兜底也必须是 0 —— 写 400 一旦 --gp-pad-base 缺失，
     左卡片右缘会瞬间被推向 rail 左侧 400px 处，算出巨大负宽度。 */
  --gp-pad:var(--gp-pad-base, 0px);
  /* 左右两侧统一的「呼吸位」= 卡片与 ul 边缘的距离。
     右侧靠 --gp-right-w（几何表传出 18），左侧靠这个值。
     两处必须同源，否则左右留白不等、中线看着是歪的。 */
  --gp-edge:var(--gp-right-w-base, 18px);
  --gp-right-w:var(--gp-right-w-base, 400px);
  --gp-link:var(--gp-link-base, 22px);
  /* ⚠️ 不用 padding-left / padding-right 来留白：
     中线布局下 <li> 必须**满宽**（从 ul 最左到最右），
     这样右卡片的 right:0 才能直接对齐到 ul 的右缘。
     左卡的起点、右卡的终点都由 <li> 内部的 margin / padding 表达。 */
  padding-left:0;
  padding-right:0;
  /* ⚠️ 必须撑满父容器宽度。
     父级 .gp-modal-panel 是 column flex + overflow:auto，<ul> 作为 flex item
     默认按内容宽度（fit-content）收缩；而卡片有 ±0.3deg 的贴纸微旋转，
     旋转后的外接矩形宽度逐行不同 —— 于是 <ul> 宽度每行微调，
     连带把每一行的 <li> 宽度推来推去，圆点跟着一起漂。
     写死 width:100% 把行宽钉住，漂移即消失。 */
  width:100%;
  box-sizing:border-box;
}
/* 竖线：贯穿整条主干，钉在居中位置 */
/* ---------- 主干：空胶囊形态（2026-09 用户定稿） ----------
   原来是一根 1px 细线；现在改成 9px 宽的竖向胶囊：
   - 黑边框（--gp-ink，深浅色主题各自是近黑/米白，永远可见）
   - 内部默认蓝色斜条纹 = 「没有子计划覆盖」的底色
   - 外径 9px = 圆点直径，圆点正好当「珠子」卡在胶囊上，
     连接线的端点（rail±4.5）也正好抵住胶囊两壁 —— 几何零改动 */
.gp-tl::before{
  content:"";
  position:absolute;
  top:14px;
  bottom:14px;
  left:calc(var(--gp-rail) - 4.5px);
  width:9px;
  box-sizing:border-box;
  border:1.5px solid var(--gp-ink);
  border-radius:999px;
  background:repeating-linear-gradient(-45deg,
    rgba(59,110,165,.50) 0 3px,
    rgba(59,110,165,.15) 3px 6px);
}
/* ---------- 首尾路牌不再骑线 ----------
   用户要求：「开始/结束要放在主时间线的上和下，不要和时间线重叠」。
   原胶囊从 top:14 拉到 bottom:14，正好从两张路牌中间穿过去；
   现在把胶囊（含子层细轨）两端收掉路牌的高度：
     · 上端 80px —— 线从「开始」牌下缘之后约 12px 才开始
     · 下端 86px —— 线在「结束」牌上缘之前约 12px 就结束
   效果：线被两端的牌子「夹」在中间，不再穿过任何一张牌。
   （窄屏路牌退回右挂、不需要收边 —— 见窄屏块里的还原规则。） */
.gp-tl-d1::before,
.gp-tl-d2::before{
  top:80px;
  bottom:86px;
}
/* ---------- 区段填充：子计划的颜色刷在胶囊内壁 ----------
   每条目在自己的行高内刷一段 --gp-seg（服务端算好挂在 li 的
   style 上），相邻行的段上下相接 = 连续色带。
   ⚠️ 宽 6px = 胶囊内径（9 - 1.5×2），只盖住条纹、不压边框；
      li 没有 seg 时背景 transparent，露出壳的默认蓝条纹。
   ⚠️⚠️ 首尾两条的段必须「缩进 14px」对齐胶囊壳的 top/bottom。
      胶囊壳是 top:14px / bottom:14px（给上下圆头留出弧顶），
      而段默认 top:0 / bottom:0（铺满行高）。落到首尾行时，
      段会在壳的圆头之外多出 14px 的**方形色块**——
      实测表现为胶囊顶端/底端各突出一截小方块，像多了个接头。
      这里让首段的 top、末段的 bottom 各让出 14px，与壳端头齐平。
      ⚠️ 胶囊两端收边（top:80 / bottom:86）后，
      真正会「露头」的是首/末两条**内容行**（前面是「开始」牌、后面是
      「结束」牌——两张牌自身没有色带）——见下面 d1 的两条收边规则。
      子层不用管：细轨 2px 与色带同宽，本来就齐平（用户确认子层无此问题）。 */
.gp-tl-item::before{
  content:"";
  position:absolute;
  top:0;
  bottom:0;
  left:calc(var(--gp-rail) - 3px);
  width:6px;
  background:var(--gp-seg, transparent);
}
.gp-tl-item:first-child::before{ top:14px; }
.gp-tl-item:last-child::before{ bottom:14px; }
/* 主干收边后，首/末内容行的色带跟着收，
   与胶囊内壁端点齐平：上 9.5px（80 + 1.5 边框 − 72 行高）、
   下 12.5px（86 − 1.5 边框 − 72 行高），端角 3px 圆角贴着内壁圆头。
   不收的话，6px 色带的方块端头会盖住 9px 胶囊圆头的外沿、
   看着像「线被截断、接了个断头」（实测截图可见）。 */
/* ⚠️ 端点要「内缩 1.5px（胶囊边框厚）+ 3px 圆角（内壁圆头半径）」——
   色带画在胶囊上层，方块端头会盖住圆头外沿、看着像被切平（实测）。*/
.gp-tl-d1 > .gp-tl-item:nth-child(2)::before{ top:9.5px; border-radius:3px 3px 0 0; }
.gp-tl-d1 > .gp-tl-item:nth-last-child(2)::before{ bottom:12.5px; border-radius:0 0 3px 3px; }
/* ⚠️ --gp-seg 会沿 DOM 继承：子时间线嵌在父计划的 li 里，
   不重置的话嵌套时间线的行会全部继承父计划的颜色，
   「按各自子计划的起止染色」就变成了「整条嵌套线都被父色淹没」。
   每条时间线只按它自己的子计划染（渲染器逐层重算），在这里清零。 */
.gp-tl{
  --gp-seg:transparent;
}

.gp-tl-item{
  position:relative;
  padding-bottom:12px;
}

/* 节点卡片收窄：主干左移后右列变很宽，
   卡片一路铺满会拉出很长的单行文字。用户要求「卡片缩短一大半」，
   这里统一给个上限（约原来的一半），靠左排、右侧留白。 */
.gp-tl-card,
.gp-tl-details{
  max-width:390px;
}
.gp-tl-item.side-c > .gp-tl-card,
.gp-tl-item.side-c > details > .gp-tl-card{
  max-width:none;   /* 首尾路牌是内容撑宽 + 居中骑线，不受此限制 */
}
.gp-tl-item:last-child{ padding-bottom:0; }

/* ------------------------------------------------------------------
   横向连接线：从圆点往两侧各连一小段
   ⚠️ 长度固定（--gp-link），不做成 pad-rail 的差 —— 中线布局里
      那个差动辄上百像素，直接当线长会画出横贯半屏的直线。
   两侧各用一条规则，互不干扰。
   ------------------------------------------------------------------ */
/* 左侧节点的连接线（圆点左缘 → 左卡片右缘） */
.gp-tl-item.side-l::after{
  content:"";
  position:absolute;
  top:17.5px;
  left:calc(var(--gp-rail) - var(--gp-link) - 4.5px);
  width:var(--gp-link);
  height:1px;
  background:var(--gp-line-2);
}
/* 右侧节点的连接线（圆点右缘 → 右卡片左缘） */
.gp-tl-item.side-r::after{
  content:"";
  position:absolute;
  top:17.5px;
  left:calc(var(--gp-rail) + 4.5px);
  width:var(--gp-link);
  height:1px;
  background:var(--gp-line-2);
}
/* 卡片里给右上角的展开箭头腾出位置，否则长标题会钻到箭头底下 */
summary.gp-tl-card{ padding-right:30px; }

/* ------------------------------------------------------------------
   两侧卡片的定位
   一行 = [日期列][左卡片]  主干  [右卡片]
   左侧卡片贴左、右侧卡片贴右，中间是主干。
   ------------------------------------------------------------------ */
/* 把「卡片或 details 外框」统一当左/右侧的容器来处理。
   ⚠️ 节点里可能是 <details>（可展开）也可能是裸 <span>（不可展开），
      两者的外框都要参与定位，所以选择器要覆盖两种。 */
.gp-tl-item.side-l > details,
.gp-tl-item.side-l > .gp-tl-card,
.gp-tl-item.side-l > .gp-tl-details{
  display:block;
  /* 左卡的左边界让开日期列；右边界顶到「主干 - 连接线 - 圆点半径 - pad」，
     保证卡片不会越过主干、也不会压住圆点。
     order 上先 margin-left 后 margin-right：右缘用 percentage 表达，
     它相对的是「包含块宽度」而不是已扣除后的宽度，所以两条边互不干扰。
     ⚠️ 左侧的 10px 换成 var(--gp-edge)：本版给右侧也留了 18px 呼吸位
     （--gp-right-w=18），左边缘若还是硬编码 10px，左右留白就不对称了
     —— 实测左卡从 15px 起、右卡到 1040 止，中线布局看着是歪的。
     两边统一走 --gp-edge，日后调呼吸感只改一个数。 */
  margin-left:calc(var(--gp-date-w) + var(--gp-edge));
  margin-right:calc(100% - var(--gp-rail) + var(--gp-link) + 4.5px + var(--gp-pad));
}
/* ⚠️ 不可展开节点的 <summary> 缺失时，裸 <span> 是行内元素 —— 
   上面已经 display:block 钉住了，这里不用重复。 */

/* 右侧卡片：靠 padding 把内容整体推到主干右侧。
   ⚠️ 用 padding 而不是绝对定位：padding 撑得起 <li> 的高度，
      右卡片内容变高时 <li> 自然跟着变；绝对定位则会让 <li> 塌成 0 高，
      下一行叠上来、整条线乱掉。
   ⚠️ 右缘由 --gp-right-w 收住：默认 0 就是一路铺到 ul 右缘，
      这样右侧卡片右对齐、和整块内容对齐，视觉上才「有边」。 */
.gp-tl-item.side-r{
  padding-left:calc(var(--gp-rail) + var(--gp-link) + 4.5px);
  padding-right:var(--gp-right-w);
}

/* ------------------------------------------------------------------
   单侧化：把「中线两侧」退回「单侧」的三条规则
   ------------------------------------------------------------------
   什么时候要单侧化？
     1. **第 3 层及更深的子时间线**（.gp-tl-d3/4）——
        再深一层 <ul> 只有 200 出头宽，中线布局会把卡片压成竖条。
        （第 2 层自 2026-09-25 起也走中线布局 —— 见几何表注释。）
        子线是父计划里的一条支线，用紧凑单侧样式表达从属，比再画一根
        中线更清楚，也更省空间。
     2. **窄屏**（见 max-width:640px 断点）—— 390px 的屏左右各分不到 100px。

   单侧化的三条规则：
     a. 左侧节点不再左挂，改走右侧（margin-left 推过主干、margin-right 归零）
     b. 左连接线（::after）取消，避免右边残留一条短横线
     c. 右节点的 padding-left 收窄到 rail+14（不再给 link 全长）
   顺手把圆点收小一圈，靠点的大小读层级。

   ⚠️ specificity：`.gp-tl-item.side-l > .gp-tl-card` 是 0,2,0；
      这里再加 `.gp-tl` 前缀升到 0,3,0，保证压得住，不靠 !important。
   ------------------------------------------------------------------ */
.gp-tl-d3 .gp-tl-item.side-l > details,
.gp-tl-d4 .gp-tl-item.side-l > details,
.gp-tl-d3 .gp-tl-item.side-l > .gp-tl-card,
.gp-tl-d4 .gp-tl-item.side-l > .gp-tl-card,
.gp-tl-d3 .gp-tl-item.side-l > .gp-tl-details,
.gp-tl-d4 .gp-tl-item.side-l > .gp-tl-details{
  margin-left:calc(var(--gp-rail) + var(--gp-link) + 4.5px);
  margin-right:0;
}
.gp-tl-d3 .gp-tl-item.side-l::after,
.gp-tl-d4 .gp-tl-item.side-l::after{
  content:none;
}
.gp-tl-d3 .gp-tl-item.side-r,
.gp-tl-d4 .gp-tl-item.side-r{
  padding-left:calc(var(--gp-rail) + var(--gp-link) + 4.5px);
}

/* ------------------------------------------------------------------
   居中节点（side-c）：卡片**压在主干线上**，左右各一半
   ------------------------------------------------------------------
   谁用：开始 / 结束。它们是这条线的「头」和「尾」，不属于左右任何一侧，
   放在正中当路牌，读者一眼就知道「这条线从哪儿到哪儿」。

   做法：卡片宽度是 auto（内容撑），想让它的**中轴**落在 rail 上，
   用 margin 是算不出来的（要先知道卡片多宽，而它是 auto）。
   所以走绝对定位 + transform：把卡片左缘钉在 rail 上，再整体左移半个自身宽度。

       position:absolute; left:var(--gp-rail); transform:translateX(-50%);

   ⚠️ 代价：卡片脱离文档流，<li> 会塌成 0 高 —— 下一行的卡片就会叠上来。
      必须用 padding-top / padding-bottom 把行高重新撑起来。
      卡片高度不固定（可能两行字），padding-top 取一个足够大的固定值
      （30px 能容下两行路牌），bottom 留 16px 呼吸。

   ⚠️ 圆点仍在 rail 上，卡片压在线上会盖住线 —— 这是想要的：
     路牌压在时间线上，是常见的时间线样式。卡片有背景色，
     线在卡片下面自然被盖住，不需要额外断线。
   ------------------------------------------------------------------ */
.gp-tl-item.side-c{
  /* 卡片脱离流后靠这里撑行高。
     ⚠️ 2026-09-25 第 7 版路牌变两行（标签 + 日期）后高度 ≈58px，
     30px 会让卡片压住下一行节点，这里跟着抬到 60px。 */
  padding-top:60px;
  padding-bottom:12px;
}
.gp-tl-item.side-c > .gp-tl-card,
.gp-tl-item.side-c > details,
.gp-tl-item.side-c > .gp-tl-details{
  position:absolute;
  top:0;
  left:var(--gp-rail);
  transform:translateX(-50%);
  display:block;
  /* ⚠️ `width:max-content` 只是「想要」内容宽度，一旦外面套了
     `max-width` 上限，浏览器就会在**内部换行**来凑合 ——
     实测「开始 + 2026年4月1日」被拆成两行，日期那行还溢出卡片
     （because 内层 .gp-tl-head 是 flex，换行后行盒宽度算错）。
     路牌是「一行字」，就该保持一行：nowrap 让 width:max-content
     真正生效，长到超过 max-width 时宁可溢出也不折行
     （文案是「开始 / 结束 + 日期」，现实中不会长到需要折行）。 */
  white-space:nowrap;
  width:max-content;
  /* 路牌要「细」，压在线上才像路牌，太宽就成了横幅 */
  max-width:min(300px, 80%);
  text-align:center;
}
/* 走 <details> 时卡片是它的子元素，宽度交给内容自己决定 */
/* ⚠️ 选择器要写 `.gp-tl-item.side-c > .gp-tl-card`（去掉 details 层）——
   实测「开始/结束」卡片是 li 的**直接子元素**，写成
   `> details > .gp-tl-card` 匹配不到，样式静默失效、数值一点不变。
   ⚠️⚠️ 这里原来写 `position:relative`（给旧版
   卡内绝对定位日期当锚点）——同特异性、后写优先，它把上面「骑线规则」的
   `position:absolute` **静默盖掉**，卡片掉回文档流：垂直位置跟着
   padding-top 走（离行顶 60px），主干起点（top:72px）于是从卡片腰部
   穿过去 —— 用户报的「开始牌和线重叠」就是这个。
   第 7 版路牌日期已回到正常流、不再需要卡内锚点；这里保持 absolute，
   让卡片真正钉在行顶（配合两端收边：开始牌在上、线从下方留 7px 缝开始）。 */
.gp-tl-item.side-c > .gp-tl-card,
.gp-tl-item.side-c > details > .gp-tl-card{
  display:block;
  /* ⚠️ width:**max-content**，不能写 auto。
     写 auto 时块级元素一路撑到 max-width（实测 300px），
     路牌就成横杆；max-content 才贴合内容 ——「开始」39px + 内边距，
     卡片才细。上一版这里误写成 auto，把 max-content 覆盖掉了，
     因为 max-width 同为 300、肉眼看不出异常，一直没暴露。 */
  width:max-content;
  white-space:nowrap;
  text-align:center;
  position:absolute;   /* 2026-09-25：恢复「骑线规则」的绝对定位，勿改回 relative（见上注） */
  padding:11px 16px;
}
/* ⚠️ `.gp-tl-head` 全局是 `flex-wrap:wrap`（卡片窄的时候让「类型 + 日期」
   自动折行，对左右两侧的长卡片是对的）。但路牌只有「开始 + 日期」两个
   短词，折行反而把卡片撑成又高又窄、日期溢出的怪样子。
   居中节点上强制不折行，让 max-content 真正量出一行的宽度。 */
/* ⚠️ 首尾路牌的内容布局（踩过三次坑后的定稿）
   ────────────────────────────────────────────────
   卡片中轴骑在胶囊上，但「开始」+ 日期横排时会横跨中线被胶囊切断。
   试过两种都不行：
     a. 整段居中 + padding → 文字整块内缩，日期仍压在胶囊上；
     b. space-between 两端对齐 → 卡片只有 176px 宽，两侧 padding
        吃掉 44px 后剩 106px，恰好等于「开始 39 + 日期 68」，
        两个元素被挤在一起、推不到两端。
   定稿：**日期换行到第二行**。「开始」自己一行居中骑在胶囊上
   （字号大于胶囊宽度，视觉上就是「牌子骑在杆上」），
   日期另起一行落在下方 —— 日期不再和胶囊争同一条横线。 */
/* ⚠️ 路牌内容布局（第 6 版定稿，前面五版全废弃）
   ──────────────────────────────────────────────────────────
   核心矛盾：卡片中轴骑在胶囊上（translateX(-50%)），胶囊 9px 宽
   正好落在卡片正中，**任何跨过中线的文字都会被胶囊切断**。

   历代失败方案（都在「同一竖轴」上给日期找位置，绕不开胶囊）：
     1. 日期放在 li 级、居中在卡片正下方 → 与胶囊同心，被切成两半
     2. 整段居中 + padding        → 日期仍压在胶囊上
     3. space-between 两端对齐    → 卡片太窄，推不到两端
     4. 日期在卡内绝对定位右下     → 溢出卡片
     5. 日期进卡内、「开始 + 日期」整体居中
        → 这一版把卡片撑到 86px，但**整组居中 = 日期仍横跨胶囊**
           （实测日期 [694,746]，胶囊 [715,724]，左右各差 30px）

   第 6 版正解：**「开始」二字单独居中对齐胶囊；日期绝对定位到
   「开始」右侧**。卡片只负责给「开始」当牌子，日期脱离正常流，
   用 `left:calc(50% + 22px)` 从卡片中轴往右推 —— 50% 是胶囊中轴，
   +22px 让开「开始」的右半（39/2≈20）再留 2px 缝。
   这样日期永远在胶囊右侧，与卡片宽度解耦（卡片窄了也不受影响）。 */
.gp-tl-item.side-c .gp-tl-head{
  justify-content:center;
  flex-wrap:nowrap;
  flex-direction:row;
  align-items:center;
  gap:0;
  padding-left:0;
  padding-right:0;
}
/* ⚠️ 路牌内容布局（第 7 版，2026-09-25 用户要求）
   ──────────────────────────────────────────────────────────
   第 6 版是「『开始/结束』居中 + 日期绝对定位到中轴右侧 + 对称 58px 留白」。
   真机上用户看到的：日期贴着卡片右缘、甚至溢出（2027.10.01 实测出缘 12px），
   58px 对称留白又让卡片又宽又空。用户明确要求：
   「时间放在结束下面居中」——**日期换到标签下方、整组居中**。
   本版照做：卡片改 flex 竖排、内容居中、内边距收到 20px。
   ⚠️ 日期居中后胶囊（9px）会从日期文字中间穿过（几何上）——
      视觉上由不透明底色盖住（见下方 .gp-tl-card.is-edge 规则），
      看上去只剩「牌子压线」。 */
.gp-tl-item.side-c > .gp-tl-card{
  display:flex;
  flex-direction:column;
  align-items:center;
  gap:3px;
  padding:10px 20px;
}
/* 路牌副行（日期）：第 7 版起改为**正常流的第二行**、居中（用户要求）。
   （撤销第 6 版的「中轴右侧绝对定位」——那个方案日期贴着/溢出卡片右缘，
   实测 2027.10.01 出缘 12px；58px 对称留白也让卡片空得不好看。） */
.gp-tl-item.side-c > .gp-tl-card > .gp-tl-when.is-edge{
  position:static;
  transform:none;
  margin:0;
  font-size:11px;
  letter-spacing:.02em;
  opacity:.86;
  white-space:nowrap;
  text-align:center;
}
/* ⚠️ 路牌**不要那个独立圆点**。
   左右两侧的节点：卡片挂在线的旁边，圆点在线和卡片之间当「接线柱」，
   位置天然是空的。但居中节点的卡片**骑在线上**，圆点也在线上 ——
   两者落到同一个 x，圆点就会砸在卡片正中间的字上，
   实测把「2026」的「20」盖掉了（截图肉眼可见）。
   这里直接把圆点隐掉：卡片自己的虚线边框就是节点标记，
   线从卡片底下穿过，语义更干净，也不丢信息。 */
.gp-tl-item.side-c > .gp-tl-dot{ display:none; }
/* 居中节点不需要连接线（它就骑在线上） */
.gp-tl-item.side-c::after{ content:none; }
/* ⚠️ li 级日期在本版**不再渲染**（日期已进卡片）。
   规则保留成兜底：万一旧缓存模板残留 .gp-tl-date，也不会漏出个
   孤立的、压在胶囊上的日期。 */
.gp-tl-item.side-c > .gp-tl-date{ display:none; }

/* ⚠️ 第 3 层及更深的首尾路牌**不居中**，要退回右挂。
   原因：那些层是单侧布局、rail 只有 10px（主干贴左缘），
   「中轴对 10」会把卡片左半截推到父卡片外面，层级关系彻底乱掉。
   （第 2 层自 2026-09-25 起改走中线布局、rail=50%，路牌恢复居中、
   与第 1 层同款；只有 d3/d4 保留右挂。）
   做法同窄屏那套：撤销绝对定位、回到普通流、从主干右侧起排。 */
.gp-tl-d3 .gp-tl-item.side-c,
.gp-tl-d4 .gp-tl-item.side-c{
  padding-top:0;
  padding-bottom:0;
}
.gp-tl-d3 .gp-tl-item.side-c > .gp-tl-card,
.gp-tl-d4 .gp-tl-item.side-c > .gp-tl-card,
.gp-tl-d3 .gp-tl-item.side-c > details,
.gp-tl-d4 .gp-tl-item.side-c > details,
.gp-tl-d3 .gp-tl-item.side-c > .gp-tl-details,
.gp-tl-d4 .gp-tl-item.side-c > .gp-tl-details{
  position:static;
  transform:none;
  width:auto;
  max-width:none;
  margin-left:calc(var(--gp-rail) + var(--gp-link));
  margin-right:0;
  text-align:left;
  white-space:normal;
}
/* 子层路牌要「接地」：恢复圆点 + 一条小连接线，否则像凭空挂在右边 */
.gp-tl-d3 .gp-tl-item.side-c > .gp-tl-dot,
.gp-tl-d4 .gp-tl-item.side-c > .gp-tl-dot{ display:block; }
.gp-tl-d3 .gp-tl-item.side-c .gp-tl-head,
.gp-tl-d4 .gp-tl-item.side-c .gp-tl-head{
  /* ⚠️ 子层路牌**一行排开**（标签 + 日期），不要折行。
     折行后「开始」一行、日期一行，卡片凭空高 16px；子线本来就
     一屏里挤着好几个路牌，每个高三行会让父卡片高得离谱。 */
  flex-wrap:nowrap;
  justify-content:flex-start;
}
/* ⚠️⚠️ 子层路牌要**撤销第 6 版那套「对称 padding + 绝对定位日期」**。
   ──────────────────────────────────────────────────────────────
   第 6 版的排版是给**顶层**路牌设计的：卡片骑在胶囊正中、
   日期绝对定位到卡片中轴右侧 20px。但子层的路牌根本**不居中**
   （上面刚把它拉回 position:static、右挂到主干旁边），
   此时还留着那套定位就是灾难：
     · padding-left/right:58px → 子层卡片凭空多出 116px 内边距，
       在父卡片 300px 的窄身板里，正文被压成一小撮；
     · 日期 position:absolute; left:calc(50% + 20px) 的 50% 变成
       **父卡片宽度的一半**，日期直接飞到父卡片右边缘外面。
   子层要的是最朴素的「标签 + 日期」同行、左对齐，这里全部还原。
   （实测：不还原的话，子时间线里的「开始」卡片会变成一块
     又宽又空、日期跑到卡片外面的怪东西 —— 截图上那三个叠在一起
     的绿色药丸就是这么来的。） */
.gp-tl-d3 .gp-tl-item.side-c > .gp-tl-card,
.gp-tl-d4 .gp-tl-item.side-c > .gp-tl-card{
  padding:7px 12px;
  /* 第 7 版起路牌统一「标签一行 + 日期一行」上下堆叠（与顶层同款），
     这里只收一档内边距，列排布交给基础规则。 */
  display:flex;
  flex-direction:column;
  align-items:flex-start;
  gap:2px;
}
/* 第 7 版：日期已在基础规则里回到正常流（两行堆叠居中），
   子层不再需要单独还原绝对定位。 */
/* 子层的「开始 / 结束」标签回到普通字号，别用顶层那套放大加粗 ——
   子线是父卡片里的一条支线，字号不该盖过父卡片正文。 */
.gp-tl-d2 .gp-tl-kind.is-edge,
.gp-tl-d3 .gp-tl-kind.is-edge,
.gp-tl-d4 .gp-tl-kind.is-edge{
  font-size:10.5px;
  letter-spacing:.02em;
}

/* ⚠️⚠️ 子时间线的主干**不能是胶囊**（第 7 版修正）
   ──────────────────────────────────────────────────────────
   症状：一个带 3 个子计划的主计划，渲染出来是 4 根并排的胶囊
   （父线 1 根 + 每个子计划内嵌 1 根），像一排平行的柱子，
   整块「排版」看起来就是乱的 —— 这就是用户说的「UI 排版不对」。
   根因：胶囊壳（.gp-tl::before）是**通用规则**，子层也照单全收。
   但子线嵌在父卡片里、只有 300px 出头宽，再画一根 9px 的黑边胶囊，
   既粗重、又和外层的胶囊抢视觉，还因为层层缩进堆成「楼梯」。
   正解：子层退回**细轨**（2px 竖线，无边框、无圆角、无条纹），
   它只是父卡片内部的一条支线，用「细」表达从属关系。
   父线保留胶囊 —— 层级差异一眼可见。 */
.gp-tl-d2::before,
.gp-tl-d3::before,
.gp-tl-d4::before{
  left:calc(var(--gp-rail) - 1px);
  width:2px;
  border:0;
  border-radius:0;
  background:var(--gp-line-2);
}
/* 子层的区段染色同样收窄到 2px、贴住细轨 */
.gp-tl-d2 > .gp-tl-item::before,
.gp-tl-d3 > .gp-tl-item::before,
.gp-tl-d4 > .gp-tl-item::before{
  left:calc(var(--gp-rail) - 1px);
  width:2px;
}
/* 子层细轨也要缩进，首尾不再需要 14px 的圆头让位 */
.gp-tl-d2 > .gp-tl-item:first-child::before,
.gp-tl-d3 > .gp-tl-item:first-child::before,
.gp-tl-d4 > .gp-tl-item:first-child::before{ top:0; }
.gp-tl-d2 > .gp-tl-item:last-child::before,
.gp-tl-d3 > .gp-tl-item:last-child::before,
.gp-tl-d4 > .gp-tl-item:last-child::before{ bottom:6px; }

/* ⚠️ 子时间线要**在父卡片内部缩进**，不能让它跑到父卡片左缘外面。
   子 <ul> 是父卡片里的一个块，它的坐标原点是自己的左缘 ——
   但父卡片本身已经向右推过（margin-left = date-w + 10）。
   如果子线不额外缩进，它的主干会落在父卡片的最左侧、
   卡片反而比父卡片更靠左，层级关系看起来是反的。
   这里给整条子线一个左内边距，让它明显「缩在金主的身体里」。 */
/* ⚠️ 2026-09-25：d2 改中线布局（rail=50%）后，左内边距会让
   li 坐标系（内容盒）和 ul 坐标系（padding 盒）错开 14px ——
   路牌卡片按 li 的 50% 居中、胶囊按 ul 的 50% 居中，两个「50%」
   基准不同 → 路牌偏心 7px。d2 的 padding-left 归零保证同基准；
   d3/d4 保持原缩进（单侧布局不受影响）。 */
.gp-tl-d2{ padding-left:0; }
.gp-tl-d3,
.gp-tl-d4{
  /* 14 → 20：再深一档缩进，让孙计划区域和
     子计划卡片拉开一点视觉步差（用户反馈层级难辨认）。 */
  padding-left:20px;
}

/* 日期列：钉在节点左侧，右对齐成一列。
   只有左侧节点有 —— 右侧打卡的日期写在卡片内部。

   ⚠️ 这个 <time> 在 DOM 里是**卡片的第一个子元素**，不是 <li> 的子元素。
      这么排是为了让窄屏能一键「去列化」：桌面端 position:absolute 把它
      抽到卡片左边，窄屏端 position:static 它就自然回到卡片第一行。
      一份标记两套布局，不用为了响应式把日期渲染两遍。

   ⚠️ 中线布局下 <li> 已满宽、坐标与 ul 对齐，所以 left 直接写 0。 */
.gp-tl-date{
  position:absolute;
  top:11px;
  left:0;
  width:var(--gp-date-w);
  text-align:right;
  font-size:11.5px;
  line-height:1.5;
  color:var(--gp-ink-3);
  white-space:nowrap;
  /* 日期全是数字，等宽数字才不会让日期列左右抖 */
  font-variant-numeric:tabular-nums;
  /* ⚠️ 兜底防裁：等宽字体回退时「2026.01.15」在 11.5px 需要 71px，
     比早先给的 68px 宽，末位会被静默裁成「2026.01.0」。
     几何表已给 78px，这里再保证不裁剪 —— 日期少一位是信息错误，
     比排版不齐严重。 */
  overflow:visible;
}

/* 卡片内日期：右侧打卡、以及默认展开的子计划，日期就写在卡片标题行里，
   不占左列（左列只留给主时间线上的节点）。
   和 .gp-tl-date 用同一套等宽口径，保证两种日期看起来是同一种字体。 */
.gp-tl-card-date{
  font-family:var(--gp-mono);
  font-size:11px;
  line-height:1.5;
  color:var(--gp-ink-3);
  white-space:nowrap;
  font-variant-numeric:tabular-nums;
}
/* 默认展开的子计划：不要三角、不要「可点击」的暗示。
   ⚠️ <details> 的三角是靠 summary 的 display 控制的：
      summary 一旦不是 list-item（这里是 flex），原生三角就消失；
      所以只要不再输出 .gp-tl-chev，就没有任何箭头残留。 */
.gp-tl-details-open > summary.gp-tl-card{ cursor:default; }
.gp-tl-details-open > summary.gp-tl-card:hover{ border-color:var(--gp-line); }

/* 轴上的圆点。位置由 rail 反推，与竖线永远对齐。 */
.gp-tl-dot{
  position:absolute;
  top:13px;
  width:9px;
  height:9px;
  border-radius:50%;
  box-sizing:border-box;
  background:var(--gp-panel);
  border:1.5px solid var(--gp-line-2);
  left:calc(var(--gp-rail) - 4.5px);
}
/* 轮次：已完成的历史，实心灰点 */
.gp-tl-dot-round{
  background:var(--gp-archived);
  border-color:var(--gp-archived);
  opacity:.75;
}
/* 子计划：用类型色点出「这里挂了一条子计划」 */
.gp-tl-dot-child{
  background:var(--gp-cat, var(--gp-accent));
  border-color:var(--gp-cat, var(--gp-accent));
  box-shadow:0 0 0 3px var(--gp-cat-soft, var(--gp-bg-soft));
}
/* 日志：最小的点，属于「随手记」级别。
   ⚠️ 别用 --gp-line-2 那种 16% 透明度的线色：那根竖线本身就是这个色，
   同样颜色、同样尺寸的点骑在线上完全分辨不出来，看上去像「线断了」。
   用实心灰 + 一圈底色描边，才能从竖线里「抠」出来一个点。 */
.gp-tl-dot-log{
  top:14px;
  /* 直径取奇数 7（不是 6）、偏移取 -3.5（不是 -3）：
     偶数直径的中心会落在整数格，与其它节点（9/11px，中心在 .5 格）差半像素。
     统一到奇数直径后，全条主干上每一颗点的中心都严格同格。 */
  width:7px;
  height:7px;
  background:var(--gp-ink-3);
  border:1.5px solid var(--gp-panel);
  box-shadow:0 0 0 1px var(--gp-line-2);
  left:calc(var(--gp-rail) - 3.5px);
}
/* 首尾的「开始 / 结束」节点：比普通节点更大更实，颜色后台可配。
   半径 5.5px（直径 11），left 的 -5.5px 就是它的半径。 */
.gp-tl-dot-edge{
  top:12px;
  width:11px;
  height:11px;
  background:var(--gp-node-color, var(--gp-accent));
  border:2px solid var(--gp-panel);
  box-shadow:0 0 0 2px var(--gp-node-color, var(--gp-accent));
  /* 中线布局：主干本身就在 rail 上，圆点中心直接对 rail，不再减 pad */
  left:calc(var(--gp-rail) - 5.5px);
}
/* 文章打卡：用「方块」点而非圆点，形状本身就和子计划(圆)/日志(圆)/首尾(大圆)区分开，
   一眼认出这是一篇文章的打卡。颜色用主题强调色，所有打卡节点统一，不和类型色打架。 */
.gp-tl-dot-post{
  /* ⚠️ 尺寸/偏移刻意与默认圆点（.gp-tl-dot，9px / -4.5px）保持同一基准：
     直径取奇数、偏移取半像素，中心才会落在同一个格上、和整条主干的圆点
     严格同列。早先写成 8px / -4px，虽然数学上中心也是 rail-pad，
     但奇偶像素取整后中心差半像素，放大看能看出「这颗点比别的偏了一点」。 */
  top:14px;
  width:9px;
  height:9px;
  border-radius:2px;
  background:var(--gp-accent);
  border:1.5px solid var(--gp-panel);
  box-sizing:border-box;
  left:calc(var(--gp-rail) - 4.5px);
}

/* ------------------------------------------------------------------
   节点卡片
   两种形态：
     <summary class="gp-tl-card">  可以展开（自带折叠语义 + 键盘可达）
     <span class="gp-tl-card is-plain">  没有额外内容，不给展开箭头
   ------------------------------------------------------------------ */
.gp-tl-card{
  display:flex;
  flex-direction:column;
  gap:6px;
  padding:11px 13px;
  border:1px solid var(--gp-line);
  border-radius:var(--gp-radius-sm);
  background:var(--gp-bg-soft);
  text-decoration:none;
  color:inherit;
  transition:background .16s ease, border-color .16s ease, transform .16s ease;
}
/* 可展开的卡片是 summary，要点它 —— 鼠标必须变成手型 */
summary.gp-tl-card{ cursor:pointer; }
.gp-tl-card:hover{
  background:var(--gp-bg-card);
  border-color:var(--gp-line-2);
  transform:translateX(2px);
}
/* ⚠️ summary 的原生焦点框在 Safari 上经常不显示，且我们给 summary 加了
   display:flex（原生三角会消失），焦点样式必须自己补上。 */
summary.gp-tl-card:focus-visible{
  outline:2px solid var(--gp-cat, var(--gp-accent));
  outline-offset:2px;
}
.gp-tl-card.is-plain:hover{ transform:none; }
/* 首尾节点：不参与 hover 位移，它是这条线的界桩不是内容 */
.gp-tl-card.is-edge{
  /* ⚠️ 2026-09-25：原来 transparent —— 胶囊（主干）整根从路牌里透出来，
     用户反馈「这个和时间线重叠了，ui 错了」。改回不透明底色：牌子压在
     线上时把线盖住，只剩「牌子骑杆」的干净效果。
     ⚠️ 必须用 --gp-panel（浅/深色主题里都是实色）；--gp-bg-soft 是
     6% 透明度的辅助色，盖不住线（实测仍透）。 */
  background:var(--gp-panel);
  border-style:dashed;
  padding:7px 12px;
}
.gp-tl-card.is-edge:hover{ transform:none; background:var(--gp-panel-ph); }

/* 隐藏原生三角：flex 布局下它已经不渲染，但 Safari 偶尔会留个位置。
   ⚠️ 藏掉之后必须自己画箭头（见 .gp-tl-chev），否则没有任何展开提示。 */
summary.gp-tl-card{ list-style:none; }
summary.gp-tl-card::-webkit-details-marker{ display:none; }
summary.gp-tl-card::marker{ content:""; }

/* 自绘展开箭头。用真实元素而不是伪元素：
   伪元素画的字屏幕阅读器读不到，而 <summary> 的展开语义已经由原生
   details/summary 承担，这里只是视觉提示，所以标 aria-hidden。

   ⚠️ 为什么用 ▸ / ▾ 两个字符而不是「一个字符转 45 度」：
      转 45 度得到的是 ↙ / ↘ 这种斜箭头，在卡片圆角旁边看着像个多余的逗号
      —— 第一版就这么写的，截图里非常明显。真正的三角字符才是「可展开」
      的通用符号，而且不用管旋转中心偏没偏。
   用 ::before 的 content 换字符：改一个属性就够了，不用动布局。 */
.gp-tl-chev{
  position:absolute;
  right:9px;
  top:9px;
  width:16px;
  height:16px;
  font-size:9px;
  line-height:16px;
  text-align:center;
  color:var(--gp-ink-3);
  opacity:.75;
  transition:transform .18s ease, opacity .16s ease;
}
.gp-tl-chev::before{ content:"\25B8"; }
.gp-tl-details[open] > summary .gp-tl-chev::before{ content:"\25BE"; }
summary.gp-tl-card:hover .gp-tl-chev{ opacity:1; }

/* 展开区。
   ⚠️ 用 padding-top 而不是 margin-top：margin 会和里面 <ul> 的
      自带 margin 塌陷，间距时大时小；padding 不参与塌陷，稳定。
      里面的子 ul 还要显式 margin-top:0，避免叠加出双倍间距。 */
.gp-tl-open{
  padding:9px 0 2px;
}
.gp-tl-open > .gp-tl{ margin-top:0; }

/* 子计划展开后的信息块（收起时节点只显示日期+标题） */
.gp-tl-open .gp-tl-sub{
  display:flex;
  flex-direction:column;
  gap:6px;
  margin:0 0 10px;
  padding:10px 12px;
  border:1px dashed var(--gp-line-2);
  border-radius:var(--gp-radius-sm);
  background:var(--gp-bg-soft);
}
.gp-tl-open .gp-tl-sub:last-child{ margin-bottom:2px; }
.gp-tl-open-text{
  margin:0;
  padding:10px 12px;
  border-left:2px solid var(--gp-line-2);
  color:var(--gp-ink-2);
  font-size:12.5px;
  line-height:1.75;
  word-break:break-word;
}

/* ------------------------------------------------------------------
   日志小卡片（本版新增）
   ------------------------------------------------------------------
   日志和右侧文章卡是**两种不同的卡片**，视觉上要能一眼分开：

     文章卡：有「打卡」标签行、有标题、能展开看摘要 —— 是「一条记录」
     日志卡：只有正文 + 缩略图 + 右下角日期 —— 是「一段随手记」

   所以日志卡刻意做得更**素**：
     · 更小的内边距、更小的字号
     · 去掉顶部那行 kind 标签（日志本来就统一是日志，不需要再声明）
     · 日期不再占左侧列，而是贴着卡片右下角
   ------------------------------------------------------------------ */
.gp-tl-card.is-log{
  /* 日志改用**专属卡片样式**：
     用户要求「日志卡片也挪到右边，但要用特殊一点的卡片」——
     现在日志和打卡同处右列，必须一眼能区分开，所以给它：
       · 左侧一道强调色竖条（不再用中性灰）
       · 暖色便签底（--gp-bg-soft，Lo-Fi 手帐的纸感）
       · 不对称圆角（左窄右宽，像一张贴上去的便签）
       · 正文改斜体感的衬线？—— 不，保持可读，只加左条 + 底色 + 圆角。 */
  border-left:3px solid var(--gp-accent);
  background:var(--gp-bg-soft);
  border-radius:4px 14px 14px 4px;
  padding:9px 13px 8px;
  gap:7px;
}
.gp-tl-card.is-log .gp-tl-text{
  font-size:12.5px;
  line-height:1.65;
  color:var(--gp-ink-2);
}
/* 日期钉在卡片右下角。
   ⚠️⚠️ 不能用 align-self:flex-end！
   `.gp-tl-item.side-l > .gp-tl-card{display:block}`（0,2,0）压过了
   `.gp-tl-card{display:flex}`（0,1,0）—— 定位规则把卡片强制成块级，
   align-self 在块级容器里**完全无效**，实测日期仍然贴左（右边空 114px）。
   改用最朴素也最稳的块级办法：日期自己是一个块，text-align:right 靠右。
   这样不管父级是 flex 还是 block 都对。 */
.gp-tl-card.is-log .gp-tl-card-date{
  display:block;
  text-align:right;
  margin-top:1px;
  font-size:10.5px;
  color:var(--gp-ink-3);
  opacity:.85;
}

/* ------------------------------------------------------------------
   日志配图：缩略图（点击放大）
   ------------------------------------------------------------------
   ⚠️ 本版从「悬浮放大」改成「点击放大」。
      原因：悬浮放大在**触摸屏上根本触发不了**，手机上永远看不到大图；
      而且悬浮时大图压在正文上是"临时弹出"的感觉，想边看图边读字做不到。
      点击 → :target 铺出独立大图层，看着更稳、手机也能用。
   ------------------------------------------------------------------ */
.gp-tl-shots{
  display:flex;
  flex-wrap:wrap;
  gap:8px;
}
a.gp-tl-shot{
  position:relative;
  display:block;
  width:72px;
  height:72px;
  border-radius:8px;
  overflow:hidden;
  border:1px solid var(--gp-line);
  background:var(--gp-panel-ph);
  text-decoration:none;
}
a.gp-tl-shot img{
  display:block;
  width:100%;
  height:100%;
  /* 缩略图统一裁成正方形：这里要的是「一排整齐的格子」 */
  object-fit:cover;
  transition:transform .18s ease, filter .18s ease;
}
a.gp-tl-shot:hover img{
  transform:scale(1.06);
  filter:brightness(1.06);
}
/* 右下角一个小「放大」提示角标，暗示可以点 */
a.gp-tl-shot::after{
  content:"";
  position:absolute;
  right:4px;
  bottom:4px;
  width:11px;
  height:11px;
  border-right:2px solid #fff;
  border-bottom:2px solid #fff;
  opacity:.75;
  pointer-events:none;
}

/* ------------------------------------------------------------------
   配图大图层（纯 CSS :target 放大）
   ------------------------------------------------------------------ */
.gp-lightbox{
  display:none;
  position:fixed;
  inset:0;
  z-index:60;
  align-items:center;
  justify-content:center;
}
/* 只有「当前 :target 的那一张」才显示 */
.gp-lightbox:target{
  display:flex;
}
.gp-lightbox-veil{
  position:absolute;
  inset:0;
  background:rgba(20,18,15,.78);
  backdrop-filter:blur(3px);
  -webkit-backdrop-filter:blur(3px);
}
.gp-lightbox-box{
  position:relative;
  z-index:1;
  display:block;
  max-width:min(92vw, 1100px);
  max-height:88vh;
  padding:8px;
  border-radius:12px;
  background:var(--gp-panel);
  border:1px solid var(--gp-line);
  box-shadow:0 30px 80px -20px rgba(0,0,0,.7);
}
.gp-lightbox-box img{
  display:block;
  max-width:100%;
  max-height:calc(88vh - 60px);
  object-fit:contain;
  border-radius:8px;
}
.gp-lightbox-cap{
  display:block;
  padding:9px 4px 3px;
  font-size:12px;
  line-height:1.6;
  color:var(--gp-ink-2);
  text-align:center;
}
.gp-lightbox-x{
  position:absolute;
  top:14px;
  right:16px;
  z-index:2;
  width:36px;
  height:36px;
  display:flex;
  align-items:center;
  justify-content:center;
  border-radius:50%;
  background:rgba(255,255,255,.14);
  border:1px solid rgba(255,255,255,.28);
  color:#fff;
  font-size:15px;
  text-decoration:none;
}
.gp-lightbox-x:hover{
  background:rgba(255,255,255,.24);
}

.gp-tl-head{
  display:flex;
  align-items:center;
  flex-wrap:wrap;
  gap:7px;
  font-size:11.5px;
}
.gp-tl-kind{
  padding:1px 8px;
  border-radius:999px;
  background:var(--gp-bg-card);
  border:1px solid var(--gp-line);
  color:var(--gp-ink-3);
  font-size:10.5px;
  letter-spacing:.02em;
}
/* 首尾节点的「开始 / 结束」标签：**不带自己的边框**。
   ──────────────────────────────────────────────────────────
   原来的样子：外层 .gp-tl-card.is-edge 是虚线框，内层 .gp-tl-kind.is-edge
   又是带 node-color 的实线胶囊 —— **两个框套在一起**，截图里就是
   「一个虚线大方框里塞着一个绿色小胶囊」，视觉上又碎又吵。
   路牌的正确语言是**一块牌子**：一个边框 + 一行字。
   这里把内层胶囊的边框去掉，只留字体颜色（延续和圆点的呼应），
   外层虚线框就是唯一的「牌子轮廓」。 */
.gp-tl-kind.is-edge{
  background:transparent;
  border:0;
  padding:0;
  border-radius:0;
  color:var(--gp-node-color, var(--gp-accent));
  font-weight:700;
  font-size:12.5px;
  letter-spacing:.12em;
}
/* 「打卡」标签：文章打卡节点的身份标识，用强调色描出来，和圆点（方块）呼应 */
.gp-tl-kind.is-post{
  background:transparent;
  border-color:var(--gp-accent);
  color:var(--gp-accent);
  font-weight:600;
}
.gp-tl-when{
  color:var(--gp-ink-3);
  font-variant-numeric:tabular-nums;
}
.gp-tl-title{
  display:block;
  font-size:14.5px;
  font-weight:620;
  line-height:1.45;
}
/* 完整正文。不截断 —— 时间线是「读」的地方，不是「扫」的地方。
   word-break 兜住超长 URL / 连续英文，否则会把卡片撑出横向滚动。 */
.gp-tl-text{
  display:block;
  color:var(--gp-ink-2);
  font-size:13px;
  line-height:1.7;
  word-break:break-word;
}
.gp-tl-more{
  align-self:flex-start;
  padding:1px 8px;
  border-radius:999px;
  background:var(--gp-bg-card);
  border:1px solid var(--gp-line);
  color:var(--gp-ink-3);
  font-size:10.5px;
}
/* 打卡节点展开区里的「阅读全文 →」：纯文本链接，不抢卡片标题的视觉权重 */
.gp-tl-post-link{
  display:inline-block;
  margin-top:2px;
  padding:3px 0;
  color:var(--gp-accent);
  font-size:12.5px;
  font-weight:600;
  text-decoration:none;
}
.gp-tl-post-link:hover{
  text-decoration:underline;
}
.gp-tl-meter{
  display:block;
  height:4px;
  border-radius:999px;
  background:var(--gp-bg-card);
  box-shadow:inset 0 0 0 1px var(--gp-line);
  overflow:hidden;
}
.gp-tl-meter > span{
  display:block;
  height:100%;
  border-radius:999px;
  background:var(--gp-cat, var(--gp-accent));
}
.gp-tl-round .gp-tl-meter > span{ background:var(--gp-archived); }
.gp-tl-meta{
  display:block;
  font-size:11px;
  color:var(--gp-ink-3);
  font-variant-numeric:tabular-nums;
}

/* --------------------------- 空态 / 提示 --------------------------- */
.gp-empty{
  padding:64px 24px;
  border:1px dashed var(--gp-line-2);
  border-radius:var(--gp-radius);
  text-align:center;
  color:var(--gp-ink-3);
  font-size:14px;
  background:var(--gp-bg-soft);
}
.gp-empty::before{
  content:"";
  display:block;
  width:34px;
  height:34px;
  margin:0 auto 14px;
  border:1.5px dashed var(--gp-line-2);
  border-radius:50%;
}

/* --------------------------- 响应式 --------------------------- */
/* 中间档（641~1000px）：平板 / 窄窗口。
   面板宽 = min(1040px, 92vw)，这个区间里 92vw 说了算。
   以视口 900px 为例：面板 828，去掉内边距后 ul ≈ 828。
   目标：主干**保持居中** → rail 同样给 50%（面板 96vw 时依旧自动对半分）。
  ⚠️ 本版没有日期列（日志日期进了卡片右下角），date-w 给 0。
  ⚠️ 只改 --gp-*-base，实际值由 var() 派生，圆点/竖线/连线跟着走。 */
@media (max-width:1080px){
  .gp-modal-panel{ max-width:min(1040px, 96vw); }
  /* ⚠️ 只覆盖**第 1 层**（.gp-tl-d1），不能写 .gp-tl ——
     !important 声明的优先级高于内联样式，一旦写成 .gp-tl，
     子层内联的 --gp-rail-base:10px 会被这条直接盖掉，
     子卡片立刻塌成 26~45px 的竖条（实测就是这么塌的）。
     子时间线本来就退回单侧、有自己的紧凑几何，断点里不该去碰它。 */
  .gp-tl-d1{
    --gp-date-w-base:0px !important;
    --gp-rail-base:50% !important;
    --gp-pad-base:0px !important;
    --gp-right-w-base:0px !important;
  }
}
@media (max-width:640px){
  .gp-wrap{ font-size:14.5px; padding-bottom:40px; }
  .gp-head h1{ font-size:24px; }
  .gp-grid{
    grid-template-columns:repeat(auto-fill, minmax(150px, 1fr));
    gap:12px;
  }
  .gp-card-title{ font-size:14px; }
  .gp-card-main{ padding:11px 11px 12px; }
  .gp-card-cover-ini{ font-size:26px; }
  .gp-card-count{ display:none; }
  .gp-group{ margin-bottom:40px; }

  /* 模态在窄屏上铺满，圆角收一档 */
  .gp-modal{ padding:0; }
  .gp-modal-panel{
    max-width:none;
    max-height:100%;
    height:100%;
    border-radius:0;
    border:none;
    padding:20px 16px 26px;
  }
  .gp-modal-head{ gap:11px; padding-right:30px; }
  .gp-modal-cover{ width:70px; }
  .gp-modal-title{ font-size:17px; }

  /* 窄屏「去中线化」：390px 的屏，主干居中后左右各只剩不到 100px，
     两边的卡片都会被压成一条竖着的窄条，正文完全没法读。
     所以窄屏直接把**分侧规则整体撤销**，退回「单侧时间线」——
     主干贴到最左边，所有卡片（无论记录还是打卡）统一列在主干右侧。

     这里仍然只改**变量 + 两条侧别规则**，不改任何散落的 left：
     圆点/竖线/连接线全都是从变量推导出来的，改一次全部跟着走。
     侧别规则用 `margin-*:auto` 归零的写法覆盖，保证 specificity 压得住
     （`.gp-tl-item.side-l > .gp-tl-card` 是 0,2,0；这里再加 .gp-tl 前缀到 0,3,0）。

     ⚠️ 组内右侧留白：窄屏 rail 贴左后，右卡片的 padding-left 会跟着变小，
        卡片就顶到屏幕上沿 —— 所以 rail 不能真的取 0，留 9px 给圆点半径。 */
  /* ⚠️ 同样是**只覆盖第 1 层**：!important 会盖掉子层的内联值，
     写 .gp-tl 会让深层几何一起被改，子卡片直接塌掉。 */
  .gp-tl-d1{
    --gp-date-w-base:0px !important;
    --gp-rail-base:9px !important;
    --gp-pad-base:0px !important;
    --gp-right-w-base:0px !important;
    /* 窄屏连接线缩到 12px：22px 在 390px 的屏上占比太夸张，
       而且窄屏是单侧布局，线长了会把卡片一路推右、正文没地方放。 */
    --gp-link-base:12px !important;
  }
  /* 窄屏下的子时间线：几何表里给的 rail 是 10px，窄屏再压到 4px，
     把横向缩进压到几乎为零 —— 窄屏宽度金贵，层级靠圆点大小表达。 */
  .gp-tl.gp-tl-d2,
  .gp-tl.gp-tl-d3,
  .gp-tl.gp-tl-d4{
    --gp-rail-base:4px !important;
    --gp-link-base:10px !important;
  }
  /* 撤销左侧卡片：窄屏不再左挂，统一走右侧（记录/日志/子计划/首尾都归右） */
  .gp-tl .gp-tl-item.side-l > details,
  .gp-tl .gp-tl-item.side-l > .gp-tl-card,
  .gp-tl .gp-tl-item.side-l > .gp-tl-details{
    margin-left:calc(var(--gp-rail) + 14px);
    margin-right:0;
  }
  /* 撤销左连接线：只保留右侧那条（::after 由 .side-r 规则提供） */
  .gp-tl .gp-tl-item.side-l::after{ content:none; }
  /* ⚠️⚠️ 窄屏必须**放弃居中**，改成和日志/子计划一样的右挂。
     原因：窄屏 rail 被压到 9px（几乎贴左缘）。居中 = 卡片中轴对 rail，
     于是卡片左半部分整个跑到屏幕外 —— 实测 390px 屏上「开始/结束」
     卡片只剩右半截，「2026年4月1日」被切掉一半，背景也没了。
     窄屏本来就已经「统一右挂」（见上面 .side-l 的撤销规则），
     路牌跟着走右侧才是一致的行为：线在最左，卡片一列排下去。
     做法：撤销绝对定位，回到普通流；左移到 rail + 连接线之后。
     同时把残留的 padding-top（为绝对定位撑高用的）也收掉。 */
  .gp-tl .gp-tl-item.side-c{
    padding-top:0;
    padding-bottom:0;
  }
  .gp-tl .gp-tl-item.side-c > .gp-tl-card,
  .gp-tl .gp-tl-item.side-c > details,
  .gp-tl .gp-tl-item.side-c > .gp-tl-details{
    position:static;
    transform:none;
    width:auto;
    max-width:100%;
    /* 与 .side-r 同款：从主干右侧 + 连接线处起排 */
    margin-left:calc(var(--gp-rail) + 14px);
    margin-right:0;
    text-align:left;
    white-space:normal;
    /* ⚠️ 路牌卡片要 flex row：顶层是 `display:block`（给绝对定位日期当锚点），
       窄屏日期改回 static 后，head / when 会各占一行、路牌被撑成两行。
       跟子层一样，用 flex row 把它们并回一行。 */
    display:flex;
    flex-direction:row;
    flex-wrap:nowrap;
    align-items:center;
    gap:0;
    /* 顶层为日期预留的对称 58px 内边距也要收掉 —— 窄屏卡片本来就窄，
       再留 58px 会把「开始」压成一条。 */
    padding:7px 12px;
  }
  .gp-tl .gp-tl-item.side-c .gp-tl-head{
    flex-wrap:nowrap;
    justify-content:flex-start;
  }
  /* ⚠️ 窄屏路牌已经**退回右挂**（上面撤销了绝对定位），
     所以顶层那套「日期钉在中轴右侧 20px」必须一起撤销 ——
     不撤销的话，`left:calc(50% + 20px)` 的 50% 会按**满宽卡片**解析，
     日期被推到卡片中间偏右（实测 600px 屏上卡片 40→583，
     日期却落在 331→383，距左缘 291px），看着像悬在半空。
     窄屏要的是最朴素的「标签 + 日期」紧挨着、左对齐。 */
  .gp-tl .gp-tl-item.side-c > .gp-tl-card > .gp-tl-when.is-edge{
    position:static;
    left:auto;
    top:auto;
    transform:none;
    margin-left:8px;
    font-size:11px;
  }
  /* 窄屏路牌要让它「接地」：给一条到主干的连接线，否则它像是凭空挂在右边 */
  .gp-tl .gp-tl-item.side-c::after{
    content:"";
    position:absolute;
    top:22px;
    left:var(--gp-rail);
    width:14px;
    height:1px;
    background:var(--gp-line-2);
  }
  /* 窄屏恢复圆点：不再居中，圆点重新变成「接线柱」，不会盖住字 */
  .gp-tl .gp-tl-item.side-c > .gp-tl-dot{ display:block; }
  /* 窄屏路牌退回右挂 → 主干不需要为首尾路牌收边，
     恢复原跨度（否则线会在顶部/底部空出一大截）。 */
  .gp-tl.gp-tl-d1::before,
  .gp-tl.gp-tl-d2::before{
    top:14px;
    bottom:14px;
  }
  .gp-tl .gp-tl-item.side-r{ padding-left:calc(var(--gp-rail) + 14px); }
  /* 深层圆点再收小一圈，让「层级」靠点的大小也能读出来 */
  .gp-tl.gp-tl-d2 .gp-tl-dot,
  .gp-tl.gp-tl-d3 .gp-tl-dot,
  .gp-tl.gp-tl-d4 .gp-tl-dot{
    width:7px;
    height:7px;
    left:calc(var(--gp-rail) - 3.5px);
  }
  /* 窄屏卡片内边距收一档；右padding 给展开箭头，不用 30px 那么多 */
  summary.gp-tl-card{ padding-right:26px; }
  .gp-tl-card{ padding:10px 11px; }
  .gp-tl-title{ font-size:13.5px; }
  .gp-tl-text{ font-size:12.5px; }
  /* 深层卡片直接去掉边框，靠左侧色条+缩进表达从属，省宽度 */
  .gp-tl.gp-tl-d2 .gp-tl-card,
  .gp-tl.gp-tl-d3 .gp-tl-card,
  .gp-tl.gp-tl-d4 .gp-tl-card{
    padding:9px 10px;
  }
  /* 日期：窄屏没有横向空间留给日期列。
     ⚠️ 结构上 <time> 是 <li> 的子元素、排在卡片**之前**，
        所以这里不能用 `position:static` —— 那会让它跑到卡片上方
        独立占一行，和卡片之间空开，看起来像条游离的注释。
        正确做法：取消绝对定位、改成**紧跟卡片上沿的小字**，
        用负 margin 把它拉进卡片顶部的视觉区域。 */
  .gp-tl-date{
    position:static;
    display:block;
    width:auto;
    text-align:left;
    font-size:11px;
    margin:0 0 -2px calc(var(--gp-rail) + 14px);
    padding-left:2px;
  }
  /* 窄屏缩略图小一圈，否则一排只能放两个 */
  .gp-tl-shot{ width:64px; height:64px; }
  /* 放大图不能超过屏幕：42vw 在 390px 上只有 164px，太小 */
  .gp-tl-shot:hover img{
    width:min(78vw, 320px);
    max-height:min(50vh, 320px);
  }
  .gp-tl-shot:hover .gp-tl-shot-cap{
    top:calc(min(50vh, 320px) + 8px);
    width:min(78vw, 320px);
  }
}
/* 窄屏 + 深色：收窄内边距，避免小屏上内容被挤得太窄 */
@media (max-width:640px){
  html.darkmode .gp-wrap{ padding:22px 16px 48px; border-radius:16px; }
}

/* 尊重「减少动态效果」偏好：动画全部关掉。
   模态的入场动画对前庭敏感用户不友好，这是明确的系统级信号。 */
@media (prefers-reduced-motion: reduce){
  .gp-card,
  .gp-card-cover img,
  .gp-tl-card,
  .gp-card-fill,
  .gp-modal-panel,
  .gp-modal-x{
    transition:none !important;
    animation:none !important;
    transform:none !important;
  }
  .gp-card:hover{ transform:none; }
}

/* ==================================================================
   [Lo-Fi zine 手帐层 2026-09-22] Riso 印刷 / 拍纸本质感
   只加视觉，不改结构；全部纯 CSS，零新请求。
   ================================================================== */

/* ---------- 纸面 + 顶部胶带 ---------- */
.gp-wrap{
  position:relative;
  font-family:var(--gp-hand);
  background:
    radial-gradient(rgba(43,36,28,.045) 1px, transparent 1.2px) 0 0/6px 6px,
    linear-gradient(180deg, rgba(255,253,247,.55), rgba(255,253,247,0) 46%),
    var(--gp-paper);
  border:1px solid var(--gp-line);
  border-radius:3px;
  box-shadow:10px 12px 0 rgba(43,36,28,.08);
  padding:26px 30px 60px;
}
.gp-wrap::before{
  content:"";
  position:absolute;
  top:-10px;
  left:50%;
  width:148px;
  height:26px;
  transform:translateX(-50%) rotate(-1.6deg);
  background:
    repeating-linear-gradient(90deg, rgba(255,255,255,.20) 0 3px, rgba(255,255,255,0) 3px 7px),
    var(--gp-tape);
  box-shadow:0 2px 3px rgba(43,36,28,.14);
  pointer-events:none;
}

/* ---------- 字体分层：宋体标题 / 楷体正文 / 打字机数字 ---------- */
.gp-head h1{
  font-family:var(--gp-serif);
  font-size:34px;
  letter-spacing:.05em;
  text-shadow:3px 0 rgba(232,76,158,.30), -3px 0 rgba(15,107,168,.28);
}
.gp-group-head h2{ font-family:var(--gp-serif); }
.gp-modal-title{
  font-family:var(--gp-serif);
  text-shadow:2px 0 rgba(232,76,158,.26), -2px 0 rgba(15,107,168,.24);
}
.gp-tl-date,.gp-year,.gp-card-num,.gp-modal-num,.gp-overview b{ font-family:var(--gp-mono); }

/* ---------- 页头 / 分组：针脚分隔线 ---------- */
.gp-head{ border-bottom:1px dashed var(--gp-line-2); }
.gp-group-head{
  border-bottom:1px dashed var(--gp-line);
  padding-bottom:9px;
  margin-bottom:10px;
}
.gp-group-desc{ margin-bottom:20px; }

/* ---------- 概览：小票印章 ---------- */
.gp-overview li{
  border:1px dashed var(--gp-line-2);
  border-radius:3px;
  background:var(--gp-paper-2);
}
.gp-overview b{ color:var(--gp-ink); }

/* ---------- 筛选胶囊：索引标签 ---------- */
.gp-chip{
  border-radius:3px;
  border:1px solid var(--gp-line-2);
  background:var(--gp-paper-2);
  color:var(--gp-ink-2);
}
.gp-chip:hover{
  background:var(--gp-paper-3);
  border-color:var(--gp-ink-3);
  color:var(--gp-ink);
}
.gp-chip.is-current{
  border-color:transparent;
  background:var(--gp-ink);
  color:var(--gp-chip-on,#F8F2E2);
  transform:translate(-1px,-1px);
  box-shadow:2px 2px 0 var(--gp-offsh);
}
.gp-chip em{ font-family:var(--gp-mono); }
.gp-filters-archive{ border-bottom:1px dashed var(--gp-line); }

/* ---------- 卡片：贴纸便签 ---------- */
.gp-card{
  background:var(--gp-paper-2);
  border:1px solid var(--gp-line-2);
  border-radius:5px;
}
.gp-card:hover{
  border-color:var(--gp-ink-3);
  box-shadow:3px 4px 0 var(--gp-offsh);
}
.gp-card-item:nth-child(3n+1) .gp-card{ rotate:-.45deg; }
.gp-card-item:nth-child(3n+2) .gp-card{ rotate:.3deg; }
.gp-card-item .gp-card:hover,
.gp-card-item .gp-card:focus-visible{ rotate:0deg; }
.gp-card-item{ position:relative; }
.gp-card-item:nth-child(3n+1)::before{
  content:"";
  position:absolute;
  z-index:3;
  top:-8px;
  left:16px;
  width:64px;
  height:17px;
  transform:rotate(-5deg);
  background:
    repeating-linear-gradient(90deg, rgba(255,255,255,.22) 0 3px, rgba(255,255,255,0) 3px 7px),
    var(--gp-tape);
  box-shadow:0 1px 2px rgba(43,36,28,.16);
  pointer-events:none;
}
/* 无封面占位：半调网点 */
.gp-card-cover-ph{
  background:
    radial-gradient(rgba(43,36,28,.12) 1.1px, transparent 1.3px) 0 0/7px 7px,
    var(--gp-paper-3);
}
.gp-card-cover-ini{ font-family:var(--gp-serif); }
.gp-card-badges .gp-tag{ rotate:-1.2deg; }
.gp-card-badges .gp-year{ rotate:.8deg; }
.gp-card-title{ font-size:16px; }

/* ---------- 进度条：丝网印刷填充 ---------- */
.gp-card-bar,.gp-tl-meter{
  box-sizing:border-box;
  height:7px;
  border-radius:3px;
  border:1px dashed var(--gp-line-2);
  background:transparent;
  box-shadow:none;
}
.gp-wrap .gp-card-item .gp-card-fill,
.gp-wrap .gp-tl-meter > span{
  border-radius:2px;
  background-image:repeating-linear-gradient(-45deg, rgba(255,255,255,.30) 0 2px, rgba(255,255,255,0) 2px 5px);
}

/* ---------- 标签：图章小票 ---------- */
.gp-tag{
  background:transparent;
  border:0;
  box-shadow:inset 0 0 0 1px currentColor;
  border-radius:3px;
  padding:1px 8px;
  rotate:-.8deg;
}
.gp-tl-kind{ border-radius:3px; border-style:dashed; }
.gp-tl-kind.is-edge{ font-family:var(--gp-serif); }

/* ---------- 弹窗：印刷版面 ---------- */
.gp-modal-veil{ backdrop-filter:none; -webkit-backdrop-filter:none; }
.gp-modal-panel{
  border-radius:6px;
  border:1px solid var(--gp-line-2);
  outline:1px dashed var(--gp-line-2);
  outline-offset:-7px;
  box-shadow:7px 9px 0 var(--gp-offsh), 0 42px 80px -40px rgba(43,36,28,.55);
  rotate:-.25deg;
}
.gp-modal-x{
  background:transparent;
  border:1px dashed var(--gp-line-2);
  border-radius:50%;
}
.gp-modal-x:hover{ background:var(--gp-ro-pink); color:#F8F2E2; border-color:transparent; }
.gp-modal-cover{
  border-radius:3px;
  border-color:var(--gp-line-2);
  background-image:radial-gradient(rgba(43,36,28,.12) 1.1px, transparent 1.3px);
  background-size:7px 7px;
}

/* ---------- 时间线：票据与便签 ---------- */
.gp-tl-date{
  font-family:var(--gp-mono);
  letter-spacing:.02em;
  border-bottom:1px dashed var(--gp-line-2);
  padding-bottom:1px;
  rotate:-.8deg;
}
.gp-tl-card{
  background:var(--gp-paper-2);
  border:1px solid var(--gp-line-2);
  border-radius:4px;
}
.gp-tl-card:hover{
  background:var(--gp-paper-2);
  border-color:var(--gp-ink-3);
}
.gp-tl-item .gp-tl-card{ rotate:-.3deg; }
.gp-tl-item:nth-child(even) .gp-tl-card{ rotate:.25deg; }
.gp-tl-item .gp-tl-card:hover,
.gp-tl-item .gp-tl-card:focus-visible{ rotate:0deg; }
.gp-tl-open .gp-tl-sub{
  background:var(--gp-paper-2);
  box-shadow:2px 2px 0 var(--gp-offsh);
}

/* ---------- 日志配图：拍立得 ---------- */
.gp-tl-shot{
  box-sizing:border-box;
  width:78px;
  height:78px;
  padding:4px 4px 10px;
  border-radius:2px;
  border:1px solid var(--gp-line-2);
  background:var(--gp-paper-2);
  box-shadow:1px 2px 0 var(--gp-offsh);
  rotate:-1deg;
}
.gp-tl-shot:nth-child(even){ rotate:1.2deg; }
.gp-tl-shot:hover{ rotate:0deg; }
.gp-tl-shot img{ border-radius:1px; }
.gp-tl-shot:hover img{
  border-radius:4px;
  border:1px solid var(--gp-line-2);
  background:var(--gp-paper-2);
  box-shadow:6px 8px 0 rgba(43,36,28,.18);
}
.gp-tl-shot:hover .gp-tl-shot-cap{
  background:var(--gp-paper-2);
  border:1px solid var(--gp-line-2);
  box-shadow:6px 8px 0 rgba(43,36,28,.15);
}

/* ---------- 空态 ---------- */
.gp-empty{
  border-radius:4px;
  background:
    radial-gradient(rgba(43,36,28,.05) 1px, transparent 1.2px) 0 0/7px 7px,
    var(--gp-paper-2);
}

/* ---------- 响应式补丁 ---------- */
@media (max-width:640px){
  .gp-wrap{ padding:18px 14px 44px; }
  .gp-head h1{ font-size:26px; text-shadow:2px 0 rgba(232,76,158,.30), -2px 0 rgba(15,107,168,.28); }
  .gp-modal-panel{ rotate:0deg; }
  .gp-card-item:nth-child(3n+1)::before{ left:10px; width:52px; }
}
</style>

<?php
// 关闭所有模态的公共回跳锚点。
// 纯 CSS 模态靠 :target 显隐，而 :target 只能指向一个元素 ——
// 「关闭」就是把地址改成这个锚点，让所有 .gp-modal:target 全部失配。
// 它故意是个零尺寸的 span：视觉上不存在，但必须在文档里，
// 否则 href="#gp-grid" 会滚到页面最底部（浏览器找不到锚点就跳顶/跳底）。
?>
<span class="gp-close-anchor" id="gp-grid" aria-hidden="true"></span>
<div class="gp-wrap">

    <header class="gp-head">
        <h1><?php $this->title(); ?></h1>
        <?php if ($gpAvailable && $gpTotal > 0): ?>
            <p>
                共 <strong><?php echo (int) $gpParentCount; ?></strong> 条长计划、<strong><?php echo (int) $gpChildCount; ?></strong> 条子计划
                <span>。点任意一张卡片，看它的时间线。</span>
            </p>
            <?php if ($gpShowStatus): ?>
                <ul class="gp-overview">
                    <?php if ($gpCounts['active'] > 0): ?>
                        <li class="is-active"><b><?php echo (int) $gpCounts['active']; ?></b>进行中</li>
                    <?php endif; ?>
                    <?php if ($gpCounts['soon'] > 0): ?>
                        <li class="is-soon"><b><?php echo (int) $gpCounts['soon']; ?></b>即将到期</li>
                    <?php endif; ?>
                    <?php if ($gpCounts['pending'] > 0): ?>
                        <li><b><?php echo (int) $gpCounts['pending']; ?></b>未开始</li>
                    <?php endif; ?>
                    <?php if ($gpCounts['expired'] > 0): ?>
                        <li class="is-expired"><b><?php echo (int) $gpCounts['expired']; ?></b>已过期</li>
                    <?php endif; ?>
                    <?php if ($gpCounts['done'] > 0): ?>
                        <li class="is-done"><b><?php echo (int) $gpCounts['done']; ?></b>已完成</li>
                    <?php endif; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
    </header>

    <?php if (!$gpAvailable): ?>

        <div class="gp-empty"><?php echo Plugin::e($gpOfflineTip); ?></div>

    <?php else: ?>

        <?php
        /* ==============================================================
           卡片角标计数：主区 ∪ 归档区一起算。
           从下面原来的位置（$gpTotal 分支内）上移到这里 ——
           主区可能为空而归档区不为空，归档卡片同样要角标。
           卡片和弹窗共用同一份，避免两边各算一次算出不一样。
           ============================================================== */
        $gpChildCounts = [];
        $gpLogCounts = [];
        foreach ([$gpPlans, $gpArchivedPlans] as $gpCountSet) {
            foreach ($gpCountSet as $gpP) {
                $gpPid = (int) $gpP['id'];
                $gpLogCounts[$gpPid] = count($gpLogs[$gpPid] ?? []);
            }
        }
        // 子计划计数：从每个分组的树里数，保证和实际渲染出来的一致
        foreach ($gpGroups as $gpGroup) {
            foreach ($gpGroup['plans'] as $gpP) {
                $gpParent = (int) $gpP['parent_id'];
                if ($gpParent > 0) {
                    $gpChildCounts[$gpParent] = ($gpChildCounts[$gpParent] ?? 0) + 1;
                }
            }
        }
        // 归档区自己的子计划计数（子树跟着父计划一起进归档，在归档集合里数）
        foreach ($gpArchivedPlans as $gpP) {
            $gpParent = (int) $gpP['parent_id'];
            if ($gpParent > 0) {
                $gpChildCounts[$gpParent] = ($gpChildCounts[$gpParent] ?? 0) + 1;
            }
        }
        ?>

        <?php if ($gpTotal === 0): ?>

            <div class="gp-empty"><?php
                echo Plugin::e(!empty($gpYears) || $gpYearParam !== '' ? '当前筛选条件下没有计划。' : $gpEmptyTip);
            ?></div>

        <?php else: ?>

            <?php if ($gpYearFilterOn && count($gpYears) > 1): ?>
                <nav class="gp-filters">
                    <span class="gp-filters-label">年份</span>
                    <a class="gp-chip<?php echo $gpYearParam === '' ? ' is-current' : ''; ?>"
                       href="<?php echo Plugin::e($gpYearLink('')); ?>">全部</a>
                    <?php foreach ($gpYears as $gpYear): ?>
                        <a class="gp-chip<?php echo (string) $gpYear === $gpYearParam ? ' is-current' : ''; ?>"
                           href="<?php echo Plugin::e($gpYearLink((string) $gpYear)); ?>"><?php echo (int) $gpYear; ?></a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>

            <?php
            /* ==============================================================
               模态区：全部模态和卡片网格是兄弟关系，都在 #gp-grid 里。
               必须这样排 —— 模态不能嵌在卡片那个 <a> 里面，
               交互式内容不能嵌套（HTML 规范），浏览器会拆 DOM。

               先把这条计划要用的上下文算好，卡片和模态共用同一份，
               避免两边各算一次算出不一样的结果（比如百分比对不上）。
               ============================================================== */
            // 卡片角标计数（$gpChildCounts / $gpLogCounts）
            // 已上移到「空状态」判断之前统一计算（覆盖主区 ∪ 归档区），这里不再重算。
            ?>

            <?php foreach ($gpGroups as $gpGroup): ?>
                <?php
                $gpColor = Plugin::normalizeColor($gpGroup['color']);
                $gpStyle = '';
                if ($gpColor !== '') {
                    $gpStyle = ' style="--gp-cat:' . $gpColor . ';"';
                }
                $gpCount = count($gpGroup['plans']);
                list($gpRoots, $gpChildren) = Plugin::planTree($gpGroup['plans']);
                ?>
                <section class="gp-group">
                    <div class="gp-group-head">
                        <h2><span class="gp-dot"<?php echo $gpStyle; ?>></span><?php echo Plugin::e($gpGroup['name']); ?></h2>
                        <span class="gp-group-count"><?php echo (int) $gpCount; ?> 条</span>
                    </div>
                    <?php if ($gpGroup['description'] !== ''): ?>
                        <p class="gp-group-desc"><?php echo nl2br(Plugin::e($gpGroup['description'])); ?></p>
                    <?php else: ?>
                        <p class="gp-group-desc"></p>
                    <?php endif; ?>

                    <?php
                    /* 只给顶级计划出卡片。
                       子计划不进网格 —— 它们是「父计划时间线上的节点」，
                       塞进网格会让网格既有「计划」又有「阶段」，
                       两类东西视觉权重还一样，反而看不出主次。
                       父被年份筛选掉时 planTree() 会把子计划当顶级返回，
                       那些子计划在这里才会出现在网格上，不丢行。 */
                    ?>
                    <ul class="gp-grid">
                        <?php foreach ($gpRoots as $gpPlan): ?>
                            <?php
                            $gpPid = (int) $gpPlan['id'];
                            // id 在这里取一次，卡片和模态共用 —— 见 gp_render_card() 里的注释。
                            // 二级弹窗已经拆掉，整棵子树都在窗内渲染，
                            // 所以整页只剩「每条计划一个号」，不再需要给节点分号。
                            $gpModalId = gp_uid();
                            ?>
                            <li>
                            <?php echo gp_render_card($gpPlan, [
                                'now'         => $gpNow,
                                'show_status' => $gpShowStatus,
                                'cover_color' => $gpColor,
                                'log_count'   => $gpLogCounts[$gpPid] ?? 0,
                                'child_count' => $gpChildCounts[$gpPid] ?? 0,
                                'post_count'  => $gpPostCounts[$gpPid] ?? 0,
                                'has_rounds'  => !empty($gpRounds[$gpPid]),
                                'modal_id'    => $gpModalId,
                            ]); ?>
                            </li>

                            <?php
                            // 时间线弹窗紧跟在自己那张卡片后面：网格顺序 = DOM 顺序，
                            // :target 命中时页面不会先跳一下再回来。
                            // 整棵子树的子时间线都在这个窗内原地展开，不再单独出窗。
                            ?>
                            <?php
                            gp_render_timeline_modal($gpPlan, [
                                'now'          => $gpNow,
                                'show_status'  => $gpShowStatus,
                                'children'     => $gpChildren,
                                'logs'         => $gpLogs,
                                'rounds'       => $gpRounds,
                                'posts'        => $gpPosts,
                                'carry'        => $gpCarry,
                                'child_counts' => $gpChildCounts,
                                'log_counts'   => $gpLogCounts,
                                'modal_id'     => $gpModalId,
                                'start_color'  => $gpNodeStartColor,
                                'end_color'    => $gpNodeEndColor,
                            ]);
                            ?>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endforeach; ?>

        <?php endif; ?>

        <?php
        /* ==============================================================
           归档区：常驻页面底部（用户要求）
           - 不再用 ?gp_archived= 切换视图，这块永远在（没有归档时空着）；
           - 只给归档的顶级计划出卡片，子树收进各自弹窗里原地展开；
           - 卡片沿用 gp_render_card（自带 is-archived 压淡 + 「已归档」标签）。
           ============================================================== */
        $gpCatColors = [];
        foreach ($gpCats as $gpC) {
            $gpCatColors[(int) $gpC['id']] = Plugin::normalizeColor($gpC['color'] ?? '');
        }
        $gpArchRoots = [];
        $gpArchChildren = [];
        if (!empty($gpArchivedPlans)) {
            list($gpArchRoots, $gpArchChildren) = Plugin::planTree($gpArchivedPlans);
        }
        ?>
        <section class="gp-group gp-group-archived">
            <div class="gp-group-head">
                <h2><span class="gp-dot"></span>归档</h2>
                <span class="gp-group-count"><?php echo (int) $gpArchivedCount; ?> 条</span>
            </div>
            <?php if (!empty($gpArchRoots)): ?>
                <p class="gp-group-desc">已经收起来的计划。点卡片还能看它们的时间线，随时可以恢复。</p>
                <ul class="gp-grid">
                    <?php foreach ($gpArchRoots as $gpPlan): ?>
                        <?php
                        $gpPid = (int) $gpPlan['id'];
                        $gpModalId = gp_uid();
                        $gpColor = $gpCatColors[(int) $gpPlan['cid']] ?? '';
                        ?>
                        <li>
                        <?php echo gp_render_card($gpPlan, [
                            'now'         => $gpNow,
                            'show_status' => $gpShowStatus,
                            'cover_color' => $gpColor,
                            'log_count'   => $gpLogCounts[$gpPid] ?? 0,
                            'child_count' => $gpChildCounts[$gpPid] ?? 0,
                            'post_count'  => $gpPostCounts[$gpPid] ?? 0,
                            'has_rounds'  => !empty($gpRounds[$gpPid]),
                            'modal_id'    => $gpModalId,
                        ]); ?>
                        </li>
                        <?php
                        // 归档计划的弹窗同样紧跟卡片，:target 命中时页面不跳。
                        ?>
                        <?php gp_render_timeline_modal($gpPlan, [
                            'now'          => $gpNow,
                            'show_status'  => $gpShowStatus,
                            'children'     => $gpArchChildren,
                            'logs'         => $gpLogs,
                            'rounds'       => $gpRounds,
                            'posts'        => $gpPosts,
                            'carry'        => $gpCarry,
                            'child_counts' => $gpChildCounts,
                            'log_counts'   => $gpLogCounts,
                            'modal_id'     => $gpModalId,
                            'start_color'  => $gpNodeStartColor,
                            'end_color'    => $gpNodeEndColor,
                        ]); ?>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="gp-group-desc">还没有归档的计划。归档后的计划会收在这里，主区只保留进行中的。</p>
            <?php endif; ?>
        </section>

    <?php endif; ?>

</div>
