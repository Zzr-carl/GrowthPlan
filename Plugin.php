<?php

namespace TypechoPlugin\GrowthPlan;

use Typecho\Db;
use Typecho\Plugin\PluginInterface;
use Typecho\Router;
use Typecho\Widget\Helper\Form;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 成长计划 GrowthPlan
 *
 * 后台自定义计划类型（技能计划 / 目标计划 / 旅行计划…），
 * 每条计划带目标年份与起止日期，前台用时间线 + 进度条按日期倒计时展示。
 *
 * @package GrowthPlan
 * @author  士多啤梨zzr
 * @version 1.0.0
 * @license MIT
 * @link    https://github.com/Zzr-carl/GrowthPlan
 */
class Plugin implements PluginInterface
{
    /**
     * 插件标识
     */
    public const PLUGIN_NAME = 'GrowthPlan';

    /**
     * 前台成长计划页缩略名（独立页面 slug）
     */
    public const PAGE_SLUG = 'growth';

    /**
     * 前台页面标题
     */
    public const PAGE_TITLE = '成长计划';

    /**
     * 后台面板文件标识
     *
     * 形如「插件目录名/文件名」，必须带 .php 扩展名。
     * admin/extending.php 拿到 panel 参数后是直接
     *     require_once pluginDir(插件名) . '/' . 文件名
     * 少写扩展名就会变成 require .../GrowthPlan/manage → 500。
     */
    public const PANEL_FILE = 'GrowthPlan/manage.php';

    /**
     * 后台菜单名
     */
    public const MENU_NAME = '成长计划';

    /**
     * 前台模板文件名
     *
     * 必须带 .php 扩展名：Widget\Archive::render() 会把 contents.template
     * 直接拼到 themeDir 后面做 file_exists()，只写别名会静默回落到 page.php。
     */
    public const TEMPLATE_FILE = 'page-growth.php';

    /**
     * 计划状态
     */
    public const STATUS_PENDING  = 'pending';   // 未开始
    public const STATUS_ACTIVE   = 'active';    // 进行中
    public const STATUS_SOON     = 'soon';      // 即将到期
    public const STATUS_EXPIRED  = 'expired';   // 已过期

    /**
     * 「即将到期」的阈值（天）
     */
    public const SOON_DAYS = 30;

    /**
     * 独立页面记录缓存
     *
     * @var array|null
     */
    private static $pageRowCache = null;

    /**
     * 独立页面 ID 缓存
     *
     * @var int|null
     */
    private static $pageIdCache = null;

    /**
     * 插件设置缓存
     *
     * @var array|null
     */
    private static $configCache = null;

    // ---------------------------------------------------------------------
    // 生命周期
    // ---------------------------------------------------------------------

    /**
     * 启用插件
     *
     * @throws \Typecho\Db\Exception
     * @throws \Typecho\Plugin\Exception
     */
    public static function activate()
    {
        self::createTables();
        self::migrate();
        self::writeConfig(self::defaultConfig());

        // 先清一次旧挂载，保证重复启用不会堆叠菜单
        self::deactivate();

        self::registerPanel();

        // 文章打卡：把插件挂到文章编辑器与文章保存/删除流程上。
        self::registerHooks();
    }

    /**
     * 注册插件钩子（文章编辑器 UI + 文章保存/删除时的映射同步）
     *
     * 全部用 `=` 赋值而非累加，重复启用不会堆叠同一个回调。
     * 这里只挂「文章」(Widget_Contents_Post_Edit)；页面(page)如需同样能力，
     * 再补一条 Widget_Contents_Page_Edit 的注册即可。
     */
    private static function registerHooks(): void
    {
        // 编辑器里渲染「关联成长计划」多选框：挂后台页脚 end 事件，
        // 用 JS 把选择框移进写文章表单（表单名 write_post）。
        \Typecho\Plugin::factory('admin/footer.php')->end = [self::class, 'renderPostMetaBox'];

        // 发布成功后落库（此时 $widget->cid 已就绪）。
        \Typecho\Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = [self::class, 'onFinishPublish'];
        // 发布前过滤器：编辑「已存在」文章（含草稿）保存时也能同步；
        // 新建草稿此时 cid 还没生成，靠 finishPublish 在发布时兜底。
        \Typecho\Plugin::factory('Widget_Contents_Post_Edit')->write = [self::class, 'onWrite'];
        // 文章删除时清掉它的打卡映射，避免孤儿行。
        \Typecho\Plugin::factory('Widget_Contents_Post_Edit')->delete = [self::class, 'onDelete'];
    }

    /**
     * 禁用插件
     *
     * 只摘菜单，不删数据。重新启用后数据仍在。
     *
     * @throws \Typecho\Db\Exception
     */
    public static function deactivate()
    {
        $db = Db::get();

        $row = $db->fetchRow(
            $db->select('value')->from('table.options')
                ->where('name = ?', 'panelTable')
                ->limit(1)
        );

        if (empty($row)) {
            return;
        }

        $panelTable = @unserialize((string) $row['value']);
        if (!is_array($panelTable)) {
            return;
        }

        $encoded = urlencode(trim(self::PANEL_FILE, '/'));

        // 1) 摘 parent：按名字精确匹配，可能存在多项，全清并重排索引
        if (!empty($panelTable['parent']) && is_array($panelTable['parent'])) {
            $parents = [];
            foreach ($panelTable['parent'] as $name) {
                if ($name !== self::MENU_NAME) {
                    $parents[] = $name;
                }
            }
            $panelTable['parent'] = array_values($parents);
        }

        // 2) 摘 child：按第 3 项（url）匹配，清掉后重排索引
        if (!empty($panelTable['child']) && is_array($panelTable['child'])) {
            $children = [];
            foreach ($panelTable['child'] as $index => $items) {
                if (!is_array($items)) {
                    continue;
                }
                $kept = [];
                foreach ($items as $item) {
                    $url = is_array($item) ? ($item[2] ?? '') : '';
                    if ($url !== 'extending.php?panel=' . $encoded) {
                        $kept[] = $item;
                    }
                }
                if (!empty($kept)) {
                    $children[$index] = array_values($kept);
                }
            }
            $panelTable['child'] = $children;
        }

        // 3) 摘 file
        if (!empty($panelTable['file']) && is_array($panelTable['file'])) {
            $files = [];
            foreach ($panelTable['file'] as $file) {
                if ($file !== $encoded) {
                    $files[] = $file;
                }
            }
            $panelTable['file'] = array_values($files);
        }

        self::syncPanelTable($panelTable);
    }

    /**
     * 插件配置表单
     *
     * @param Form $form
     */
    public static function config(Form $form)
    {
        $cfg = self::settings();

        $front = new Form\Element\Select(
            'front_enabled',
            ['1' => '开启', '0' => '关闭'],
            $cfg['front_enabled'],
            _t('前台显示')
        );
        $front->description(_t('关闭后前台成长计划页只显示提示文案，后台数据不受影响'));
        $form->addInput($front);

        $soon = new Form\Element\Text(
            'soon_days',
            null,
            $cfg['soon_days'],
            _t('「即将到期」阈值（天）')
        );
        $soon->description(_t('剩余天数小于该值且尚未到期时，标记为「即将到期」，默认 30'));
        $form->addInput($soon);

        $autoStatus = new Form\Element\Select(
            'show_status',
            ['1' => '显示', '0' => '隐藏'],
            $cfg['show_status'],
            _t('显示状态标签')
        );
        $autoStatus->description(_t('在前台计划卡片上显示「进行中 / 即将到期 / 已过期」标签'));
        $form->addInput($autoStatus);

        $yearFilter = new Form\Element\Select(
            'year_filter',
            ['1' => '显示', '0' => '隐藏'],
            $cfg['year_filter'],
            _t('显示年份筛选')
        );
        $yearFilter->description(_t('在前台时间线顶部显示按年份筛选的标签'));
        $form->addInput($yearFilter);

        $offline = new Form\Element\Text(
            'offline_tip',
            null,
            $cfg['offline_tip'],
            _t('关闭时的提示文案')
        );
        $form->addInput($offline);

        $empty = new Form\Element\Text(
            'empty_tip',
            null,
            $cfg['empty_tip'],
            _t('无计划时的空状态文案')
        );
        $form->addInput($empty);
    }

    /**
     * 个人配置
     *
     * PluginInterface 要求实现，但本插件的设置统一放在后台面板里，
     * 个人配置留空（与 DeviceShow 一致）。
     *
     * @param Form $form
     */
    public static function personalConfig(Form $form)
    {
    }

    // ---------------------------------------------------------------------
    // 数据表
    // ---------------------------------------------------------------------

    /**
     * 全部表名（不带前缀）
     *
     * ⚠️ 抽成常量而不是散在 SQL 字符串里：createTables() 的孤儿修复
     *    需要一份「这张插件一共管哪几张表」的清单，如果每次去 SQL 里
     *    正则抠表名，加一张表就漏一处。这里是唯一的真源。
     */
    private const TABLES = [
        'growth_categories',
        'growth_plans',
        'growth_logs',
        'growth_log_images',
        'growth_rounds',
        'growth_plan_posts',
    ];

