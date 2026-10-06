<x-workspace-layout title="智能创作工厂">
  <div class="mx-auto max-w-[1200px] px-4 py-6 md:px-8 md:py-8">

    {{-- 顶部：标题 + 模式切换（菜单模式 / 对话模式） --}}
    <div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
      <div>
        <h2 class="text-2xl font-bold text-slate-800">智能创作工厂</h2>
        <p class="mt-1 text-sm text-slate-500">选题 → 写稿 → 出片 → 质检 → 发布，点一下就能干。每个功能都是即插即用的「插件」。</p>
      </div>
      <div class="inline-flex items-center rounded-xl border border-slate-200 bg-white p-1 shadow-sm">
        <span class="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-medium text-white">☰ 菜单模式</span>
        <a href="/studio/chat" class="rounded-lg px-3 py-1.5 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-brand-600">💬 对话模式</a>
      </div>
    </div>

    {{-- 插件能力菜单：由 /studio/capabilities 自动生成（按 cat 分区） --}}
    <div id="capRoot" class="space-y-8">
      <div class="py-10 text-center text-sm text-slate-400">正在加载创作能力…</div>
    </div>

    {{-- 管理后台（非创作类工具，静态分区，便于自管理） --}}
    <div class="mt-10 border-t border-slate-200 pt-6">
      <h3 class="mb-3 text-sm font-semibold tracking-wide text-slate-400">管理后台</h3>
      <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
        <a href="/studio/accounts"   class="rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-600 shadow-sm transition hover:border-brand-300 hover:text-brand-600">发布账号</a>
        <a href="/studio/voices"     class="rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-600 shadow-sm transition hover:border-brand-300 hover:text-brand-600">声音库</a>
        <a href="/studio/covers"     class="rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-600 shadow-sm transition hover:border-brand-300 hover:text-brand-600">封面库</a>
        <a href="/studio/models"     class="rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-600 shadow-sm transition hover:border-brand-300 hover:text-brand-600">数字人模特</a>
        <a href="/studio/queue"      class="rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-600 shadow-sm transition hover:border-brand-300 hover:text-brand-600">任务队列</a>
        <a href="/studio/publish"    class="rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-600 shadow-sm transition hover:border-brand-300 hover:text-brand-600">发布助手</a>
        <a href="/studio/schedule"   class="rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-600 shadow-sm transition hover:border-brand-300 hover:text-brand-600">发布排期</a>
        <a href="/studio/metrics"    class="rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-600 shadow-sm transition hover:border-brand-300 hover:text-brand-600">数据效果</a>
        <a id="mgmt-review" href="/studio/review" class="flex items-center justify-between rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-600 shadow-sm transition hover:border-brand-300 hover:text-brand-600"><span>人工审核</span><span id="mgmt-review-badge" class="hidden"></span></a>
        <a href="/studio/help"       class="rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-600 shadow-sm transition hover:border-brand-300 hover:text-brand-600">帮助中心</a>
      </div>
    </div>
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

    var root = document.getElementById('capRoot');

    // 待审数量（菜单壳小红点）：与审核页队列同一口径，加载时向服务端实时取。
    var pendingReviewCount = 0;

    function reviewBadgeHtml(n) {
      return '<span style="display:inline-flex;min-width:20px;align-items:center;justify-content:center;border-radius:9999px;background:#ef4444;padding:2px 6px;font-size:12px;font-weight:700;line-height:1;color:#fff;">' + n + '</span>';
    }
    function applyMgmtBadge(n) {
      var slot = document.getElementById('mgmt-review-badge');
      if (! slot || ! n) return;
      slot.className = slot.className.replace(/\s*hidden\b/, '').trim();
      slot.innerHTML = reviewBadgeHtml(n);
    }
    function applyCardBadge(n) {
      if (! n) return;
      var card = root.querySelector('a[href="/studio/review"]');
      if (! card) return;
      var titleRow = card.querySelector('div');
      if (! titleRow || titleRow.querySelector('.review-badge')) return;
      var sp = document.createElement('span');
      sp.className = 'review-badge';
      sp.setAttribute('style', 'display:inline-flex;min-width:20px;align-items:center;justify-content:center;border-radius:9999px;background:#ef4444;padding:2px 6px;font-size:12px;font-weight:700;line-height:1;color:#fff;margin-left:8px;');
      sp.textContent = n;
      titleRow.appendChild(sp);
    }

    function esc(s) {
      return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];
      });
    }

    function cardHtml(id, c) {
      var name = CAP_LABELS[id] || c.name || id;
      var desc = CAP_DESC[id] || c.desc || '';
      var icon = c.icon || '▶️';
      var page = c.page || c.link || '';
      var params = (c.params || []).filter(function (p) { return p.required; })
        .map(function (p) { return p.label; });
      var hint = params.length
        ? '<div class="mt-2 text-xs text-slate-400">需填写：' + esc(params.join('、')) + '</div>'
        : '';
      if (! page) {
        return '<div class="rounded-xl border border-dashed border-slate-200 bg-slate-50 p-4 opacity-70">'
          + '<div class="flex items-center gap-2 text-slate-500"><span class="text-xl">' + esc(icon) + '</span>'
          + '<span class="font-medium">' + esc(name) + '</span></div>'
          + '<p class="mt-1.5 text-sm text-slate-400">' + esc(desc) + '</p>'
          + '<div class="mt-2 text-xs text-slate-300">即将上线</div></div>';
      }
      return '<a href="' + esc(page) + '" class="group block rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-brand-300 hover:shadow-md">'
        + '<div class="flex items-center gap-2 text-slate-700"><span class="text-2xl">' + esc(icon) + '</span>'
        + '<span class="font-semibold">' + esc(name) + '</span></div>'
        + '<p class="mt-1.5 text-sm text-slate-500">' + esc(desc) + '</p>'
        + hint
        + '<div class="mt-3 text-sm font-medium text-brand-600 opacity-0 transition group-hover:opacity-100">进入 →</div>'
        + '</a>';
    }

    function render(caps) {
      // 按 capabilities.py 中 cat 出现的顺序分组
      var order = [];
      var groups = {};
      Object.keys(caps).forEach(function (id) {
        var c = caps[id];
        var cat = c.cat || '其他';
        if (! groups[cat]) { groups[cat] = []; order.push(cat); }
        groups[cat].push([id, c]);
      });
      var html = '';
      order.forEach(function (cat) {
        var label = CAT_LABELS[cat] || cat;
        html += '<section><h3 class="mb-3 flex items-center gap-2 text-base font-semibold text-slate-700">'
          + '<span class="inline-block h-4 w-1 rounded bg-brand-500"></span>' + esc(label) + '</h3>';
        html += '<div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">';
        groups[cat].forEach(function (pair) { html += cardHtml(pair[0], pair[1]); });
        html += '</div></section>';
      });
      root.innerHTML = html;
      applyCardBadge(pendingReviewCount);
    }

    fetch('/studio/capabilities', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (! j || j.ok === false || ! j.caps) {
          throw new Error((j && j.error) || '能力清单为空');
        }
        render(j.caps);
      })
      .catch(function (e) {
        root.innerHTML = '<div class="rounded-xl border border-red-200 bg-red-50 p-6 text-center text-sm text-red-600">'
          + '创作能力加载失败：' + esc(e.message) + '。请确认出片服务（8500）已运行，或稍后刷新重试。</div>';
      });

    // 待审数量小红点：实时向服务端取，注入「人工审核」管理链接 + 审核能力卡片
    fetch('/studio/review/count', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        pendingReviewCount = (j && typeof j.count === 'number') ? j.count : 0;
        applyMgmtBadge(pendingReviewCount);
        applyCardBadge(pendingReviewCount);
      })
      .catch(function () { /* 角标取数失败不影响主功能 */ });
  })();
  </script>
</x-workspace-layout>
