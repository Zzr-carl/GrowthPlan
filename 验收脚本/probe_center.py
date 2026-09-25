#!/usr/bin/env python3
"""居中节点（side-c）验收：开始 / 结束卡片的中轴是否落在主干线上。

⚠️ 关键前提：时间线平时是**关闭的模态框**（`.gp-modal` display:none）。
   必须先点卡片让 `:target` 激活，量到的才是真实布局值；
   否则所有 offsetWidth 都是 0，测出来的「对齐」全是假绿。

⚠️ 口径：视觉量测（dotCx / cardCx）用 getBoundingClientRect 可以，
   但比较时统一在同一参照系 —— 都用 rect，旋转影响两边同时存在，相减抵消。
   本脚本用 rect 做「中轴 vs 圆点」的比较（相对值），
   用 offset 做「<li> 高度」的比较（绝对值），各取所长。
"""
import sys, re
from playwright.sync_api import sync_playwright

URL = 'http://127.0.0.1:8080/index.php/growth.html'

ok = fail = 0
def t(name, cond, extra=''):
    global ok, fail
    if cond: ok += 1; print('  PASS  %s' % name)
    else:    fail += 1; print('  FAIL  %s  %s' % (name, extra))

with sync_playwright() as p:
    br = p.chromium.launch()
    pg = br.new_page(viewport={'width': 1440, 'height': 2000})
    pg.goto(URL, wait_until='networkidle')
    pg.wait_for_timeout(400)

    print('=== 0. 先打开一个含时间线的模态 ===')
    cards = pg.evaluate("""() => [...document.querySelectorAll('a.gp-card')]
        .map(a => a.getAttribute('href'))""")
    t('页面上有可点开的计划卡片', len(cards) > 0, str(len(cards)))
    if not cards:
        br.close(); sys.exit(1)
    # 逐个尝试，找到第一个能打开且含 side-c 的模态
    target = None
    for href in cards:
        pid = href.lstrip('#')
        pg.locator('a.gp-card[href="#%s"]' % pid).first.click()
        pg.wait_for_timeout(500)
        n = pg.evaluate("""(id) => {
            const m = document.getElementById(id);
            if (!m || getComputedStyle(m).display === 'none') return 0;
            return m.querySelectorAll('.gp-tl-item.side-c').length;
        }""", pid)
        if n > 0:
            target = pid
            print('      打开模态 %s，其中 side-c 节点 %d 个' % (pid, n))
            break
    t('成功打开一个含 side-c 的模态', target is not None, str(target))
    if not target:
        br.close(); sys.exit(1)

    print('=== 1. side-c 节点存在性 ===')
    n_sc = pg.evaluate("(id) => document.getElementById(id).querySelectorAll('.gp-tl-item.side-c').length", target)
    n_start = pg.evaluate("(id) => document.getElementById(id).querySelectorAll('.gp-tl-item.side-c.is-start').length", target)
    n_end = pg.evaluate("(id) => document.getElementById(id).querySelectorAll('.gp-tl-item.side-c.is-end').length", target)
    print('      side-c=%d  is-start=%d  is-end=%d' % (n_sc, n_start, n_end))
    t('存在 side-c 节点', n_sc > 0, str(n_sc))
    t('开始节点已改为 side-c', n_start > 0, str(n_start))
    t('结束节点已改为 side-c', n_end > 0, str(n_end))
    t('side-c 里不含日志/打卡（只有首尾）',
      pg.evaluate("""(id) => document.getElementById(id).querySelectorAll(
          '.gp-tl-item.side-c.gp-tl-log, .gp-tl-item.side-c.gp-tl-post').length""", target) == 0)

    print('=== 2. 卡片中轴 vs 主干线（rail）===')
    # ⚠️ 口径修正：不能再拿「卡片中轴 == 圆点中心」当契约了。
    #    居中节点上圆点被**故意隐藏**（display:none）—— 见源码注释：
    #    卡片骑在线上时，圆点和卡片落在同一个 x，圆点会砸在卡片正中间的字上。
    #    所以真正的契约变成「卡片中轴 == rail」。
    #    rail 从 ul 的几何算：ul 左缘 + rail 值（li 的 offsetLeft 起点即 ul 内容盒左缘）。
    data = pg.evaluate("""(id) => {
      const root = document.getElementById(id);
      const ul = root.querySelector('.gp-tl');
      const ulR = ul.getBoundingClientRect();
      const rail = (r => r.endsWith('%') ? ul.offsetWidth * parseFloat(r) / 100 : parseFloat(r))(getComputedStyle(ul).getPropertyValue('--gp-rail').trim());
      const railX = ulR.left + rail;
      const out = [];
      root.querySelectorAll('.gp-tl-item.side-c').forEach(li => {
        const dot = li.querySelector('.gp-tl-dot');
        const card = li.querySelector(':scope > .gp-tl-card, :scope > details > .gp-tl-card');
        const cardR = card.getBoundingClientRect();
        const liR = li.getBoundingClientRect();
        out.push({
          kind: li.classList.contains('is-start') ? 'start'
              : li.classList.contains('is-end') ? 'end' : '?',
          railX: +railX.toFixed(2),
          dotDisplay: dot ? getComputedStyle(dot).display : 'none',
          dotCx: dot ? +(dot.getBoundingClientRect().left + dot.getBoundingClientRect().width / 2).toFixed(2) : null,
          cardCx: +(cardR.left + cardR.width / 2).toFixed(2),
          cardW: +cardR.width.toFixed(1),
          cardH: +cardR.height.toFixed(1),
          delta: +((cardR.left + cardR.width / 2) - railX).toFixed(2),
          liH: li.offsetHeight,
          padTop: getComputedStyle(li).paddingTop,
          cardTf: getComputedStyle(card).transform,
        });
      });
      return out;
    }""", target)
    for d in data:
        print('      %-5s railX=%-8.1f cardCx=%-8.1f Δ=%-7.2f cardW=%-6.1f cardH=%-5.1f liH=%-4d'
              % (d['kind'], d['railX'], d['cardCx'], d['delta'], d['cardW'], d['cardH'], d['liH']))

    t('取到了 side-c 节点', len(data) > 0, str(len(data)))
    bad = [d for d in data if abs(d['delta']) > 2.0]
    t('所有首尾卡片中轴落在主干线上（|Δ| ≤ 2px）', len(bad) == 0,
      '偏差项: ' + str([(d['kind'], d['delta']) for d in bad]))
    # 圆点被故意隐掉（避免砸在卡片文字上）
    t('居中节点的圆点已隐藏（不再砸在卡片文字上）',
      all(d['dotDisplay'] == 'none' for d in data),
      str([(d['kind'], d['dotDisplay']) for d in data]))

    print('=== 3. 绝对定位没有把 <li> 压塌 ===')
    flat = [d for d in data if d['liH'] < 24]
    for d in data:
        print('      %-5s liH=%-4d padTop=%-6s %s'
              % (d['kind'], d['liH'], d['padTop'], 'OK' if d['liH'] >= 24 else 'COLLAPSED'))
    t('所有 side-c 的 <li> 高度 ≥ 24px（未被绝对定位压塌）', len(flat) == 0,
      str([(d['kind'], d['liH']) for d in flat]))

    print('=== 4. 卡片确实用了绝对定位 + translateX ===')
    t('卡片是 position:absolute', all(
        pg.evaluate("""(id) => {
          const li = document.getElementById(id).querySelector('.gp-tl-item.side-c');
          const c = li.querySelector(':scope > .gp-tl-card, :scope > details > .gp-tl-card');
          return getComputedStyle(c).position;
        }""", target) == 'absolute' for _ in [0]))
    tf_bad = [d for d in data if 'matrix' not in d['cardTf'] and d['cardTf'] != 'none']
    print('      cardTf 样本: %s' % (data[0]['cardTf'] if data else '-'))
    t('卡片带 translateX 位移（transform 生效）',
      all(d['cardTf'] != 'none' for d in data),
      str([d['cardTf'][:40] for d in data[:3]]))

    print('=== 5. 没有多余的连接线 / 日期列 ===')
    t('side-c 不渲染连接线（::after content:none）',
      pg.evaluate("""(id) => {
        const li = document.getElementById(id).querySelector('.gp-tl-item.side-c');
        return getComputedStyle(li, '::after').content === 'none';
      }""", target))
    t('side-c 隐藏左侧日期列',
      pg.evaluate("""(id) => {
        const d = document.getElementById(id).querySelector('.gp-tl-item.side-c > .gp-tl-date');
        return !d || getComputedStyle(d).display === 'none';
      }""", target))

    print('=== 6. 卡片宽度受控（不横跨屏幕）===')
    wide = [d for d in data if d['cardW'] > 320]
    print('      max cardW = %.1f' % (max([d['cardW'] for d in data]) if data else 0))
    t('所有首尾卡片宽度 ≤ 320px', len(wide) == 0, str([(d['kind'], d['cardW']) for d in wide]))
    t('所有首尾卡片有实际高度（内容渲染出来了）',
      all(d['cardH'] > 10 for d in data), str([(d['kind'], d['cardH']) for d in data]))

    print('=== 7. 日志默认展开（本轮另一处改动）===')
    logs = pg.evaluate("""(id) => [...document.getElementById(id)
        .querySelectorAll('.gp-tl-log details.gp-tl-details')]
        .map(d => ({open: d.hasAttribute('open'),
                    chev: !!d.querySelector('.gp-tl-chev'),
                    cls: d.className}))""", target)
    if logs:
        for l in logs:
            print('      open=%s chev=%s %s' % (l['open'], l['chev'], l['cls']))
        t('有日志节点', True)
        t('日志节点全部默认展开', all(l['open'] for l in logs))
        t('日志节点不再渲染展开箭头', all(not l['chev'] for l in logs))
    else:
        print('      （本模态没有日志节点，换个模态再试）')
        # 换一个含日志的模态。⚠️ 必须先把当前模态关掉：
        #    打开态覆盖全屏的 .gp-modal-veil 会拦截后续 click。
        #    关法就是点遮罩本身（它的 href=#gp-grid 把 target 挪走）。
        veil = pg.locator('.gp-modal-veil').first
        if veil.count() > 0:
            veil.click(force=True)
            pg.wait_for_timeout(350)
        still_open = pg.evaluate("""() => [...document.querySelectorAll('.gp-modal')]
            .filter(m => getComputedStyle(m).display !== 'none').length""")
        print('      关闭后仍打开的模态数: %d' % still_open)
        for href in cards:
            pid = href.lstrip('#')
            if pg.evaluate("""(id) => !document.getElementById(id)""", pid):
                continue
            if still_open > 0:
                # 还有模态开着，用 JS 直接点（绕过 veil 拦截）
                pg.evaluate("""(sel) => document.querySelector(sel).click()""",
                            'a.gp-card[href="#%s"]' % pid)
            else:
                pg.locator('a.gp-card[href="#%s"]' % pid).first.click()
            pg.wait_for_timeout(450)
            lg = pg.evaluate("""(id) => [...document.getElementById(id)
                .querySelectorAll('.gp-tl-log details.gp-tl-details')]
                .map(d => ({open: d.hasAttribute('open'), chev: !!d.querySelector('.gp-tl-chev')}))""", pid)
            if lg:
                print('      模态 %s 有 %d 个日志节点' % (pid, len(lg)))
                for l in lg:
                    print('        open=%s chev=%s' % (l['open'], l['chev']))
                t('日志节点全部默认展开', all(l['open'] for l in lg))
                t('日志节点不再渲染展开箭头', all(not l['chev'] for l in lg))
                break

    print('=== 8. 日志节点仍在左侧（side-l），打卡仍在右侧（side-r）===')
    sides = pg.evaluate("""(id) => {
      const root = document.getElementById(id);
      const g = (sel) => [...root.querySelectorAll(sel)]
          .map(li => li.className.replace('gp-tl-item gp-tl-node ', '').trim());
      return {log: g('.gp-tl-log'), post: g('.gp-tl-post'), round: g('.gp-tl-round'),
              child: g('.gp-tl-child')};
    }""", target)
    print('      log:   %s' % sides['log'][:3])
    print('      post:  %s' % sides['post'][:3])
    t('日志节点都挂 side-l（左侧）', all('side-l' in c for c in sides['log']) if sides['log'] else True)
    t('打卡节点都挂 side-r（右侧）', all('side-r' in c for c in sides['post']) if sides['post'] else True)
    t('日志与打卡分居两侧（不是同侧）',
      (not sides['log'] or not sides['post']) or
      (all('side-l' in c for c in sides['log']) and all('side-r' in c for c in sides['post'])))

    pg.screenshot(path='/tmp/shot_center_desktop.png', full_page=False)
    print('      截图: /tmp/shot_center_desktop.png')

    br.close()

print('\n==== %d PASS / %d FAIL ====' % (ok, fail))
sys.exit(1 if fail else 0)