    /**
     * 创建数据表
     *
     * @throws \Typecho\Db\Exception
     */
    private static function createTables(): void
    {
        $db = Db::get();
        $prefix = $db->getPrefix();

        // ⚠️ 建表之前先治一遍「孤儿表空间」。
        //    顺序不能反：孤儿状态下 CREATE TABLE IF NOT EXISTS 一定会抛 1050，
        //    直接死在这一步，后面所有表都建不出来。
        //    代价是多花 N 次 CHECK TABLE —— 只在启用插件那一刻跑一次，
        //    可以忽略。每个表名的 DDL 仍然各自带 IF NOT EXISTS，
        //    所以这里的修复对健康表是纯粹的空操作。
        foreach (self::TABLES as $table) {
            self::repairOrphanTable($table);
        }

        $adapter = strtolower($db->getAdapterName());
        $isMysql = strpos($adapter, 'mysql') !== false
            || strpos($adapter, 'pdo_mysql') !== false
            || strpos($adapter, 'mysqli') !== false;

        if ($isMysql) {
            $engine = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

            $sqlCats = 'CREATE TABLE IF NOT EXISTS `' . $prefix . 'growth_categories` ('
                . '`id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`name` VARCHAR(120) NOT NULL DEFAULT \'\','
                . '`description` VARCHAR(255) NOT NULL DEFAULT \'\','
                . '`color` VARCHAR(20) NOT NULL DEFAULT \'\','
                . '`sort` INT(10) NOT NULL DEFAULT 0,'
                . 'PRIMARY KEY (`id`)'
                . ') ' . $engine;

            $sqlPlans = 'CREATE TABLE IF NOT EXISTS `' . $prefix . 'growth_plans` ('
                . '`id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`cid` INT(10) UNSIGNED NOT NULL DEFAULT 0,'
                . '`parent_id` INT(10) UNSIGNED NOT NULL DEFAULT 0,'
                . '`title` VARCHAR(255) NOT NULL DEFAULT \'\','
                . '`year` INT(10) NOT NULL DEFAULT 0,'
                . '`start_date` DATE DEFAULT NULL,'
                . '`end_date` DATE DEFAULT NULL,'
                . '`description` TEXT,'
                . '`status` VARCHAR(20) NOT NULL DEFAULT \'auto\','
                // 归档与「时间状态」是正交的两件事：
                // 「已归档 + 已过期」是必须能表达的合法组合，
                // 塞进 status 枚举就表达不出来，所以单独一列。
                . '`archived` TINYINT(1) NOT NULL DEFAULT 0,'
                // 卡片封面图。前台主视图是一张卡片网格，每条计划一张卡，
                // 卡片上半部分是这张图。留空时前台会用类型色画一个占位。
                . '`cover` VARCHAR(500) NOT NULL DEFAULT \'\','
                . '`sort` INT(10) NOT NULL DEFAULT 0,'
                . '`created` INT(10) UNSIGNED NOT NULL DEFAULT 0,'
                . 'PRIMARY KEY (`id`),'
                . 'KEY `idx_cid` (`cid`),'
                . 'KEY `idx_year` (`year`),'
                . 'KEY `idx_parent` (`parent_id`)'
                . ') ' . $engine;

            // 计划日志：一条计划可以有任意多条，每条带自己的日期与正文
            $sqlLogs = 'CREATE TABLE IF NOT EXISTS `' . $prefix . 'growth_logs` ('
                . '`id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`plan_id` INT(10) UNSIGNED NOT NULL DEFAULT 0,'
                . '`log_date` DATE DEFAULT NULL,'
                . '`content` TEXT,'
                . '`sort` INT(10) NOT NULL DEFAULT 0,'
                . '`created` INT(10) UNSIGNED NOT NULL DEFAULT 0,'
                . 'PRIMARY KEY (`id`),'
                . 'KEY `idx_plan` (`plan_id`),'
                . 'KEY `idx_date` (`log_date`)'
                . ') ' . $engine;

            // 日志配图：图片链接 + 每张图自己的文字说明
            $sqlImages = 'CREATE TABLE IF NOT EXISTS `' . $prefix . 'growth_log_images` ('
                . '`id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`log_id` INT(10) UNSIGNED NOT NULL DEFAULT 0,'
                . '`url` VARCHAR(500) NOT NULL DEFAULT \'\','
                . '`caption` VARCHAR(255) NOT NULL DEFAULT \'\','
                . '`sort` INT(10) NOT NULL DEFAULT 0,'
                . 'PRIMARY KEY (`id`),'
                . 'KEY `idx_log` (`log_id`)'
                . ') ' . $engine;

            // 文章打卡映射：写文章时把文章关联到一个或多个计划，
            // 发布后该文章作为计划的「打卡」节点出现在时间线上。
            // 只存映射，文章本身的标题/日期/状态都实时从 contents 读，
            // 所以「自动同步」不需要任何额外逻辑 —— 改文章、撤稿、删除都自动反映。
            $sqlPlanPosts = 'CREATE TABLE IF NOT EXISTS `' . $prefix . 'growth_plan_posts` ('
                . '`id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`post_id` INT(10) UNSIGNED NOT NULL DEFAULT 0,'
                . '`plan_id` INT(10) UNSIGNED NOT NULL DEFAULT 0,'
                . '`created` INT(10) UNSIGNED NOT NULL DEFAULT 0,'
                . 'PRIMARY KEY (`id`),'
                . 'UNIQUE KEY `uq_pp` (`post_id`, `plan_id`),'
                . 'KEY `idx_pp_plan` (`plan_id`)'
                . ') ' . $engine;

            // 续期轮次：每次「继续计划」封存一条历史轮次快照，
            // 用于前台折叠展示 + 累计进度。
            $sqlRounds = self::buildRoundsSql($prefix);

            // 第三参数传空字符串，不能传 null
            $db->query($sqlCats, Db::WRITE, '');
            $db->query($sqlPlans, Db::WRITE, '');
            $db->query($sqlLogs, Db::WRITE, '');
            $db->query($sqlImages, Db::WRITE, '');
            $db->query($sqlPlanPosts, Db::WRITE, '');
            $db->query($sqlRounds, Db::WRITE, '');
        } else {
            // [本站适配 2026-09-22] SQLite / Pgsql 等：使用标准 SQL（原版仅支持 MySQL，直接抛异常）
            $sqlCats = 'CREATE TABLE IF NOT EXISTS "' . $prefix . 'growth_categories" ('
                . '"id" INTEGER NOT NULL,'
                . '"name" VARCHAR(120) NOT NULL DEFAULT \'\','
                . '"description" VARCHAR(255) NOT NULL DEFAULT \'\','
                . '"color" VARCHAR(20) NOT NULL DEFAULT \'\','
                . '"sort" INTEGER NOT NULL DEFAULT 0,'
                . 'PRIMARY KEY ("id")'
                . ')';

            $sqlPlans = 'CREATE TABLE IF NOT EXISTS "' . $prefix . 'growth_plans" ('
                . '"id" INTEGER NOT NULL,'
                . '"cid" INTEGER NOT NULL DEFAULT 0,'
                . '"parent_id" INTEGER NOT NULL DEFAULT 0,'
                . '"title" VARCHAR(255) NOT NULL DEFAULT \'\','
                . '"year" INTEGER NOT NULL DEFAULT 0,'
                . '"start_date" DATE DEFAULT NULL,'
                . '"end_date" DATE DEFAULT NULL,'
                . '"description" TEXT,'
                . '"status" VARCHAR(20) NOT NULL DEFAULT \'auto\','
                . '"archived" INTEGER NOT NULL DEFAULT 0,'
                . '"cover" VARCHAR(500) NOT NULL DEFAULT \'\','
                . '"sort" INTEGER NOT NULL DEFAULT 0,'
                . '"created" INTEGER NOT NULL DEFAULT 0,'
                . 'PRIMARY KEY ("id")'
                . ')';

            $sqlLogs = 'CREATE TABLE IF NOT EXISTS "' . $prefix . 'growth_logs" ('
                . '"id" INTEGER NOT NULL,'
                . '"plan_id" INTEGER NOT NULL DEFAULT 0,'
                . '"log_date" DATE DEFAULT NULL,'
                . '"content" TEXT,'
                . '"sort" INTEGER NOT NULL DEFAULT 0,'
                . '"created" INTEGER NOT NULL DEFAULT 0,'
                . 'PRIMARY KEY ("id")'
                . ')';

            $sqlImages = 'CREATE TABLE IF NOT EXISTS "' . $prefix . 'growth_log_images" ('
                . '"id" INTEGER NOT NULL,'
                . '"log_id" INTEGER NOT NULL DEFAULT 0,'
                . '"url" VARCHAR(500) NOT NULL DEFAULT \'\','
                . '"caption" VARCHAR(255) NOT NULL DEFAULT \'\','
                . '"sort" INTEGER NOT NULL DEFAULT 0,'
                . 'PRIMARY KEY ("id")'
                . ')';

            // 文章打卡映射（含义同 MySQL 分支）
            $sqlPlanPosts = 'CREATE TABLE IF NOT EXISTS "' . $prefix . 'growth_plan_posts" ('
                . '"id" INTEGER NOT NULL,'
                . '"post_id" INTEGER NOT NULL DEFAULT 0,'
                . '"plan_id" INTEGER NOT NULL DEFAULT 0,'
                . '"created" INTEGER NOT NULL DEFAULT 0,'
                . 'PRIMARY KEY ("id")'
                . ')';

            $sqlRounds = self::buildRoundsSqlSqlite($prefix);

            $db->query($sqlCats, Db::WRITE, '');
            $db->query($sqlPlans, Db::WRITE, '');
            $db->query($sqlLogs, Db::WRITE, '');
            $db->query($sqlImages, Db::WRITE, '');
            $db->query($sqlPlanPosts, Db::WRITE, '');
            $db->query($sqlRounds, Db::WRITE, '');

            // SQLite 不支持建表内联 KEY，索引单独幂等创建
            $indexes = [
                'idx_cid'    => $prefix . 'growth_plans (`cid`)',
                'idx_year'   => $prefix . 'growth_plans (`year`)',
                'idx_parent' => $prefix . 'growth_plans (`parent_id`)',
                'idx_plan'   => $prefix . 'growth_logs (`plan_id`)',
                'idx_date'   => $prefix . 'growth_logs (`log_date`)',
                'idx_log'    => $prefix . 'growth_log_images (`log_id`)',
                'idx_round'  => $prefix . 'growth_rounds (`plan_id`, `round_no`)',
                'idx_pp_plan' => $prefix . 'growth_plan_posts (`plan_id`)',
            ];
            foreach ($indexes as $idxName => $idxSpec) {
                $db->query('CREATE INDEX IF NOT EXISTS `' . $idxName . '` ON ' . $idxSpec, Db::WRITE, '');
            }
        }
    }

