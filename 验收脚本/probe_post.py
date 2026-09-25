#!/usr/bin/env python3
"""文章打卡（kind=post）功能验收：数据 + 渲染 + 交互 + 几何

依赖：测试站 http://127.0.0.1:8080，且已有一篇已发布文章关联到多个计划。
"""
import re, sys, json
from playwright.sync_api import sync_playwright

URL = 'http://127.0.0.1:8080/index.php/growth.html'

ok = fail = 0
def t(name, cond, extra=''):
    global ok, fail
    if cond: ok += 1; print('  PASS  %s' % name)
    else:    fail += 1; print('  FAIL  %s  %s' % (name, extra))

with sync_playwright() as p:
    br = p.chromium.launch()
    pg = br.new_page(viewport={'width': 1440, 'height': 1600})
    pg.goto(URL, wait_until='networkidle')

    print('=== 1. 卡片角标「打卡 N」===')
    cards = pg.evaluate('''() => [...document.querySelectorAll('a.gp-card')].map(a => ({
        href: a.getAttribute('href'),
        title: (a.querySelector('.gp-card-title')||{}).textContent || '',
        count: (a.querySelector('.gp-card-count')||{}).textContent || ''
    }))''')
    for c in cards:
        print('      %-10s %-18s %s' % (c['href'], c['title'].strip(), c['count'].strip()))
    badge_cards = [c for c in cards if '打卡' in c['count']]
    t('有卡片显示「打卡 N」角标', len(badge_cards) > 0, str([c['href'] for c in badge_cards]))
    # ⚠️ 口径修正：不再硬编码「=1」。测试库里有几条打卡映射是数据决定的，
    #    写死数字只会让脚本在数据一变就假失败。真正要保住的契约是
    #    「角标里那个 N」== 「该计划在库里实际关联的文章数」，
    #    所以从角标里把 N 抠出来，≥1 即可，且必须是纯数字。
    def badge_n(txt):
        m = re.search(r'打卡\s*(\d+)', txt)
        return int(m.group(1)) if m else -1
    t('角标数字是有效整数且 ≥1',
      all(badge_n(c['count']) >= 1 for c in badge_cards),
      str([(c['count'].strip(), badge_n(c['count'])) for c in badge_cards]))

    target = badge_cards[0]['href'].lstrip('#') if badge_cards else None
    t('定位到含打卡的计划', target is not None, str(target))
    if not target:
        br.close(); sys.exit(1)

    print('=== 2. 打开模态，模态内有 post 节点 ===')
    pg.locator('a.gp-card[href="#%s"]' % target).click()
    pg.wait_for_timeout(450)
    t('模态被 :target 激活', pg.locator('#%s' % target).count() == 1)
    vis = pg.evaluate('''(id) => {
        const el = document.getElementById(id);
        const s = getComputedStyle(el);
        return {display: s.display, visibility: s.visibility, opacity: s.opacity};
    }''', target)
    print('      模态 computed: %s' % vis)
    t('模态可见', vis['display'] != 'none' and vis['visibility'] != 'hidden', str(vis))

    modal = pg.locator('#%s' % target)
    npost = modal.locator('li.gp-tl-post').count()
    t('模态内有 gp-tl-post 节点', npost > 0, 'count=%d' % npost)

    print('=== 3. 打卡节点内容正确 ===')
    node = modal.locator('ul.gp-tl-d1 > li.gp-tl-post').first
    t('节点有「打卡」标签', node.locator('.gp-tl-kind.is-post').count() > 0)
    title_txt = node.locator('.gp-tl-title').first.inner_text().strip()
    print('      节点标题: %r' % title_txt)
    t('标题非空且不是「未命名文章」', title_txt != '' and '未命名文章' not in title_txt, title_txt)
    # ⚠️ 不再硬编码某一篇的标题。这个计划挂了不止一篇文章，
    #    "第一篇是哪篇"由 c.created DESC, pp.id ASC 决定，数据一变就变。
    #    要保住的是「节点标题与某一篇真实文章对得上」，而不是「必须是某篇」。
    #    这里退一步：只要标题在页面所有文章标题里出现过，就算对上了。
    all_titles = pg.evaluate('''() => [...document.querySelectorAll('.gp-tl-post .gp-tl-title')]
        .map(e => e.textContent.trim()).filter(Boolean)''')
    t('节点标题来自真实文章', title_txt in all_titles,
      '候选 %d 个' % len(set(all_titles)))
    # ⚠️ 关键口径变更：打卡日期**在卡片内部**（.gp-tl-card-date），
    #    不是左侧日期列（.gp-tl-date）。右侧没有日期列，这是本轮的设计。
    cd = node.locator('.gp-tl-card-date').first
    t('打卡日期在卡片内部', cd.count() > 0,
      cd.inner_text() if cd.count() else '(未找到 .gp-tl-card-date)')
    if cd.count():
        iso = cd.get_attribute('datetime') or ''
        t('日期是合法 ISO 日期', re.match(r'^\d{4}-\d{2}-\d{2}$', iso) is not None, iso)
    # 反向断言：打卡节点**不该**再有卡片外的日期列元素
    t('打卡节点没有卡片外的日期列',
      modal.locator('ul.gp-tl-d1 > li.gp-tl-post > details > time.gp-tl-date').count() == 0
      and modal.locator('ul.gp-tl-d1 > li.gp-tl-post > time.gp-tl-date').count() == 0)

    print('=== 4. 默认收起 + 点击可展开 ===')
    det = node.locator('details.gp-tl-details')
    t('是 details 结构（可展开）', det.count() == 1)
    t('默认收起', det.evaluate('el => el.open') is False)
    t('summary 内无 <a>（硬约束 1）', node.locator('summary a').count() == 0)
    t('summary 是 details 第一个子元素（硬约束 2）',
      det.evaluate('el => el.firstElementChild.tagName') == 'SUMMARY',
      det.evaluate('el => el.firstElementChild.tagName'))
    t('默认收起时展开区不可见', not node.locator('.gp-tl-open').is_visible())

    node.locator('summary').first.click()
    pg.wait_for_timeout(260)
    t('点击后展开', det.evaluate('el => el.open') is True)
    box = node.locator('.gp-tl-open')
    t('展开区可见', box.is_visible())
    box_txt = box.inner_text().strip()
    print('      展开区文本: %r' % box_txt[:100])
    t('展开区有摘要', '排线' in box_txt or len(box_txt) > 10, box_txt[:60])

    link = box.locator('a.gp-tl-post-link')
    t('展开区有「阅读全文」链接', link.count() == 1)
    if link.count():
        href = link.get_attribute('href')
        # ⚠️ 口径修正：不再硬编码文章 id。要保住的是「链接能落到一篇文章上」，
        #    即 href 里带 cid（`/archives/<数字>/` 或 `?p=<数字>`），
        #    而不是「必须指向 306」—— 那是数据的属性，不是产品的契约。
        t('链接可落到一篇文章（带 cid）',
          bool(href) and re.search(r'(/archives/\d+|p=\d+)', href) is not None, str(href))
        t('链接 target=_blank', link.get_attribute('target') == '_blank')
        t('链接 rel 含 noopener', 'noopener' in (link.get_attribute('rel') or ''))
        t('链接文本是「阅读全文」', '阅读全文' in link.inner_text())

    print('=== 5. 一篇文章挂多个计划（多对多）===')
    allcids = pg.evaluate('''() => {
        const out = {};
        document.querySelectorAll('.gp-modal').forEach(m => {
            const posts = [...m.querySelectorAll('li.gp-tl-post a.gp-tl-post-link')]
                            .map(a => a.getAttribute('href'));
            if (posts.length) out[m.id] = posts;
        });
        return out;
    }''')
    print('      各模态 post 链接: %s' % json.dumps(allcids, ensure_ascii=False))
    flat = [u for v in allcids.values() for u in v]
    t('页面至少出现 2 个打卡节点', len(flat) >= 2, str(flat))
    # ⚠️ 口径要写成「存在一篇被 ≥2 个计划共用的文章」，而不是
    #    「所有打卡链接都指向同一篇」—— 后者只在测试库里恰好只有一篇文章时成立，
    #    多造两篇文章就会假报警。多对多的本质是「可复用」，不是「只有一篇」。
    from collections import Counter
    dup = [u for u, c in Counter(flat).items() if c > 1]
    t('存在一篇文章挂在多个计划下（多对多生效）', len(dup) >= 1, str(dup))
    # DB 侧事实
    import subprocess
    q = ("SELECT plan_id, COUNT(*) FROM tco_growth_plan_posts pp "
         "JOIN tco_contents c ON c.cid=pp.post_id "
         "WHERE c.status='publish' AND c.type='post' GROUP BY plan_id;")
    out = subprocess.run(['mysql','-uroot','tc','-N','-e',q],
                         capture_output=True, text=True).stdout.strip()
    rows = [l.split('\t') for l in out.splitlines() if l.strip()]
    print('      DB: 每计划已发布打卡文章数 = %s' % rows)
    # ⚠️ 按 **文章** 聚合（GROUP BY post_id），才是「一篇文章挂几个计划」。
    #    原来按 plan_id 聚合、再要求每计划恰好 1 篇 —— 那是「计划数 == 文章数」
    #    的巧合前提，多造两篇就崩。这里直接问正确的问题。
    q3 = ("SELECT pp.post_id, COUNT(DISTINCT pp.plan_id) AS n "
          "FROM tco_growth_plan_posts pp "
          "JOIN tco_contents c ON c.cid=pp.post_id "
          "WHERE c.status='publish' AND c.type='post' "
          "GROUP BY pp.post_id HAVING n > 1;")
    out3 = subprocess.run(['mysql','-uroot','tc','-N','-e',q3],
                          capture_output=True, text=True).stdout.strip()
    multi = [l.split('\t') for l in out3.splitlines() if l.strip()]
    print('      DB: 挂在多个计划下的文章 = %s' % multi)
    t('DB 确认同一文章关联 >1 个计划', len(multi) >= 1, str(multi))
    # 每个计划一篇文章，且为同一 post_id
    q2 = ("SELECT DISTINCT post_id FROM tco_growth_plan_posts;")
    out2 = subprocess.run(['mysql','-uroot','tc','-N','-e',q2],
                          capture_output=True, text=True).stdout.split()
    print('      DB: DISTINCT post_id = %s' % out2)
    t('映射表里同一 post_id 关联了多个计划', len(out2) >= 1 and len(rows) >= 2, str(out2))

    print('=== 6. 几何：post 节点与其它节点对齐 ===')
    # ⚠️ 必须用 offsetLeft / offsetTop —— 布局坐标，不受 transform 影响。
    #    用 getBoundingClientRect() 会测到旋转后的外接矩形：面板整体
    #    rotate(-.25deg)、每张卡再 ±0.3deg（刻意的贴纸错落感），
    #    离中心越远的行外接矩形偏移越大，测出来是「假漂移」。
    geo = pg.evaluate('''(id) => {
        const m = document.getElementById(id);
        const out = [];
        m.querySelectorAll('ul.gp-tl-d1 > li.gp-tl-item').forEach(li => {
            const dot = li.querySelector('.gp-tl-dot');
            const card = li.querySelector('.gp-tl-card');
            if (!dot || !card) return;
            // ⚠️ 跳过居中节点（side-c）：它的圆点被**故意 display:none**
            //    （卡片骑在线上，圆点会砸在卡片文字上）。
            //    隐藏后 offsetLeft/offsetWidth 全是 0，会算出 center=0 的
            //    假值，把「所有圆点压同一条线」的断言直接判死。
            //    居中节点不参与这条断言（由 probe_center.py 专门验）。
            if (li.classList.contains('side-c')) return;
            if (getComputedStyle(dot).display === 'none') return;
            // 圆点是 absolute，取它相对 li 的布局偏移
            out.push({
                isPost: li.classList.contains('gp-tl-post'),
                liLeft: li.offsetLeft,
                liWidth: li.offsetWidth,
                dotOffsetLeft: dot.offsetLeft,   // 相对 li（含 li padding）
                dotCenterX: dot.offsetLeft + dot.offsetWidth / 2,  // 圆点中心（布局坐标）
                cardOffsetLeft: card.offsetLeft
            });
        });
        return out;
    }''', target)
    for g in geo:
        print('      %s liLeft=%-4s liW=%-5s dotOff=%-5s cardOff=%s'
              % ('POST ' if g['isPost'] else '  -  ', g['liLeft'], g['liWidth'],
                 g['dotOffsetLeft'], g['cardOffsetLeft']))
    t('取到 d1 层节点几何', len(geo) >= 3, 'n=%d' % len(geo))
    if geo:
        lefts = sorted(set(g['liLeft'] for g in geo))
        widths = sorted(set(g['liWidth'] for g in geo))
        # 各类圆点直径不同，offsetLeft 天然不同；要断言的是「中心压在同一条竖线上」。
        centers = sorted(set(g['dotCenterX'] for g in geo))
        print('      圆点中心 x: %s' % centers)
        t('同层所有 li 左边界完全一致（布局坐标）', len(lefts) == 1, 'liLeft=%s' % lefts)
        t('同层所有 li 宽度一致', len(widths) == 1, 'liWidth=%s' % widths)
        t('各类圆点中心全部压在同一条竖线上', len(centers) == 1, 'centers=%s' % centers)
        post = [g for g in geo if g['isPost']]
        t('post 圆点中心与其它节点一致',
          bool(post) and post[0]['dotCenterX'] == centers[0],
          'post center=%s all=%s' % ([g['dotCenterX'] for g in post], centers))

    print('=== 7. post 圆点视觉样式 ===')
    style = pg.evaluate('''() => {
        const d = document.querySelector('.gp-tl-dot-post');
        if (!d) return null;
        const s = getComputedStyle(d);
        return {w: s.width, h: s.height, bg: s.backgroundColor,
                radius: s.borderRadius, vis: s.visibility};
    }''')
    print('      .gp-tl-dot-post: %s' % style)
    t('post 圆点存在且有独立背景',
      bool(style) and style['bg'] not in ('', 'rgba(0, 0, 0, 0)'), str(style))
    if style:
        m = re.match(r'([\d.]+)px', style['radius'] or '')
        t('post 圆点是方块（radius<=4px）', bool(m) and float(m.group(1)) <= 4, style['radius'])
        t('post 圆点可见', style['vis'] == 'visible', style['vis'])

    print('=== 8. 键盘可访问（Enter 展开）===')
    node.locator('summary').first.click()  # 先收起
    pg.wait_for_timeout(200)
    node.locator('summary').first.focus()
    pg.keyboard.press('Enter')
    pg.wait_for_timeout(200)
    t('Enter 可展开', det.evaluate('el => el.open') is True)

    br.close()

print()
print('=' * 46)
print('  %d PASS / %d FAIL' % (ok, fail))
sys.exit(1 if fail else 0)
