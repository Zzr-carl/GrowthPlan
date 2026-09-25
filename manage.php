<?php
/**
 * GrowthPlan 后台管理面板
 *
 * 由 Typecho 通过 admin/extending.php?panel=GrowthPlan%2Fmanage.php 引入，
 * 因此 $db / $user / $security / $request / $response / $options 均已可用。
 *
 * 视图：计划（plans） / 类型（categories） / 设置（内嵌在页面底部）
 *
 * @package GrowthPlan
 */

use Typecho\Db;
use TypechoPlugin\GrowthPlan\Plugin;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

// 仅管理员可访问
if (method_exists(Plugin::class, 'requireAdmin')) {
    Plugin::requireAdmin();
} else {
    \Widget\User::alloc()->pass('administrator', true);
}

// 面板文件在某些场景下可能被重复 include，避免类重复声明
if (!class_exists('GrowthPlan_Admin', false)) {
    /**
     * 成长计划管理面板
     */
    class GrowthPlan_Admin
    {
        /** @var Plugin */
        private $plugin;

        /** @var array */
        private $cfg;

        /** @var string 当前视图 */
        private $view;

        /** @var int 当前操作的记录 ID */
        private $id;

        public function __construct(Plugin $plugin)
        {
            $this->plugin = $plugin;
            $this->cfg = Plugin::settings();

            $this->view = (string) $this->param('view', 'plans');
            $this->id = (int) $this->param('id', 0);
        }

        // -----------------------------------------------------------------
        // 入口
        // -----------------------------------------------------------------

        public function dispatch()
        {
            $do = (string) $this->param('do', '');

            // AJAX 上传必须先于一切 HTML 输出处理。它要返回纯 JSON，
            // 一旦走到下面的 admin/header.php，页面骨架就会混进响应体里。
            if ($do === 'up_log') {
                $this->uploadLogImage();
                return;
            }

            if ($do !== '') {
                $this->handleAction($do);
            }

            $this->autoCreatePage();

            header('Content-Type: text/html; charset=UTF-8');

            $message = (string) $this->param('msg', '');
            $error = (string) $this->param('err', '');

            // 关键：admin/extending.php 本身只 include 面板文件，不输出任何
            // html / head 标签。如果不自己 include 后台的 header/menu，
            // 页面会是一个没有 CSS 的裸骨架（这是相册插件踩过的坑）。
            //
            // $menu / $options / $security / $request 等均由 admin/common.php 提供，
            // 这里用 include 引入即可（官方 manage-pages.php 也是同样写法）。
            // header.php 需要 $menu，所以顺序必须是 header → menu。
            $adminDir = defined('__TYPECHO_ROOT_DIR__') ? __TYPECHO_ROOT_DIR__ . '/admin' : null;

            if ($adminDir && file_exists($adminDir . '/header.php')) {
                if (!isset($options) || !($options instanceof \Widget\Options)) {
                    $options = \Widget\Options::alloc();
                }
                if (!isset($user) || !($user instanceof \Widget\User)) {
                    $user = \Widget\User::alloc();
                }
                if (!isset($menu) || !($menu instanceof \Widget\Menu)) {
                    \Widget\Menu::alloc()->init($options, true);
                    $menu = \Widget\Menu::alloc();
                }

                include $adminDir . '/header.php';
                include $adminDir . '/menu.php';
            }

            $this->renderStyle();

            echo '<div class="main"><div class="body container">';

            switch ($this->view) {
                case 'categories':
                    $this->renderCategories($message, $error);
                    break;
                case 'plans':
                default:
                    $this->renderPlans($message, $error);
                    break;
            }

            echo '</div></div>';

            $this->renderScript();

            if ($adminDir && file_exists($adminDir . '/footer.php')) {
                include $adminDir . '/footer.php';
            }
        }

        /**
         * 面板脚本
         *
         * 只做两件事：给日志表单动态加/删图片行，以及把本地图片上传到
         * Typecho 原生上传接口后回填 URL 输入框。用原生 fetch，不引任何库。
         */
        private function renderScript()
        {
            ?>
            <script>
            (function () {
                'use strict';

                // 每行图片的 HTML 模板（行号由 JS 递增，保证不覆盖已提交的索引）
                var counter = {};

                function blankRow(formKey, index) {
                    var div = document.createElement('div');
                    div.className = 'gp-img-row';
                    div.innerHTML =
                        '<input type="text" class="gp-img-url" placeholder="https://…/photo.jpg 或直接上传">' +
                        '<input type="text" class="gp-img-cap" placeholder="这张图的说明（鼠标悬停时会显示）">' +
                        '<label class="gp-upload-btn">上传<input type="file" accept="image/*" hidden></label>' +
                        '<button type="button" class="btn btn-xs gp-img-remove">移除</button>';
                    div.querySelector('.gp-img-url').name = 'images[' + index + '][url]';
                    div.querySelector('.gp-img-cap').name = 'images[' + index + '][caption]';
                    return div;
                }

                document.addEventListener('click', function (ev) {
                    var addBtn = ev.target.closest ? ev.target.closest('.gp-img-add') : null;
                    if (addBtn) {
                        var targetId = addBtn.getAttribute('data-target');
                        var box = document.getElementById(targetId);
                        if (!box) { return; }
                        if (counter[targetId] === undefined) {
                            counter[targetId] = parseInt(addBtn.getAttribute('data-start') || '0', 10);
                        }
                        box.appendChild(blankRow(targetId, counter[targetId]++));
                        return;
                    }

                    var rmBtn = ev.target.closest ? ev.target.closest('.gp-img-remove') : null;
                    if (rmBtn) {
                        var row = rmBtn.closest('.gp-img-row');
                        if (row && row.parentNode) {
                            // 清空而不是移除：留一个空行，用户能立刻重新填
                            var url = row.querySelector('.gp-img-url');
                            var cap = row.querySelector('.gp-img-cap');
                            if (url) { url.value = ''; }
                            if (cap) { cap.value = ''; }
                        }
                    }
                });

                // 删除/归档这类不可逆操作的二次确认。
                //
                // 必须拦 submit 而不是 click：按钮渲染在 <td> 里，表单却在
                // 表格外用 form="id" 关联，click 的目标是 <button> 拿不到
                // data-confirm，只有 submit 事件的 target 才是那个 form。
                document.addEventListener('submit', function (ev) {
                    var form = ev.target;
                    if (!form || !form.classList) { return; }
                    if (!form.classList.contains('gp-js-confirm')) { return; }

                    var msg = form.getAttribute('data-confirm');
                    if (msg && !window.confirm(msg)) {
                        ev.preventDefault();
                    }
                });

                // 本地上传：选中文件后立刻 POST，成功就把返回的 URL 填进本行输入框
                document.addEventListener('change', function (ev) {
                    var input = ev.target;
                    if (!input || input.type !== 'file') { return; }
                    if (!input.files || !input.files.length) { return; }

                    var row = input.closest('.gp-img-row');
                    var form = input.closest('form');
                    var msg = form ? form.querySelector('.gp-upload-msg') : null;
                    // 日志配图那一行的 URL 输入框带 .gp-img-url；
                    // 计划封面只有一个单行输入，没有这个类，所以再兜一层
                    // （取该行里第一个文本输入框），否则封面图上传后不会回填。
                    var urlField = row
                        ? (row.querySelector('.gp-img-url') || row.querySelector('input[type="text"]'))
                        : null;
                    var uploadUrl = input.getAttribute('data-upload-url');
                    var token = input.getAttribute('data-token') || '';

                    if (!uploadUrl) { return; }

                    if (msg) { msg.textContent = '上传中…'; msg.className = 'gp-upload-msg'; }

                    var fd = new FormData();
                    fd.append('file', input.files[0]);
                    fd.append('_', token);

                    fetch(uploadUrl, {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin'
                    }).then(function (res) {
                        return res.json();
                    }).then(function (data) {
                        if (data && data.ok && urlField) {
                            urlField.value = data.url;
                            // 封面图上传后顺手把预览图刷新出来，省一次保存+刷新
                            var prev = row.parentNode
                                ? row.parentNode.querySelector('.gp-cover-preview')
                                : null;
                            if (prev) { prev.src = data.url; }
                            if (msg) {
                                msg.textContent = '已上传：' + (data.name || '');
                                msg.className = 'gp-upload-msg is-ok';
                            }
                        } else {
                            if (msg) {
                                msg.textContent = '上传失败：' + ((data && data.msg) || '未知错误');
                                msg.className = 'gp-upload-msg is-err';
                            }
                        }
                    }).catch(function () {
                        if (msg) {
                            msg.textContent = '上传失败，请检查网络或文件大小';
                            msg.className = 'gp-upload-msg is-err';
                        }
                    }).then(function () {
                        input.value = '';
                    });
                });
            })();
            </script>
            <?php
        }

        /**
         * 面板样式
         *
         * 前缀统一 gp-，避免和后台自身或别的插件互相污染。
         */
        private function renderStyle()
        {
            ?>
<style>
/* ---------------- GrowthPlan 后台样式 ---------------- */
.gp-backend{margin:0}
.gp-page-title{display:flex;align-items:baseline;gap:14px;margin:6px 0 14px}
.gp-page-title h2{margin:0;font-size:20px;font-weight:600}
.gp-add-link{font-size:13px}

/* 顶部导航 + 状态 */
.gp-nav{display:flex;flex-wrap:wrap;align-items:center;gap:16px;
  margin:0 0 16px;padding-bottom:10px;border-bottom:1px solid rgba(0,0,0,.08)}
.gp-nav-list{display:flex;gap:6px;margin:0;padding:0;list-style:none}
.gp-nav-list a{display:block;padding:5px 14px;border-radius:20px;font-size:13px;
  color:#6b7280;text-decoration:none;border:1px solid transparent;transition:all .18s}
.gp-nav-list a:hover{background:rgba(0,0,0,.04);color:#111}
.gp-nav-list .is-active a{background:#D97757;color:#fff;border-color:#D97757}
.gp-nav-status{margin-left:auto;font-size:12.5px;color:#6b7280}
.gp-nav-status strong.on{color:#059669}
.gp-nav-status strong.off{color:#b91c1c}

/* 卡片 */
.gp-card{background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:10px;
  padding:18px;margin-bottom:16px;box-shadow:0 1px 2px rgba(0,0,0,.03)}
.gp-card-title{margin:0 0 14px;font-size:15px;font-weight:600;color:#111827}

/* 提示 */
.gp-notice{padding:9px 14px;border-radius:8px;font-size:13px;margin-bottom:14px}
.gp-notice-ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
.gp-notice-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}

/* 工具条 */
.gp-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-bottom:14px}
.gp-filters{display:flex;flex-wrap:wrap;gap:6px}
.gp-chip{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;
  border-radius:16px;border:1px solid rgba(0,0,0,.1);font-size:12.5px;
  color:#4b5563;text-decoration:none;background:#fff;transition:all .18s}
.gp-chip:hover{border-color:#9ca3af;color:#111}
.gp-chip.is-on{background:#D97757;border-color:#D97757;color:#fff}
.gp-chip em{font-style:normal;font-size:11px;opacity:.7}
.gp-chip.is-on em{opacity:.85}

/* 表格 */
.gp-table{width:100%;border-collapse:collapse;font-size:13px;overflow:visible}
/* 显式声明 table-cell + vertical-align：某些宿主样式会把 th 的
   display 改成 block 并加 float，首尾两列（列的起止两端）会被
   挤到下一行，表现为表头与数据列整体错位。这里钉死，不外借。 */
.gp-table th{display:table-cell;vertical-align:bottom;text-align:left;
  font-weight:600;color:#6b7280;font-size:12px;
  padding:8px 10px;border-bottom:1px solid rgba(0,0,0,.09);white-space:nowrap}
.gp-table td{display:table-cell;vertical-align:middle;
  padding:10px;border-bottom:1px solid rgba(0,0,0,.05)}
.gp-table thead tr{display:table-row}
.gp-table tbody tr{display:table-row}
.gp-table tr:last-child td{border-bottom:0}
/* ⚠️ 一律用 min-width，绝不给列写死 width。
   曾经的 bug：给「操作」列写 width:250px，加上内容里的 details/form
   元素撑开，列宽总和逼近容器宽度时，Chromium 会把「ID」和「操作」两个
   表头挤到第二行渲染（thead 里明明还是同一个 tr），看起来像表头
   与数据列完全错位。改成 min-width 后浏览器会自己合理地分配。
   注意：本注释里不能出现 HTML 标签字面量，否则会被测试的表单提取
   正则误判成一个真表单。 */
.gp-table .gp-w-id{min-width:52px}
.gp-table .gp-w-sort{min-width:86px}
.gp-table .gp-w-num{min-width:74px}
.gp-table .gp-w-op{min-width:132px}
.gp-table .gp-w-date{min-width:112px}
.gp-table-plan .gp-w-year{min-width:56px}
.gp-table-plan .gp-w-range{min-width:96px}
.gp-table-plan .gp-w-prog{min-width:132px}
.gp-table-plan .gp-w-status{min-width:82px}
.gp-table-plan .gp-w-sort{min-width:70px}
/* 操作列：给一个「够放 3 个按钮」的窄下限，剩下的交给 flex-wrap 换行。
   绝不写 width、也不给大 min-width —— 表格各列 min-width 的总和一旦
   接近容器宽度，Chromium 的自动表格布局就会把挤不下的表头（通常是
   首列 ID 和末列 操作）折到第二行渲染，看起来像整张表错位。 */
.gp-table-plan .gp-w-op{min-width:132px}
/* 封面缩略图列。宽度给得窄（56px），因为它在视觉上只是「一眼认出是哪条」的
   辅助信息，不是主要内容。没封面时用类型色画一个占位块。 */
.gp-table-plan .gp-w-cover{min-width:56px}
/* 「计划」列必须给 min-width。它内容最长（标题 + 类型 + 子计划数），
   但没有任何显式宽度——Chromium 的自动表格布局会把富余空间优先分给
   其他列，把它压到 78px 左右，装不下就折行，连累整个 thead 被撑到
   两行渲染，看起来像表头错位。给个下限就稳了。 */
.gp-table-plan .gp-w-plan{min-width:170px}
/* 缩略图本体：复用同一套尺寸给列表和表单预览 */
.gp-thumb{display:block;width:56px;aspect-ratio:16/10;object-fit:cover;
  border:1px solid rgba(0,0,0,.12);border-radius:5px;background:#f3f4f6}
/* 无封面占位：类型色画一根竖条，比纯灰方块更有信息量。
   不用 color-mix()：那玩意 Safari 16.2 以下、Chrome 111 以下都不认，
   --gp-cover 是十六进制值时用 opacity 叠出淡底最稳。 */
.gp-thumb.is-empty{
  display:flex;align-items:center;justify-content:center;
  background:#f3f4f6;border-color:rgba(0,0,0,.10);
}
.gp-thumb.is-empty::after{
  content:"";width:3px;height:18px;border-radius:2px;
  background:var(--gp-cover, #9ca3af);opacity:.5;
}
.gp-table-plan td.gp-w-range div{font-size:12px;line-height:1.55;white-space:nowrap}
.gp-mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#9ca3af;font-size:12px}
.gp-row-muted td{background:rgba(0,0,0,.015);color:#9ca3af}

/* 行内表单 */
.gp-inline-form{display:inline}
/* 类型名单元格：色点 + 输入框同行，输入框不再被挤到第二行 */
.gp-cat-name{display:flex;align-items:center;gap:8px;white-space:nowrap}
.gp-cat-name .gp-input-inline{flex:1 1 auto;min-width:0}
.gp-input-inline{width:100%;max-width:320px;padding:5px 9px;font-size:13px;
  border:1px solid rgba(0,0,0,.14);border-radius:6px;box-sizing:border-box}
.gp-input-inline:focus{outline:2px solid rgba(217,119,87,.30);border-color:#D97757}
.gp-input-mini{width:100%;max-width:72px;padding:5px 8px;font-size:13px;
  border:1px solid rgba(0,0,0,.14);border-radius:6px;box-sizing:border-box}
.gp-input-date{width:100%;max-width:150px;padding:5px 8px;font-size:12.5px;
  border:1px solid rgba(0,0,0,.14);border-radius:6px;box-sizing:border-box}
/* 操作按钮组：允许换行。若用 nowrap，5 个按钮的最小内容宽（约 260px）
   就成了这一列不可压缩的下限，直接把表格顶到溢出、表头折行。 */
.gp-ops{display:flex;align-items:center;flex-wrap:wrap;gap:6px}
.gp-btn-danger{color:#b91c1c !important}
.gp-hint{margin:10px 0 0;font-size:12.5px;color:#9ca3af;line-height:1.7}
.gp-empty{padding:26px 14px;text-align:center;color:#9ca3af;font-size:13px;
  border:1px dashed rgba(0,0,0,.13);border-radius:9px}

/* 表单栅格 */
.gp-form-grid{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end}
.gp-field{display:flex;flex-direction:column;gap:5px;font-size:12.5px;color:#6b7280}
.gp-field span{font-weight:500}
.gp-field input,.gp-field select,.gp-field textarea{
  padding:6px 10px;font-size:13px;border:1px solid rgba(0,0,0,.14);
  border-radius:6px;box-sizing:border-box;font-family:inherit;color:#111}
.gp-field input:focus,.gp-field select:focus,.gp-field textarea:focus{
  outline:2px solid rgba(217,119,87,.30);border-color:#D97757}
.gp-field-grow{flex:1 1 240px;min-width:200px}
.gp-field-md{flex:0 1 200px}
.gp-field-sm{flex:0 1 130px}
.gp-field-xs{flex:0 1 100px}
.gp-field-btn{flex:0 0 auto}
.gp-field textarea{resize:vertical;min-height:64px;line-height:1.6}
.gp-field-wide{flex:1 1 100%}
/* 取色器：浏览器原生 color 输入框自带一圈内边距和边框，
   直接套上面的 padding 会让它变成一块被撑歪的色块。
   这里单独压掉 padding、给固定高度，让它和旁边的文字框基线齐平。 */
.gp-field input[type="color"]{
  padding:2px;height:32px;width:100%;cursor:pointer;
  background:#fff;border:1px solid rgba(0,0,0,.14)}
.gp-field input[type="color"]::-webkit-color-swatch-wrapper{padding:0}
.gp-field input[type="color"]::-webkit-color-swatch{border:none;border-radius:3px}
.gp-field input[type="color"]::-moz-color-swatch{border:none;border-radius:3px}

/* 交付物检查 */
.gp-deliverables{margin-top:16px;padding-top:14px;border-top:1px solid rgba(0,0,0,.07)}
.gp-deliverable-row{display:flex;flex-wrap:wrap;align-items:center;gap:9px;
  font-size:12.5px;margin-bottom:8px}
.gp-deliverable-row:last-child{margin-bottom:0}
.gp-dl-label{min-width:78px;color:#6b7280;font-weight:500}
.gp-deliverable-row code{background:rgba(0,0,0,.045);padding:2px 7px;
  border-radius:5px;font-size:12px;
  font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
.gp-ok{color:#059669}
.gp-warn{color:#b45309}

/* 类型颜色小圆点 */
.gp-dot{display:inline-block;width:9px;height:9px;border-radius:50%;
  margin-right:7px;vertical-align:middle;background:#9ca3af}

/* 状态标签 */
.gp-tag{display:inline-block;padding:2px 9px;border-radius:12px;font-size:11.5px;
  line-height:1.7;white-space:nowrap}
.gp-tag-active{background:#eff6ff;color:#1d4ed8}
.gp-tag-soon{background:#fffbeb;color:#b45309}
.gp-tag-expired{background:#fef2f2;color:#b91c1c}
.gp-tag-pending{background:#f3f4f6;color:#4b5563}
.gp-tag-done{background:#ecfdf5;color:#065f46}
/* 归档是独立于时间状态的一维，用中性灰把它和「进行中」区分开 */
.gp-tag-archived{background:#f1f5f9;color:#64748b}

/* 进度条（后台预览） */
.gp-bar{position:relative;height:7px;border-radius:4px;background:rgba(0,0,0,.07);
  overflow:hidden;min-width:88px}
.gp-bar i{position:absolute;left:0;top:0;bottom:0;border-radius:4px;
  background:#D97757;display:block}
.gp-bar-text{font-size:11.5px;color:#6b7280;margin-top:4px}

/* 已移除「跟随系统深色」样式：后台面板统一亮色（不需要暗色模式）；
   原黑色元素统一换成 Claude 橙黄 #D97757 系。 */

/* --------------------------- 层级缩进 --------------------------- */
.gp-tree-cell{display:flex;align-items:flex-start;gap:6px}
.gp-branch{color:#c4cad1;font-size:13px;line-height:1.5;flex:0 0 auto;
  font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
.gp-tree-title{display:flex;flex-direction:column;gap:3px;min-width:0}
.gp-tree-title a{text-decoration:none}
.gp-tree-meta{margin-top:0;display:block}
.gp-row-child td{background:rgba(0,0,0,.016)}
/* 子计划整行稍作收窄，视觉上「缩」进父计划里 */
.gp-row-child td:first-child{color:#c4cad1}

/* --------------------------- 计划日志 --------------------------- */
#gp-log-manager{margin-top:18px}
.gp-sub-title{margin:0 0 12px;font-size:14px;font-weight:600;color:#B4532F}
.gp-log-picker{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:4px 0 16px}
.gp-log-picker-label{font-size:12.5px;color:#9ca3af;margin-right:2px}

.gp-log-items{list-style:none;margin:0 0 18px;padding:0;display:flex;
  flex-direction:column;gap:10px}
.gp-log-item{background:#fafbfc;border:1px solid rgba(0,0,0,.07);
  border-radius:9px;padding:11px 13px}
.gp-log-item-head{display:flex;align-items:center;flex-wrap:wrap;
  gap:8px;margin-bottom:6px}
.gp-log-item-head .gp-ops{margin-left:auto}
.gp-log-date{display:inline-block;padding:1px 8px;border-radius:6px;
  background:rgba(0,0,0,.05);font-size:11.5px;color:#374151;
  font-variant-numeric:tabular-nums}
.gp-log-owner{font-size:12px;color:#9ca3af}
.gp-log-body{margin:0 0 8px;font-size:13px;line-height:1.7;color:#374151;
  word-break:break-word}
.gp-log-thumbs{display:flex;flex-wrap:wrap;gap:8px}
.gp-thumb{margin:0;display:flex;flex-direction:column;gap:4px;max-width:110px}
.gp-thumb img{width:82px;height:82px;object-fit:cover;border-radius:7px;
  border:1px solid rgba(0,0,0,.09);display:block;background:rgba(0,0,0,.04)}
.gp-thumb figcaption{font-size:11px;color:#9ca3af;line-height:1.5;
  word-break:break-word}

.gp-log-form-wrap{border-top:1px solid rgba(0,0,0,.07);padding-top:16px}

/* 图片行 */
.gp-img-rows{display:flex;flex-direction:column;gap:8px;
  padding:10px;border:1px dashed rgba(0,0,0,.12);border-radius:9px}
.gp-img-row{display:flex;flex-wrap:wrap;gap:7px;align-items:center}
.gp-img-url{flex:1 1 300px;min-width:180px;padding:6px 10px;font-size:12.5px;
  border:1px solid rgba(0,0,0,.14);border-radius:6px;box-sizing:border-box;color:#111}
.gp-img-cap{flex:1 1 200px;min-width:140px;padding:6px 10px;font-size:12.5px;
  border:1px solid rgba(0,0,0,.14);border-radius:6px;box-sizing:border-box;color:#111}
.gp-img-url:focus,.gp-img-cap:focus{outline:2px solid rgba(217,119,87,.30);
  border-color:#D97757}
.gp-upload-btn{display:inline-block;padding:5px 11px;font-size:12px;
  border:1px solid rgba(0,0,0,.14);border-radius:6px;background:#fff;
  color:#374151;cursor:pointer;white-space:nowrap}
.gp-upload-btn:hover{background:#f3f4f6}
.gp-img-add{margin-top:9px}
.gp-upload-msg{margin-left:10px;font-size:12px;color:#9ca3af}
.gp-upload-msg.is-ok{color:#059669}
.gp-upload-msg.is-err{color:#b91c1c}
.gp-field-label{display:block;font-size:12.5px;color:#6b7280;font-weight:500;
  margin-bottom:6px}
/* 封面图预览：和前台卡片的封面同比例，所见即所得 */
.gp-cover-preview{display:block;margin-top:9px;width:168px;aspect-ratio:16/10;
  object-fit:cover;border:1px solid rgba(0,0,0,.12);border-radius:8px;
  background:#f3f4f6}

/* --------------------------- 归档 / 续期 --------------------------- */
/* 已归档的行整体压淡，一眼能从主列表里摘出来 */
.gp-row-archived td{background:rgba(0,0,0,.018);color:#9ca3af}
.gp-row-archived .gp-tree-title a{color:#9ca3af}

/* --------------------------- 归档 / 续期 ---------------------------
   「继续计划」面板用 :target 显隐，面板本体在表格之外。

   ⚠️ 这段是两次踩坑后的最终形态，改动前务必读完：
   面板里全是 input 这类块级元素。第一次把面板做成 details 浮层、
   改 position:absolute，以为脱离文档流就没事了 —— 不够。
   details 元素自身仍是 display:block 且占位，照样把「操作」列撑爆，
   Chromium 判定表格布局失效，把所有 th/td 的 display 改成
   block + float:left，整张表看着像列全错位（表头跑到第二行）。
   现在面板整个移出 table，表格内只留一个不占宽度的锚点。 */
.gp-row-archived td{background:rgba(0,0,0,.018);color:#9ca3af}
.gp-row-archived .gp-tree-title a{color:#9ca3af}

.gp-ext-panel{
  display:none;
  margin:0 0 16px;
  padding:14px 16px;
  border:1px solid rgba(0,0,0,.1);
  border-radius:10px;
  background:#fff;
  box-shadow:0 10px 30px rgba(0,0,0,.09), 0 2px 6px rgba(0,0,0,.05);
}
/* 命中 :target 才展开 —— 地址栏变成 #gp-ext-N */
.gp-ext-panel:target{display:block}
.gp-ext-panel .gp-field{display:block;margin-bottom:10px}
.gp-ext-panel .gp-field > span{display:block;margin-bottom:4px;font-size:12px;
  color:#6b7280;font-weight:500}
.gp-ext-panel .gp-field input{width:100%;max-width:260px;padding:5px 9px;
  font-size:12.5px;border:1px solid rgba(0,0,0,.14);border-radius:6px;
  box-sizing:border-box;color:#111;font-family:inherit}
.gp-ext-panel .gp-field input:focus{outline:2px solid rgba(217,119,87,.30);
  border-color:#D97757}
.gp-ext-panel .gp-ext-body{position:static;width:auto;padding:0;
  border:none;background:transparent;box-shadow:none}
.gp-ext-note{margin:0 0 11px;font-size:12px;color:#6b7280;line-height:1.7}
.gp-ext-note strong{color:#B4532F}
.gp-ext-actions{display:flex;align-items:center;gap:9px;margin-top:2px}


/* [本站适配 2026-09-22] 面板满宽：消除居中大留白 + 修复 100vw 横向溢出（与设备/相册面板统一） */
.main > .body.container{max-width:none;margin-left:0;margin-right:0}
.gp-backend{width:100%;min-width:0}
.gp-backend .row{width:auto;margin-left:0;margin-right:0}
.main > .body.container > .row{margin-left:0;margin-right:0}
</style>
            <?php
        }

        // -----------------------------------------------------------------
        // 表单处理
        // -----------------------------------------------------------------

        /**
         * @param string $do
         */
        private function handleAction($do)
        {
            // CSRF 校验失败会自动跳回
            $this->security()->protect();

            // 业务方法会通过 redirect() 结束请求，而 redirect() 最终走的是
            // Typecho\Widget\Response::redirect() -> Response::respond()。
            // 正常站点里 respond() 直接 exit，沙箱里则抛 Widget\Terminal。
            // catch 里必须先把 Terminal 原样抛出去，否则会把「跳转」当成
            // 「操作失败」，再 redirect 一次，形成层层自我递归。
            try {
                switch ($do) {
                    case 'add_category':
                        $this->addCategory();
                        break;
                    case 'edit_category':
                        $this->editCategory();
                        break;
                    case 'del_category':
                        $this->deleteCategory();
                        break;

                    case 'add_plan':
                        $this->addPlan();
                        break;
                    case 'edit_plan':
                        $this->editPlan();
                        break;
                    case 'del_plan':
                        $this->deletePlan();
                        break;
                    case 'archive_plan':
                        $this->archivePlan();
                        break;
                    case 'resume_plan':
                        $this->resumePlan();
                        break;

                    case 'add_log':
                        $this->addLog();
                        break;
                    case 'edit_log':
                        $this->editLog();
                        break;
                    case 'del_log':
                        $this->deleteLog();
                        break;

                    case 'save_config':
                        $this->saveConfig();
                        break;

                    default:
                        $this->redirect('plans', null, '未知操作');
                }
            } catch (\Typecho\Widget\Terminal $t) {
                // 请求正常终止（成功跳转），原样向上抛，交给框架处理
                throw $t;
            } catch (\Throwable $e) {
                // 只兜真正的业务异常（如 SQL 错误），避免白屏 500
                $this->redirect($this->view, null, '操作失败：' . $e->getMessage());
            }
        }

        // -----------------------------------------------------------------
        // 计划类型
        // -----------------------------------------------------------------

        private function addCategory()
        {
            $name = trim((string) $this->param('name', ''));
            $description = trim((string) $this->param('description', ''));
            $color = Plugin::normalizeColor($this->param('color', ''), '');
            $sort = (int) $this->param('sort', 0);

            if ($name === '') {
                $this->redirect('categories', null, '类型名不能为空');
            }

            $db = $this->db();
            $db->query(
                $db->insert('table.growth_categories')->rows([
                    'name'        => mb_substr($name, 0, 120),
                    'description' => mb_substr($description, 0, 255),
                    'color'       => $color,
                    'sort'        => $sort,
                ])
            );

            $this->redirect('categories', '类型「' . $name . '」已添加');
        }

        private function editCategory()
        {
            $id = (int) $this->param('id', 0);
            $name = trim((string) $this->param('name', ''));
            $sort = (int) $this->param('sort', 0);
            $description = trim((string) $this->param('description', ''));
            $color = Plugin::normalizeColor($this->param('color', ''), '');

            if ($id <= 0) {
                $this->redirect('categories', null, '参数不完整');
            }

            if ($name === '') {
                $this->redirect('categories', null, '类型名不能为空');
            }

            $db = $this->db();
            $row = $db->fetchRow(
                $db->select('id')->from('table.growth_categories')->where('id = ?', $id)->limit(1)
            );

            if (empty($row)) {
                $this->redirect('categories', null, '类型不存在');
            }

            $rows = [
                'name' => mb_substr($name, 0, 120),
                'sort' => $sort,
            ];

            // 描述与颜色只有在提交了才覆盖，避免行内快速改名时被清空
            if (null !== $this->param('description', null)) {
                $rows['description'] = mb_substr($description, 0, 255);
            }
            if (null !== $this->param('color', null)) {
                $rows['color'] = $color;
            }

            $db->query(
                $db->update('table.growth_categories')->rows($rows)->where('id = ?', $id)
            );

            $this->redirect('categories', '类型已更新');
        }

        /**
         * 删除类型
         *
         * 该类型下的计划不删除，统一归入「未分类」（cid = 0）。
         */
        private function deleteCategory()
        {
            $id = (int) $this->param('id', 0);

            if ($id <= 0) {
                $this->redirect('categories', null, '参数不完整');
            }

            $db = $this->db();
            $row = $db->fetchRow(
                $db->select('id')->from('table.growth_categories')->where('id = ?', $id)->limit(1)
            );

            if (empty($row)) {
                $this->redirect('categories', null, '类型不存在');
            }

            // 先把该类型下的计划归到未分类，再删类型
            $db->query(
                $db->update('table.growth_plans')->rows(['cid' => 0])->where('cid = ?', $id)
            );
            $db->query($db->delete('table.growth_categories')->where('id = ?', $id));

            $this->redirect('categories', '类型已删除，原类型下的计划已归入「未分类」');
        }

        // -----------------------------------------------------------------
        // 计划
        // -----------------------------------------------------------------

        /**
         * 收集并校验计划表单字段
         *
         * @param bool $isEdit
         * @return array [rows, error]
         */
        private function collectPlanRows(bool $isEdit): array
        {
            $title = trim((string) $this->param('title', ''));
            $cid = (int) $this->param('cid', 0);
            $year = (int) $this->param('year', 0);
            $description = (string) $this->param('description', '');
            $sort = (int) $this->param('sort', 0);
            $selfId = $isEdit ? (int) $this->param('id', 0) : 0;

            // parent_id 允许为 0（顶级）。不要写 param('parent_id', 0)——
            // param() 在值为空串时会返回默认值，这里默认 0 恰好一致，
            // 但为了语义明确仍显式取整。
            $parentId = (int) $this->param('parent_id', 0);
            if ($parentId < 0) {
                $parentId = 0;
            }

            $startDate = Plugin::normalizeDate($this->param('start_date', ''));
            $endDate = Plugin::normalizeDate($this->param('end_date', ''));

            if ($title === '') {
                return [[], '计划标题不能为空'];
            }

            // 父计划防环：不能挂到自己身上，也不能挂到自己的后代下面
            if ($parentId > 0) {
                $all = $this->db()->fetchAll(
                    $this->db()->select('id', 'parent_id')->from('table.growth_plans')
                );

                $exists = false;
                foreach ($all as $row) {
                    if ((int) $row['id'] === $parentId) {
                        $exists = true;
                        break;
                    }
                }

                if (!$exists) {
                    return [[], '指定的父计划不存在'];
                }

                if ($isEdit && $selfId > 0 && Plugin::isAncestor($parentId, $selfId, $all)) {
                    return [[], '不能把计划挂到自己或自己的子计划下面'];
                }
            }

            $rawStart = trim((string) $this->param('start_date', ''));
            $rawEnd = trim((string) $this->param('end_date', ''));
            if ($rawStart !== '' && $startDate === '') {
                return [[], '开始日期格式无效，应为 YYYY-MM-DD'];
            }
            if ($rawEnd !== '' && $endDate === '') {
                return [[], '截止日期格式无效，应为 YYYY-MM-DD'];
            }

            // 起止顺序校验
            if ($startDate !== '' && $endDate !== '') {
                $ts = Plugin::parseDate($startDate);
                $te = Plugin::parseDate($endDate);
                if (null !== $ts && null !== $te && $ts > $te) {
                    return [[], '开始日期不能晚于截止日期'];
                }
            }

            // 没填年份时，用截止日期的年份兜底
            if ($year <= 0 && $endDate !== '') {
                $year = (int) substr($endDate, 0, 4);
            }

            $status = (string) $this->param('status', 'auto');
            if (!in_array($status, ['auto', 'done'], true)) {
                $status = 'auto';
            }

            // 归档态不在本表单里单独编辑，而是靠隐藏域原样带回。
            // 这一点很关键：列表页行内改排序走的也是 edit_plan，
            // 而这里对 $rows 是全量 UPDATE，漏了 archived 就会在
            // 用户随手改一行排序时把归档态重置成 0。
            // add_plan 没有这个字段，param() 回落 '0'，恰好等于「不归档」。
            $archived = $this->param('archived', '0') === '1' ? 1 : 0;

            // 封面图：前台卡片网格的卡面。
            // 与 archived 同理，列表页行内保存走的也是 edit_plan，
            // 这里漏了 cover 就会在用户随手改一行排序时把封面图清空。
            // 表里是 VARCHAR(500)，但 mb_substr 按字符截断，
            // 中文域名/编码后的 URL 不会因字节截断变成乱码。
            $cover = trim((string) $this->param('cover', ''));

            $rows = [
                'cid'         => $cid,
                'parent_id'   => $parentId,
                'title'       => mb_substr($title, 0, 255),
                'year'        => $year,
                'start_date'  => $startDate !== '' ? $startDate : null,
                'end_date'    => $endDate !== '' ? $endDate : null,
                'description' => mb_substr($description, 0, 5000),
                'status'      => $status,
                'archived'    => $archived,
                'cover'       => mb_substr($cover, 0, 500),
                'sort'        => $sort,
            ];

            return [$rows, null];
        }

        private function addPlan()
        {
            [$rows, $err] = $this->collectPlanRows(false);

            if ($err !== null) {
                $this->redirect('plans', null, $err);
            }

            $rows['created'] = time();

            $db = $this->db();
            $db->query($db->insert('table.growth_plans')->rows($rows));

            $this->redirect('plans', '计划「' . $rows['title'] . '」已添加');
        }

        private function editPlan()
        {
            $id = (int) $this->param('id', 0);

            if ($id <= 0) {
                $this->redirect('plans', null, '参数不完整');
            }

            [$rows, $err] = $this->collectPlanRows(true);

            if ($err !== null) {
                $this->redirect('plans', null, $err);
            }

            $db = $this->db();
            $row = $db->fetchRow(
                $db->select('id')->from('table.growth_plans')->where('id = ?', $id)->limit(1)
            );

            if (empty($row)) {
                $this->redirect('plans', null, '计划不存在');
            }

            $db->query($db->update('table.growth_plans')->rows($rows)->where('id = ?', $id));

            $this->redirect('plans', '计划已更新');
        }

        private function deletePlan()
        {
            $id = (int) $this->param('id', 0);

            if ($id <= 0) {
                $this->redirect('plans', null, '参数不完整');
            }

            $db = $this->db();
            $all = $db->fetchAll($db->select()->from('table.growth_plans'));

            // 级联删除：子树 + 所有日志 + 所有配图。
            // 逻辑放在 Plugin::deletePlanCascade()，前台与测试共用同一份实现。
            $deleted = Plugin::deletePlanCascade($id, $all);

            if ($deleted <= 0) {
                $this->redirect('plans', null, '计划不存在');
            }

            if ($deleted > 1) {
                $this->redirect('plans', '已删除 ' . $deleted . ' 条计划及其日志');
            }

            $this->redirect('plans', '计划已删除');
        }

        /**
         * 归档 / 恢复一条计划
         *
         * 归档只改 archived 标记，不动 status，也绝不删数据 ——
         * 归档是「从主时间线摘出去」，不是「不要了」，随时可以恢复。
         * 子树跟着一起走：父计划归档了，底下的小任务还留在主时间线上
         * 会很奇怪，用户要的是整块收起来。
         */
        private function archivePlan()
        {
            $id = (int) $this->param('id', 0);
            $undo = (string) $this->param('undo', '') === '1';

            if ($id <= 0) {
                $this->redirect('plans', null, '参数不完整');
            }

            $db = $this->db();
            $all = $db->fetchAll($db->select('id', 'parent_id', 'title')->from('table.growth_plans'));

            // descendantIds() 自带「自身不存在返回 []」的保护，
            // 所以空数组就代表这条计划根本不存在，必须报错而非静默成功。
            $ids = Plugin::archivedPlanIds($id, $all);

            if (empty($ids)) {
                $this->redirect('plans', null, '计划不存在');
            }

            $inIds = implode(',', array_map('intval', $ids));
            $flag = $undo ? 0 : 1;

            $db->query(
                $db->update('table.growth_plans')
                    ->rows(['archived' => $flag])
                    ->where('id IN (' . $inIds . ')')
            );

            $others = count($ids) - 1;

            if ($undo) {
                $msg = '计划已恢复';
                if ($others > 0) {
                    $msg = '计划已恢复（含 ' . $others . ' 条子计划）';
                }
                $this->redirect('plans', $msg);
            }

            $msg = '计划已归档';
            if ($others > 0) {
                $msg = '计划已归档（含 ' . $others . ' 条子计划）';
            }
            $this->redirect('plans', $msg);
        }

        /**
         * 继续计划（续期）
         *
         * 把当前这一期封存成一条历史轮次，然后拉长计划的时间线。
         * 起止日期取表单传回来的值（表单里已由 PHP 预填好「原时长翻倍」的
         * 建议值，用户可改），不在这里重算，避免两处逻辑不一致。
         *
         * 写入顺序：先主表后子表。没有事务，最坏情况是 plans 更新成功、
         * rounds 插入失败 —— 结果只是少一条历史轮次，下一轮仍可继续，
         * 不会丢用户数据。反过来先插 rounds 再更新 plans，失败时会多出
         * 一条永远对不上的轮次记录，更难收拾。
         */
        private function resumePlan()
        {
            $id = (int) $this->param('id', 0);

            if ($id <= 0) {
                $this->redirect('plans', null, '参数不完整');
            }

            $db = $this->db();
            $plan = $db->fetchRow(
                $db->select()->from('table.growth_plans')->where('id = ?', $id)->limit(1)
            );

            if (empty($plan)) {
                $this->redirect('plans', null, '计划不存在');
            }

            $title = trim((string) $this->param('title', ''));
            if ($title === '') {
                $title = (string) $plan['title'];
            }
            $title = mb_substr($title, 0, 255);

            $rawStart = trim((string) $this->param('start_date', ''));
            $rawEnd = trim((string) $this->param('end_date', ''));

            // 没填开始日期就按「今天」起算，符合「继续往前推进」的直觉
            $startDate = $rawStart === '' ? date('Y-m-d') : Plugin::normalizeDate($rawStart);
            $endDate = Plugin::normalizeDate($rawEnd);

            if ($rawStart !== '' && $startDate === '') {
                $this->redirect('plans', null, '开始日期格式无效，应为 YYYY-MM-DD');
            }
            if ($endDate === '') {
                $this->redirect('plans', null, '新的截止日期不能为空，且格式应为 YYYY-MM-DD');
            }

            $ts = Plugin::parseDate($startDate);
            $te = Plugin::parseDate($endDate);
            if (null !== $ts && null !== $te && $ts > $te) {
                $this->redirect('plans', null, '开始日期不能晚于截止日期');
            }

            $year = (int) substr($endDate, 0, 4);
            if ($year <= 0) {
                $year = (int) $plan['year'];
            }

            // ---- 封存当前这一期为历史轮次 ----
            // days_gone 在这里定格：历史轮次不该随时间的推移继续漂移，
            // 也避免「还没到期就提前续期」把未来天数算进累计。
            $snapshot = Plugin::progress($plan);
            $roundNo = $this->nextRoundNo($id);

            $roundRow = [
                'plan_id'    => $id,
                'round_no'   => $roundNo,
                'title'      => mb_substr((string) $plan['title'], 0, 255),
                'start_date' => $plan['start_date'] ?: null,
                'end_date'   => $plan['end_date'] ?: null,
                'days_total' => (int) $snapshot['days_total'],
                'days_gone'  => (int) $snapshot['days_gone'],
                'note'       => '',
                'created'    => time(),
            ];

            // ---- 先更新主表（拉长未来的时间线）----
            $db->query(
                $db->update('table.growth_plans')
                    ->rows([
                        'title'      => $title,
                        'start_date' => $startDate,
                        'end_date'   => $endDate,
                        'year'       => $year,
                        // 继续计划隐含「不再是已归档、也不再是已完成」，
                        // 否则续期完还挂在归档区，用户会以为自己点错了
                        'archived'   => 0,
                        'status'     => 'auto',
                    ])
                    ->where('id = ?', $id)
            );

            // ---- 再补历史轮次快照 ----
            $db->query($db->insert('table.growth_rounds')->rows($roundRow));

            $this->redirect('plans', '计划已续期至 ' . $endDate . '，第 ' . $roundNo . ' 轮已存档');
        }

        /**
         * 算出「继续计划」表单的日期预填值
         *
         * 思路：保持原有跨度，从今天往后推同样长的一段时间。
         * 用 parseDate()/mktime() 做日期运算而不是字符串拼接 ——
         * 跨月、跨年、闰年上字符串算法会直接算错。
         *
         * 在 PHP 侧算好再输出，前端没有 JS 也能正常用。
         *
         * @param array $plan
         * @return array [startDate, endDate]
         */
        private function resumeDefaults(array $plan): array
        {
            $today = mktime(0, 0, 0, (int) date('n'), (int) date('j'), (int) date('Y'));
            $startStr = date('Y-m-d', $today);

            $oldStart = Plugin::parseDate($plan['start_date'] ?? null);
            $oldEnd = Plugin::parseDate($plan['end_date'] ?? null);

            // 原跨度（含首尾）。没有完整跨度时给个 90 天的默认值，
            // 总比让用户对着空输入框发呆强。
            $spanDays = 90;
            if (null !== $oldStart && null !== $oldEnd && $oldEnd >= $oldStart) {
                $spanDays = (int) round(($oldEnd - $oldStart) / 86400) + 1;
            }
            if ($spanDays < 1) {
                $spanDays = 1;
            }

            // 「拉长未来的时间线」：新周期 = 从今天起，再走一遍原跨度。
            // 首尾都算，所以总天数 = spanDays，末日 = 今天 + (spanDays - 1)。
            $newEnd = mktime(0, 0, 0, (int) date('n', $today), (int) date('j', $today) + $spanDays - 1, (int) date('Y', $today));

            return [$startStr, date('Y-m-d', $newEnd)];
        }

        /**
         * 取某条计划的下一个轮次号
         *
         * 取 MAX(round_no) + 1，不加冗余列维护「当前轮」——
         * 冗余列一旦跟数据不同步就会很难查。
         *
         * @param int $planId
         * @return int
         */
        private function nextRoundNo(int $planId): int
        {
            $db = $this->db();
            $row = $db->fetchRow(
                $db->select('MAX(round_no) AS mx')->from('table.growth_rounds')
                    ->where('plan_id = ?', $planId)
            );

            $max = (int) ($row['mx'] ?? 0);

            return $max > 0 ? $max + 1 : 1;
        }

        // -----------------------------------------------------------------
        // 计划日志
        // -----------------------------------------------------------------

        /**
         * 收集并校验日志表单字段
         *
         * @param bool $isEdit
         * @return array [rows, images, error]
         */
        private function collectLogRows(bool $isEdit): array
        {
            $planId = (int) $this->param('plan_id', 0);
            $content = trim((string) $this->param('content', ''));
            $sort = (int) $this->param('sort', 0);

            // 空串要回落「今天」，所以不能把默认值交给 param()
            $rawDate = trim((string) $this->param('log_date', ''));
            if ($rawDate === '') {
                $logDate = date('Y-m-d');
            } else {
                $logDate = Plugin::normalizeDate($rawDate);
                if ($logDate === '') {
                    return [[], [], '日志日期格式无效，应为 YYYY-MM-DD'];
                }
            }

            if ($planId <= 0) {
                return [[], [], '请选择日志所属的计划'];
            }

            $db = $this->db();
            $plan = $db->fetchRow(
                $db->select('id')->from('table.growth_plans')->where('id = ?', $planId)->limit(1)
            );
            if (empty($plan)) {
                return [[], [], '指定的计划不存在'];
            }

            $rows = [
                'plan_id'  => $planId,
                'log_date' => $logDate,
                'content'  => mb_substr($content, 0, 20000),
                'sort'     => $sort,
            ];

            return [$rows, $this->collectLogImages(), null];
        }

        /**
         * 收集日志配图行
         *
         * 表单字段形如 images[0][url] / images[0][caption]，
         * 提交后是二维数组。url 为空的行直接丢掉（用户加了空行没填是常态）。
         *
         * @return array
         */
        private function collectLogImages(): array
        {
            // 注意：Typecho\Request::get() 结尾有一句
            //     is_array($default) == is_array($value) ? $value : $default
            // 也就是说「取数组」时必须传数组形式的默认值，否则即使真的
            // 传来了数组，也会被判为类型不匹配而回落到 $default（null），
            // 表现为「图片全丢、但页面不报任何错」。这里必须传 []。
            $raw = $this->request()->get('images', []);
            if (!is_array($raw)) {
                return [];
            }

            $images = [];
            $sort = 0;

            foreach ($raw as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $url = trim((string) ($item['url'] ?? ''));
                if ($url === '') {
                    continue;
                }

                $caption = trim((string) ($item['caption'] ?? ''));

                $images[] = [
                    'url'     => mb_substr($url, 0, 500),
                    'caption' => mb_substr($caption, 0, 255),
                    'sort'    => $sort++,
                ];
            }

            return $images;
        }

        /**
         * 把一组图片写入某条日志
         *
         * 全量替换语义：编辑日志时先清空再插入，前端传什么就是什么。
         * 这样「删掉一张图」不需要额外接口，直接不传即可。
         */
        private function syncLogImages(int $logId, array $images): void
        {
            $db = $this->db();
            $db->query($db->delete('table.growth_log_images')->where('log_id = ?', $logId));

            foreach ($images as $image) {
                $row = $image;
                $row['log_id'] = $logId;
                $db->query($db->insert('table.growth_log_images')->rows($row));
            }
        }

        private function addLog()
        {
            [$rows, $images, $err] = $this->collectLogRows(false);

            if ($err !== null) {
                $this->redirect('plans', null, $err);
            }

            $rows['created'] = time();

            $db = $this->db();
            $logId = (int) $db->query($db->insert('table.growth_logs')->rows($rows));

            $this->syncLogImages($logId, $images);

            $this->redirect('plans', '日志已添加', null, ['log_plan' => (int) $rows['plan_id']]);
        }

        private function editLog()
        {
            $id = (int) $this->param('id', 0);

            if ($id <= 0) {
                $this->redirect('plans', null, '参数不完整');
            }

            [$rows, $images, $err] = $this->collectLogRows(true);

            if ($err !== null) {
                $this->redirect('plans', null, $err);
            }

            $db = $this->db();
            $exists = $db->fetchRow(
                $db->select('id')->from('table.growth_logs')->where('id = ?', $id)->limit(1)
            );

            if (empty($exists)) {
                $this->redirect('plans', null, '日志不存在');
            }

            $db->query($db->update('table.growth_logs')->rows($rows)->where('id = ?', $id));
            $this->syncLogImages($id, $images);

            $this->redirect('plans', '日志已更新', null, ['log_plan' => (int) $rows['plan_id']]);
        }

        private function deleteLog()
        {
            $id = (int) $this->param('id', 0);

            if ($id <= 0) {
                $this->redirect('plans', null, '参数不完整');
            }

            $db = $this->db();
            $log = $db->fetchRow(
                $db->select('id', 'plan_id')->from('table.growth_logs')->where('id = ?', $id)->limit(1)
            );

            if (empty($log)) {
                $this->redirect('plans', null, '日志不存在');
            }

            $planId = (int) $log['plan_id'];

            $db->query($db->delete('table.growth_log_images')->where('log_id = ?', $id));
            $db->query($db->delete('table.growth_logs')->where('id = ?', $id));

            $this->redirect('plans', '日志已删除', null, ['log_plan' => $planId]);
        }

        /**
         * 图片本地上传（AJAX 端点，输出 JSON 后直接结束请求）
         *
         * 直接复用 Typecho 原生的 Widget\Upload::uploadHandle()：
         *  - 它是 static 方法，不需要 alloc()
         *  - 落盘规则、允许的扩展名（options.attachmentTypes，默认 @image@）
         *    和后台「撰写文章 → 附件」完全一致，不会出现「写文章能传、
         *    这里传不了」的割裂
         *  - 返回 ['name','path','size','type','mime']，其中 path 是相对路径
         */
        private function uploadLogImage()
        {
            // 输出 JSON 前必须把已开的缓冲清干净，否则后台骨架的 HTML 会混进来
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            header('Content-Type: application/json; charset=UTF-8');

            try {
                $this->security()->protect();
            } catch (\Typecho\Widget\Terminal $t) {
                throw $t;
            } catch (\Throwable $e) {
                echo json_encode(['ok' => false, 'msg' => '校验失败，请刷新页面后重试']);
                return;
            }

            if (empty($_FILES)) {
                echo json_encode(['ok' => false, 'msg' => '没有收到文件']);
                return;
            }

            $file = null;
            foreach ($_FILES as $candidate) {
                if (is_array($candidate) && isset($candidate['error'])) {
                    $file = $candidate;
                    break;
                }
            }

            if (null === $file) {
                echo json_encode(['ok' => false, 'msg' => '文件格式异常']);
                return;
            }

            if ((int) $file['error'] !== 0) {
                echo json_encode(['ok' => false, 'msg' => '上传失败（错误码 ' . (int) $file['error'] . '）']);
                return;
            }

            try {
                $result = \Widget\Upload::uploadHandle($file);
            } catch (\Throwable $e) {
                echo json_encode(['ok' => false, 'msg' => '上传异常：' . $e->getMessage()]);
                return;
            }

            if (false === $result || empty($result['path'])) {
                echo json_encode(['ok' => false, 'msg' => '文件类型不被允许，或目录不可写']);
                return;
            }

            // path 存的是相对站点根的路径，转成可直接放进 img src 的 URL
            $url = \Typecho\Common::url($result['path'], $this->siteUrl());

            echo json_encode([
                'ok'   => true,
                'url'  => $url,
                'name' => (string) $result['name'],
            ]);
        }

        // -----------------------------------------------------------------
        // 设置
        // -----------------------------------------------------------------

        private function saveConfig()
        {
            $cfg = Plugin::settings();

            $soonDays = (int) $this->param('soon_days', 30);
            if ($soonDays < 0) {
                $soonDays = 0;
            }
            if ($soonDays > 365) {
                $soonDays = 365;
            }

            $defaults = Plugin::defaultConfig();

            // 注意不能写成 $this->param('offline_tip', $cfg['offline_tip'])：
            // param() 在值为空串时会返回传入的 $default，那样用户「清空输入框」
            // 会拿到上一次保存的旧值，永远回不到默认文案。这里 raw 取原始值，
            // 空串就让它空着，由下面统一回落到内置默认文案。
            $offlineTip = trim((string) $this->param('offline_tip', ''));
            $emptyTip = trim((string) $this->param('empty_tip', ''));

            // 起止节点颜色：走 normalizeColor() 校验，非法值回落到内置默认色。
            // ⚠️ 不能直接用 $this->param('node_start_color', $defaults[...])：
            //    老站点存过的配置里没有这两个键，$this->cfg 会缺键，
            //    这里统一用 ?? 兜一层默认，避免 PHP 报 undefined key。
            $startColor = Plugin::normalizeColor(
                $this->param('node_start_color', ''),
                $defaults['node_start_color']
            );
            $endColor = Plugin::normalizeColor(
                $this->param('node_end_color', ''),
                $defaults['node_end_color']
            );

            $new = [
                'front_enabled' => $this->param('front_enabled', '1') === '0' ? '0' : '1',
                'soon_days'     => (string) $soonDays,
                'show_status'   => $this->param('show_status', '1') === '0' ? '0' : '1',
                'year_filter'   => $this->param('year_filter', '1') === '0' ? '0' : '1',
                'offline_tip'   => $offlineTip === '' ? $defaults['offline_tip'] : $offlineTip,
                'empty_tip'     => $emptyTip === '' ? $defaults['empty_tip'] : $emptyTip,
                'node_start_color' => $startColor,
                'node_end_color'   => $endColor,
            ];

            // 复用 Plugin::writeConfig() 的 upsert 逻辑。不要在这里自己写 UPDATE：
            // 如果 plugin:GrowthPlan 这一行因故不存在（手工清了 options、插件未正常
            // 激活过等），UPDATE 会 0 行命中，配置就丢了，还会报「配置信息没有找到」。
            Plugin::writeConfig($new);

            $this->cfg = $new;
            // 插件配置在 Options 单例里是静态缓存的，直接赋值刷掉旧值，
            // 否则同一请求内后续读取到的仍是保存前的配置
            \Widget\Options::alloc()->plugin('GrowthPlan', $new);

            $this->redirect($this->view, '插件设置已保存');
        }

        // -----------------------------------------------------------------
        // 视图：计划
        // -----------------------------------------------------------------

        private function renderPlans($message, $error)
        {
            $db = $this->db();

            $cats = $db->fetchAll(
                $db->select()->from('table.growth_categories')
                    ->order('sort', Db::SORT_ASC)->order('id', Db::SORT_ASC)
            );

            $filterCid = $this->param('cid', null);
            $filterCid = (null === $filterCid) ? null : (int) $filterCid;

            $filterYear = $this->param('year', null);
            $filterYear = (null === $filterYear) ? null : (int) $filterYear;

            // 各类型计划数（一次 GROUP BY，避免 N+1）
            $countMap = [];
            $countRows = $db->fetchAll(
                $db->select('cid', ['COUNT(id)' => 'num'])
                    ->from('table.growth_plans')
                    ->group('cid')
            );
            foreach ($countRows as $row) {
                $countMap[(int) $row['cid']] = (int) $row['num'];
            }
            $total = array_sum($countMap);

            // 年份分布
            $years = [];
            $yearRows = $db->fetchAll(
                $db->select('year', ['COUNT(id)' => 'num'])
                    ->from('table.growth_plans')
                    ->group('year')
                    ->order('year', Db::SORT_ASC)
            );
            foreach ($yearRows as $row) {
                $y = (int) $row['year'];
                if ($y > 0) {
                    $years[$y] = (int) $row['num'];
                }
            }

            // 计划列表
            $select = $db->select()->from('table.growth_plans');
            if (null !== $filterCid) {
                $select->where('cid = ?', $filterCid);
            }
            if (null !== $filterYear) {
                $select->where('year = ?', $filterYear);
            }
            $plans = $db->fetchAll(
                $select->order('sort', Db::SORT_ASC)->order('id', Db::SORT_ASC)
            );

            // 全量计划（不受筛选影响），供父计划下拉、层级深度与级联删除使用
            $allPlans = $db->fetchAll(
                $db->select()->from('table.growth_plans')->order('id', Db::SORT_ASC)
            );

            $catName = [];
            foreach ($cats as $c) {
                $catName[(int) $c['id']] = $c['name'];
            }

            // 类型色：没有封面图时，列表缩略图用类型色画一个占位块，
            // 至少能一眼区分「这条属于哪个类型」
            $coverColor = [];
            foreach ($cats as $c) {
                $coverColor[(int) $c['id']] = Plugin::normalizeColor($c['color'] ?? '', '');
            }
            $coverColor[0] = '';

            // 筛选结果按树展平：父在前、子紧随，并给每行算出缩进层级。
            // 若某条子计划的父被筛掉了，planTree() 会把它当顶级，不会丢行。
            $filteredIds = [];
            foreach ($plans as $plan) {
                $filteredIds[(int) $plan['id']] = true;
            }

            $flat = [];
            foreach (Plugin::sortPlansTree($plans) as $plan) {
                $parentId = (int) ($plan['parent_id'] ?? 0);
                // 父不在本次结果里 → 视觉上当顶级，避免出现「悬空缩进」
                $plan['_display_depth'] = isset($filteredIds[$parentId]) ? (int) $plan['_depth'] : 0;
                $flat[] = $plan;
            }

            $childCounts = [];
            foreach ($allPlans as $plan) {
                $pid = (int) ($plan['parent_id'] ?? 0);
                if ($pid > 0) {
                    $childCounts[$pid] = ($childCounts[$pid] ?? 0) + 1;
                }
            }

            // 各计划的续期轮次与累计天数，一次查出后按 plan_id 分组。
            // 用 $allPlans 而不是筛选后的 $plans，免得筛选后行内的
            // 累计进度忽然变成「本轮」，看起来像数据错乱。
            $allPlanIds = [];
            foreach ($allPlans as $plan) {
                $allPlanIds[] = (int) $plan['id'];
            }
            $roundMap = Plugin::roundsForPlans($allPlanIds);
            $carryMap = Plugin::carryForPlans($allPlanIds);

            // 当前选中的日志所属计划
            $logPlan = (int) $this->param('log_plan', 0);
            if ($logPlan <= 0 && !empty($flat)) {
                $logPlan = (int) $flat[0]['id'];
            }

            $this->openPage('成长计划', '添加计划', '#gp-add-plan');
            $this->notice($message, $error);
            ?>
            <div class="gp-toolbar">
                <div class="gp-filters">
                    <a class="gp-chip <?php echo null === $filterCid ? 'is-on' : ''; ?>"
                       href="<?php echo $this->url(['view' => 'plans']); ?>">
                        全部 <em><?php echo (int) $total; ?></em>
                    </a>
                    <?php foreach ($cats as $cat): ?>
                        <a class="gp-chip <?php echo $filterCid === (int) $cat['id'] ? 'is-on' : ''; ?>"
                           href="<?php echo $this->url(['view' => 'plans', 'cid' => (int) $cat['id']]); ?>">
                            <?php echo Plugin::e($cat['name']); ?>
                            <em><?php echo (int) ($countMap[(int) $cat['id']] ?? 0); ?></em>
                        </a>
                    <?php endforeach; ?>
                    <a class="gp-chip <?php echo 0 === $filterCid ? 'is-on' : ''; ?>"
                       href="<?php echo $this->url(['view' => 'plans', 'cid' => 0]); ?>">
                        未分类 <em><?php echo (int) ($countMap[0] ?? 0); ?></em>
                    </a>
                </div>
                <?php if (!empty($years)): ?>
                    <div class="gp-filters" style="margin-left:auto">
                        <?php foreach ($years as $y => $n): ?>
                            <a class="gp-chip <?php echo $filterYear === $y ? 'is-on' : ''; ?>"
                               href="<?php echo $this->url(['view' => 'plans', 'year' => $y]); ?>">
                                <?php echo (int) $y; ?> 年 <em><?php echo (int) $n; ?></em>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="gp-card">
                <h3 class="gp-card-title">计划列表</h3>
                <?php if (empty($plans)): ?>
                    <div class="gp-empty">还没有任何计划。用下面的表单添加第一条。</div>
                <?php endif; ?>
                <table class="gp-table gp-table-plan">
                        <thead>
                        <tr>
                            <th class="gp-w-id">ID</th>
                            <th class="gp-w-cover">封面</th>
                            <th class="gp-w-plan">计划</th>
                            <th class="gp-w-year">年份</th>
                            <th class="gp-w-range">起止日期</th>
                            <th class="gp-w-prog">进度</th>
                            <th class="gp-w-status">状态</th>
                            <th class="gp-w-sort">排序</th>
                            <th class="gp-w-op">操作</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($flat as $plan): ?>
                            <?php
                            // 注入累计天数后再算进度：percent / days_left 等
                            // 仍是本轮语义，cumulative_* 才是累计值
                            $pid = (int) $plan['id'];
                            $prog = Plugin::progress(Plugin::applyCarry($plan, $carryMap[$pid] ?? []));
                            $st = Plugin::status($plan);
                            $cid = (int) $plan['cid'];
                            $depth = (int) $plan['_display_depth'];
                            $kids = (int) ($childCounts[$pid] ?? 0);
                            $isArchived = (int) ($plan['archived'] ?? 0) === 1;
                            $rounds = $roundMap[$pid] ?? [];
                            $rowClass = trim(($depth > 0 ? 'gp-row-child' : '') . ($isArchived ? ' gp-row-archived' : ''));
                            ?>
                            <tr class="<?php echo $rowClass; ?>">
                                <td class="gp-mono"><?php echo $pid; ?></td>
                                <td class="gp-w-cover">
                                    <?php
                                    $coverUrl = trim((string) ($plan['cover'] ?? ''));
                                    // 用 CSS 变量把类型色带进来，让占位块也能有颜色区分
                                    $coverStyle = $coverColor[$cid] !== ''
                                        ? ' style="--gp-cover:' . $coverColor[$cid] . ';"'
                                        : '';
                                    ?>
                                    <?php if ($coverUrl !== ''): ?>
                                        <img class="gp-thumb" src="<?php echo Plugin::e($coverUrl); ?>"
                                             alt="" loading="lazy"/>
                                    <?php else: ?>
                                        <span class="gp-thumb is-empty"<?php echo $coverStyle; ?>></span>
                                    <?php endif; ?>
                                </td>
                                <td class="gp-w-plan">
                                    <div class="gp-tree-cell" style="padding-left:<?php echo $depth * 22; ?>px">
                                        <?php if ($depth > 0): ?>
                                            <span class="gp-branch" aria-hidden="true">└</span>
                                        <?php endif; ?>
                                        <span class="gp-tree-title">
                                            <a href="<?php echo $this->url(['view' => 'plans', 'id' => $pid]); ?>">
                                                <strong><?php echo Plugin::e($plan['title']); ?></strong>
                                            </a>
                                            <span class="gp-mono gp-tree-meta">
                                                <?php echo Plugin::e($catName[$cid] ?? '未分类'); ?>
                                                <?php if ($kids > 0): ?>
                                                    ｜子计划 <?php echo $kids; ?> 条
                                                <?php endif; ?>
                                            </span>
                                        </span>
                                    </div>
                                </td>
                                <td class="gp-w-year">
                                    <?php if ((int) $plan['year'] > 0): ?>
                                        <span style="font-size:13px"><?php echo (int) $plan['year']; ?></span>
                                    <?php else: ?>
                                        <span class="gp-mono">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="gp-w-range">
                                    <div>
                                        <?php echo $plan['start_date'] ? Plugin::e($plan['start_date']) : '<span class="gp-mono">未设置</span>'; ?>
                                        <br/>
                                        <span class="gp-mono">→</span>
                                        <?php echo $plan['end_date'] ? Plugin::e($plan['end_date']) : '<span class="gp-mono">未设置</span>'; ?>
                                    </div>
                                </td>
                                <td class="gp-w-prog">
                                    <div class="gp-bar"><i style="width:<?php echo (int) $prog['percent']; ?>%"></i></div>
                                    <div class="gp-bar-text">
                                        <?php echo (int) $prog['percent']; ?>%
                                        <?php if ($prog['end']): ?>
                                            ｜<?php echo Plugin::e(Plugin::leftText($prog)); ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($rounds)): ?>
                                        <div class="gp-bar-text" title="含 <?php echo count($rounds); ?> 轮历史">
                                            累计 <?php echo (int) $prog['cumulative_percent']; ?>%
                                            ｜<?php echo count($rounds); ?> 轮
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="gp-w-status">
                                    <span class="gp-tag gp-tag-<?php echo Plugin::e($st); ?>">
                                        <?php echo Plugin::e(Plugin::statusLabel($st)); ?>
                                    </span>
                                    <?php if ($isArchived): ?>
                                        <span class="gp-tag gp-tag-archived">已归档</span>
                                    <?php endif; ?>
                                </td>
                                <td class="gp-w-sort">
                                    <input form="gp-plan-sort-<?php echo (int) $plan['id']; ?>"
                                           type="number" class="gp-input-mini" name="sort"
                                           value="<?php echo (int) $plan['sort']; ?>"/>
                                </td>
                                <td>
                                    <div class="gp-ops">
                                        <button type="submit" form="gp-plan-sort-<?php echo (int) $plan['id']; ?>"
                                                class="btn btn-xs">保存</button>
                                        <a class="btn btn-xs"
                                           href="<?php echo $this->url(['view' => 'plans', 'id' => (int) $plan['id']]); ?>">编辑</a>
                                        <?php
                                        // 「继续计划」的入口是一个纯锚点，指向表格外那个面板。
                                        //
                                        // 关键坑（踩过两次）：面板里有 <input> 这类块级元素，
                                        // 一旦让它参与表格正常流，就会把「操作」列撑到几百像素宽，
                                        // Chromium 判定表格布局失效，把所有 th/td 的 display
                                        // 改成 block+float:left —— 整张表看起来像列全错位。
                                        //
                                        // 第一次的修法（浮层改 position:absolute）不够：
                                        // details 元素自身仍是 block 且占位，照样撑列。
                                        // 现在的修法是把面板整个移出表格，用 :target 控制显隐，
                                        // 表格内只留一个不占宽度的锚点。
                                        ?>
                                        <a class="btn btn-xs" href="#gp-ext-<?php echo $pid; ?>">继续</a>
                                        <button type="submit" form="gp-plan-arch-<?php echo $pid; ?>"
                                                class="btn btn-xs"><?php echo $isArchived ? '恢复' : '归档'; ?></button>
                                        <button type="submit" form="gp-plan-del-<?php echo (int) $plan['id']; ?>"
                                                class="btn btn-xs gp-btn-danger">删除</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php
                    // 续期面板：全部放在表格之外。
                    // 用 :target 显隐 —— 操作列那个「继续」锚点把地址改成 #gp-ext-N，
                    // 对应面板就被点亮。点别处或按返回键即关闭。
                    // 面板里的输入用 form="gp-plan-ext-N" 关联到更下方的表单本体。
                    foreach ($flat as $plan):
                        $pid = (int) $plan['id'];
                        [$extStart, $extEnd] = $this->resumeDefaults($plan);
                        $roundCount = count($roundMap[$pid] ?? []);
                        ?>
                        <div class="gp-ext-panel" id="gp-ext-<?php echo $pid; ?>">
                            <div class="gp-ext-body">
                                <p class="gp-ext-note">
                                    当前这一期会存档为<strong>第 <?php echo $roundCount + 1; ?> 轮</strong>，
                                    进度条按<strong>累计不清零</strong>计算。
                                </p>
                                <label class="gp-field">
                                    <span>新周期标题</span>
                                    <input form="gp-plan-ext-<?php echo $pid; ?>" type="text"
                                           name="title"
                                           value="<?php echo Plugin::e($plan['title']); ?>"/>
                                </label>
                                <label class="gp-field">
                                    <span>开始日期</span>
                                    <input form="gp-plan-ext-<?php echo $pid; ?>" type="date"
                                           name="start_date"
                                           value="<?php echo Plugin::e($extStart); ?>"/>
                                </label>
                                <label class="gp-field">
                                    <span>截止日期</span>
                                    <input form="gp-plan-ext-<?php echo $pid; ?>" type="date"
                                           name="end_date"
                                           value="<?php echo Plugin::e($extEnd); ?>"/>
                                </label>
                                <div class="gp-ext-actions">
                                    <button type="submit" form="gp-plan-ext-<?php echo $pid; ?>"
                                            class="btn btn-xs btn-primary">确认继续</button>
                                    <a class="btn btn-xs" href="#gp-plans-top">取消</a>
                                    <span class="gp-upload-msg">日期可自行调整</span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php
                    // 行内表单移出表格：form 放在 td 里会被 Chromium 重排 DOM，
                    // 直接把表头挤乱（表头列与数据列错位）。改用 form="id" 属性关联，
                    // 表单本体统一放在表格之后，结构最稳。
                    // 注释里不写 HTML 标签字面量，免得被表单提取正则误判。
                    foreach ($flat as $plan):
                        $pid = (int) $plan['id'];
                        ?>
                        <form method="post" class="gp-inline-form"
                              action="<?php echo $this->urlPage(); ?>"
                              id="gp-plan-sort-<?php echo $pid; ?>">
                            <?php $this->hidden('edit_plan'); ?>
                            <input type="hidden" name="id" value="<?php echo $pid; ?>"/>
                            <input type="hidden" name="title" value="<?php echo Plugin::e($plan['title']); ?>"/>
                            <input type="hidden" name="cid" value="<?php echo (int) $plan['cid']; ?>"/>
                            <input type="hidden" name="parent_id" value="<?php echo (int) ($plan['parent_id'] ?? 0); ?>"/>
                            <input type="hidden" name="year" value="<?php echo (int) $plan['year']; ?>"/>
                            <input type="hidden" name="start_date" value="<?php echo Plugin::e($plan['start_date']); ?>"/>
                            <input type="hidden" name="end_date" value="<?php echo Plugin::e($plan['end_date']); ?>"/>
                            <input type="hidden" name="description" value="<?php echo Plugin::e($plan['description']); ?>"/>
                            <input type="hidden" name="status" value="<?php echo Plugin::e($plan['status']); ?>"/>
                            <?php
                            // 必带！行内保存走的是 edit_plan，collectPlanRows()
                            // 会对整行做全量 UPDATE，漏了这个隐藏域，用户改一次
                            // 排序就会把「已归档」悄悄重置成「未归档」。
                            ?>
                            <input type="hidden" name="archived" value="<?php echo (int) ($plan['archived'] ?? 0) === 1 ? '1' : '0'; ?>"/>
                            <?php
                            // 同理必带！cover 也是全量 UPDATE 的一部分，
                            // 漏了就会让用户改一次排序把卡片封面图清空。
                            ?>
                            <input type="hidden" name="cover" value="<?php echo Plugin::e($plan['cover'] ?? ''); ?>"/>
                            <input type="hidden" name="quick" value="1"/>
                        </form>
                        <form method="post" class="gp-inline-form gp-js-confirm"
                              action="<?php echo $this->urlPage(); ?>"
                              id="gp-plan-arch-<?php echo $pid; ?>"
                              data-confirm="<?php
                                  $isArch = (int) ($plan['archived'] ?? 0) === 1;
                                  $kidsA = (int) ($childCounts[$pid] ?? 0);
                                  if ($isArch) {
                                      echo '确定恢复计划「' . Plugin::e($plan['title']) . '」吗？'
                                          . ($kidsA > 0 ? '它下面的 ' . $kidsA . ' 个子计划也会一起恢复。' : '')
                                          . '恢复后它会重新出现在主时间线上。';
                                  } else {
                                      echo '确定归档计划「' . Plugin::e($plan['title']) . '」吗？'
                                          . ($kidsA > 0 ? '它下面的 ' . $kidsA . ' 个子计划也会一起归档。' : '')
                                          . '归档不会删除任何数据，之后可以随时恢复。';
                                  }
                              ?>">
                            <?php $this->hidden('archive_plan'); ?>
                            <input type="hidden" name="id" value="<?php echo $pid; ?>"/>
                            <input type="hidden" name="undo" value="<?php echo $isArch ? '1' : '0'; ?>"/>
                        </form>
                        <?php
                        // 日期字段不在这里放 hidden：真正的输入框在行内的
                        // <details> 面板里（用 form= 关联到本表单）。
                        // 放 hidden 会和用户填的值重名打架，提交时会被后出现的覆盖。
                        ?>
                        <form method="post" class="gp-inline-form gp-js-confirm"
                              action="<?php echo $this->urlPage(); ?>"
                              id="gp-plan-ext-<?php echo $pid; ?>"
                              data-confirm="确定继续计划「<?php echo Plugin::e($plan['title']); ?>」吗？当前这一期会存档为第 <?php echo count($rounds) + 1; ?> 轮，进度条按累计不清零计算。">
                            <?php $this->hidden('resume_plan'); ?>
                            <input type="hidden" name="id" value="<?php echo $pid; ?>"/>
                        </form>
                        <form method="post" class="gp-inline-form gp-js-confirm"
                              action="<?php echo $this->urlPage(); ?>"
                              id="gp-plan-del-<?php echo $pid; ?>"
                              data-confirm="确定删除计划「<?php echo Plugin::e($plan['title']); ?>」吗？<?php
                                  $kids = (int) ($childCounts[$pid] ?? 0);
                                  echo $kids > 0 ? '它下面的 ' . $kids . ' 个子计划' : '它';
                              ?>以及全部日志（含 <?php echo count($rounds); ?> 条轮次记录）会一起删除，该操作不可撤销。">
                            <?php $this->hidden('del_plan'); ?>
                            <input type="hidden" name="id" value="<?php echo $pid; ?>"/>
                        </form>
                    <?php endforeach; ?>
            </div>

            <?php
            // 编辑单条计划：用全量计划查，保证被筛选掉时也能编辑
            if ($this->id > 0) {
                $editing = null;
                foreach ($allPlans as $p) {
                    if ((int) $p['id'] === $this->id) {
                        $editing = $p;
                        break;
                    }
                }
                if (!empty($editing)) {
                    $this->renderPlanForm($allPlans, $cats, $editing, true);
                }
            }
            ?>

            <div class="gp-card" id="gp-add-plan">
                <h3 class="gp-card-title">添加计划</h3>
                <?php $this->renderPlanFields(null, $cats, false, $allPlans); ?>
                <p class="gp-hint">
                    进度条按「已过天数 / 总天数」计算，所以<strong>开始日期</strong>和<strong>截止日期</strong>都填上最准。
                    只填截止日期时，开始日期按今天算。<br/>
                    「父计划」留空就是顶级计划；选了父计划，这条就变成它的子任务，
                    子任务有自己独立的日期与进度条，也可以继续往下挂。
                </p>
            </div>
            <?php

            $this->renderLogManager($allPlans, $logPlan);

            $this->closePage();
        }

        /**
         * 日志管理卡片
         *
         * 嵌在「计划」视图底部，而不是独立 tab：日志天然依附于某条计划，
         * 独立 tab 还得让用户再选一次计划，多一跳。
         *
         * @param array $allPlans 全量计划
         * @param int $logPlan 当前选中的计划 id
         */
        private function renderLogManager(array $allPlans, int $logPlan)
        {
            $db = $this->db();

            $planTitles = [];
            foreach ($allPlans as $plan) {
                $planTitles[(int) $plan['id']] = (string) $plan['title'];
            }

            // 日志条数（一次 GROUP BY）
            $logCounts = [];
            foreach ($db->fetchAll(
                $db->select('plan_id', ['COUNT(id)' => 'num'])
                    ->from('table.growth_logs')->group('plan_id')
            ) as $row) {
                $logCounts[(int) $row['plan_id']] = (int) $row['num'];
            }

            // 图片（一次拿全，按日志分组）
            $logs = [];
            $images = [];
            if (!empty($allPlans)) {
                $select = $db->select()->from('table.growth_logs');
                if ($logPlan > 0) {
                    $select->where('plan_id = ?', $logPlan);
                }
                $logs = $db->fetchAll(
                    $select->order('log_date', Db::SORT_DESC)
                        ->order('sort', Db::SORT_ASC)
                        ->order('id', Db::SORT_ASC)
                );

                foreach ($logs as $log) {
                    foreach ($db->fetchAll(
                        $db->select()->from('table.growth_log_images')
                            ->where('log_id = ?', (int) $log['id'])
                            ->order('sort', Db::SORT_ASC)
                    ) as $img) {
                        $images[(int) $log['id']][] = $img;
                    }
                }
            }

            $editLogId = (int) $this->param('log_id', 0);
            $editLog = null;
            if ($editLogId > 0) {
                foreach ($logs as $log) {
                    if ((int) $log['id'] === $editLogId) {
                        $editLog = $log;
                        break;
                    }
                }
                if (null === $editLog) {
                    $editLog = $db->fetchRow(
                        $db->select()->from('table.growth_logs')->where('id = ?', $editLogId)->limit(1)
                    );
                }
            }
            ?>
            <div class="gp-card" id="gp-log-manager">
                <h3 class="gp-card-title">计划日志</h3>
                <p class="gp-hint">
                    每条计划（大计划或小任务）都能写日志：选一个日期、写一段感想、配上图片。
                    前台按日期倒序展示，图片鼠标悬停会放大并浮出说明文字。
                </p>

                <?php if (empty($allPlans)): ?>
                    <div class="gp-empty">先添加一条计划，然后就能给它写日志了。</div>
                <?php else: ?>
                    <div class="gp-log-picker">
                        <span class="gp-log-picker-label">查看计划</span>
                        <?php foreach (Plugin::sortPlansTree($allPlans) as $opt): ?>
                            <?php $optId = (int) $opt['id']; ?>
                            <a class="gp-chip <?php echo $logPlan === $optId ? 'is-on' : ''; ?>"
                               href="<?php echo $this->url(['view' => 'plans', 'log_plan' => $optId]); ?>">
                                <?php echo str_repeat('·', (int) $opt['_depth']); ?><?php
                                    echo Plugin::e($opt['title']); ?>
                                <em><?php echo (int) ($logCounts[$optId] ?? 0); ?></em>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <?php if (empty($logs)): ?>
                        <div class="gp-empty">这条计划还没有日志。用下面的表单写第一条。</div>
                    <?php else: ?>
                        <ul class="gp-log-items">
                            <?php foreach ($logs as $log): ?>
                                <?php
                                $lid = (int) $log['id'];
                                $lPlanId = (int) $log['plan_id'];
                                $body = trim((string) $log['content']);
                                ?>
                                <li class="gp-log-item">
                                    <div class="gp-log-item-head">
                                        <span class="gp-log-date">
                                            <?php echo Plugin::e((string) $log['log_date']); ?>
                                        </span>
                                        <span class="gp-log-owner">
                                            <?php echo Plugin::e($planTitles[$lPlanId] ?? '（已删除的计划）'); ?>
                                        </span>
                                        <div class="gp-ops">
                                            <a class="btn btn-xs"
                                               href="<?php echo $this->url([
                                                   'view' => 'plans',
                                                   'log_plan' => $lPlanId,
                                                   'log_id' => $lid,
                                               ]); ?>">编辑</a>
                                            <button type="submit" form="gp-log-del-<?php echo $lid; ?>"
                                                    class="btn btn-xs gp-btn-danger">删除</button>
                                        </div>
                                    </div>
                                    <?php if ($body !== ''): ?>
                                        <p class="gp-log-body"><?php echo nl2br(Plugin::e($body)); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($images[$lid])): ?>
                                        <div class="gp-log-thumbs">
                                            <?php foreach ($images[$lid] as $img): ?>
                                                <figure class="gp-thumb">
                                                    <img src="<?php echo Plugin::e($img['url']); ?>"
                                                         alt="<?php echo Plugin::e($img['caption']); ?>"
                                                         loading="lazy"/>
                                                    <?php if (trim((string) $img['caption']) !== ''): ?>
                                                        <figcaption><?php echo Plugin::e($img['caption']); ?></figcaption>
                                                    <?php endif; ?>
                                                </figure>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>

                        <?php
                        // 行内表单统一放列表之后，靠 form="id" 关联，
                        // 绝不嵌进 <li> / <td> 里面（Chromium 会重排 DOM）
                        foreach ($logs as $log):
                            $lid = (int) $log['id'];
                            ?>
                            <form method="post" class="gp-inline-form gp-js-confirm"
                                  action="<?php echo $this->urlPage(); ?>"
                                  id="gp-log-del-<?php echo $lid; ?>"
                                  data-confirm="确定删除这条日志吗？它配的图片会一起删除。">
                                <?php $this->hidden('del_log'); ?>
                                <input type="hidden" name="id" value="<?php echo $lid; ?>"/>
                            </form>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="gp-log-form-wrap">
                        <h4 class="gp-sub-title">
                            <?php echo null === $editLog ? '写一条日志' : '编辑日志 #' . (int) $editLog['id']; ?>
                        </h4>
                        <?php
                        if (null === $editLog) {
                            $this->renderLogFields(null, $allPlans, $logPlan);
                        } else {
                            $editImages = $images[(int) $editLog['id']] ?? [];
                            $this->renderLogFields(
                                $editLog,
                                $allPlans,
                                (int) $editLog['plan_id'],
                                $editImages
                            );
                        }
                        ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php
        }

        /**
         * 日志表单（新增与编辑共用）
         *
         * @param array|null $log
         * @param array $allPlans
         * @param int $defaultPlan
         * @param array $images 编辑态已存在的图片
         */
        private function renderLogFields($log, array $allPlans, int $defaultPlan, array $images = [])
        {
            $isEdit = null !== $log;
            $formId = $isEdit ? 'gp-edit-log-' . (int) $log['id'] : 'gp-new-log';
            $action = $isEdit ? 'edit_log' : 'add_log';

            $planId = (int) ($log['plan_id'] ?? $defaultPlan);
            $logDate = (string) ($log['log_date'] ?? date('Y-m-d'));
            if ($logDate === '') {
                $logDate = date('Y-m-d');
            }
            $content = (string) ($log['content'] ?? '');
            $sort = (int) ($log['sort'] ?? 0);

            // 至少 3 行，已有图片则按实际数量铺开
            $rows = max(3, count($images));
            ?>
            <form method="post" action="<?php echo $this->urlPage(); ?>" class="gp-form-grid" id="<?php echo $formId; ?>">
                <?php $this->hidden($action); ?>
                <?php if ($isEdit): ?>
                    <input type="hidden" name="id" value="<?php echo (int) $log['id']; ?>"/>
                <?php endif; ?>

                <label class="gp-field gp-field-md">
                    <span>所属计划</span>
                    <select name="plan_id" required>
                        <?php foreach (Plugin::sortPlansTree($allPlans) as $opt): ?>
                            <?php $optId = (int) $opt['id']; ?>
                            <option value="<?php echo $optId; ?>"
                                <?php echo $planId === $optId ? ' selected' : ''; ?>>
                                <?php echo str_repeat('　', (int) $opt['_depth']); ?><?php
                                    echo (int) $opt['_depth'] > 0 ? '└ ' : '';
                                    echo Plugin::e($opt['title']);
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="gp-field gp-field-sm">
                    <span>日志日期</span>
                    <input type="date" name="log_date" value="<?php echo Plugin::e($logDate); ?>"/>
                </label>

                <label class="gp-field gp-field-xs">
                    <span>排序</span>
                    <input type="number" name="sort" value="<?php echo $sort; ?>"/>
                </label>

                <label class="gp-field gp-field-wide">
                    <span>这一段感想</span>
                    <textarea name="content" rows="4"
                              placeholder="今天做了什么、有什么感觉、卡在哪里…"><?php echo Plugin::e($content); ?></textarea>
                </label>

                <div class="gp-field gp-field-wide">
                    <span class="gp-field-label">配图（可留空，也可填图片链接或上传本地图片）</span>
                    <div class="gp-img-rows" id="<?php echo $formId; ?>-imgs">
                        <?php for ($i = 0; $i < $rows; $i++): ?>
                            <?php
                            $imgUrl = (string) ($images[$i]['url'] ?? '');
                            $imgCap = (string) ($images[$i]['caption'] ?? '');
                            ?>
                            <div class="gp-img-row">
                                <input type="text" name="images[<?php echo $i; ?>][url]"
                                       class="gp-img-url" value="<?php echo Plugin::e($imgUrl); ?>"
                                       placeholder="https://…/photo.jpg 或直接上传"/>
                                <input type="text" name="images[<?php echo $i; ?>][caption]"
                                       class="gp-img-cap" value="<?php echo Plugin::e($imgCap); ?>"
                                       placeholder="这张图的说明（鼠标悬停时会显示）"/>
                                <label class="gp-upload-btn">
                                    上传
                                    <input type="file" accept="image/*" hidden
                                           data-upload-url="<?php echo $this->url(['view' => 'plans', 'do' => 'up_log']); ?>"
                                           data-token="<?php echo Plugin::e($this->security()->getToken($this->request()->getRequestUrl())); ?>"/>
                                </label>
                                <button type="button" class="btn btn-xs gp-img-remove">移除</button>
                            </div>
                        <?php endfor; ?>
                    </div>
                    <button type="button" class="btn btn-xs gp-img-add"
                            data-target="<?php echo $formId; ?>-imgs"
                            data-start="<?php echo $rows; ?>">+ 再加一张</button>
                    <span class="gp-upload-msg" id="<?php echo $formId; ?>-msg" role="status"></span>
                </div>

                <div class="gp-field gp-field-btn">
                    <button type="submit" class="btn primary">
                        <?php echo $isEdit ? '保存日志' : '添加日志'; ?>
                    </button>
                    <?php if ($isEdit): ?>
                        <a class="btn"
                           href="<?php echo $this->url(['view' => 'plans', 'log_plan' => $planId]); ?>">取消编辑</a>
                    <?php endif; ?>
                </div>
            </form>
            <?php
        }

        /**
         * 计划表单字段（新增与编辑共用）
         *
         * @param array|null $plan
         * @param array $cats
         * @param bool $isEdit
         * @param array $allPlans 全量计划，用于「父计划」下拉
         */
        private function renderPlanFields($plan, array $cats, bool $isEdit, array $allPlans = [])
        {
            $formId = $isEdit ? 'gp-edit-plan-' . (int) $plan['id'] : 'gp-new-plan';
            $action = $isEdit ? 'edit_plan' : 'add_plan';

            $title = $plan['title'] ?? '';
            $cid = (int) ($plan['cid'] ?? 0);
            $parentId = (int) ($plan['parent_id'] ?? 0);
            $year = (int) ($plan['year'] ?? 0);
            $startDate = $plan['start_date'] ?? '';
            $endDate = $plan['end_date'] ?? '';
            $description = $plan['description'] ?? '';
            $status = $plan['status'] ?? 'auto';
            $archived = (int) ($plan['archived'] ?? 0) === 1 ? '1' : '0';
            $cover = (string) ($plan['cover'] ?? '');
            $sort = (int) ($plan['sort'] ?? 0);
            $selfId = $isEdit ? (int) $plan['id'] : 0;
            ?>
            <form method="post" action="<?php echo $this->urlPage(); ?>" class="gp-form-grid" id="<?php echo $formId; ?>">
                <?php $this->hidden($action); ?>
                <?php if ($isEdit): ?>
                    <input type="hidden" name="id" value="<?php echo $selfId; ?>"/>
                <?php endif; ?>

                <label class="gp-field gp-field-grow">
                    <span>计划标题</span>
                    <input type="text" name="title" value="<?php echo Plugin::e($title); ?>" required
                           placeholder="例如：学会画画 / 跑完半马 / 去冰岛看极光"/>
                </label>

                <label class="gp-field gp-field-md">
                    <span>所属类型</span>
                    <select name="cid">
                        <option value="0"<?php echo 0 === $cid ? ' selected' : ''; ?>>未分类</option>
                        <?php foreach ($cats as $cat): ?>
                            <option value="<?php echo (int) $cat['id']; ?>"
                                <?php echo $cid === (int) $cat['id'] ? ' selected' : ''; ?>>
                                <?php echo Plugin::e($cat['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="gp-field gp-field-md">
                    <span>父计划（可选）</span>
                    <select name="parent_id">
                        <option value="0"<?php echo 0 === $parentId ? ' selected' : ''; ?>>— 顶级计划 —</option>
                        <?php
                        // 树形展平后逐个输出；编辑态要排除自身与自己的所有后代，
                        // 否则用户能把父计划挂进自己的子树里，形成环。
                        foreach (Plugin::sortPlansTree($allPlans) as $opt):
                            $optId = (int) $opt['id'];

                            if ($isEdit && $selfId > 0
                                && Plugin::isAncestor($optId, $selfId, $allPlans)) {
                                continue;
                            }
                            ?>
                            <option value="<?php echo $optId; ?>"
                                <?php echo $parentId === $optId ? ' selected' : ''; ?>>
                                <?php echo str_repeat('　', (int) $opt['_depth']); ?><?php
                                    echo (int) $opt['_depth'] > 0 ? '└ ' : '';
                                    echo Plugin::e($opt['title']);
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="gp-field gp-field-xs">
                    <span>目标年份</span>
                    <input type="number" name="year" value="<?php echo $year > 0 ? $year : ''; ?>"
                           min="1970" max="2999" placeholder="<?php echo (int) date('Y'); ?>"/>
                </label>

                <label class="gp-field gp-field-sm">
                    <span>开始日期</span>
                    <input type="date" name="start_date" value="<?php echo Plugin::e($startDate); ?>"/>
                </label>

                <label class="gp-field gp-field-sm">
                    <span>截止日期</span>
                    <input type="date" name="end_date" value="<?php echo Plugin::e($endDate); ?>"/>
                </label>

                <label class="gp-field gp-field-sm">
                    <span>状态</span>
                    <?php /* 状态只有两个选项：未完成（默认）/ 已完成，后台随时可改。
                       ⚠️ 存储值保持 auto / done 不变 —— 老数据无需迁移：
                       「未完成」就是原来的 auto（按日期自动显示 进行中/已过期），
                       「已完成」就是原来的 done（超期也显示已完成，优先级最高）。
                       已过期因此只可能出现在未完成的计划上 —— 做完的记得标已完成。 */ ?>
                    <select name="status">
                        <option value="auto"<?php echo $status !== 'done' ? ' selected' : ''; ?>>未完成（默认）</option>
                        <option value="done"<?php echo $status === 'done' ? ' selected' : ''; ?>>已完成</option>
                    </select>
                </label>

                <label class="gp-field gp-field-xs">
                    <span>排序</span>
                    <input type="number" name="sort" value="<?php echo $sort; ?>"/>
                </label>

                <?php if ($isEdit): ?>
                    <?php
                    // 归档态平时由列表行内的隐藏域原样带回，编辑页也给一个
                    // 下拉：用户在这里能一眼看到当前是否已归档，也方便手改。
                    ?>
                    <label class="gp-field gp-field-sm">
                        <span>归档</span>
                        <select name="archived">
                            <option value="0"<?php echo $archived !== '1' ? ' selected' : ''; ?>>正常（在主时间线）</option>
                            <option value="1"<?php echo $archived === '1' ? ' selected' : ''; ?>>已归档（折叠收起）</option>
                        </select>
                    </label>
                <?php endif; ?>

                <label class="gp-field gp-field-wide">
                    <span>描述（可留空）</span>
                    <textarea name="description" rows="3"
                              placeholder="为什么要做这件事、怎么衡量完成、关键节点…"><?php echo Plugin::e($description); ?></textarea>
                </label>

                <?php
                // 封面图：前台卡片网格的卡面。
                // 复用日志配图那套上传交互（同一个 up_log 接口），
                // 因为 uploadLogImage() 是先取 $_FILES 里的第一个文件再上传，
                // 与表单字段名无关，所以这里不需要另开一个上传接口。
                ?>
                <div class="gp-field gp-field-wide">
                    <span>封面图（可留空，前台卡片用；填链接或上传本地图片）</span>
                    <div class="gp-img-row">
                        <input type="text" name="cover" value="<?php echo Plugin::e($cover); ?>"
                               placeholder="https://…/cover.jpg 或直接上传"/>
                        <label class="gp-upload-btn">
                            上传
                            <input type="file" accept="image/*" hidden
                                   data-upload-url="<?php echo $this->url(['view' => 'plans', 'do' => 'up_log']); ?>"
                                   data-token="<?php echo Plugin::e($this->security()->getToken($this->request()->getRequestUrl())); ?>"/>
                        </label>
                    </div>
                    <?php if ($cover !== ''): ?>
                        <img class="gp-cover-preview" src="<?php echo Plugin::e($cover); ?>" alt="封面预览" loading="lazy"/>
                    <?php endif; ?>
                    <span class="gp-upload-msg" role="status"></span>
                </div>

                <div class="gp-field gp-field-btn">
                    <button type="submit" class="btn primary">
                        <?php echo $isEdit ? '保存修改' : '添加计划'; ?>
                    </button>
                </div>
            </form>
            <?php
        }

        /**
         * 编辑态的计划卡片
         */
        private function renderPlanForm(array $allPlans, array $cats, array $plan, bool $isEdit)
        {
            ?>
            <div class="gp-card">
                <h3 class="gp-card-title">编辑计划 #<?php echo (int) $plan['id']; ?></h3>
                <?php $this->renderPlanFields($plan, $cats, $isEdit, $allPlans); ?>
            </div>
            <?php
        }

        // -----------------------------------------------------------------
        // 视图：类型
        // -----------------------------------------------------------------

        private function renderCategories($message, $error)
        {
            $db = $this->db();

            $categories = $db->fetchAll(
                $db->select('table.growth_categories.*', ['COUNT(table.growth_plans.id)' => 'plan_count'])
                    ->from('table.growth_categories')
                    ->join(
                        'table.growth_plans',
                        'table.growth_categories.id = table.growth_plans.cid',
                        \Typecho\Db::LEFT_JOIN
                    )
                    ->group('table.growth_categories.id')
                    ->order('table.growth_categories.sort', Db::SORT_ASC)
                    ->order('table.growth_categories.id', Db::SORT_ASC)
            );

            $uncategorized = $db->fetchRow(
                $db->select(['COUNT(id)' => 'num'])->from('table.growth_plans')
                    ->where('cid = 0')
            );

            $this->openPage('计划类型', '添加类型', '#gp-add-category');
            $this->notice($message, $error);
            ?>
            <div class="gp-card">
                <h3 class="gp-card-title">类型列表</h3>
                <?php if (empty($categories)): ?>
                    <div class="gp-empty">还没有任何类型。直接用下面的表单添加第一个。</div>
                <?php endif; ?>
                <table class="gp-table gp-table-cat">
                    <thead>
                    <tr>
                        <th class="gp-w-id">ID</th>
                        <th>类型名</th>
                        <th>说明</th>
                        <th class="gp-w-sort">排序</th>
                        <th class="gp-w-num">计划数</th>
                        <th class="gp-w-op">操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($categories as $cat): ?>
                            <?php $catForm = 'gp-cat-' . (int) $cat['id']; ?>
                            <tr>
                                <td class="gp-mono"><?php echo (int) $cat['id']; ?></td>
                                <td>
                                    <div class="gp-cat-name">
                                        <?php if ((string) $cat['color'] !== ''): ?>
                                            <span class="gp-dot" style="background:<?php echo Plugin::e($cat['color']); ?>"></span>
                                        <?php endif; ?>
                                        <input form="<?php echo $catForm; ?>" type="text"
                                               class="gp-input-inline" name="name"
                                               value="<?php echo Plugin::e($cat['name']); ?>" required/>
                                    </div>
                                </td>
                                <td class="gp-mono"><?php echo Plugin::e($cat['description']); ?></td>
                                <td>
                                    <input form="<?php echo $catForm; ?>"
                                           type="number" class="gp-input-mini" name="sort"
                                           value="<?php echo (int) $cat['sort']; ?>"/>
                                </td>
                                <td class="gp-mono">
                                    <a href="<?php echo $this->url(['view' => 'plans', 'cid' => (int) $cat['id']]); ?>">
                                        <?php echo (int) $cat['plan_count']; ?>
                                    </a>
                                </td>
                                <td>
                                    <div class="gp-ops">
                                        <button type="submit" form="<?php echo $catForm; ?>"
                                                class="btn btn-xs">保存</button>
                                        <button type="submit" form="gp-cat-del-<?php echo (int) $cat['id']; ?>"
                                                class="btn btn-xs gp-btn-danger">删除</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="gp-row-muted">
                            <td class="gp-mono">—</td>
                            <td>未分类</td>
                            <td>系统分组，不可编辑</td>
                            <td>—</td>
                            <td class="gp-mono">
                                <a href="<?php echo $this->url(['view' => 'plans', 'cid' => 0]); ?>">
                                    <?php echo (int) ($uncategorized['num'] ?? 0); ?>
                                </a>
                            </td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>

                <?php
                // 同计划表：行内表单统一放在表格之后，用 form="id" 属性关联，
                // 避免 form 出现在 td 里被浏览器重排 DOM 而挤乱表头。
                foreach ($categories as $cat):
                    $cid = (int) $cat['id'];
                    ?>
                    <form method="post" class="gp-inline-form"
                          action="<?php echo $this->urlPage(); ?>"
                          id="gp-cat-<?php echo $cid; ?>">
                        <?php $this->hidden('edit_category'); ?>
                        <input type="hidden" name="id" value="<?php echo $cid; ?>"/>
                    </form>
                    <form method="post" class="gp-inline-form gp-js-confirm"
                          action="<?php echo $this->urlPage(); ?>"
                          id="gp-cat-del-<?php echo $cid; ?>"
                          data-confirm="确定删除类型「<?php echo Plugin::e($cat['name']); ?>」吗？该类型下的计划会归入「未分类」，计划本身不会被删除。">
                        <?php $this->hidden('del_category'); ?>
                        <input type="hidden" name="id" value="<?php echo $cid; ?>"/>
                    </form>
                <?php endforeach; ?>
            </div>

            <div class="gp-card" id="gp-add-category">
                <h3 class="gp-card-title">添加类型</h3>
                <form method="post" action="<?php echo $this->urlPage(); ?>" class="gp-form-grid">
                    <?php $this->hidden('add_category'); ?>
                    <label class="gp-field gp-field-md">
                        <span>类型名</span>
                        <input type="text" name="name" required
                               placeholder="例如：技能计划 / 目标计划 / 旅行计划"/>
                    </label>
                    <label class="gp-field gp-field-grow">
                        <span>说明（可留空）</span>
                        <input type="text" name="description" placeholder="这个类型大概是做什么的"/>
                    </label>
                    <label class="gp-field gp-field-xs">
                        <span>颜色（可留空）</span>
                        <input type="text" name="color" placeholder="#D97757"/>
                    </label>
                    <label class="gp-field gp-field-xs">
                        <span>排序</span>
                        <input type="number" name="sort" value="0"/>
                    </label>
                    <div class="gp-field gp-field-btn">
                        <button type="submit" class="btn primary">添加类型</button>
                    </div>
                </form>
                <p class="gp-hint">
                    排序值越小越靠前，相同排序按 ID 升序。建议先建类型再录计划。
                    颜色只接受 <code>#rgb</code> 或 <code>#rrggbb</code>，会用作前台分组标题的小圆点。
                </p>
            </div>
            <?php
            $this->closePage();
        }

        // -----------------------------------------------------------------
        // 页面框架
        // -----------------------------------------------------------------

        /**
         * 页面框架开：标题 + 顶部导航 + 打开栅格容器
         */
        private function openPage($title, $addText = '', $addAnchor = '')
        {
            ?>
            <div class="gp-backend" id="gp-plans-top">
            <div class="gp-page-title">
                <h2><?php echo Plugin::e($title); ?></h2>
                <?php if ($addText !== ''): ?>
                    <a href="<?php echo $addAnchor; ?>" class="gp-add-link"><?php echo Plugin::e($addText); ?></a>
                <?php endif; ?>
            </div>
            <?php

            $this->nav();
            echo '<div class="row typecho-page-main"><div class="col-mb-12 typecho-list">';
        }

        /**
         * 页面框架关：插件设置卡片 + 关上栅格容器
         */
        private function closePage()
        {
            $this->renderSettings();
            echo '</div></div></div>';
        }

        private function nav()
        {
            $tabs = [
                'plans'      => ['view' => 'plans', 'text' => '计划'],
                'categories' => ['view' => 'categories', 'text' => '类型'],
            ];
            ?>
            <div class="gp-nav">
                <ul class="gp-nav-list">
                    <?php foreach ($tabs as $key => $tab): ?>
                        <li class="<?php echo $key === $this->view ? 'is-active' : ''; ?>">
                            <a href="<?php echo $this->url($tab); ?>"><?php echo Plugin::e($tab['text']); ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="gp-nav-status">
                    前台
                    <strong class="<?php echo '1' === $this->cfg['front_enabled'] ? 'on' : 'off'; ?>">
                        <?php echo '1' === $this->cfg['front_enabled'] ? '已开启' : '已关闭'; ?>
                    </strong>
                    ｜即将到期阈值
                    <strong><?php echo (int) $this->cfg['soon_days']; ?> 天</strong>
                </div>
            </div>
            <?php
        }

        private function renderSettings()
        {
            ?>
            <div class="gp-card gp-card-settings">
                <h3 class="gp-card-title">插件设置</h3>
                <form method="post" action="<?php echo $this->urlPage(); ?>" class="gp-form-grid">
                    <?php $this->hidden('save_config'); ?>
                    <label class="gp-field gp-field-sm">
                        <span>前台显示</span>
                        <select name="front_enabled">
                            <option value="1"<?php echo '1' === $this->cfg['front_enabled'] ? ' selected' : ''; ?>>开启</option>
                            <option value="0"<?php echo '1' !== $this->cfg['front_enabled'] ? ' selected' : ''; ?>>关闭</option>
                        </select>
                    </label>
                    <label class="gp-field gp-field-xs">
                        <span>即将到期阈值</span>
                        <input type="number" name="soon_days" min="0" max="365"
                               value="<?php echo (int) $this->cfg['soon_days']; ?>"/>
                    </label>
                    <label class="gp-field gp-field-sm">
                        <span>状态标签</span>
                        <select name="show_status">
                            <option value="1"<?php echo '1' === $this->cfg['show_status'] ? ' selected' : ''; ?>>显示</option>
                            <option value="0"<?php echo '1' !== $this->cfg['show_status'] ? ' selected' : ''; ?>>隐藏</option>
                        </select>
                    </label>
                    <label class="gp-field gp-field-sm">
                        <span>年份筛选</span>
                        <select name="year_filter">
                            <option value="1"<?php echo '1' === $this->cfg['year_filter'] ? ' selected' : ''; ?>>显示</option>
                            <option value="0"<?php echo '1' !== $this->cfg['year_filter'] ? ' selected' : ''; ?>>隐藏</option>
                        </select>
                    </label>
                    <label class="gp-field gp-field-xs">
                        <span>开始节点颜色</span>
                        <input type="color" name="node_start_color"
                               value="<?php echo Plugin::e($this->cfg['node_start_color']); ?>"/>
                    </label>
                    <label class="gp-field gp-field-xs">
                        <span>结束节点颜色</span>
                        <input type="color" name="node_end_color"
                               value="<?php echo Plugin::e($this->cfg['node_end_color']); ?>"/>
                    </label>
                    <label class="gp-field gp-field-grow">
                        <span>关闭时的提示文案</span>
                        <input type="text" name="offline_tip"
                               value="<?php echo Plugin::e($this->cfg['offline_tip']); ?>"/>
                    </label>
                    <label class="gp-field gp-field-grow">
                        <span>无计划时的空状态文案</span>
                        <input type="text" name="empty_tip"
                               value="<?php echo Plugin::e($this->cfg['empty_tip']); ?>"/>
                    </label>
                    <div class="gp-field gp-field-btn">
                        <button type="submit" class="btn primary">保存设置</button>
                    </div>
                </form>

                <div class="gp-deliverables">
                    <div class="gp-deliverable-row">
                        <span class="gp-dl-label">前台计划页</span>
                        <?php if (Plugin::pageId() > 0): ?>
                            <code><?php echo Plugin::e(Plugin::plansUrl()); ?></code>
                            <span class="gp-ok">
                                独立页面已创建
                                <a href="<?php echo Plugin::e(Plugin::plansUrl()); ?>"
                                   target="_blank" rel="noopener">打开预览</a>
                            </span>
                        <?php else: ?>
                            <span class="gp-warn">未检测到独立页面，将在下次访问后台时自动创建</span>
                        <?php endif; ?>
                    </div>
                    <div class="gp-deliverable-row">
                        <span class="gp-dl-label">主题模板</span>
                        <?php if (Plugin::themeHasTemplate()): ?>
                            <code><?php echo Plugin::TEMPLATE_FILE; ?></code>
                            <span class="gp-ok">已就位</span>
                        <?php else: ?>
                            <span class="gp-warn">
                                当前主题缺少 <?php echo Plugin::TEMPLATE_FILE; ?>，请把插件包内
                                <code>usr/themes/你的主题/<?php echo Plugin::TEMPLATE_FILE; ?></code> 复制到
                                <code>usr/themes/<?php echo Plugin::e($this->options()->theme); ?>/</code>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php
        }

        private function notice($message, $error)
        {
            if ($message) {
                echo '<div class="gp-notice gp-notice-ok">' . Plugin::e($message) . '</div>';
            }
            if ($error) {
                echo '<div class="gp-notice gp-notice-error">' . Plugin::e($error) . '</div>';
            }
        }

        // -----------------------------------------------------------------
        // 独立页面
        // -----------------------------------------------------------------

        /**
         * 自动创建前台独立页面
         *
         * contents.template 必须写完整文件名（含 .php）：Widget\Archive::render()
         * 会把该值直接拼到 themeDir 后面做 file_exists()，只写别名会静默回落 page.php。
         */
        private function autoCreatePage()
        {
            if (Plugin::pageId() > 0) {
                return;
            }

            try {
                $db = $this->db();

                $exists = $db->fetchRow(
                    $db->select('cid')->from('table.contents')
                        ->where('slug = ?', Plugin::PAGE_SLUG)
                        ->limit(1)
                );

                if (!empty($exists)) {
                    Plugin::resetPageIdCache();
                    if (Plugin::pageId() > 0) {
                        return;
                    }
                    $db->query(
                        $db->update('table.contents')
                            ->rows([
                                'type'     => 'page',
                                'status'   => 'publish',
                                'template' => Plugin::TEMPLATE_FILE,
                                'title'    => Plugin::PAGE_TITLE,
                            ])
                            ->where('cid = ?', (int) $exists['cid'])
                    );
                    Plugin::resetPageIdCache();
                    return;
                }

                $now = time();
                $db->query(
                    $db->insert('table.contents')->rows([
                        'title'        => Plugin::PAGE_TITLE,
                        'slug'         => Plugin::PAGE_SLUG,
                        'created'      => $now,
                        'modified'     => $now,
                        'text'         => '',
                        'order'        => 0,
                        'authorId'     => (int) \Widget\User::alloc()->uid,
                        'template'     => Plugin::TEMPLATE_FILE,
                        'type'         => 'page',
                        'status'       => 'publish',
                        'password'     => null,
                        'commentsNum'  => 0,
                        'allowComment' => 0,
                        'allowPing'    => 0,
                        'allowFeed'    => 0,
                        'parent'       => 0,
                    ])
                );

                Plugin::resetPageIdCache();
            } catch (\Throwable $e) {
                // 建页失败不阻塞后台使用，前台会在下次访问时重试
            }
        }

        // -----------------------------------------------------------------
        // 基础设施
        // -----------------------------------------------------------------

        /**
         * 构造带 view 的 URL
         *
         * @param array $params
         * @return string
         */
        private function url(array $params = []): string
        {
            $base = [
                'panel' => Plugin::PANEL_FILE,
            ];
            $query = array_merge($base, $params);

            return $this->basePath() . '?' . http_build_query($query);
        }

        /**
         * 当前页面 URL（表单 action 用）
         *
         * 只用 panel + view，不带 id / cid 等展示参数 —— 这不是省略，
         * 而是必须如此。
         *
         * 曾试过回吐完整 REQUEST_URI，结果踩了一个隐蔽的坑：表单 action 的
         * 查询串会被解析进 $_GET，而 Typecho\Request::get() 取参数时 $_GET
         * 优先于 $_POST。于是「编辑 #6」的页面里，行内表单 action 带上
         * id=6，提交时改的是 999999 那条，$_GET 里的 id=6 却把它盖回 6 ——
         * 目标被静默劫持，编辑了另一条计划。
         *
         * 所以 action 上只允许出现「与提交数据无关」的参数，任何表单字段
         * 都不得出现在这里。
         */
        private function urlPage(): string
        {
            return $this->url(['view' => $this->view]);
        }

        /**
         * 面板入口路径
         *
         * 用相对路径，避免 Common::url() 在某些 rewrite 规则下算错。
         *
         * @return string
         */
        private function basePath(): string
        {
            return '/admin/extending.php';
        }

        /**
         * 输出 CSRF 隐藏域（含 do 值）
         *
         * Widget\Security::getToken($suffix) = md5(token . '&' . $suffix)，
         * token 始终以 Referer 为键，所以这里用当前请求 URL 作后缀。
         *
         * @param string $do
         */
        private function hidden(string $do)
        {
            $token = $this->security()->getToken($this->request()->getRequestUrl());
            echo '<input type="hidden" name="do" value="' . Plugin::e($do) . '">';
            echo '<input type="hidden" name="_" value="' . Plugin::e($token) . '">';
        }

        /**
         * 取请求参数
         *
         * Typecho\Widget\Request 没有 post()，get() 会按
         * params → sandbox → @json → $_GET → $_POST 优先级查找。
         *
         * @param string $key
         * @param mixed $default
         * @return mixed
         */
        private function param(string $key, $default = null)
        {
            $value = $this->request()->get($key, $default);

            if (is_string($value)) {
                $value = trim($value);
                return $value === '' ? $default : $value;
            }

            return $value;
        }

        /**
         * 跳转回面板某视图
         *
         * @param string $view
         * @param string|null $message
         * @param string|null $error
         * @param array $extra 额外的 query 参数（如日志页要保留当前选中的计划）
         */
        private function redirect($view, $message = null, $error = null, array $extra = [])
        {
            $params = array_merge(['view' => $view], $extra);

            if ($message !== null && $message !== '') {
                $params['msg'] = $message;
            }
            if ($error !== null && $error !== '') {
                $params['err'] = $error;
            }

            $this->response()->redirect($this->url($params));
        }

        /** @return \Typecho\Db */
        private function db()
        {
            return Db::get();
        }

        /**
         * 站点根地址
         *
         * @return string
         */
        private function siteUrl(): string
        {
            $options = $this->options();
            if (!empty($options->siteUrl)) {
                return (string) $options->siteUrl;
            }

            return '/';
        }

        /** @return \Widget\Options */
        private function options()
        {
            return \Widget\Options::alloc();
        }

        /** @return \Widget\Security */
        private function security()
        {
            return \Widget\Security::alloc();
        }

        /** @return \Typecho\Request */
        private function request()
        {
            return \Typecho\Request::getInstance();
        }

        /** @return \Typecho\Widget\Response */
        private function response()
        {
            return \Widget\Options::alloc()->response;
        }
    }
}

$gpAdmin = new GrowthPlan_Admin(new Plugin());
$gpAdmin->dispatch();