    /**
     * 轮次建表 SQL
     *
     * 抽成独立方法，让 createTables() 与 migrate() 共用同一份定义，
     * 避免两处 SQL 各写一遍、日后改字段漏改一处。
     *
     * 只存「每一轮自己的」天数，不存累计值：
     * 累计 = SUM() 一次 GROUP BY 算全站，避免 N+1 与冗余不一致。
     * days_gone 在封轮时定格，这样历史轮次之后被编辑也不会漂移。
     */
    private static function buildRoundsSql(string $prefix): string
    {
        $engine = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

        return 'CREATE TABLE IF NOT EXISTS `' . $prefix . 'growth_rounds` ('
            . '`id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '`plan_id` INT(10) UNSIGNED NOT NULL DEFAULT 0,'
            . '`round_no` INT(10) UNSIGNED NOT NULL DEFAULT 1,'
            . '`title` VARCHAR(255) NOT NULL DEFAULT \'\','
            . '`start_date` DATE DEFAULT NULL,'
            . '`end_date` DATE DEFAULT NULL,'
            . '`days_total` INT(10) NOT NULL DEFAULT 0,'
            . '`days_gone` INT(10) NOT NULL DEFAULT 0,'
            . '`note` VARCHAR(255) NOT NULL DEFAULT \'\','
            . '`created` INT(10) UNSIGNED NOT NULL DEFAULT 0,'
            . 'PRIMARY KEY (`id`),'
            . 'KEY `idx_plan` (`plan_id`),'
            . 'KEY `idx_round` (`plan_id`, `round_no`)'
            . ') ' . $engine;
    }

    /**
     * 「孤儿表空间」自愈：表在数据字典里登记着，磁盘文件却没了
     *
     * ⚠️ 这不是臆想出来的边界情况，是真实踩到过的：
     *   服务器异常断电 / 快照回滚 / 直接拷 mysql 数据目录迁移时，
     *   InnoDB 的 `ibdata1` 里可能还留着某张表的表空间登记，
     *   但 `库名/表名.ibd` 已经不在了。此时：
     *     - SELECT 报 "Table 'x' doesn't exist"（1146）
     *     - CREATE TABLE IF NOT EXISTS 却报 "Table 'x' already exists"（1050）
     *   两条路一起堵死，插件再也建不出这张表，前台功能整个失效。
     *
     * 处理办法分三步（MySQL 系）：
     *   1. 先探测：CHECK TABLE。表真的缺了才会报错，正常表是 OK。
     *   2. 清登记：`DROP TABLE IF EXISTS`。这一步在孤儿状态下会失败
     *      （因为文件没了，drop 找不到表空间），所以后面还要兜。
     *   3. 建表：走正常的 CREATE TABLE。
     *
     * 为什么 DROP 失败也算成功：孤儿登记分两种，
     *   一种是 drop 能清掉的（软状态），清完再 create 就好了；
     *   另一种是 drop 也清不掉的（`ibdata1` 里的硬登记），
     *   这种只能靠 DBA 手动处理，插件层无从下手。
     *   与其在这里静默卡住，不如把原始报错记进日志，让人能查。
     *
     * SQLite 没有表空间概念，直接跳过。
     *
     * @param string $table 不带前缀的表名
     * @return bool 真的执行过修复动作（用于日志）
     */
    private static function repairOrphanTable(string $table): bool
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $full = $prefix . $table;

        $adapter = strtolower($db->getAdapterName());
        $isMysql = strpos($adapter, 'mysql') !== false
            || strpos($adapter, 'pdo_mysql') !== false
            || strpos($adapter, 'mysqli') !== false;
        if (!$isMysql) {
            return false;
        }

        // 1. 探测：这张表当前能不能读。
        //
        // ⚠️ CHECK TABLE 对坏表**不抛异常** —— 它返回一个结果集，
        //    里面 Msg_type='Error' / Msg_text 说表不存在 / Msg_type='status'
        //    且 Msg_text='Operation failed'。
        //    第一版这里只套了个 try/catch 就以为探得到，结果「探测永远成功」，
        //    修复被整段跳过，孤儿表一直建不出来。必须**读结果集**判断。
        try {
            $res = $db->query('CHECK TABLE `' . $full . '`', Db::READ, '');
            $ok = false;
            while ($row = $res->fetch(\PDO::FETCH_ASSOC)) {
                $msg = strtolower((string) ($row['Msg_text'] ?? ''));
                $type = strtolower((string) ($row['Msg_type'] ?? ''));
                // 只要有 Error 行、或出现 status=Operation failed，就判定不健康
                if ($type === 'error' || strpos($msg, 'operation failed') !== false) {
                    $ok = false;
                    break;
                }
                // status=OK 才算健康
                if ($type === 'status' && strpos($msg, 'ok') !== false) {
                    $ok = true;
                }
            }
            if ($ok) {
                return false; // 表是好的，什么都不用做
            }
        } catch (\Exception $e) {
            // 连 CHECK 都执行不了（权限/连接层问题）：当成不健康，继续往下试
        }

        // 2. 清掉残留登记，然后**立刻重建**。
        //
        // ⚠️ 关键顺序：DROP 之后马上 CREATE，中间不能隔任何东西。
        //    因为孤儿状态分两种：
        //      a. 软登记（DROP 能清掉）—— 清完建表就好了；
        //      b. 硬登记（DROP 也清不掉）—— 表名被 ibdata1 死锁，
        //         这时只能靠 SQL 层之外的手段，插件无能为力。
        //    所以这里不做「DROP 是否成功」的假设，直接 DROP → CREATE 走一遍，
        //    CREATE 失败会把原始报错抛出去，让人知道要手工介入，
        //    而不是静默地把问题藏起来。
        try {
            $db->query('DROP TABLE IF EXISTS `' . $full . '`', Db::WRITE, '');
        } catch (\Exception $e) {
            \Typecho\Log::write('GrowthPlan repairOrphanTable: DROP ' . $full
                . ' 失败 - ' . $e->getMessage(), \Typecho\Log::WARNING);
        }

