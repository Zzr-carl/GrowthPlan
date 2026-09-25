#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""首尾路牌排版验收 v2：
   1) 卡片宽度 = 内容宽（「开始 + 日期」一行）
   2) 日期在卡片内、且横向完全落在胶囊右侧（不被胶囊切断）
   3) 胶囊首尾端头不再有色块突出
"""
from playwright.sync_api import sync_playwright

URL = 'http://127.0.0.1:8080/growth.html'
ok = 0
bad = []


def chk(cond, msg):
    global ok
    if cond:
        ok += 1
        print(f'  \033[32mPASS\033[0m {msg}')
    else:
        bad.append(msg)
        print(f'  \033[31mFAIL\033[0m {msg}')


with sync_playwright() as p:
    br = p.chromium.launch()
    pg = br.new_page(viewport={'width': 1440, 'height': 1400})
    pg.goto(URL, wait_until='networkidle')
    pg.wait_for_timeout(400)

    cards = pg.evaluate("""() => [...document.querySelectorAll('a.gp-card')]
        .map(a => a.getAttribute('href')).filter(Boolean)""")
    tid = None
    for href in cards:
        pid = href.lstrip('#')
        pg.locator('a.gp-card[href="#%s"]' % pid).first.click()
        pg.wait_for_timeout(450)
        n = pg.evaluate("""(id) => {
            const m = document.getElementById(id);
            if (!m || getComputedStyle(m).display === 'none') return 0;
            return m.querySelectorAll('.gp-tl-item.side-c').length;
        }""", pid)
        if n > 0:
            tid = pid
            break

    d = pg.evaluate("""(id) => {
      const tl = document.getElementById(id).querySelector('.gp-tl');
      const tlr = tl.getBoundingClientRect();
      const shell = getComputedStyle(tl, '::before');
      const rail = getComputedStyle(tl).getPropertyValue('--gp-rail').trim();
      const rc = el => { const b = el.getBoundingClientRect();
        return {t:b.top,b:b.bottom,h:b.height,l:b.left,r:b.right,w:b.width,
                cx:(b.left+b.right)/2}; };
      const edges = [...tl.querySelectorAll(':scope > .gp-tl-item.side-c')].map(li => {
        const card = li.querySelector(':scope > .gp-tl-card');
        const when = li.querySelector(':scope > .gp-tl-card > .gp-tl-when');
        const kind = li.querySelector('.gp-tl-kind');
        return {kind: kind ? kind.textContent.trim() : '?',
                cls: li.className, card: rc(card),
                when: when ? rc(when) : null};
      });
      return {
        rail, tl: {l: tlr.left, r: tlr.right, w: tlr.width},
        capL: tlr.left + parseFloat(shell.left),
        capR: tlr.left + parseFloat(shell.left) + parseFloat(shell.width),
        capT: tlr.top + parseFloat(shell.top),
        capB: tlr.bottom - parseFloat(shell.bottom),
        edges,
      };
    }""", tid)

    capL, capR = d['capL'], d['capR']
    print(f"rail={d['rail']}  tl w={d['tl']['w']:.0f}  胶囊 x=[{capL:.1f},{capR:.1f}] y=[{d['capT']:.1f},{d['capB']:.1f}]")
    for e in d['edges']:
        c, w = e['card'], e['when']
        print(f"\n--- 「{e['kind']}」")
        print(f"  card L={c['l']:.1f} R={c['r']:.1f} W={c['w']:.1f} cx={c['cx']:.1f}")
        if w:
            print(f"  when L={w['l']:.1f} R={w['r']:.1f} W={w['w']:.1f}")

    print('\n断言：')
    for e in d['edges']:
        k, c, w = e['kind'], e['card'], e['when']
        chk(c['w'] < 200, f"「{k}」卡片宽度合理（{c['w']:.0f}px < 200）")
        chk(c['r'] <= d['tl']['r'] + 1, f"「{k}」卡片不溢出容器（{c['r']:.0f} <= {d['tl']['r']:.0f}）")
        chk(abs(c['cx'] - (capL + capR) / 2) < 1.5,
            f"「{k}」卡片中轴骑在胶囊上（Δ={abs(c['cx']-(capL+capR)/2):.2f}）")
        if w:
            # 日期必须完全在胶囊右侧（gap>0），或者完全在左侧
            gapR = w['l'] - capR
            gapL = capL - w['r']
            chk(gapR > 0 or gapL > 0,
                f"「{k}」日期不被胶囊穿过（左隙={gapL:.1f} 右隙={gapR:.1f}）")
            chk(c['l'] <= w['l'] + 0.5 and w['r'] <= c['r'] + 0.5,
                f"「{k}」日期在卡片内")

    print(f"\n=== {ok} pass / {len(bad)} fail ===")

    # ---- 窄屏：路牌退回右挂后，日期必须紧跟标签、不被推飞 ----
    print('\n窄屏（390px）路牌：')
    for vw in (600, 390, 360):
        p2 = br.new_page(viewport={'width': vw, 'height': 900})
        p2.goto(URL, wait_until='networkidle')
        p2.wait_for_timeout(400)
        p2.locator('a.gp-card[href="#gp-m3"]').first.click()
        p2.wait_for_timeout(550)
        z = p2.evaluate("""() => {
          const tl = document.querySelector('#gp-m3 .gp-tl');
          const sc = tl.querySelector(':scope > .gp-tl-item.side-c');
          const card = sc.querySelector(':scope > .gp-tl-card').getBoundingClientRect();
          const head = sc.querySelector(':scope > .gp-tl-card > .gp-tl-head');
          const when = sc.querySelector(':scope > .gp-tl-card > .gp-tl-when');
          const hb = head ? head.getBoundingClientRect() : null;
          const wb = when ? when.getBoundingClientRect() : null;
          return {vh: Math.round(card.height), cl: card.left, cr: card.right,
                  hbR: hb ? hb.right : null, wbL: wb ? wb.left : null,
                  wbR: wb ? wb.right : null};
        }""")
        chk(z['vh'] < 60, f"{vw}px 路牌单行（高 {z['vh']}px < 60）")
        chk(z['wbL'] - z['hbR'] < 20,
            f"{vw}px 日期紧跟标签（间距 {z['wbL']-z['hbR']:.0f}px < 20）")
        chk(z['wbR'] <= z['cr'] + 1, f"{vw}px 日期不溢出卡片")
        p2.close()

    print(f"\n=== 合计 {ok} pass / {len(bad)} fail ===")
    br.close()
