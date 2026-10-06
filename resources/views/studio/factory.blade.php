<x-app-layout>
<x-workspace-layout title="智能创作工厂">
  <style>
    .fc-item { display:flex; align-items:center; gap:10px; width:100%; box-sizing:border-box;
      padding:9px 12px; border-radius:10px; font-size:14px; line-height:1.3; color:#cbd5e1;
      text-decoration:none; cursor:pointer; transition:background .15s,color .15s; }
    .fc-item:hover { background:#1e293b; color:#fff; }
    .fc-item.fc-active { background:#4f46e5; color:#fff; font-weight:600; }
    .fc-cat { padding:14px 12px 6px; font-size:11px; letter-spacing:.12em; color:#64748b; }
    .fc-card { display:block; background:#fff; border:1px solid #e2e8f0; border-radius:14px;
      padding:16px; text-decoration:none; transition:border-color .15s, box-shadow .15s, transform .15s; }
    .fc-card:hover { border-color:#a5b4fc; box-shadow:0 8px 20px rgba(79,70,229,.10); transform:translateY(-2px); }
    #fcFrame { background:#fff; }
    @media (max-width:900px) {
      .fc-shell { flex-direction:column !important; }
      .fc-menu { width:100% !important; flex:none !important; position:static !important; max-height:none !important; }
      #fcFrame { min-height:70vh; }
    }
  </style>

  <div class="fc-shell" style="display:flex; flex:1; min-height:0; gap:18px; align-items:stretch; max-width:1480px; margin:0 auto; padding:16px;">

    {{-- ═══ 左侧：深色竖排菜单（能力自动从插件生成） ═══ --}}
    <aside class="fc-menu" style="flex:0 0 240px; width:240px; background:#0f172a; border-radius:16px; padding:14px 10px 12px; position:relative; top:0; max-height:100%; overflow-y:auto;">
      <div style="display:flex; align-items:center; gap:8px; padding:4px 12px 12px; border-bottom:1px solid #1e293b;">
        <span style="font-size:18px;">🏭</span>
        <span style="font-size:15px; font-weight:700; color:#fff;">智能创作工厂</span>
      </div>

      <nav id="fcNav" style="margin-top:6px;">
        <a class="fc-item fc-active" data-panel="overview" href="javascript:void(0)">
          <span style="font-size:16px;">🏠</span><span>总览</span>
        </a>
        {{-- 能力菜单：JS 按 cat 分区自动生成 --}}
      </nav>

      <div style="border-top:1px solid #1e293b; margin-top:10px; padding-top:4px;">
        <div class="fc-cat">资 产 与 管 理</div>
        <nav id="fcMgmt">
          <a class="fc-item" href="javascript:void(0)" data-page="/studio/accounts"><span style="font-size:16px;">👤</span><span>发布账号</span></a>
          <a class="fc-item" href="javascript:void(0)" data-page="/studio/voices"><span style="font-size:16px;">🔊</span><span>声音库</span></a>
          <a class="fc-item" href="javascript:void(0)" data-page="/studio/covers"><span style="font-size:16px;">🖼️</span><span>封面库</span></a>
          <a class="fc-item" href="javascript:void(0)" data-page="/studio/models"><span style="font-size:16px;">🧑‍💼</span><span>数字人模特</span></a>
          <a class="fc-item" href="javascript:void(0)" data-page="/studio/queue"><span style="font-size:16px;">📋</span><span>任务队列</span></a>
          <a class="fc-item" href="javascript:void(0)" data-page="/studio/publish"><span style="font-size:16px;">🚀</span><span>发布助手</span></a>
          <a class="fc-item" href="javascript:void(0)" data-page="/studio/schedule"><span style="font-size:16px;">📅</span><span>发布排期</span></a>
          <a class="fc-item" href="javascript:void(0)" data-page="/studio/metrics"><span style="font-size:16px;">📈</span><span>数据效果</span></a>
          <a class="fc-item" id="fc-review-item" href="javascript:void(0)" data-page="/studio/review"><span style="font-size:16px;">✅</span><span>人工审核</span><span id="fc-review-badge" style="margin-left:auto;"></span></a>
          <a class="fc-item" href="javascript:void(0)" data-page="/studio/help"><span style="font-size:16px;">❓</span><span>帮助中心</span></a>
        </nav>
        <div style="border-top:1px solid #1e293b; margin-top:8px; padding-top:6px;">
          <a class="fc-item" href="/studio/chat" data-full="1"><span style="font-size:16px;">💬</span><span>对话模式</span></a>
        </div>
      </div>
    </aside>

    {{-- ═══ 右侧：内容界面（iframe 内嵌，菜单不消失） ═══ --}}
    <main style="flex:1; min-width:0; display:flex; flex-direction:column; background:#fff; border-radius:16px; overflow:hidden; box-shadow:0 1px 3px rgba(15,23,42,.06);">

      {{-- 总览面板（默认） --}}
      <section id="fc-panel-overview" style="flex:1; min-height:0; overflow-y:auto; padding:22px 24px;">
        <div style="background:linear-gradient(135deg,#4f46e5,#7c3aed); border-radius:18px; padding:26px 28px; color:#fff;">
          <h2 style="margin:0; font-size:22px; font-weight:800;">今天想创作点什么？</h2>
          <p style="margin:8px 0 0; font-size:14px; color:rgba(255,255,255,.85);">选题 → 写稿 → 出片 → 质检 → 发布，从左侧菜单点一下就能干。每个功能都是即插即用的「插件」。</p>
          <div id="fcStats" style="display:flex; flex-wrap:wrap; gap:10px; margin-top:16px;"></div>
        </div>

        <h3 style="margin:26px 0 12px; font-size:16px; font-weight:700; color:#334155;">全部能力</h3>
        <div id="fcAllCards" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(230px,1fr)); gap:12px;">
          <div style="padding:32px; text-align:center; font-size:14px; color:#94a3b8;">正在加载创作能力…</div>
        </div>
      </section>

      <div id="fcFrameBar" style="display:none; align-items:center; gap:8px; padding:10px 16px; border-bottom:1px solid #e2e8f0; background:#f8fafc; font-size:14px; font-weight:600; color:#334155;">
        <span style="color:#6366f1;">📍</span><span id="fcFrameTitle"></span>
        <a href="#" id="fcOpenFull" style="margin-left:auto; font-size:12px; font-weight:500; color:#6366f1; text-decoration:none;">新窗口打开 ↗</a>
      </div>
      <iframe id="fcFrame" style="flex:1; min-height:0; width:100%; border:0; display:none;"></iframe>
    </main>
  </div>

  <script>
  (function () {
    // —— 商业文案映射（仅展示层，不改后端能力 id / 不碰 LLM 提示词）——
    var CAT_LABELS = {
      '选题': '选题策划', '写稿': '智能写稿', '出片': '视频生成',
      '质检': '合规质检', '发布': '发布运营', '审核': '人工审核', '素材': '素材中心'
    };
    var CAP_LABELS = {
      'topic': '爆款选题挖掘', 'strategist': '获客潜力评估', 'hotspot': '实时热点追踪',
      'dissect': '爆款结构拆解', 'rewrite': '口播稿二创', 'video_render': '一键成片',
      'qc': '文案合规检测', 'qc_video': '成片技术体检', 'publish_pack': '发布素材打包',
      'xhs': '小红书图文', 'review': '人工审核把关', 'footage_edit': '真人素材精剪',
      'clone_voice': '专属音色克隆'
    };
    var CAP_DESC = {
      'topic': '按行业与关键词，一次产出一批可拍选题',
      'strategist': '评估选题能不能带来付费客户，并给钩子建议',
      'hotspot': '抓取当下财税 / 行业热点，变成你的选题',
      'dissect': '丢一条爆款进来，拆出它为什么火',
      'rewrite': '把别人的稿改成你的口径，自动过违禁词',
      'video_render': '口播稿一键做成成品视频（配音+字幕+画面）',
      'qc': '发布前查违禁词、敏感表述、逻辑漏洞',
      'qc_video': '技术体检：黑屏、没声音、字幕压字',
      'publish_pack': '成片 + 封面 + 标题 + 文案，打包好直接发',
      'xhs': '同一内容改成小红书图文笔记，一鱼多吃',
      'review': '成片送你过一遍再发，审核页列出待审视频',
      'footage_edit': '对已有素材做精剪处理',
      'clone_voice': '录一段声音，以后配音都用你的音色'
    };

    var frame = document.getElementById('fcFrame');
    var overview = document.getElementById('fc-panel-overview');
    var nav = document.getElementById('fcNav');
    var allCards = document.getElementById('fcAllCards');
    var statsBox = document.getElementById('fcStats');
    var pendingReviewCount = 0;
    var capCount = 0, catCount = 0;

    function esc(s) {
      return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];
      });
    }
    function badgeHtml(n) {
      return '<span style="display:inline-flex;min-width:18px;align-items:center;justify-content:center;border-radius:9999px;background:#ef4444;padding:1px 6px;font-size:11px;font-weight:700;line-height:1.5;color:#fff;">' + n + '</span>';
    }

    // —— 菜单激活态 ——
    function setActive(el) {
      document.querySelectorAll('.fc-item').forEach(function (n) { n.classList.remove('fc-active'); });
      if (el && el.classList.contains('fc-item')) el.classList.add('fc-active');
    }
    function activateByPage(page) {
      var m = document.querySelector('.fc-item[data-page="' + page.replace(/"/g, '\\"') + '"]');
      if (m) setActive(m);
    }

    // —— 总览 / 工具内嵌切换 ——
    function showOverview() {
      overview.style.display = '';
      frame.style.display = 'none';
      if (frame.getAttribute('src')) frame.src = 'about:blank';
      document.getElementById('fcFrameBar').style.display = 'none';
      setActive(document.querySelector('.fc-item[data-panel="overview"]'));
      try { history.replaceState(null, '', location.pathname); } catch (e) {}
    }
    function loadTool(page, el) {
      if (! page) return;
      var sep = page.indexOf('?') >= 0 ? '&' : '?';
      overview.style.display = 'none';
      frame.style.display = '';
      frame.src = page + sep + 'embed=1';
      if (el && el.classList.contains('fc-item')) setActive(el);
      else activateByPage(page);
      var active = document.querySelector('.fc-item.fc-active');
      var t = active ? active.textContent.trim() : page;
      document.getElementById('fcFrameTitle').textContent = t;
      var bar = document.getElementById('fcFrameBar');
      bar.style.display = 'flex';
      document.getElementById('fcOpenFull').setAttribute('href', page);
      try { history.replaceState(null, '', location.pathname + '?tool=' + encodeURIComponent(page)); } catch (e) {}
    }

    // —— 统一拦截带 data-page 的菜单项 / 卡片，内嵌到右侧 iframe ——
    document.addEventListener('click', function (e) {
      var item = e.target.closest('[data-page]');
      if (! item) return;
      if (item.getAttribute('data-full')) return; // 对话模式等：整页跳转
      e.preventDefault();
      loadTool(item.getAttribute('data-page'), item);
    });
    document.querySelector('.fc-item[data-panel="overview"]').addEventListener('click', function (e) {
      e.preventDefault(); showOverview();
    });

    // —— 左侧菜单：能力项按 cat 分区自动生成（顺序取自 capabilities.py）——
    function renderMenu(caps) {
      var order = [], groups = {};
      Object.keys(caps).forEach(function (id) {
        var cat = caps[id].cat || '其他';
        if (! groups[cat]) { groups[cat] = []; order.push(cat); }
        groups[cat].push([id, caps[id]]);
      });
      catCount = order.length;
      var html = '';
      order.forEach(function (cat) {
        html += '<div class="fc-cat">' + esc((CAT_LABELS[cat] || cat).toUpperCase()) + '</div>';
        groups[cat].forEach(function (pair) {
          var id = pair[0], c = pair[1];
          var page = c.page || c.link || '';
          var label = CAP_LABELS[id] || c.name || id;
          var badge = (id === 'review' && pendingReviewCount) ? badgeHtml(pendingReviewCount) : '';
          if (page) {
            html += '<a class="fc-item" href="javascript:void(0)" data-page="' + esc(page) + '"><span style="font-size:16px;">' + esc(c.icon || '▶️') + '</span><span style="flex:1;">' + esc(label) + '</span>' + badge + '</a>';
          } else {
            html += '<span class="fc-item" style="opacity:.45;cursor:default;"><span style="font-size:16px;">' + esc(c.icon || '▶️') + '</span><span style="flex:1;">' + esc(label) + '</span><span style="font-size:11px;color:#64748b;">即将上线</span></span>';
          }
        });
      });
      nav.insertAdjacentHTML('beforeend', html);
    }

    // —— 右侧总览：统计条 + 全部能力卡片（点击内嵌到右侧）——
    function renderStats() {
      var chips = [
        ['⚡', capCount + ' 个能力在线'],
        ['🗂️', catCount + ' 个功能分区'],
        ['✅', '待审 ' + pendingReviewCount + ' 条']
      ];
      statsBox.innerHTML = chips.map(function (ch) {
        return '<span style="display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.16);border-radius:9999px;padding:6px 14px;font-size:13px;color:#fff;">' + ch[0] + ' ' + esc(ch[1]) + '</span>';
      }).join('');
    }

    function renderAllCards(caps) {
      var html = '';
      Object.keys(caps).forEach(function (id) {
        var c = caps[id];
        var page = c.page || c.link || '';
        var label = CAP_LABELS[id] || c.name || id;
        var desc = CAP_DESC[id] || c.desc || '';
        var badge = (id === 'review' && pendingReviewCount) ? badgeHtml(pendingReviewCount) : '';
        if (page) {
          html += '<a class="fc-card" href="javascript:void(0)" data-page="' + esc(page) + '">'
            + '<div style="display:flex;align-items:center;gap:8px;font-size:15px;font-weight:600;color:#334155;"><span style="font-size:20px;">' + esc(c.icon || '▶️') + '</span><span>' + esc(label) + '</span>' + badge + '</div>'
            + '<p style="margin:8px 0 0;font-size:13px;line-height:1.5;color:#64748b;">' + esc(desc) + '</p>'
            + '</a>';
        } else {
          html += '<div class="fc-card" style="opacity:.55;"><div style="display:flex;align-items:center;gap:8px;font-size:15px;font-weight:600;color:#334155;"><span style="font-size:20px;">' + esc(c.icon || '▶️') + '</span><span>' + esc(label) + '</span></div>'
            + '<p style="margin:8px 0 0;font-size:13px;color:#94a3b8;">' + esc(desc) + '</p><p style="margin:6px 0 0;font-size:12px;color:#cbd5e1;">即将上线</p></div>';
        }
      });
      allCards.innerHTML = html;
    }

    function applyDeepLink() {
      var tool = null;
      try { tool = new URLSearchParams(location.search).get('tool'); } catch (e) {}
      if (! tool) return;
      var item = document.querySelector('.fc-item[data-page="' + tool.replace(/"/g, '\\"') + '"]')
              || document.querySelector('.fc-card[data-page="' + tool.replace(/"/g, '\\"') + '"]');
      if (item) loadTool(item.getAttribute('data-page'), item);
    }
    applyDeepLink(); // 先处理资产与管理类（静态项已在 DOM 中）

    fetch('/studio/capabilities', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (! j || j.ok === false || ! j.caps) throw new Error((j && j.error) || '能力清单为空');
        var caps = j.caps;
        capCount = Object.keys(caps).length;
        renderMenu(caps);
        renderStats();
        renderAllCards(caps);
        applyDeepLink(); // 能力项已生成，再处理 ?tool= 深链
      })
      .catch(function (e) {
        allCards.innerHTML = '<div style="border:1px solid #fecaca;background:#fef2f2;border-radius:14px;padding:20px;text-align:center;font-size:14px;color:#dc2626;">创作能力加载失败：' + esc(e.message) + '。请确认出片服务（8500）已运行，或稍后刷新重试。</div>';
      });

    // 待审数量小红点：与审核页队列同一口径，实时向服务端取
    fetch('/studio/review/count', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        pendingReviewCount = (j && typeof j.count === 'number') ? j.count : 0;
        if (pendingReviewCount) {
          var slot = document.getElementById('fc-review-badge');
          if (slot) slot.innerHTML = badgeHtml(pendingReviewCount);
          renderStats();
        }
      })
      .catch(function () { /* 角标取数失败不影响主功能 */ });
  })();
  </script>
</x-workspace-layout>
</x-app-layout>
