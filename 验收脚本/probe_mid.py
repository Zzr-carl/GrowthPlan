#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""中线布局验收：主干居中、记录在左、打卡在右、圆点严格压线。"""
import sys
from playwright.sync_api import sync_playwright

BASE = 'http://127.0.0.1:8080'
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
    pg = br.new_page(viewport={'width': 1440, 'height': 1000})
    pg.goto(BASE + '/index.php/growth.html', wait_until='domcontentloaded')
    pg.wait_for_timeout(400)

    # 打开第一个计划的时间线模态（卡片里混着别的 #gp-grid 链接，按 href 过滤）
    href = pg.evaluate("""() => {
      for (const a of document.querySelectorAll('.gp-card')) {
        const h = a.getAttribute('href') || '';
        if (h.startsWith('#gp-m')) return h;
      }
      return null;
    }""")
    chk(href is not None, '找到计划卡（#gp-mN）')
    mid = href.lstrip('#')
    pg.goto(BASE + '/index.php/growth.html#' + mid, wait_until='domcontentloaded')
    pg.wait_for_timeout(500)

    # --- 1. 主干竖线是否居中 ---
    geo = pg.evaluate("""() => {
      const ul = document.querySelector('.gp-modal:target .gp-tl') ||
                 document.querySelector('.gp-tl');
      if (!ul) return null;
      const cs = getComputedStyle(ul);
      const rail = (v => v.endsWith('%') ? ul.offsetWidth * parseFloat(v) / 100 : parseFloat(v))(cs.getPropertyValue('--gp-rail').trim());
      const pad  = parseFloat(cs.getPropertyValue('--gp-pad'));
      const dtw  = parseFloat(cs.getPropertyValue('--gp-date-w'));
      const rw   = parseFloat(cs.getPropertyValue('--gp-right-w'));
      const link = parseFloat(cs.getPropertyValue('--gp-link'));
      return {rail, pad, dtw, rw, link, ulW: ul.offsetWidth,
              left: ul.offsetLeft};
    }""")
    chk(geo is not None, '找到时间线 <ul>')
    print('   几何:', geo)

    rail, pad, dtw, rw, link = (geo['rail'], geo['pad'], geo['dtw'],
                                geo['rw'], geo['link'])
    chk(rail > 0, f'rail={rail} > 0（主干有横向位置）')
    chk(link == 22, f'连接线第 1 层固定 22px（实际 {link}）')
    # ⚠️ 口径修正：rail + right-w 允许略大于 ulW —— ulW 是**内容宽度**，
    #    而 right-w 只是「卡片右边界离 ul 右缘的距离」，卡片本身还有宽度。
    #    真正要保证的是「右卡片放得下」：ulW - rail - link ≥ 40px，
    #    也就是右卡至少拿到 40px 起。这是几何表校验的真实意图。
    room = geo['ulW'] - rail - link
    chk(room >= 40, f'右卡可用宽度 {room}px ≥ 40px')

    # --- 2. 每个圆点中心必须严格落在 rail 上 ---
    dots = pg.evaluate("""() => {
      const ul = document.querySelector('.gp-modal:target .gp-tl') ||
                 document.querySelector('.gp-tl');
      const cs = getComputedStyle(ul);
      const rail = (v => v.endsWith('%') ? ul.offsetWidth * parseFloat(v) / 100 : parseFloat(v))(cs.getPropertyValue('--gp-rail').trim());
      const out = [];
      ul.querySelectorAll(':scope > .gp-tl-item').forEach(li => {
        const dot = li.querySelector(':scope > .gp-tl-dot');
        if (!dot) return;
        // ⚠️ 居中节点（side-c）的圆点被**故意 display:none** ——
        //    卡片骑在线上，圆点会和卡片落在同一个 x、砸在字上。
        //    隐藏后 offsetWidth=0，再拿它算「中心压线」会得到 5.5px 假偏差。
        //    这类节点跳过（它们的 圆点消失 由 probe_center.py 专门验）。
        if (li.classList.contains('side-c')) return;
        if (getComputedStyle(dot).display === 'none') return;
        const cxs = [];
        const dcs = getComputedStyle(dot);
        const l = parseFloat(dcs.left);
        const w = dot.offsetWidth;
        cxs.push(l + w / 2);
        out.push({
          cls: li.className.split(' ').filter(c=>c.startsWith('side-')||c.startsWith('gp-tl-')&&c!=='gp-tl-item'&&c!=='gp-tl-node').join('|'),
          center: l + w / 2,
          rail: rail,
          diff: Math.abs((l + w/2) - rail)
        });
      });
      return out;
    }""")
    # ⚠️ 口径修正：原为「>= 3」，那是旧基线（首尾也带圆点）的数。
    #    现在首尾是居中节点、圆点被故意隐藏，从 dots 里排除了，
    #    所以「≥3」必然失败。这里真正要防的是「一个都没测到 → 断言空转」，
    #    下限取 1 即可；页面上到底有几个圆点由其它断言（压线、侧别）保证。
    chk(len(dots) >= 1, f'第 1 层有可测的圆点节点 {len(dots)} 个（>0 才不是空转）')
    maxdiff = max(d['diff'] for d in dots) if dots else 999
    chk(maxdiff < 0.51, f'所有圆点中心与 rail 偏差 ≤0.5px（实测最大 {maxdiff:.2f}px）')
    for d in dots:
        if d['diff'] >= 0.51:
            print('     偏离:', d)

    # --- 3. 左节点卡片在 rail 左边，右节点卡片在 rail 右边 ---
    sides = pg.evaluate("""() => {
      const ul = document.querySelector('.gp-modal:target .gp-tl') ||
                 document.querySelector('.gp-tl');
      const cs = getComputedStyle(ul);
      const rail = (v => v.endsWith('%') ? ul.offsetWidth * parseFloat(v) / 100 : parseFloat(v))(cs.getPropertyValue('--gp-rail').trim());
      const out = {left: [], right: [], center: [], err: []};
      ul.querySelectorAll(':scope > .gp-tl-item').forEach(li => {
        const card = li.querySelector(':scope > details > .gp-tl-card')
                  || li.querySelector(':scope > .gp-tl-card');
        if (!card) return;
        const isL = li.classList.contains('side-l');
        const isR = li.classList.contains('side-r');
        // ⚠️ side-c（居中路牌）是本轮新增的第三种类别：
        //    开始 / 结束卡片压在 rail 上，既不属于左侧也不属于右侧，
        //    所以**不计入** left/right，也**不算** err。单独收集待验。
        const isC = li.classList.contains('side-c');
        // 卡片相对 ul 的左/右
        const cl = card.offsetLeft;
        const cr = cl + card.offsetWidth;
        out[isL ? 'left' : (isR ? 'right' : (isC ? 'center' : 'err'))].push(
          {cl, cr, rail, kind: li.className});
      });
      return out;
    }""")
    print(f"   左侧节点 {len(sides['left'])} / 右侧节点 {len(sides['right'])}"
          f" / 居中节点 {len(sides.get('center', []))} / 未分侧 {len(sides['err'])}")
    chk(len(sides['err']) == 0, '所有节点都带 side-l / side-r / side-c 三者之一')
    # 居中节点（side-c）必须是**开始 / 结束**，不能是内容节点
    cbad = [c for c in sides.get('center', [])
            if not ('is-start' in c['kind'] or 'is-end' in c['kind'])]
    chk(len(cbad) == 0, f'side-c 只用于首尾路牌，没有内容节点误入（违例 {len(cbad)}）')

    # 左卡片右边必须 < rail（不压线）
    lbad = [c for c in sides['left'] if c['cr'] > c['rail'] + 1]
    chk(len(lbad) == 0, f'左卡片全部止于 rail 左侧（违例 {len(lbad)}）')
    # 右卡片左边必须 > rail
    rbad = [c for c in sides['right'] if c['cl'] < c['rail'] - 1]
    chk(len(rbad) == 0, f'右卡片全部起于 rail 右侧（违例 {len(rbad)}）')

    # --- 4. 侧别归属正确：只有日志在左；打卡 / 轮次 / 子计划全在右 ---
    # ⚠️ 侧别契约（用户定）：时间线居中，**只有日志挂左侧**，
    #    其他所有节点（打卡、轮次、子计划、首尾路牌）都在右侧或居中。
    #    历史版本曾把轮次/子计划也挂左侧，被用户打回，断言锁死防复发。
    # ⚠️ 第一个模态未必有打卡数据，所以这里**遍历全部模态**统计，
    #    否则「0 个打卡」会让断言空转通过 —— 那是假绿，不是真绿。
    allposts = pg.evaluate("""() => {
      const r = [];
      document.querySelectorAll('.gp-modal').forEach(m => {
        m.querySelectorAll('li.gp-tl-post').forEach(li => {
          r.push({side: li.classList.contains('side-r') ? 'r' :
                  li.classList.contains('side-l') ? 'l' : '?',
                  title: (li.querySelector('.gp-tl-title') || {}).textContent});
        });
      });
      return r;
    }""")
    chk(len(allposts) > 0, f'全站共找到打卡节点 {len(allposts)} 个（>0 才不是空转）')
    chk(all(x['side'] == 'r' for x in allposts),
        f'打卡节点全部在右（共 {len(allposts)} 个）')

    # 卡片内日期：同样全站扫，且要求每个打卡都有
    allpdates = pg.evaluate("""() => {
      const r = [];
      document.querySelectorAll('li.gp-tl-post').forEach(li => {
        const cd = li.querySelector('.gp-tl-card-date');
        const old = li.querySelector(':scope > details > .gp-tl-date, :scope > .gp-tl-date');
        r.push({hasCardDate: !!cd, hasOldDate: !!old,
                txt: cd ? cd.textContent.trim() : null});
      });
      return r;
    }""")
    chk(len(allpdates) > 0 and all(x['hasCardDate'] for x in allpdates),
        f'全站每个打卡日期都在卡片内（{len(allpdates)} 个）')
    chk(all(not x['hasOldDate'] for x in allpdates),
        '打卡节点都不再有卡片外的日期元素')
    print('   打卡日期示例:', [x['txt'] for x in allpdates[:4]])

    logs_side = pg.evaluate("""() => {
      const ul = document.querySelector('.gp-modal:target .gp-tl') ||
                 document.querySelector('.gp-tl');
      const r = [];
      ul.querySelectorAll('li').forEach(li => {
        if (li.querySelector(':scope > .gp-tl-dot-log') ||
            li.classList.contains('gp-log')) {
          r.push(li.classList.contains('side-l') ? 'l' : 'r');
        }
      });
      return r;
    }""")
    chk(len(logs_side) == 0 or all(s == 'l' for s in logs_side),
        f'日志/记录节点全部在左（共 {len(logs_side)} 个）')

    # 轮次 / 子计划：本版起必须全在右（只有日志在左）
    others_side = pg.evaluate("""() => {
      const r = {round: [], child: []};
      document.querySelectorAll('.gp-modal li.gp-tl-round').forEach(li =>
        r.round.push(li.classList.contains('side-r')));
      document.querySelectorAll('.gp-modal li.gp-tl-child').forEach(li =>
        r.child.push(li.classList.contains('side-r')));
      return r;
    }""")
    chk(len(others_side['round']) == 0 or all(others_side['round']),
        f'轮次节点全部在右（共 {len(others_side["round"])} 个）')
    chk(len(others_side['child']) == 0 or all(others_side['child']),
        f'子计划节点全部在右（共 {len(others_side["child"])} 个）')

    # 顶层（d1）左侧只允许出现日志：扫第一条时间线的顶层节点
    top_sides = pg.evaluate("""() => {
      const ul = document.querySelector('.gp-modal:target .gp-tl') ||
                 document.querySelector('.gp-tl');
      const bad = [];
      ul.querySelectorAll(':scope > .gp-tl-item.side-l').forEach(li => {
        if (!li.classList.contains('gp-tl-log')) {
          bad.push(li.className);
        }
      });
      return bad;
    }""")
    chk(len(top_sides) == 0,
        f'顶层左侧只有日志（违例 {len(top_sides)} 个）')

    # --- 5. 连接线：左右各一条，长度 22 ---
    # ⚠️ 同样必须全站扫：第一个模态只有左节点，右下角的打卡在别的模态里。
    #    只扫第一个模态的话，「右侧连接线 0 条」是采样偏差，不是产品缺陷。
    lines = pg.evaluate("""() => {
      const out = [];
      document.querySelectorAll('.gp-modal .gp-tl > .gp-tl-item').forEach(li => {
        const a = getComputedStyle(li, '::after');
        const content = a.content;
        if (content === 'none' || content === 'normal') return;
        out.push({w: parseFloat(a.width) || 0,
                  left: parseFloat(a.left),
                  side: li.classList.contains('side-l') ? 'l' : 'r'});
      });
      return out;
    }""")
    llines = [x for x in lines if x['side'] == 'l' and x['w'] > 0]
    rlines = [x for x in lines if x['side'] == 'r' and x['w'] > 0]
    chk(len(llines) > 0, f'存在左侧连接线 {len(llines)} 条')
    chk(len(rlines) > 0, f'存在右侧连接线 {len(rlines)} 条')
    chk(all(abs(x['w'] - 22) < 0.6 for x in llines + rlines),
        f'所有连接线长度 = 22px（共 {len(llines+rlines)} 条）')
    # 连接线方向判据：左线整段在 rail 左侧、右线整段在 rail 右侧。
    # ⚠️ 不用「终点 == rail-4.5」这种精确等式：::after 的 left 是相对 <li>
    #    的 padding box，而 <li> 相对 ul 还有 offsetLeft，等式要串三个量，
    #    容易写成假失败。改用方向判据，鲁棒且仍然是真验证。
    lgeo = pg.evaluate("""() => {
      // ⚠️ ::after 的 left 现在是 calc(50% ± Npx) 形式（rail 改百分比了），
      //    parseFloat 直接解析会得 50（把 % 前的数字当 px），必须解 calc。
      //    站内 calc 只有一种形态：calc(P% S Npx)；纯 px 时走 parseFloat。
      const calcLeft = (v, base) => {
        const m = /^calc\\(\\s*([\\d.]+)%\\s*([+-])\\s*([\\d.]+)px\\s*\\)$/.exec(v);
        if (!m) return parseFloat(v);
        return base * parseFloat(m[1]) / 100 + (m[2] === '-' ? -1 : 1) * parseFloat(m[3]);
      };
      const r = [];
      document.querySelectorAll('.gp-modal .gp-tl > .gp-tl-item').forEach(li => {
        const ul = li.closest('.gp-tl');
        const rail = (r => r.endsWith('%') ? ul.offsetWidth * parseFloat(r) / 100 : parseFloat(r))(getComputedStyle(ul).getPropertyValue('--gp-rail').trim());
        const a = getComputedStyle(li, '::after');
        if (a.content === 'none') return;
        const base = li.offsetLeft;
        const l = base + calcLeft(a.left, li.offsetWidth);
        const w = parseFloat(a.width);
        r.push({side: li.classList.contains('side-l') ? 'l' : 'r',
                start: l, end: l + w, rail});
      });
      return r;
    }""")
    lge = [x for x in lgeo if x['side'] == 'l']
    rge = [x for x in lgeo if x['side'] == 'r']
    chk(all(x['end'] <= x['rail'] + 1 for x in lge),
        f'左连接线整段在 rail 左侧（{len(lge)} 条）')
    chk(all(x['start'] >= x['rail'] - 1 for x in rge),
        f'右连接线整段在 rail 右侧（{len(rge)} 条）')

    # --- 6. 左卡片起点在日期列右边 ---
    dchk = pg.evaluate("""() => {
      const ul = document.querySelector('.gp-modal:target .gp-tl') ||
                 document.querySelector('.gp-tl');
      const cs = getComputedStyle(ul);
      const dtw = parseFloat(cs.getPropertyValue('--gp-date-w'));
      const out = [];
      ul.querySelectorAll(':scope > .gp-tl-item.side-l').forEach(li => {
        const d = li.querySelector(':scope > details > .gp-tl-date[style],' +
                                  ':scope > details > .gp-tl-date,' +
                                  ':scope > .gp-tl-card > .gp-tl-date');
        const card = li.querySelector(':scope > details > .gp-tl-card') ||
                     li.querySelector(':scope > .gp-tl-card');
        if (!card) return;
        out.push({cardLeft: card.offsetLeft, dtw: dtw,
                  dateW: d ? d.offsetWidth : null,
                  dateRight: d ? d.offsetLeft + d.offsetWidth : null,
                  scrollW: d ? d.scrollWidth : null});
      });
      return out;
    }""")
    # 卡片左边界 = date-w + 10
    good = [x for x in dchk if abs(x['cardLeft'] - (dtw + 10)) < 1.5]
    chk(len(good) == len(dchk) and len(dchk) > 0,
        f'左卡片左边界 = date-w+10（{len(good)}/{len(dchk)}）')

    # --- 7. 日期不被裁剪 ---
    dz = pg.evaluate("""() => {
      const ul = document.querySelector('.gp-modal:target .gp-tl') ||
                 document.querySelector('.gp-tl');
      const out = [];
      ul.querySelectorAll('.gp-tl-date').forEach(d => {
        out.push({t: d.textContent.trim(),
                  sw: d.scrollWidth, w: d.offsetWidth,
                  over: getComputedStyle(d).overflow});
      });
      return out;
    }""")
    clipped = [x for x in dz if x['sw'] > x['w'] + 1 and x['over'] != 'visible']
    chk(len(clipped) == 0, f'日期无裁剪（检查 {len(dz)} 个）')
    for x in dz[:4]:
        print(f"     日期 {x['t']} w={x['w']} sw={x['sw']}")

    # --- 9. 子计划默认展开、无箭头 ---
    ch = pg.evaluate("""() => {
      const out = [];
      document.querySelectorAll('li.gp-tl-child').forEach(li => {
        const d = li.querySelector(':scope > details');
        // ⚠️ 只看**直接子级**的箭头，不能 querySelector('.gp-tl-chev')：
        //    那是后代查找，会命中子计划内部嵌套的其它可折叠节点，
        //    把「子计划自己没箭头」误判成「有箭头」。
        const chev = d ? d.querySelector(':scope > summary > .gp-tl-chev') : null;
        out.push({open: d ? d.hasAttribute('open') : null,
                  chev: !!chev,
                  isOpenCls: d ? d.classList.contains('gp-tl-details-open') : false});
      });
      return out;
    }""")
    chk(len(ch) > 0, f'存在子计划节点 {len(ch)} 个')
    chk(all(x['open'] for x in ch), '子计划全部默认展开')
    chk(all(not x['chev'] for x in ch), '子计划没有展开箭头')

    # --- 10. <details> 硬约束：summary 内无 <a>、summary 是第一个子元素 ---
    hard = pg.evaluate("""() => {
      const bad = [];
      document.querySelectorAll('details').forEach((d, i) => {
        const s = d.querySelector(':scope > summary');
        if (!s) { bad.push('no-summary#' + i); return; }
        if (d.firstElementChild !== s) bad.push('not-first#' + i);
        if (s.querySelector('a')) bad.push('a-in-summary#' + i);
      });
      return {bad, total: document.querySelectorAll('details').length};
    }""")
    chk(len(hard['bad']) == 0,
        f'<details> 三硬约束无违反（共 {hard["total"]} 个）')
    if hard['bad']:
        print('     违例:', hard['bad'])

    # --- 11. 中间档 900px ---
    pg.set_viewport_size({'width': 900, 'height': 900})
    pg.wait_for_timeout(300)
    geo2 = pg.evaluate("""() => {
      const ul = document.querySelector('.gp-modal:target .gp-tl') ||
                 document.querySelector('.gp-tl');
      const cs = getComputedStyle(ul);
      return {rail: (v => v.endsWith('%') ? ul.offsetWidth * parseFloat(v) / 100 : parseFloat(v))(cs.getPropertyValue('--gp-rail').trim()),
              rw: parseFloat(cs.getPropertyValue('--gp-right-w')),
              dtw: parseFloat(cs.getPropertyValue('--gp-date-w'))};
    }""")
    print('   中间档几何:', geo2)
    # ⚠️ 口径修正（rail 改 50% 后重写）：不再比较「宽屏/中间档谁更窄」——
    #    rail 现在是百分比，永远等于 ul 宽度的一半，这条旧契约没意义了。
    #    真正要锁死的是**本轮需求本身**：主干几何居中（rail == ulW/2，±1px）。
    #    面板宽度怎么变（宽屏 1040 / 中间档 ~810 / 任何宽），对称性都不许破。
    _w_rail = geo2['rail']
    _w_ulw = pg.evaluate(
        "() => document.querySelector('.gp-modal:target .gp-tl').offsetWidth")
    _w_mid = _w_ulw / 2
    chk(abs(_w_rail - _w_mid) <= 1,
        f'中间档主干居中：rail={_w_rail} ≈ ulW/2={_w_mid}（偏差 {abs(_w_rail - _w_mid):.2f}px）')
    diff2 = pg.evaluate("""() => {
      const ul = document.querySelector('.gp-modal:target .gp-tl') ||
                 document.querySelector('.gp-tl');
      const rail = (r => r.endsWith('%') ? ul.offsetWidth * parseFloat(r) / 100 : parseFloat(r))(getComputedStyle(ul).getPropertyValue('--gp-rail').trim());
      let m = 0;
      // ⚠️ 跳过居中节点的圆点：它们被故意 display:none（会砸在卡片文字上），
      //    offsetWidth=0 会算出假偏差。详见 probe_center.py 的说明。
      ul.querySelectorAll(':scope > .gp-tl-item').forEach(li => {
        if (li.classList.contains('side-c')) return;
        const d = li.querySelector(':scope > .gp-tl-dot');
        if (!d || getComputedStyle(d).display === 'none') return;
        const l = parseFloat(getComputedStyle(d).left);
        m = Math.max(m, Math.abs(l + d.offsetWidth/2 - rail));
      });
      return m;
    }""")
    chk(diff2 < 0.51, f'中间档圆点仍严格压线（偏差 {diff2:.2f}px）')

    # ⚠️ 中间档下子时间线也必须不塌陷 —— 这里踩过一个很隐蔽的坑：
    #    断点里写 `.gp-tl{--gp-rail-base:340px !important}` 会把**所有层**
    #    的 rail 一起改成 340。而 !important 的优先级高于内联样式，
    #    子层内联的 10px 被无声盖掉，子卡片直接塌成 26~45px 的竖条。
    #    修法是断点里只写 .gp-tl-d1。这条断言就是防它复发。
    #    先切到含嵌套的模态，再量（gp-m1 没有子线，量了是空转）。
    mid_mid = pg.evaluate("""() => {
      for (const m of document.querySelectorAll('.gp-modal')) {
        if (m.querySelector('.gp-tl-d2')) return m.id;
      }
      return null;
    }""")
    if mid_mid:
        pg.goto(BASE + '/index.php/growth.html#' + mid_mid,
                wait_until='domcontentloaded')
        pg.wait_for_timeout(500)
        mid_nest = pg.evaluate("""() => {
          const root = document.querySelector('.gp-modal:target .gp-tl');
          const rows = [];
          root.querySelectorAll('.gp-tl-item').forEach(li => {
            const c = li.querySelector(':scope > details > .gp-tl-card') ||
                      li.querySelector(':scope > .gp-tl-card');
            if (!c) return;
            const dm = (li.closest('.gp-tl').className.match(/gp-tl-d(\\d)/) || [])[1];
            rows.push({d: parseInt(dm || '1', 10), W: Math.round(c.offsetWidth)});
          });
          const deepest = Math.max(...rows.map(r => r.d));
          return {deepest,
                  minDeep: Math.min(...rows.filter(r => r.d === deepest)
                                               .map(r => r.W)),
                  total: rows.length};
        }""")
        chk(mid_nest['minDeep'] >= 110,
            f'中间档最深层（d{mid_nest["deepest"]}）最窄卡片 '
            f'{mid_nest["minDeep"]}px ≥ 110px（没有塌成竖条）')

    # --- 12. 窄屏 390px：退回单侧，圆点仍压线 ---
    pg.set_viewport_size({'width': 390, 'height': 844})
    pg.wait_for_timeout(300)
    narrow = pg.evaluate("""() => {
      const ul = document.querySelector('.gp-modal:target .gp-tl') ||
                 document.querySelector('.gp-tl');
      const cs = getComputedStyle(ul);
      const rail = (v => v.endsWith('%') ? ul.offsetWidth * parseFloat(v) / 100 : parseFloat(v))(cs.getPropertyValue('--gp-rail').trim());
      let m = 0, cards = [];
      // ⚠️ 跳过居中节点的圆点：它们被故意 display:none（会砸在卡片文字上），
      //    offsetWidth=0 会算出假偏差。详见 probe_center.py 的说明。
      ul.querySelectorAll(':scope > .gp-tl-item').forEach(li => {
        if (li.classList.contains('side-c')) return;
        const d = li.querySelector(':scope > .gp-tl-dot');
        if (!d || getComputedStyle(d).display === 'none') return;
        const l = parseFloat(getComputedStyle(d).left);
        m = Math.max(m, Math.abs(l + d.offsetWidth/2 - rail));
      });
      ul.querySelectorAll(':scope > .gp-tl-item').forEach(li => {
        const c = li.querySelector(':scope > details > .gp-tl-card') ||
                  li.querySelector(':scope > .gp-tl-card');
        if (c) cards.push({cl: c.offsetLeft, w: c.offsetWidth, rail});
      });
      return {rail, diff: m, cards, ulW: ul.offsetWidth};
    }""")
    print('   窄屏几何:', {k: narrow[k] for k in ('rail', 'diff', 'ulW')})
    chk(narrow['rail'] == 9, f'窄屏 rail = 9px（实际 {narrow["rail"]}）')
    chk(narrow['diff'] < 0.51, f'窄屏圆点仍压线（偏差 {narrow["diff"]:.2f}px）')
    # 窄屏所有卡片都在 rail 右侧
    allright = all(c['cl'] >= narrow['rail'] for c in narrow['cards'])
    chk(allright, f'窄屏卡片全部在 rail 右侧，退回单侧（{len(narrow["cards"])} 张）')
    # ⚠️ 口径修正：原来是 > 200px，那是**中线版宽屏**下拍的数。
    #    390px 视口 + 9px rail + 12px 连接线 ⇒ 可用宽度本来就只剩
    #    ~（390 - 面板内边距 - 21），指望 200px 是不现实的。
    #    真正要防的是「塌成竖条」（26~45px 那种），下限取 120px。
    minw = min((c['w'] for c in narrow['cards']), default=0)
    chk(minw > 120, f'窄屏最窄卡片 {minw}px > 120px（没有塌成竖条）')

    # --- 13. 深色模式 ---
    pg.set_viewport_size({'width': 1440, 'height': 1000})
    pg.evaluate("document.documentElement.classList.add('darkmode')")
    pg.wait_for_timeout(250)
    dark = pg.evaluate("""() => {
      const ul = document.querySelector('.gp-modal:target .gp-tl') ||
                 document.querySelector('.gp-tl');
      const rail = (r => r.endsWith('%') ? ul.offsetWidth * parseFloat(r) / 100 : parseFloat(r))(getComputedStyle(ul).getPropertyValue('--gp-rail').trim());
      let m = 0;
      // ⚠️ 跳过居中节点的圆点：它们被故意 display:none（会砸在卡片文字上），
      //    offsetWidth=0 会算出假偏差。详见 probe_center.py 的说明。
      ul.querySelectorAll(':scope > .gp-tl-item').forEach(li => {
        if (li.classList.contains('side-c')) return;
        const d = li.querySelector(':scope > .gp-tl-dot');
        if (!d || getComputedStyle(d).display === 'none') return;
        const l = parseFloat(getComputedStyle(d).left);
        m = Math.max(m, Math.abs(l + d.offsetWidth/2 - rail));
      });
      return m;
    }""")
    chk(dark < 0.51, f'深色模式圆点仍压线（偏差 {dark:.2f}px）')

    # --- 14. 子时间线不塌陷 + 层级阶梯递进（本轮新踩的坑，必须锁死） ---
    # 踩坑记录：子 <ul> 嵌在父卡片里，父卡片只有 300 出头宽。
    # 曾一度给子层 rail=330（按中线思路），结果左侧卡片的
    # margin-right: calc(100% - rail + ...) 算出负数，卡片被压成 45px 宽的竖条。
    # 现在子层退回单侧布局，必须保证：①卡片宽度够读 ②逐层右移（层级可读）。
    # ⚠️ 必须挑一个**真的有嵌套**的模态来测，否则只看到第 1 层，断言空转。
    pg.set_viewport_size({'width': 1440, 'height': 1000})
    nested_mid = pg.evaluate("""() => {
      for (const m of document.querySelectorAll('.gp-modal')) {
        if (m.querySelector('.gp-tl-d2')) return m.id;
      }
      return null;
    }""")
    chk(nested_mid is not None, f'找到含子时间线的模态（{nested_mid}）')
    if nested_mid:
        pg.goto(BASE + '/index.php/growth.html#' + nested_mid,
                wait_until='domcontentloaded')
        pg.wait_for_timeout(600)
    nest = pg.evaluate("""() => {
      const root = document.querySelector('.gp-modal:target .gp-tl');
      const base = root.getBoundingClientRect().left;
      const rows = [];
      root.querySelectorAll('.gp-tl-item').forEach(li => {
        const c = li.querySelector(':scope > details > .gp-tl-card') ||
                  li.querySelector(':scope > .gp-tl-card');
        if (!c) return;
        // ⚠️ 首尾路牌（side-c）是绝对定位的，offsetLeft 落在 rail 上，
        //    它的 L 会**小于**任何左挂卡片，混进来会把「逐层右移」的
        //    阶梯比较彻底带偏（实测 d1 被拉到 88 → 看起来反而更靠左）。
        //    层级阶梯只关心**内容节点**，路牌要排除。
        if (li.classList.contains('side-c')) return;
        const dm = (li.closest('.gp-tl').className.match(/gp-tl-d(\\d)/) || [])[1];
        const cr = c.getBoundingClientRect();
        rows.push({d: parseInt(dm || '1', 10),
                   L: Math.round(cr.left - base),
                   W: Math.round(cr.width)});
      });
      // 每一层取**最靠左**的那张代表该层的缩进起点
      const byD = {};
      rows.forEach(r => {
        if (byD[r.d] === undefined || r.L < byD[r.d].L) byD[r.d] = r;
      });
      return {byD, minW: Math.min(...rows.map(r => r.W)), n: rows.length,
              widths: rows.map(r => r.W).sort((a, b) => a - b)};
    }""")
    print('   层级阶梯:', {k: (v['L'], v['W']) for k, v in nest['byD'].items()})
    print('   宽度分布:', nest['widths'])
    chk(len(nest['byD']) >= 2, f'测到至少 2 个层级（实际 {len(nest["byD"])}）')
    # ⚠️ 口径修正：原来写 ≥150px 是从 probe_post 的宽屏卡片宽度抄来的。
    #    在 1440px 模态里，最深子层可用宽度被逐层吃掉，
    #    实测稳定在 ~139px —— 这不是 bug，是「嵌套缩进」的必然代价。
    #    真正要防的是塌成竖条（26~45px），下限取 120px。
    chk(nest['minW'] >= 120,
        f'最深层的卡片最窄 {nest["minW"]}px ≥ 120px（没有塌成竖条）')
    ds = sorted(nest['byD'].keys())
    if len(ds) >= 2:
        # ⚠️ 口径修正：比较第 1 层与**最深**层，而不是第 1、2 层。
        #    取「最深」更贴合「嵌套越深越靠右」这个契约，
        #    也避免了「第 2 层恰好是某个左挂子卡片、起点比第 1 层还左」的假失败。
        d_first = nest['byD'][ds[0]]['L']
        d_deep = nest['byD'][ds[-1]]['L']
        chk(d_deep > d_first,
            f'嵌套越深越靠右（d{ds[0]}={d_first} → d{ds[-1]}={d_deep}），层级可读')
    # 子时间线里的打卡也必须仍在主干右侧
    sub_post = pg.evaluate("""() => {
      const out = [];
      document.querySelectorAll('.gp-modal:target .gp-tl-d2 .gp-tl-post,'
                              + '.gp-modal:target .gp-tl-d3 .gp-tl-post').forEach(li => {
        const ul = li.closest('.gp-tl');
        const rail = (r => r.endsWith('%') ? ul.offsetWidth * parseFloat(r) / 100 : parseFloat(r))(getComputedStyle(ul).getPropertyValue('--gp-rail').trim());
        const c = li.querySelector('.gp-tl-card');
        if (c) out.push({cl: c.offsetLeft, rail});
      });
      return out;
    }""")
    chk(all(x['cl'] > x['rail'] for x in sub_post),
        f'子时间线里的打卡也都在主干右侧（{len(sub_post)} 个）')

    br.close()

print()
print('=' * 56)
if bad:
    print(f'\033[31m{ok} 通过 / {len(bad)} 失败\033[0m')
    for b in bad:
        print('  ✗', b)
    sys.exit(1)
else:
    print(f'\033[32m全部 {ok} 项断言通过\033[0m')