        return true;
    }

    /**
     * 轮次建表 SQL（SQLite 版）
     *
     * [本站适配 2026-09-22] 与 buildRoundsSql() 同一份字段定义，仅方言不同。
     */
    private static function buildRoundsSqlSqlite(string $prefix): string
    {
        return 'CREATE TABLE IF NOT EXISTS "' . $prefix . 'growth_rounds" ('
            . '"id" INTEGER NOT NULL,'
            . '"plan_id" INTEGER NOT NULL DEFAULT 0,'
            . '"round_no" INTEGER NOT NULL DEFAULT 1,'
            . '"title" VARCHAR(255) NOT NULL DEFAULT \'\','
            . '"start_date" DATE DEFAULT NULL,'
            . '"end_date" DATE DEFAULT NULL,'
            . '"days_total" INTEGER NOT NULL DEFAULT 0,'
            . '"days_gone" INTEGER NOT NULL DEFAULT 0,'
            . '"note" VARCHAR(255) NOT NULL DEFAULT \'\','
            . '"created" INTEGER NOT NULL DEFAULT 0,'
            . 'PRIMARY KEY ("id")'
            . ')';
    }

    /**
     * 结构迁移：给老版本已建好的表补新列 / 新索引
     *
     * CREATE TABLE IF NOT EXISTS 对「已存在的表」是空操作，不会补列。
     * 所以 1.0.x 升级上来的站点，growth_plans 里没有 parent_id，
     * 必须在这里显式 ALTER。
     *
     * 用 SHOW COLUMNS / SHOW INDEX 先判断再加，而不是
     * `ADD COLUMN IF NOT EXISTS` —— 后者 MariaDB 支持、MySQL 5.7 不支持，
     * 写了在部分环境会直接报语法错误。
     *
     * 整个过程吞掉异常：迁移失败不该让后台整个白屏，最坏情况是
     * 层级功能不可用，基础的增删改查仍然能跑。
     */
    public static function migrate(): void
    {
        try {
            $db = Db::get();
            $prefix = $db->getPrefix();
            $table = $prefix . 'growth_plans';

            $adapter = strtolower((string) $db->getAdapterName());
            $isMysql = strpos($adapter, 'mysql') !== false || strpos($adapter, 'mysqli') !== false;

            if (!$isMysql) {
                // [本站适配 2026-09-22] SQLite 迁移路径：PRAGMA 查列 + 标准 ALTER + 幂等建索引
                $cols = [];
                foreach ($db->fetchAll('PRAGMA table_info(`' . $table . '`)') as $col) {
                    if (isset($col['name'])) {
                        $cols[] = (string) $col['name'];
                    }
                }
                if (!in_array('parent_id', $cols, true)) {
                    $db->query(
                        'ALTER TABLE `' . $table . '` ADD COLUMN `parent_id` INTEGER NOT NULL DEFAULT 0',
                        Db::WRITE,
                        ''
                    );
                }
                if (!in_array('archived', $cols, true)) {
                    $db->query(
                        'ALTER TABLE `' . $table . '` ADD COLUMN `archived` INTEGER NOT NULL DEFAULT 0',
                        Db::WRITE,
                        ''
                    );
                }
                if (!in_array('cover', $cols, true)) {
                    $db->query(
                        'ALTER TABLE `' . $table . '` ADD COLUMN `cover` VARCHAR(500) NOT NULL DEFAULT \'\'',
                        Db::WRITE,
                        ''
                    );
                }
                $db->query(
                    'CREATE INDEX IF NOT EXISTS `idx_parent` ON `' . $table . '` (`parent_id`)',
                    Db::WRITE,
                    ''
                );
                $db->query(self::buildRoundsSqlSqlite($prefix), Db::WRITE, '');

                return;
            }

            $cols = [];
            foreach ($db->fetchAll('SHOW COLUMNS FROM `' . $table . '`') as $col) {
                if (isset($col['Field'])) {
                    $cols[] = (string) $col['Field'];
                }
            }

            if (!in_array('parent_id', $cols, true)) {
                $db->query(
                    'ALTER TABLE `' . $table . '`'
                    . ' ADD COLUMN `parent_id` INT(10) UNSIGNED NOT NULL DEFAULT 0 AFTER `cid`',
                    Db::WRITE,
                    ''
                );
            }

            if (!in_array('archived', $cols, true)) {
                $db->query(
                    'ALTER TABLE `' . $table . '`'
                    . ' ADD COLUMN `archived` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`',
                    Db::WRITE,
                    ''
                );
            }

            if (!in_array('cover', $cols, true)) {
                $db->query(
                    'ALTER TABLE `' . $table . '`'
                    . ' ADD COLUMN `cover` VARCHAR(500) NOT NULL DEFAULT \'\' AFTER `archived`',
                    Db::WRITE,
                    ''
                );
            }

            $idx = [];
            foreach ($db->fetchAll('SHOW INDEX FROM `' . $table . '`') as $row) {
                if (isset($row['Key_name'])) {
                    $idx[] = (string) $row['Key_name'];
                }
            }

            if (!in_array('idx_parent', $idx, true)) {
                $db->query(
                    'ALTER TABLE `' . $table . '` ADD KEY `idx_parent` (`parent_id`)',
                    Db::WRITE,
                    ''
                );
            }

            // 老站点可能只是升级了插件文件、没有重新「启用」过插件，
            // 那就走不到 createTables()，这里的 IF NOT EXISTS 兜住轮次表。
            $db->query(self::buildRoundsSql($prefix), Db::WRITE, '');
        } catch (\Throwable $e) {
            // 静默失败
        }
    }

    /**
     * 插件设置读写
     *
     * @param array|null $config
     * @return array
     * @throws \Typecho\Db\Exception
     */
    public static function writeConfig(?array $config = null): array
    {
        $db = Db::get();

        if (null !== $config) {
            $row = $db->fetchRow(
                $db->select('value')->from('table.options')
                    ->where('name = ?', 'plugin:GrowthPlan')
                    ->limit(1)
            );

            $serialized = serialize($config);

            if (empty($row)) {
                $db->query(
                    $db->insert('table.options')->rows([
                        'name'  => 'plugin:GrowthPlan',
                        'user'  => 0,
                        'value' => $serialized,
                    ])
                );
            } else {
                $db->query(
                    $db->update('table.options')
                        ->rows(['value' => $serialized])
                        ->where('name = ?', 'plugin:GrowthPlan')
                );
            }

            self::$configCache = $config;
            return $config;
        }

        return self::settings();
    }

    /**
     * 默认设置
     *
     * @return array
     */
    public static function defaultConfig(): array
    {
        return [
            'front_enabled' => '1',
            'soon_days'     => '30',
            'show_status'   => '1',
            'year_filter'   => '1',
            'offline_tip'   => '成长计划功能未启用，请稍后再来。',
            'empty_tip'     => '还没有添加任何成长计划。',
            // 时间线首尾「开始 / 结束」节点的颜色。留空则前台回落到默认色。
            // 为什么做成配置而不是写死：深浅两套主题下同一色的观感差别很大，
            // 让用户自己调比我们猜要准。
            'node_start_color' => '#2f7d5a',
            'node_end_color'   => '#3b6ea5',
        ];
    }

    /**
     * 读取插件设置
     *
     * @return array
     */
    public static function settings(): array
    {
        if (null !== self::$configCache) {
            return self::$configCache;
        }

        $defaults = self::defaultConfig();

        try {
            $db = Db::get();
            $row = $db->fetchRow(
                $db->select('value')->from('table.options')
                    ->where('name = ?', 'plugin:GrowthPlan')
                    ->limit(1)
            );

            if (empty($row['value'])) {
                self::$configCache = $defaults;
                return $defaults;
            }

            $saved = @unserialize((string) $row['value']);
            if (!is_array($saved)) {
                self::$configCache = $defaults;
                return $defaults;
            }

            self::$configCache = array_merge($defaults, $saved);
            return self::$configCache;
        } catch (\Throwable $e) {
            self::$configCache = $defaults;
            return $defaults;
        }
    }

    /**
     * 读取单项设置
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function setting(string $key, $default = null)
    {
        $cfg = self::settings();
        return $cfg[$key] ?? $default;
    }

    // ---------------------------------------------------------------------
    // 后台菜单
    // ---------------------------------------------------------------------

    /**
     * 注册后台菜单
     *
     * 直接读写 panelTable，不用 Helper::addMenu()/addPanel()：
     * 那两个方法纯追加，重复 activate 会把菜单堆成好几份。
     *
     * @throws \Typecho\Db\Exception
     */
    private static function registerPanel(): void
    {
        $db = Db::get();

        $row = $db->fetchRow(
            $db->select('value')->from('table.options')
                ->where('name = ?', 'panelTable')
                ->limit(1)
        );

        $panelTable = empty($row['value']) ? [] : @unserialize((string) $row['value']);
        if (!is_array($panelTable)) {
            $panelTable = [];
        }

        $panelTable['parent'] = empty($panelTable['parent']) ? [] : $panelTable['parent'];
        $panelTable['child'] = empty($panelTable['child']) ? [] : $panelTable['child'];
        $panelTable['file'] = empty($panelTable['file']) ? [] : $panelTable['file'];

        $menuName = self::MENU_NAME;
        $encoded = urlencode(trim(self::PANEL_FILE, '/'));

        // 挂载点：parent 加一项后的位置即其键，child 用「键 + 10」对应
        $panelTable['parent'][] = $menuName;
        $index = array_key_last($panelTable['parent']) + 10;

        $panelTable['child'][$index] = [[
            $menuName,
            // 第二项会作为后台页面 <title> 使用（Widget\Menu::execute 里
            // $this->title = $title），必须是页面标题而不是副标题文案。
            $menuName,
            'extending.php?panel=' . $encoded,
            'administrator',
            false,
            '',
        ]];

        if (!in_array($encoded, $panelTable['file'], true)) {
            $panelTable['file'][] = $encoded;
        }

        self::syncPanelTable($panelTable);
    }

    /**
     * 写回 panelTable
     *
     * \Widget\Options 是单例且带实例缓存，destroy() + alloc() 刷不掉，
     * 必须直接给属性赋值。
     *
     * @param array $panelTable
     * @throws \Typecho\Db\Exception
     */
    private static function syncPanelTable(array $panelTable): void
    {
        $db = Db::get();
        $serialized = serialize($panelTable);

        $row = $db->fetchRow(
            $db->select('name')->from('table.options')
                ->where('name = ?', 'panelTable')
                ->limit(1)
        );

        if (empty($row)) {
            $db->query(
                $db->insert('table.options')->rows([
                    'name'  => 'panelTable',
                    'user'  => 0,
                    'value' => $serialized,
                ])
            );
        } else {
            $db->query(
                $db->update('table.options')
                    ->rows(['value' => $serialized])
                    ->where('name = ?', 'panelTable')
            );
        }

        Options::alloc()->panelTable = $serialized;
    }

    // ---------------------------------------------------------------------
    // 状态与进度计算
    // ---------------------------------------------------------------------

    /**
     * 解析日期字符串为时间戳（当天 00:00:00）
     *
     * @param string|null $date
     * @return int|null
     */
    public static function parseDate(?string $date): ?int
    {
        if (empty($date) || $date === '0000-00-00') {
            return null;
        }

        $clean = substr(trim($date), 0, 10);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $clean, $m)) {
            return null;
        }

        $ts = mktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]);
        return false === $ts ? null : $ts;
    }

    /**
     * 计算计划的进度信息
     *
     * 进度 = 已过天数 / 总天数，钳制在 0~100。
     *
     * 返回 9 个键，分两组：
     *   本轮（前 6 个）—— percent / days_total / days_gone / days_left / start / end
     *   累计（后 3 个）—— cumulative_days / cumulative_total / cumulative_percent
     *
     * 「本轮」这 6 个键的语义与数值跟续期功能上线前完全一致，一字未改。
     * 原因有二：1) leftText() 只读 days_left，倒计时必须是本轮剩余，
     * 改成累计就全错；2) 档期进度条若用累计百分比，续期 10 次后
     * 永远爬不到 100%，视觉上不合理。累计值单独给键，调用方按需取。
     *
     * 累计值来自 $plan['_carry_total'] / $plan['_carry_gone']
     * （由 applyCarry() 注入，代表已封存的历史轮次天数）。
     * 没有 carry 时三个累计键回落成本轮的值。
     *
     * @param array $plan  含 start_date / end_date，可选 _carry_total / _carry_gone
     * @param int|null $now 参照时间戳，默认当前时间
     * @return array
     */
    public static function progress(array $plan, ?int $now = null): array
    {
        $now = $now ?? time();
        $today = mktime(0, 0, 0, (int) date('n', $now), (int) date('j', $now), (int) date('Y', $now));

        $start = self::parseDate($plan['start_date'] ?? null);
        $end = self::parseDate($plan['end_date'] ?? null);

        $result = [
            'percent'    => 0,
            'days_total' => 0,
            'days_gone'  => 0,
            'days_left'  => 0,
            'start'      => $start,
            'end'        => $end,
        ];

        // 没有截止日期：无法倒计时
        if (null === $end) {
            $result['cumulative_days'] = 0;
            $result['cumulative_total'] = 0;
            $result['cumulative_percent'] = 0;
            return $result;
        }

        // 没有开始日期：视为从今天开始
        if (null === $start) {
            $start = $today;
        }

        // 开始晚于结束：容错，按同一天处理
        if ($start > $end) {
            $start = $end;
        }

        $daySeconds = 86400;
        $total = (int) round(($end - $start) / $daySeconds) + 1;
        $total = max(1, $total);

        $gone = (int) round(($today - $start) / $daySeconds) + 1;
        $left = (int) round(($end - $today) / $daySeconds);

        $gone = max(0, $gone);
        $left = $left;

        $percent = (int) round($gone / $total * 100);
        $percent = max(0, min(100, $percent));

        $result['days_total'] = $total;
        $result['days_gone'] = min($gone, $total);
        $result['days_left'] = $left;
        $result['percent'] = $percent;

        // 本轮之外的累计天数（历史轮次封存值）
        $carryTotal = max(0, (int) ($plan['_carry_total'] ?? 0));
        $carryGone = max(0, (int) ($plan['_carry_gone'] ?? 0));

        if ($carryTotal > 0 || $carryGone > 0) {
            $cumTotal = $total + $carryTotal;
            // 本轮已过天数先钳到本轮总天数，再加上历史，避免
            // 「本轮超期」被算成额外进度
            $cumGone = min($gone, $total) + $carryGone;

            $result['cumulative_days'] = min($cumGone, $cumTotal);
            $result['cumulative_total'] = $cumTotal;
            $result['cumulative_percent'] = $cumTotal > 0
                ? max(0, min(100, (int) round($cumGone / $cumTotal * 100)))
                : 0;
        } else {
            $result['cumulative_days'] = $result['days_gone'];
            $result['cumulative_total'] = $result['days_total'];
            $result['cumulative_percent'] = $result['percent'];
        }

        return $result;
    }

    /**
     * 判断计划状态
     *
     * 状态语义（2026-09 定稿）：后台只有两个选项 ——
     *   - 未完成（存储值 auto，默认）：按日期自动显示 未开始/进行中/即将到期/已过期；
     *     超期后显示「已过期」，因此**已过期只会出现在未完成的计划上**。
     *   - 已完成（存储值 done）：手动标记，优先级最高，超期也显示已完成。
     *
     * @param array $plan
     * @param int|null $now
     * @return string STATUS_* 之一
     */
    public static function status(array $plan, ?int $now = null): string
    {
        $now = $now ?? time();
        $today = mktime(0, 0, 0, (int) date('n', $now), (int) date('j', $now), (int) date('Y', $now));

        // 手动标记：已完成优先级最高 —— 做完的计划哪怕超期也不显示已过期
        $manual = (string) ($plan['status'] ?? 'auto');
        if ($manual === 'done') {
            return 'done';
        }

        $start = self::parseDate($plan['start_date'] ?? null);
        $end = self::parseDate($plan['end_date'] ?? null);

        if (null !== $end && $today > $end) {
            return self::STATUS_EXPIRED;
        }

        if (null !== $start && $today < $start) {
            return self::STATUS_PENDING;
        }

        if (null !== $end) {
            $left = (int) round(($end - $today) / 86400);
            $soonDays = (int) self::setting('soon_days', 30);
            if ($soonDays > 0 && $left <= $soonDays) {
                return self::STATUS_SOON;
            }
        }

        return self::STATUS_ACTIVE;
    }

    /**
     * 状态的中文标签
     *
     * @param string $status
     * @return string
     */
    public static function statusLabel(string $status): string
    {
        $map = [
            'done'                  => '已完成',
            self::STATUS_PENDING    => '未开始',
            self::STATUS_ACTIVE     => '进行中',
            self::STATUS_SOON       => '即将到期',
            self::STATUS_EXPIRED    => '已过期',
        ];

        return $map[$status] ?? '进行中';
    }

    /**
     * 剩余天数的可读文案
     *
     * @param array $progress progress() 的返回
     * @return string
     */
    public static function leftText(array $progress): string
    {
        if (null === $progress['end']) {
            return '未设置截止日期';
        }

        $left = (int) $progress['days_left'];

        if ($left > 0) {
            return '还剩 ' . $left . ' 天';
        }
        if (0 === $left) {
            return '今天到期';
        }

        return '已过期 ' . abs($left) . ' 天';
    }

    // ---------------------------------------------------------------------
    // 独立页面
    // ---------------------------------------------------------------------

    /**
     * 读取（并缓存）成长计划独立页面记录
     *
     * @return array|null
     */
    private static function pageRow(): ?array
    {
        if (null !== self::$pageIdCache) {
            return self::$pageRowCache;
        }

        self::$pageRowCache = null;
        self::$pageIdCache = 0;

        try {
            $db = Db::get();
            $row = $db->fetchRow(
                $db->select('cid', 'slug', 'title')->from('table.contents')
                    ->where('type = ?', 'page')
                    ->where('status = ?', 'publish')
                    ->where('slug = ?', self::PAGE_SLUG)
                    ->limit(1)
            );

            if (!empty($row)) {
                self::$pageRowCache = $row;
                self::$pageIdCache = (int) $row['cid'];
            }
        } catch (\Throwable $e) {
            self::$pageRowCache = null;
            self::$pageIdCache = 0;
        }

        return self::$pageRowCache;
    }

    /**
     * 独立页面 ID，不存在返回 0
     *
     * @return int
     */
    public static function pageId(): int
    {
        self::pageRow();
        return (int) self::$pageIdCache;
    }

    /**
     * 清空独立页面缓存（后台创建/删除页面后调用）
     */
    public static function resetPageIdCache(): void
    {
        self::$pageRowCache = null;
        self::$pageIdCache = null;
    }

    /**
     * 前台成长计划页地址
     *
     * @return string
     */
    public static function plansUrl(): string
    {
        $options = Options::alloc();
        $row = self::pageRow();
        $slug = !empty($row['slug']) ? (string) $row['slug'] : self::PAGE_SLUG;
        return \Typecho\Common::url($slug . '.html', $options->index); // [本站适配] 未开伪静态：页面地址带 .html
    }

    // ---------------------------------------------------------------------
    // 可用性判断
    // ---------------------------------------------------------------------

    /**
     * 插件是否已启用
     *
     * 两个坑：
     *  1. 激活列表里没挂载点的插件，值是**空数组**（`"GrowthPlan": []`），
     *     用 !empty() 判断会得到 false，必须用 array_key_exists()。
     *  2. 不能用 options 表里是否存在 plugin:GrowthPlan 做兜底——停用插件时
     *     Typecho 不删这行设置（要保留数据），拿它当依据会让停用后前台
     *     照常展示，等于停用失效。
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        try {
            $exported = \Typecho\Plugin::export();
        } catch (\Throwable $e) {
            return false;
        }

        return isset($exported['activated']) && array_key_exists(self::PLUGIN_NAME, $exported['activated']);
    }

    /**
     * 前台是否开启
     *
     * @return bool
     */
    public static function isFrontEnabled(): bool
    {
        return self::isEnabled() && '1' === (string) self::setting('front_enabled', '1');
    }

    /**
     * 当前主题是否已有前台模板
     *
     * @return bool
     */
    public static function themeHasTemplate(): bool
    {
        try {
            $options = Options::alloc();
            $themeDir = __TYPECHO_ROOT_DIR__ . __TYPECHO_THEME_DIR__ . '/' . $options->theme;
            // [本站适配 2026-09-22] Sunny 等加密主题把自定义页面模板放在 theme/template/ 子目录
            return file_exists($themeDir . '/' . self::TEMPLATE_FILE)
                || file_exists($themeDir . '/template/' . self::TEMPLATE_FILE);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 表名前缀
     *
     * @return string
     */
    public static function prefix(): string
    {
        return Db::get()->getPrefix();
    }

    // ---------------------------------------------------------------------
    // 层级（父子计划）
    // ---------------------------------------------------------------------

    /**
     * 把扁平的计划数组构造成树
     *
     * @param array $plans 计划行数组（每行须含 id / parent_id）
     * @return array [roots => [plan,...], children => [parentId => [plan,...]]]
     */
    public static function planTree(array $plans): array
    {
        $ids = [];
        foreach ($plans as $plan) {
            $ids[(int) $plan['id']] = true;
        }

        $children = [];
        $roots = [];

        foreach ($plans as $plan) {
            $pid = (int) ($plan['parent_id'] ?? 0);
            $id = (int) $plan['id'];

            // 父不存在（被筛掉 / 已删除 / 脏数据）→ 当顶级处理，保证不丢行
            if ($pid > 0 && $pid !== $id && isset($ids[$pid])) {
                $children[$pid][] = $plan;
            } else {
                $roots[] = $plan;
            }
        }

        return [$roots, $children];
    }

    /**
     * 取某计划的所有后代 id（含自身）
     *
     * 广度优先，带 visited 集合，脏数据成环也不会死循环。
     *
     * @param int $id
     * @param array $plans
     * @return int[]
     */
    public static function descendantIds(int $id, array $plans): array
    {
        if ($id <= 0) {
            return [];
        }

        // 自身不在集合里 → 这条计划根本不存在，没有任何后代可言。
        // 少了这一步，BFS 会把不存在的 id 自身当成结果返回 1，
        // 于是 deletePlanCascade() 会对一个不存在的计划「删除成功」，
        // 上层就永远走不到「计划不存在」的错误分支。
        $selfExists = false;
        foreach ($plans as $plan) {
            if ((int) $plan['id'] === $id) {
                $selfExists = true;
                break;
            }
        }
        if (!$selfExists) {
            return [];
        }

        $children = [];
        foreach ($plans as $plan) {
            $pid = (int) ($plan['parent_id'] ?? 0);
            if ($pid > 0) {
                $children[$pid][] = (int) $plan['id'];
            }
        }

        $result = [];
        $visited = [];
        $queue = [$id];

        while (!empty($queue)) {
            $current = array_shift($queue);
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            $result[] = $current;

            if (!empty($children[$current])) {
                foreach ($children[$current] as $child) {
                    if (!isset($visited[$child])) {
                        $queue[] = $child;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * $candidate 是否不能作为 $id 的父计划（会造成环）
     *
     * 判定「把 $id 挂到 $candidate 下面」是否合法，等价于：
     *   $candidate 不能是 $id 自己，也不能是 $id 的后代（子 / 孙 / …）。
     *
     * ⚠️ 注意不能用「沿 parent 链向上溯源」的写法。向上溯源只能回答
     * 「$candidate 是不是 $id 的祖先」，而这里要拦的是**后代**——
     * 方向正好相反。曾经写反过：把父计划挂到自己孙计划下面时，
     * 向上溯源从 $id 出发只会走到 parent=0 就停下，永远碰不到那个
     * 孙计划，于是环被放行，前台递归渲染直接死循环。
     *
     * 正确做法是用 descendantIds() 向下收集整棵子树，判断 $candidate
     * 是否落在集合里。descendantIds 自带 visited 与队列，脏数据成环
     * 也不会死循环。
     *
     * @param int $candidate 想设置的父计划 id
     * @param int $id 当前计划 id
     * @param array $plans 全量计划
     * @return bool true = 非法（会成环 / 指向自己）
     */
    public static function isAncestor(int $candidate, int $id, array $plans): bool
    {
        if ($candidate <= 0) {
            return false;   // 挂到顶级，永远合法
        }

        // 挂到自己身上
        if ($id <= 0 || $candidate === $id) {
            return true;
        }

        // 挂到自己的后代下面
        return in_array($candidate, self::descendantIds($id, $plans), true);
    }

    /**
     * $candidate 是否是 $id 的祖先（严格的父链向上溯源）
     *
     * 语义与 isAncestor() 不同：这个只回答「上面」，不回答「下面」。
     * 用于「父计划下拉」里高亮当前父级等纯展示场景。
     *
     * @param int $candidate
     * @param int $id
     * @param array $plans
     * @param int $max
     * @return bool
     */
    public static function isUpstream(int $candidate, int $id, array $plans, int $max = 64): bool
    {
        if ($candidate <= 0 || $id <= 0) {
            return false;
        }
        if ($candidate === $id) {
            return true;
        }

        $parentOf = [];
        foreach ($plans as $plan) {
            $parentOf[(int) $plan['id']] = (int) ($plan['parent_id'] ?? 0);
        }

        if (!isset($parentOf[$id])) {
            return false;
        }

        $cursor = $parentOf[$id];
        $visited = [$id => true];
        $steps = 0;

        while ($cursor > 0 && $steps < $max) {
            if ($cursor === $candidate) {
                return true;
            }
            if (isset($visited[$cursor]) || !isset($parentOf[$cursor])) {
                return false;
            }
            $visited[$cursor] = true;
            $cursor = $parentOf[$cursor];
            $steps++;
        }

        return false;
    }

    /**
     * 计划层级深度（顶级 = 0）
     *
     * @param int $id
     * @param array $plans
     * @param int $max
     * @return int
     */
    public static function depth(int $id, array $plans, int $max = 32): int
    {
        if ($id <= 0) {
            return 0;
        }

        $parentOf = [];
        foreach ($plans as $plan) {
            $parentOf[(int) $plan['id']] = (int) ($plan['parent_id'] ?? 0);
        }

        // 该 id 本身不在集合里 → 无从谈起层级
        if (!isset($parentOf[$id])) {
            return 0;
        }

        $cursor = $parentOf[$id];
        $visited = [$id => true];
        $depth = 0;

        while ($cursor > 0 && $depth < $max) {
            // 父节点不存在（孤儿 / 脏数据）→ 视作顶级，不再往上数。
            // 否则一条指向 id=99999 的脏数据会被算成「第 1 层」，
            // 后台缩进就莫名多一级。
            if (!isset($parentOf[$cursor])) {
                break;
            }
            if (isset($visited[$cursor])) {
                break;
            }
            $visited[$cursor] = true;
            $depth++;
            $cursor = $parentOf[$cursor];
        }

        return $depth;
    }

    /**
     * 按树形深度优先展平计划列表（父在前，子紧随其后）
     *
     * @param array $plans
     * @return array 展平后的计划数组，每项额外带 _depth 键
     */
    public static function sortPlansTree(array $plans): array
    {
        [$roots, $children] = self::planTree($plans);

        $result = [];
        $visited = [];

        $walk = static function (array $node, int $depth) use (&$walk, &$result, &$visited, $children): void {
            $id = (int) $node['id'];
            if (isset($visited[$id])) {
                return;   // 防环
            }
            $visited[$id] = true;

            $node['_depth'] = $depth;
            $result[] = $node;

            if (!empty($children[$id])) {
                foreach ($children[$id] as $child) {
                    $walk($child, $depth + 1);
                }
            }
        };

        foreach ($roots as $root) {
            $walk($root, 0);
        }

        // 兜底：理论上不会走到这里，但脏数据成环时保证所有行都出现
        foreach ($plans as $plan) {
            if (!isset($visited[(int) $plan['id']])) {
                $plan['_depth'] = 0;
                $result[] = $plan;
            }
        }

        return $result;
    }

    /**
     * 一次查出多条计划的日志，按 plan_id 分组
     *
     * @param int[] $planIds
     * @return array [planId => [log,...]]
     */
    public static function logsForPlans(array $planIds): array
    {
        $planIds = array_values(array_unique(array_map('intval', $planIds)));
        $planIds = array_filter($planIds, static function ($v) {
            return $v > 0;
        });

        if (empty($planIds)) {
            return [];
        }

        $db = Db::get();
        $rows = $db->fetchAll(
            $db->select()->from('table.growth_logs')
                ->where('plan_id IN (' . implode(',', $planIds) . ')')
                ->order('log_date', Db::SORT_DESC)
                ->order('sort', Db::SORT_ASC)
                ->order('id', Db::SORT_ASC)
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['plan_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * 一次查出多条日志的配图，按 log_id 分组
     *
     * @param int[] $logIds
     * @return array [logId => [image,...]]
     */
    public static function imagesForLogs(array $logIds): array
    {
        $logIds = array_values(array_unique(array_map('intval', $logIds)));
        $logIds = array_filter($logIds, static function ($v) {
            return $v > 0;
        });

        if (empty($logIds)) {
            return [];
        }

        $db = Db::get();
        $rows = $db->fetchAll(
            $db->select()->from('table.growth_log_images')
                ->where('log_id IN (' . implode(',', $logIds) . ')')
                ->order('sort', Db::SORT_ASC)
                ->order('id', Db::SORT_ASC)
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['log_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * 一次查出多条计划的历史轮次，按 plan_id 分组
     *
     * 排序按 round_no 升序：前台折叠展示时「第 1 轮」在前，
     * 累计求和也不依赖顺序，但展示需要稳定次序。
     *
     * @param int[] $planIds
     * @return array [planId => [round,...]]
     */
    public static function roundsForPlans(array $planIds): array
    {
        $planIds = array_values(array_unique(array_map('intval', $planIds)));
        $planIds = array_filter($planIds, static function ($v) {
            return $v > 0;
        });

        if (empty($planIds)) {
            return [];
        }

        $db = Db::get();
        $rows = $db->fetchAll(
            $db->select()->from('table.growth_rounds')
                ->where('plan_id IN (' . implode(',', $planIds) . ')')
                ->order('round_no', Db::SORT_ASC)
                ->order('id', Db::SORT_ASC)
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['plan_id']][] = $row;
        }

        return $grouped;
    }

    // ---------------------------------------------------------------------
    // 文章打卡（写文章时关联计划）
    // ---------------------------------------------------------------------

    /**
     * 一次查出「关联了指定计划」的已发布文章，按 plan_id 分组
     *
     * 关键设计：只存映射（growth_plan_posts），文章的标题 / 日期 / 状态
     * 全部**实时**从 contents 读（INNER JOIN 且 status='publish'）。
     * 好处是「自动同步」零成本 —— 改标题、撤稿、删文章，时间线节点自动跟着变，
     * 不用任何额外的同步/回填逻辑。代价是查询时多 Join 一次，但计划数不多，
     * 且只在已发布文章上出节点，开销可控。
     *
     * @param int[] $planIds
     * @return array [planId => [postRow,...]]  每行含 permalink / excerpt
     */
    public static function postsForPlans(array $planIds): array
    {
        $planIds = array_values(array_unique(array_map('intval', $planIds)));
        $planIds = array_filter($planIds, static function ($v) {
            return $v > 0;
        });

        if (empty($planIds)) {
            return [];
        }

        $db = Db::get();
        /* ⚠️ 必须显式列出字段并起别名，不能用 SELECT *。
           growth_plan_posts 和 contents 两张表**都有 created / modified**，
           而 SELECT * 展开后是两列同名 —— PDO 取到的是「后一列覆盖前一列」
           还是「先到先得」，取决于驱动返回列的顺序，属于不确定行为。
           我们要的语义很明确：**打卡日期 = 文章发布时间(c.created)**，
           那就把它明确地 SELECT 出来、起个唯一别名，不再依赖列顺序。 */
        $rows = $db->fetchAll(
            $db->select(
                'pp.id AS pp_id',
                'pp.post_id AS post_id',
                'pp.plan_id AS plan_id',
                'c.title AS title',
                'c.text AS text',
                'c.created AS post_created',
                'c.modified AS post_modified',
                'c.slug AS slug'
            )->from('table.growth_plan_posts AS pp')
                ->join('table.contents AS c', 'c.cid = pp.post_id', \Typecho\Db::INNER_JOIN)
                ->where('pp.plan_id IN (' . implode(',', $planIds) . ')')
                ->where('c.status = ?', 'publish')
                ->where('c.type = ?', 'post')
                ->order('c.created', \Typecho\Db::SORT_DESC)
                ->order('pp.id', \Typecho\Db::SORT_ASC)
        );

        $grouped = [];
        foreach ($rows as $row) {
            $post = [
                'cid'      => (int) $row['post_id'],
                'plan_id'  => (int) $row['plan_id'],
                'title'    => trim((string) ($row['title'] ?? '')),
                'text'     => (string) ($row['text'] ?? ''),
                // 对齐上面的别名：打卡日期取文章发布时间，不是关联时间
                'created'  => (int) ($row['post_created'] ?? 0),
                'modified' => (int) ($row['post_modified'] ?? 0),
                'slug'     => (string) ($row['slug'] ?? ''),
            ];
            $post['permalink'] = self::postPermalink($post);
            $post['excerpt']  = self::postExcerpt($post['text']);
            $grouped[$post['plan_id']][] = $post;
        }

        return $grouped;
    }

    /**
     * 生成文章的访问链接
     *
     * 优先用 Typecho 的 Router 按当前永久链接规则生成漂亮地址；
     * 任何异常（路由参数不全、未配置路由等）一律回落到 `?p=cid` ——
     * 这是 Typecho 永远认的兜底形式，保证链接 100% 可点开。
     */
    public static function postPermalink(array $post): string
    {
        $cid = (int) ($post['cid'] ?? 0);
        if ($cid <= 0) {
            return '';
        }

        try {
            $url = Router::url('post', $post);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        } catch (\Throwable $e) {
            // 回落到 ?p=cid
        }

        try {
            $siteUrl = (string) Options::alloc()->siteUrl;
        } catch (\Throwable $e) {
            $siteUrl = '';
        }
        $siteUrl = rtrim($siteUrl, '/');

        return $siteUrl . '/?p=' . $cid;
    }

    /**
     * 由文章正文生成时间线节点用的短摘要
     *
     * 去掉 HTML 标签与 markdown 标记（代码块、图片、标题符），
     * 截到 $len 字并加省略号。空正文返回空串（节点展开区就不显示摘要）。
     */
    public static function postExcerpt(string $text, int $len = 120): string
    {
        // 去掉 markdown 的 <!--markdown--> 前缀
        $text = preg_replace('/^<!--markdown-->/', '', $text);
        // 去掉代码块
        $text = preg_replace('/```[\s\S]*?```/', ' ', $text);
        // 去掉行内代码 / 图片 / 链接，只留可见文字
        $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/', ' ', $text);
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text);
        // 去掉 HTML 标签
        $text = strip_tags($text);
        // 去掉标题/列表/引用等 markdown 记号
        $text = preg_replace('/^[\s>#*\-\+]+/m', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        if ($text === '') {
            return '';
        }
        if (mb_strlen($text, 'UTF-8') > $len) {
            $text = mb_substr($text, 0, $len, 'UTF-8') . '…';
        }

        return $text;
    }

    /**
     * 给编辑器用的计划下拉选项（支持多选）
     *
     * 加载全部计划 → 生成树 → 拍平成带「——」缩进的选项列表，
     * 深层子计划用缩进表达归属，方便在编辑器里选到具体的子计划。
     *
     * @return array [['value'=>int,'label'=>string,'depth'=>int], ...]
     */
    public static function planSelectOptions(): array
    {
        $db = Db::get();
        $plans = $db->fetchAll(
            $db->select()->from('table.growth_plans')
                ->order('year', \Typecho\Db::SORT_DESC)
                ->order('sort', \Typecho\Db::SORT_ASC)
                ->order('id', \Typecho\Db::SORT_ASC)
        );

        if (empty($plans)) {
            return [];
        }

        // ⚠️ planTree() 返回的是 [ $roots, $children ] 两元组（$children 是
        //    parent_id => [plan,...] 的索引），**不是** 带 'plan'/'children' 键
        //    的嵌套节点。前台模板就是这么用的：list($roots, $children) = planTree(...)。
        //    这里必须按同一份契约走，否则遍历到的是「两个数组」，
        //    $node['plan'] 全是 null —— 选项会退化成两个「未命名计划」，
        //    而且 value 恒为 0。
        [$roots, $children] = self::planTree($plans);
        $flat = [];

        /* 已完成的计划不再出现在「关联成长计划」选择器里。
           已完成的计划就该封账，再挂新文章会让时间线继续变长，语义上也说不通。
           判定统一走 self::status()，与后台列表 / 前台时间线显示同一套口径，
           不会出现「后台显示已完成、这里却还能选」的分裂。
           注意：只排除 done，不动 archived —— 归档是另一套独立开关，
           归档计划按现有设计仍可关联。

           ⚠️ 必须过滤**整棵树**，不能只过滤 $roots：
           planTree() 返回的 $roots 只含 parent_id 为空的根计划，所有子/孙计划
           都在 $children 里（按 parent_id 索引）。所以先把所有 done 的 id 收进
           集合，再在下面 $walk 遍历时统一跳过 —— 遍历天然覆盖 roots + children
           全树，不存在漏层。 */
        $doneIds = [];
        foreach ($plans as $plan) {
            if (self::status($plan) === 'done') {
                $doneIds[(int) $plan['id']] = true;
            }
        }

        $walk = static function (array $nodes, int $depth) use (&$walk, &$flat, $children, $doneIds): void {
            foreach ($nodes as $plan) {
                if (isset($doneIds[(int) ($plan['id'] ?? 0)])) {
                    continue;   // 已完成的计划：整棵树一起跳过（含深层子孙）
                }
                $title = trim((string) ($plan['title'] ?? ''));
                if ($title === '') {
                    $title = '未命名计划';
                }
                $year = (int) ($plan['year'] ?? 0);
                $indent = $depth > 0 ? str_repeat('——', $depth) . ' ' : '';
                $label = $indent . $title . ($year > 0 ? '（' . $year . '年）' : '');
                $pid = (int) $plan['id'];
                $flat[] = [
                    'value' => $pid,
                    'label' => $label,
                    'depth' => $depth,
                ];
                if (!empty($children[$pid])) {
                    $walk($children[$pid], $depth + 1);
                }
            }
        };
        $walk($roots, 0);

        return $flat;
    }

    /**
     * 替换某篇文章关联的所有计划
     *
     * 先删后插，保证「在编辑器里取消勾选」也能生效。
     * $planIds 里 ≤0 的值会被过滤掉。
     */
    public static function savePostPlans(int $postId, array $planIds): void
    {
        $postId = (int) $postId;
        if ($postId <= 0) {
            return;
        }

        $planIds = array_values(array_unique(array_map('intval', $planIds)));
        $planIds = array_filter($planIds, static function ($v) {
            return $v > 0;
        });

        $db = Db::get();
        $db->query(
            $db->delete('table.growth_plan_posts')
                ->where('post_id = ?', $postId),
            \Typecho\Db::WRITE
        );

        $now = time();
        foreach ($planIds as $planId) {
            $db->query(
                $db->insert('table.growth_plan_posts')
                    ->rows([
                        'post_id' => $postId,
                        'plan_id' => $planId,
                        'created' => $now,
                    ]),
                \Typecho\Db::WRITE
            );
        }
    }

    /**
     * 删除某篇文章的全部打卡映射（文章删除时调用）
     */
    public static function deletePostPlans(int $postId): void
    {
        $postId = (int) $postId;
        if ($postId <= 0) {
            return;
        }

        $db = Db::get();
        $db->query(
            $db->delete('table.growth_plan_posts')
                ->where('post_id = ?', $postId),
            \Typecho\Db::WRITE
        );
    }

    /**
     * 文章发布成功后的回调（Widget_Contents_Post_Edit::finishPublish）
     *
     * 此时 $widget->cid 已就绪，直接读取编辑器提交的勾选并落库。
     *
     * @param array  $contents 已保存的文章数据
     * @param object $widget   Widget_Contents_Post_Edit 实例
     */
    public static function onFinishPublish(array $contents, $widget): array
    {
        $postId = (int) ($widget->cid ?? 0);
        if ($postId > 0) {
            $picked = [];
            try {
                $picked = $widget->request->getArray('growth_plan_ids');
            } catch (\Throwable $e) {
                $picked = [];
            }
            self::savePostPlans($postId, is_array($picked) ? $picked : []);
        }

        return $contents;
    }

    /**
     * 文章保存过滤器（发布前）
     *
     * 仅当 $widget->cid 已存在（编辑已发布/草稿文章）时才同步 ——
     * 新建草稿此时 cid 还没生成，交给 onFinishPublish 在发布时兜底。
     * 注意：这是过滤器，必须原样返回 $contents。
     */
    public static function onWrite(array $contents, $widget): array
    {
        $postId = (int) ($widget->cid ?? 0);
        if ($postId > 0) {
            $picked = [];
            try {
                $picked = $widget->request->getArray('growth_plan_ids');
            } catch (\Throwable $e) {
                $picked = [];
            }
            self::savePostPlans($postId, is_array($picked) ? $picked : []);
        }

        return $contents;
    }

    /**
     * 文章删除回调（Widget_Contents_Post_Edit::delete）
     *
     * 清掉该文章的打卡映射，避免孤儿行。显示侧本就 INNER JOIN contents，
     * 删了文章节点自然消失；这里再清映射，让映射表保持干净。
     *
     * ⚠️ 钩子签名是 delete($post, $widget)，其中 **$post 就是一个 cid 整数**
     *    （Edit::deletePost() 里 `self::pluginHandle()->delete($post, $this)`，
     *    $post 来自 `request->filter('int')->getArray('cid')` 的循环项）。
     *    $widget 上并没有 cid 属性，早先写 `$widget->cid` 永远拿到 0，
     *    映射行会一直残留成孤儿。这里按真实签名取 $post，
     *    并兼容「$post 万一是数组」的情形。
     */
    public static function onDelete($post, $widget): void
    {
        if (is_array($post)) {
            $postId = (int) ($post['cid'] ?? 0);
        } else {
            $postId = (int) $post;
        }

        if ($postId <= 0) {
            $postId = (int) ($widget->cid ?? 0);
        }

        if ($postId > 0) {
            self::deletePostPlans($postId);
        }
    }

    /**
     * 在写文章/写页面后台渲染「关联成长计划」多选框
     *
     * 挂 admin/footer.php 的 end 事件，输出一个隐藏容器 + 一段 JS，
     * JS 把选择框移入写文章表单（表单名 write_post）的「高级选项」区。
     * 用 JS 移动而非直接输出到表单内，是因为插件无法直接往 write-post.php
     * 的表单里插 HTML（没有对应钩子），页脚是唯一稳定的注入点。
     */
    public static function renderPostMetaBox(): void
    {
        // 只在写文章页出现（按需求只做文章；页面如需同样能力，把白名单加上 write-page.php 即可）
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($script !== 'write-post.php') {
            return;
        }

        $db = Db::get();

        // 当前文章已关联的计划（编辑已存在文章时回显勾选）
        $selected = [];
        $editCid = (int) ($_GET['cid'] ?? 0);
        if ($editCid > 0) {
            $rows = $db->fetchAll(
                $db->select('plan_id')->from('table.growth_plan_posts')
                    ->where('post_id = ?', $editCid)
            );
            foreach ($rows as $r) {
                $selected[] = (int) $r['plan_id'];
            }
        }

        $options = self::planSelectOptions();

        echo '<div id="gp-post-meta-tpl" style="display:none">' . "\n";
        echo '  <div class="typecho-option">' . "\n";
        echo '    <label class="typecho-label" for="growth_plan_ids">关联成长计划（打卡）</label>' . "\n";
        echo '    <p class="description">发布后，本文会作为「打卡」自动出现在所选计划的时间线上；可多选。改文章 / 撤稿 / 删除都会自动同步。</p>' . "\n";
        echo '    <select name="growth_plan_ids[]" id="growth_plan_ids" multiple size="8"'
            . ' style="width:100%;min-width:240px;height:auto;min-height:170px;padding:6px 8px;vertical-align:middle">' . "\n";
        if (empty($options)) {
            echo '      <option value="" disabled>还没有任何成长计划</option>' . "\n";
        } else {
            foreach ($options as $opt) {
                $v = (int) $opt['value'];
                $isSel = in_array($v, $selected, true) ? ' selected' : '';
                echo '      <option value="' . $v . '"' . $isSel . '>'
                    . htmlspecialchars($opt['label'], ENT_QUOTES, 'UTF-8') . '</option>' . "\n";
            }
        }
        echo '    </select>' . "\n";
        echo '  </div>' . "\n";
        echo '</div>' . "\n";
        ?>
        <script>
        (function () {
            var form = document.querySelector('form[name="write_post"]');
            var tpl = document.getElementById('gp-post-meta-tpl');
            if (!form || !tpl) { return; }
            var node = tpl.querySelector('.typecho-option');
            if (!node) { return; }
            var panel = document.getElementById('advance-panel');
            if (panel) {
                panel.appendChild(node);
            } else {
                form.appendChild(node);
            }
            tpl.parentNode && tpl.parentNode.removeChild(tpl);
        })();
        </script>
        <?php
    }

    /**
     * 汇总多条计划的「已完成历史轮次」天数，用于累计进度
     *
     * 一次查询后在 PHP 里累加，而不是每条计划一个 SUM 子查询 ——
     * 后者在计划多的时候就是 N+1。
     *
     * 注意：只汇总已封存的历史轮次，当前轮不在这里，
     * 当前轮的天数由 progress() 现算，否则会重复计数。
     *
     * @param int[] $planIds
     * @return array [planId => ['total'=>int,'gone'=>int]]
     */
    public static function carryForPlans(array $planIds): array
    {
        $planIds = array_values(array_unique(array_map('intval', $planIds)));
        $planIds = array_filter($planIds, static function ($v) {
            return $v > 0;
        });

        if (empty($planIds)) {
            return [];
        }

        $db = Db::get();
        $rows = $db->fetchAll(
            $db->select('plan_id', 'days_total', 'days_gone')->from('table.growth_rounds')
                ->where('plan_id IN (' . implode(',', $planIds) . ')')
        );

        $carry = [];
        foreach ($rows as $row) {
            $pid = (int) $row['plan_id'];
            if (!isset($carry[$pid])) {
                $carry[$pid] = ['total' => 0, 'gone' => 0];
            }
            $carry[$pid]['total'] += (int) $row['days_total'];
            $carry[$pid]['gone'] += (int) $row['days_gone'];
        }

        return $carry;
    }

    /**
     * 把累计天数注入计划数据，供 progress() 读取
     *
     * 纯函数、不查库，方便单测直接构造入参断言结果。
     * 键名带下划线前缀，跟数据库列区分开，避免和真实列混淆。
     *
     * @param array $plan
     * @param array $carry ['total'=>int,'gone'=>int] 或 []
     * @return array
     */
    public static function applyCarry(array $plan, array $carry): array
    {
        $plan['_carry_total'] = max(0, (int) ($carry['total'] ?? 0));
        $plan['_carry_gone'] = max(0, (int) ($carry['gone'] ?? 0));

        return $plan;
    }

    /**
     * 取「归档一条计划时应该一起归档的全部 id」：自身 + 全部后代
     *
     * 直接复用 descendantIds()，它已经带「自身不存在返回 []」的保护，
     * 调用方拿到空数组时必须报错而不是静默成功。
     *
     * @param int $id
     * @param array $plans
     * @return int[]
     */
    public static function archivedPlanIds(int $id, array $plans): array
    {
        return self::descendantIds($id, $plans);
    }

    /**
     * 级联删除一条计划：子树 + 全部日志 + 全部配图
     *
     * @param int $id
     * @param array $plans 全量计划（用于找出子树）
     * @return int 删掉的计划条数
     * @throws \Typecho\Db\Exception
     */
    public static function deletePlanCascade(int $id, array $plans): int
    {
        if ($id <= 0) {
            return 0;
        }

        $db = Db::get();
        $ids = self::descendantIds($id, $plans);

        if (empty($ids)) {
            return 0;
        }

        $inIds = implode(',', array_map('intval', $ids));

        // 先查日志 id，再按日志 id 删图；空集合不能拼 IN ()，会语法错误
        $logIds = [];
        foreach ($db->fetchAll(
            $db->select('id')->from('table.growth_logs')->where('plan_id IN (' . $inIds . ')')
        ) as $row) {
            $logIds[] = (int) $row['id'];
        }

        if (!empty($logIds)) {
            $inLogs = implode(',', $logIds);
            $db->query(
                $db->delete('table.growth_log_images')->where('log_id IN (' . $inLogs . ')')
            );
        }

        $db->query($db->delete('table.growth_logs')->where('plan_id IN (' . $inIds . ')'));
        // 历史轮次属于计划本身，计划没了轮次就没有归属了，跟着删
        $db->query($db->delete('table.growth_rounds')->where('plan_id IN (' . $inIds . ')'));
        // 文章打卡映射：计划删了，挂在它上面的打卡节点也没了归属，清掉避免孤儿行
        $db->query($db->delete('table.growth_plan_posts')->where('plan_id IN (' . $inIds . ')'));
        $db->query($db->delete('table.growth_plans')->where('id IN (' . $inIds . ')'));

        return count($ids);
    }

    /**
     * 取某条计划下的子计划条数
     *
     * @param int $id
     * @param array $plans
     * @return int
     */
    public static function childCount(int $id, array $plans): int
    {
        $n = 0;
        foreach ($plans as $plan) {
            if ((int) ($plan['parent_id'] ?? 0) === $id) {
                $n++;
            }
        }

        return $n;
    }

    // ---------------------------------------------------------------------
    // 工具方法
    // ---------------------------------------------------------------------

    /**
     * HTML 转义
     *
     * @param mixed $value
     * @return string
     */
    public static function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * 校验并规范化颜色值（只接受 #rgb / #rrggbb）
     *
     * @param string|null $color
     * @param string $fallback 允许为空时返回该值
     * @return string
     */
    public static function normalizeColor(?string $color, string $fallback = ''): string
    {
        $color = trim((string) $color);
        if ($color === '') {
            return $fallback;
        }

        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color)) {
            return strtolower($color);
        }

        return $fallback;
    }

    /**
     * 校验日期字符串（Y-m-d），非法返回 ''
     *
     * @param string|null $date
     * @return string
     */
    public static function normalizeDate(?string $date): string
    {
        $date = trim((string) $date);
        if ($date === '') {
            return '';
        }

        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            return '';
        }

        // [本站适配 2026-09-22] 年份补全：<input type="date"> 里按习惯只输两位年份（如 "26"），
        // 浏览器会补成 "0026" 原样提交，此前直接入库（前台显示成 0026 年）。
        // 现在按常见规则还原：00–68 → 20xx，69–99 → 19xx；还原后仍在合理范围外视为无效。
        $year = (int) $m[1];
        if ($year < 100) {
            $year += $year <= 68 ? 2000 : 1900;
        }
        if ($year < 1970 || $year > 2100) {
            return '';
        }

        if (!checkdate((int) $m[2], (int) $m[3], $year)) {
            return '';
        }

        return sprintf('%04d-%02d-%02d', $year, (int) $m[2], (int) $m[3]);
    }

    /**
     * 取计划的封面图地址
     *
     * 只做基本净化：去掉首尾空白、截断到表字段长度（VARCHAR(500)），
     * 并且只放行 http/https 或站内绝对路径。
     *
     * ⚠️ 为什么必须挡掉 `javascript:` / `data:` 这些 scheme：
     * 这里的值会被直接写进 <img src="…">。虽然本插件只有管理员能填，
     * 但后台表单和前台展示之间隔着一次数据库往返，中间若有别的写入路径
     * （导入、外部脚本、SQL 注入），未净化的值就变成存储型 XSS 的载体。
     * 白名单比黑名单稳：只认 http/https/// 三种前缀，其余一律当没填。
     *
     * @param array|string|null $plan 计划行，或直接的封面字符串
     * @return string 合法返回原地址，否则返回空串
     */
    public static function cover($plan): string
    {
        $url = is_array($plan) ? (string) ($plan['cover'] ?? '') : (string) $plan;
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        // 长度对齐表字段。URL 是 ASCII 场景为主，但用户可能填中文路径，
        // 所以按字节截到 500，宁可截断也不让 DB 静默丢整行。
        if (strlen($url) > 500) {
            $url = substr($url, 0, 500);
        }

        // 站内相对路径：// 开头是协议相对 URL，也放行。
        //
        // ⚠️ 这里不能只判 `[a-zA-Z0-9.\-]+`。那样 `//javascript:alert(1)`
        // 会被当成「协议相对 URL，host 是 javascript」而放行 ——
        // 浏览器解析协议相对 URL 时会把它拼成 `http://javascript:alert(1)`，
        // 虽然多数浏览器不会再执行，但这是我不想要的灰色地带。
        // 要求 host 里必须有一个形如 `.tld` 的段（长度 ≥2 的纯字母），
        // 裸 `javascript` 就不满足了。
        if (strpos($url, '//') === 0) {
            return preg_match('#^//[a-zA-Z0-9\-]+(\.[a-zA-Z0-9\-]+)*\.[a-zA-Z]{2,}([:/?\#]|$)#', $url)
                ? $url
                : '';
        }
        if (strpos($url, '/') === 0) {
            return $url;
        }

        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        // 没写 scheme 的裸域名（img.example.com/a.jpg）也当成 https 补全，
        // 用户手填时很常见，直接判非法太苛刻。
        if (preg_match('#^[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}(/|$)#', $url)) {
            return 'https://' . $url;
        }

        return '';
    }

    /**
     * 中文日期格式：2026年9月21日
     *
     * @param string|null $date
     * @return string
     */
    public static function cnDate(?string $date): string
    {
        $ts = self::parseDate($date);
        if (null === $ts) {
            return '';
        }

        return (int) date('Y', $ts) . '年'
            . (int) date('n', $ts) . '月'
            . (int) date('j', $ts) . '日';
    }
}
