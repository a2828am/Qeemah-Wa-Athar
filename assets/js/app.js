/* ============================================================
   قيمة وأثر — frontend interactions (progressive enhancement)
   Everything critical is rendered server-side; this file only
   adds live behaviour. No emoji, no inline SVG strings built by JS:
   icons are copied from the DOM when needed.
   ============================================================ */
(function () {
  'use strict';

  var body = document.body;
  var CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var BASE = body ? (body.dataset.base || '') : '';

  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  /* ---------- URL helper: always relative to the app root ----------
     Accepts a relative path, an already-rooted path and a full URL. The API
     answers with root-relative paths (e.g. /qeemah-platform/storage/x.jpg) when
     the app sits in a sub-folder; prefixing those again produced
     /qeemah-platform/qeemah-platform/... and broke every image. */
  function apiUrl(path) {
    var p = String(path == null ? '' : path);
    if (/^(?:[a-z]+:)?\/\//i.test(p) || p.indexOf('data:') === 0 || p.indexOf('blob:') === 0) return p;
    var base = BASE.replace(/\/$/, '');
    var clean = p.replace(/^\//, '');
    if (base && (clean === base.replace(/^\//, '') || clean.indexOf(base.replace(/^\//, '') + '/') === 0)) {
      return '/' + clean;                       // already carries the app root
    }
    return base + '/' + clean;
  }

  /* ---------- Toast ---------- */
  var toastEl = qs('.toast');
  if (!toastEl) {
    toastEl = document.createElement('div');
    toastEl.className = 'toast';
    toastEl.setAttribute('role', 'status');
    toastEl.setAttribute('aria-live', 'polite');
    body.appendChild(toastEl);
  }
  var toastTimer = null;
  function toast(msg, isError) {
    toastEl.textContent = msg;
    toastEl.classList.toggle('err', !!isError);
    toastEl.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.classList.remove('show'); }, 2800);
  }
  window.QA_toast = toast;

  /* ---------- Fetch helper ---------- */
  function postForm(url, data) {
    var fd = new FormData();
    Object.keys(data || {}).forEach(function (k) {
      if (data[k] !== undefined && data[k] !== null) fd.append(k, data[k]);
    });
    fd.append('_csrf', CSRF);
    return fetch(apiUrl(url), {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'استجابة غير صالحة من الخادم.' }; });
    });
  }
  function getJson(url) {
    return fetch(apiUrl(url), {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false }; });
    });
  }
  window.QA_post = postForm;
  window.QA_get = getJson;

  /* ---------- Escaping for injected text ---------- */
  function esc(str) {
    return String(str === null || str === undefined ? '' : str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  window.QA_escape = esc;

  /* ---------- Mobile menu ---------- */
  var burger = qs('.hamburger');
  var nav = qs('.main-nav');
  if (burger && nav) {
    burger.addEventListener('click', function () {
      var open = nav.classList.toggle('open');
      burger.setAttribute('aria-expanded', open ? 'true' : 'false');
      burger.classList.toggle('is-open', open);
      burger.setAttribute('aria-label', open ? 'إغلاق القائمة' : 'فتح القائمة');
    });
    document.addEventListener('click', function (e) {
      if (!nav.classList.contains('open')) return;
      if (nav.contains(e.target) || burger.contains(e.target)) return;
      nav.classList.remove('open');
      burger.classList.remove('is-open');
      burger.setAttribute('aria-expanded', 'false');
    });
  }

  /* ---------- Dismissible alerts ---------- */
  qsa('[data-dismiss]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var box = btn.closest('.alert');
      if (box) box.remove();
    });
  });

  /* ---------- Favourites (AJAX, requires login) ---------- */
  qsa('[data-fav]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var id = btn.getAttribute('data-fav');
      if (btn.getAttribute('data-busy') === '1') return;
      btn.setAttribute('data-busy', '1');
      postForm('api/toggle_favorite.php', { product_id: id }).then(function (res) {
        btn.removeAttribute('data-busy');
        if (!res.ok) {
          toast(res.error || 'تعذّر تنفيذ العملية.', true);
          if (res.login) { setTimeout(function () { window.location.href = apiUrl('login.php'); }, 900); }
          return;
        }
        btn.classList.toggle('active', !!res.on);
        btn.setAttribute('aria-pressed', res.on ? 'true' : 'false');
        btn.setAttribute('aria-label', res.on ? 'إزالة من المفضلة' : 'إضافة إلى المفضلة');
        var svg = btn.querySelector('svg');
        if (svg) svg.style.fill = res.on ? 'currentColor' : 'none';
        toast(res.on ? 'أُضيف إلى المفضلة' : 'أُزيل من المفضلة');
      }).catch(function () {
        btn.removeAttribute('data-busy');
        toast('تعذّر الاتصال بالخادم.', true);
      });
    });
  });

  /* ---------- Gallery thumbs (product details) ---------- */
  var mainImg = qs('#galleryMain');
  qsa('.gallery-thumbs button').forEach(function (thumb) {
    thumb.addEventListener('click', function () {
      qsa('.gallery-thumbs button').forEach(function (t) { t.classList.remove('active'); });
      thumb.classList.add('active');
      if (mainImg && thumb.dataset.full) mainImg.src = thumb.dataset.full;
    });
  });

  /* ---------- Add-product form ---------- */
  var opRadios = qsa('input[name="transaction_type"]');
  var dynPrice = qs('#dyn-price');
  var dynSwap = qs('#dyn-swap');
  var dynDonate = qs('#dyn-donate');
  var dynFree = qs('#dyn-free');
  function syncDynamic() {
    var checked = qs('input[name="transaction_type"]:checked');
    var v = checked ? checked.value : 'sell';
    if (dynPrice) dynPrice.hidden = v !== 'sell';
    if (dynSwap) dynSwap.hidden = v !== 'swap';
    if (dynDonate) dynDonate.hidden = v !== 'donate';
    if (dynFree) dynFree.hidden = v !== 'free';
    var priceInput = qs('#price');
    if (priceInput) {
      priceInput.required = v === 'sell';
      if (v !== 'sell') priceInput.value = '';
    }
  }
  opRadios.forEach(function (r) { r.addEventListener('change', syncDynamic); });
  if (opRadios.length) syncDynamic();

  var fileInput = qs('#productImages');
  var previewBox = qs('#imagePreview');
  if (fileInput && previewBox) {
    fileInput.addEventListener('change', function () {
      previewBox.innerHTML = '';
      Array.prototype.slice.call(fileInput.files || []).slice(0, 4).forEach(function (file) {
        if (!/^image\//.test(file.type)) return;
        var img = document.createElement('img');
        img.style.maxHeight = '120px';
        img.style.borderRadius = '10px';
        img.alt = '';
        var reader = new FileReader();
        reader.onload = function (e) { img.src = e.target.result; };
        reader.readAsDataURL(file);
        previewBox.appendChild(img);
      });
    });
  }

  /* ---------- Generic AJAX forms ----------
     Every form that posts to a JSON endpoint (api/*.php) must be handled here,
     never submitted natively — a native submit dumps raw JSON into the window
     and the user sees {"ok":true,…} on a blank page. Forms opt in with
     data-ajax; ones that point an action= at api/ are picked up as well, so a
     forgotten attribute cannot expose the endpoint again. */
  qsa('form[data-ajax], form[action*="/api/"]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var endpoint = form.getAttribute('data-ajax');
      if (!endpoint) {
        var act = form.getAttribute('action') || '';
        var m = act.match(/api\/[a-z_]+\.php/);
        if (!m) return;              /* not an API form — let it submit */
        endpoint = m[0];
      }
      var confirmMsg = form.getAttribute('data-confirm');
      if (confirmMsg && !window.confirm(confirmMsg)) return;

      var btn = form.querySelector('[type="submit"]');
      var original = btn ? btn.innerHTML : '';
      if (btn) { btn.disabled = true; btn.innerHTML = 'جارٍ التنفيذ…'; }

      var fd = new FormData(form);
      if (!fd.get('_csrf')) fd.append('_csrf', CSRF);

      fetch(apiUrl(endpoint), {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      }).then(function (r) {
        return r.json().catch(function () { return { ok: false, error: 'استجابة غير صالحة.' }; });
      }).then(function (res) {
        if (btn) { btn.disabled = false; btn.innerHTML = original; }
        if (!res.ok) { toast(res.error || 'حدث خطأ.', true); return; }
        toast(res.message || 'تم بنجاح.');
        if (res.reload) { setTimeout(function () { window.location.reload(); }, 600); return; }
        if (res.redirect) { window.location.href = apiUrl(res.redirect); return; }
        var done = form.getAttribute('data-done');
        /* Deal buttons act on the chat's deal panel. A full reload would jump
           the user out of the conversation, so just refresh the panel in place
           (the same fragment the server renders on first paint). */
        if (done === 'deal') {
          window.location.replace(window.location.pathname + window.location.search + '#dealActions');
          window.location.reload();
          return;
        }
        if (done === 'remove') {
          var target = form.closest(form.getAttribute('data-remove-selector') || '.list-row,.notif,.card,.req-card,tr');
          if (target) target.remove();
        }
      }).catch(function () {
        if (btn) { btn.disabled = false; btn.innerHTML = original; }
        toast('تعذّر الاتصال بالخادم.', true);
      });
    });
  });

  /* ---------- Dashboard tabs ---------- */
  qsa('[data-dash-tab]').forEach(function (tab) {
    tab.addEventListener('click', function () {
      var name = tab.getAttribute('data-dash-tab');
      qsa('[data-dash-tab]').forEach(function (t) { t.classList.remove('active'); });
      tab.classList.add('active');
      qsa('.dash-panel').forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== name; });
      if (history.replaceState) history.replaceState(null, '', '#' + name);
    });
  });
  if (location.hash) {
    var initial = qs('[data-dash-tab="' + location.hash.slice(1) + '"]');
    if (initial) initial.click();
  }

  /* ---------- Password visibility ---------- */
  qsa('.eye').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = btn.parentNode.querySelector('input');
      if (!input) return;
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-label', show ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور');
      btn.classList.toggle('is-on', show);
    });
  });

  /* ---------- Star rating input ---------- */
  qsa('.star-input').forEach(function (wrap) {
    var input = qs('input[type="hidden"]', wrap);
    var stars = qsa('button', wrap);
    function paint(v) {
      stars.forEach(function (s, i) { s.classList.toggle('on', i < v); });
    }
    stars.forEach(function (star, i) {
      star.addEventListener('click', function () {
        var v = i + 1;
        if (input) input.value = String(v);
        paint(v);
      });
      star.addEventListener('mouseenter', function () { paint(i + 1); });
      star.addEventListener('mouseleave', function () { paint(input ? parseInt(input.value, 10) : 0); });
    });
    if (input) paint(parseInt(input.value, 10) || 0);
  });

  /* ---------- Request modal ---------- */
  var modal = qs('#requestModal');
  if (modal) {
    qsa('[data-open-request]').forEach(function (b) {
      b.addEventListener('click', function () {
        var productField = qs('#reqProductId', modal);
        var titleField = qs('#reqProductTitle', modal);
        if (productField) productField.value = b.getAttribute('data-open-request');
        if (titleField) titleField.textContent = b.getAttribute('data-product-title') || '';
        modal.classList.add('open');
        var first = qs('textarea', modal);
        if (first) setTimeout(function () { first.focus(); }, 60);
      });
    });
    qsa('[data-close-modal]', modal).forEach(function (b) {
      b.addEventListener('click', function () { modal.classList.remove('open'); });
    });
    modal.addEventListener('click', function (e) { if (e.target === modal) modal.classList.remove('open'); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') modal.classList.remove('open');
    });
  }

  /* ---------- Generic modals (request hub, admin editors) ---------- */
  qsa('[data-open-modal]').forEach(function (b) {
    b.addEventListener('click', function () {
      var m = qs('#' + b.getAttribute('data-open-modal'));
      if (!m) return;
      m.hidden = false;
      m.classList.add('open');
      var first = qs('input:not([type=hidden]), textarea, select', m);
      if (first) setTimeout(function () { first.focus(); }, 60);
    });
  });
  qsa('.modal').forEach(function (m) {
    qsa('[data-close-modal]', m).forEach(function (b) {
      b.addEventListener('click', function () { m.classList.remove('open'); m.hidden = true; });
    });
    m.addEventListener('click', function (e) { if (e.target === m) { m.classList.remove('open'); m.hidden = true; } });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && m.classList.contains('open')) { m.classList.remove('open'); m.hidden = true; }
    });
  });

  /* ---------- Assistant (optional, data-first) ---------- */
  var assist = qs('#assistForm');
  if (assist) {
    var assistBody = qs('#assistBody');
    var assistInput = qs('#assistInput');
    var assistSend = qs('#assistSend');

    function assistBubble(text, mine, meta) {
      if (!assistBody) return null;
      var div = document.createElement('div');
      div.className = 'msg ' + (mine ? 'me' : 'them');
      var span = document.createElement('span');
      span.textContent = text;
      div.appendChild(span);
      if (meta) {
        var tag = document.createElement('span');
        tag.className = 'msg-time';
        tag.textContent = meta;
        div.appendChild(tag);
      }
      assistBody.appendChild(div);
      assistBody.scrollTop = assistBody.scrollHeight;
      return div;
    }

    function ask(text) {
      text = (text || '').trim();
      if (!text) return;
      assistBubble(text, true);
      assistInput.value = '';
      assistInput.style.height = '';
      assistSend.disabled = true;
      var pending = assistBubble('جارٍ البحث في بيانات المنصة…', false);
      postForm('api/assistant.php', { question: text }).then(function (res) {
        assistSend.disabled = false;
        if (pending && pending.parentNode) pending.parentNode.removeChild(pending);
        if (!res || !res.ok) {
          assistBubble((res && res.error) || 'تعذّر الوصول إلى المساعد.', false, 'خطأ');
          return;
        }
        var meta2 = res.engine === 'model'
          ? 'مساعد آلي · نموذج ذكي'
          : (res.degraded ? 'مساعد آلي · الدليل المحلي (تعذّر الوصول للنموذج)' : 'مساعد آلي · بيانات المنصة');
        var el = assistBubble(res.answer, false, meta2);
        if (el && el.firstChild && el.firstChild.nodeType === 1) {
          // answers may carry **bold** markers — render them, everything else stays escaped
          var src = el.firstChild.textContent;
          el.firstChild.innerHTML = src.replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/\*\*(.+?)\*\*/g, '<b>$1</b>');
        }
        if (el && res.links && res.links.length) {
          var wrap = document.createElement('span');
          res.links.forEach(function (l) {
            var a = document.createElement('a');
            a.href = l.url; a.textContent = l.label;
            a.style.cssText = 'display:inline-block;margin:8px 0 0;margin-inline-end:8px;font-size:12.5px;font-weight:700;'
              + 'color:var(--green-700,#0f5132);background:#eaf6ef;border:1px solid #cfe9db;border-radius:999px;padding:4px 11px;text-decoration:none';
            wrap.appendChild(a);
          });
          el.appendChild(wrap);
        }
        if (assistBody) assistBody.scrollTop = assistBody.scrollHeight;
      }).catch(function () {
        assistSend.disabled = false;
        if (pending && pending.parentNode) pending.parentNode.removeChild(pending);
        assistBubble('تعذّر الاتصال بالخادم.', false, 'خطأ');
      });
    }

    assist.addEventListener('submit', function (e) { e.preventDefault(); ask(assistInput.value); });
    assistInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ask(assistInput.value); }
    });
    assistInput.addEventListener('input', function () {
      assistInput.style.height = 'auto';
      assistInput.style.height = Math.min(assistInput.scrollHeight, 140) + 'px';
    });
    qsa('[data-suggest]').forEach(function (btn) {
      btn.addEventListener('click', function () { ask(btn.getAttribute('data-suggest')); });
    });
    if (assistBody) assistBody.scrollTop = assistBody.scrollHeight;
  }

  /* ---------- Chat ---------- */
  var chat = qs('#chatApp');
  if (chat) {
    var convId = chat.getAttribute('data-conversation');
    var me = chat.getAttribute('data-me');
    var pollMs = parseInt(chat.getAttribute('data-poll'), 10) || 4000;
    var bodyEl = qs('#chatBody');
    var form = qs('#chatForm');
    var input = qs('#chatInput');
    var sendBtn = qs('#chatSend');
    var lastId = parseInt(chat.getAttribute('data-last-id'), 10) || 0;
    var seenIds = {};

    function dayKey(iso) { return (iso || '').slice(0, 10); }
    function dayLabel(iso) {
      var d = new Date((iso || '').replace(' ', 'T'));
      if (isNaN(d.getTime())) return '';
      var today = new Date();
      var yest = new Date(); yest.setDate(yest.getDate() - 1);
      var same = function (a, b) { return a.toDateString() === b.toDateString(); };
      if (same(d, today)) return 'اليوم';
      if (same(d, yest)) return 'أمس';
      return d.toLocaleDateString('ar-EG', { year: 'numeric', month: 'long', day: 'numeric' });
    }
    function scrollBottom() { if (bodyEl) bodyEl.scrollTop = bodyEl.scrollHeight; }

    function renderMessages(rows, prependDay) {
      if (!rows.length) return;
      var lastDay = bodyEl ? bodyEl.getAttribute('data-last-day') : '';
      rows.forEach(function (m) {
        if (seenIds[m.id]) return;
        seenIds[m.id] = true;
        if (bodyEl) {
          var dk = dayKey(m.created_at);
          if (dk && dk !== lastDay) {
            var dd = document.createElement('div');
            dd.className = 'chat-day';
            dd.textContent = dayLabel(m.created_at);
            bodyEl.appendChild(dd);
            lastDay = dk;
          }
          var div = document.createElement('div');
          div.className = 'msg ' + (String(m.sender_id) === String(me) ? 'me' : 'them');
          div.setAttribute('data-msg-id', m.id);
          var span = document.createElement('span');
          span.textContent = m.body;
          div.appendChild(span);
          var time = document.createElement('span');
          time.className = 'msg-time';
          var stamp = (m.created_at || '').slice(11, 16);
          if (String(m.sender_id) === String(me)) {
            stamp += ' · ' + (m.read_at ? 'مقروءة' : (m.delivered_at ? 'وصلت' : 'أُرسلت'));
          }
          time.textContent = stamp;
          div.appendChild(time);
          bodyEl.appendChild(div);
        }
        var id = parseInt(m.id, 10);
        if (id > lastId) lastId = id;
      });
      if (bodyEl) bodyEl.setAttribute('data-last-day', lastDay);
      if (prependDay !== false) scrollBottom();
    }

    /* The chat shows the same six-step bar as the request page, so both stay
       truthful without a reload when the other party confirms a step. */
    function paintRequestProgress(req) {
      var strip = qs('#chatDeal');
      if (!strip || !req) return;
      var dots = qsa('.req-step', strip);
      dots.forEach(function (li, i) {
        var n = i + 1;
        li.classList.toggle('done', !req.closed && n <= req.step);
        li.classList.toggle('current', !req.closed && n === req.step);
      });
      var label = qs('[data-deal-label]', strip);
      if (label) label.textContent = req.label;
      if (req.url) {
        var link = qs('[data-deal-link]', strip);
        if (link) link.setAttribute('href', req.url);
      }
    }

    function poll() {
      if (!convId) return;
      getJson('api/fetch_messages.php?conversation=' + encodeURIComponent(convId) + '&after=' + lastId)
        .then(function (res) {
          if (!res || !res.ok) return;
          if (res.messages && res.messages.length) {
            var atBottom = !bodyEl || (bodyEl.scrollHeight - bodyEl.scrollTop - bodyEl.clientHeight < 90);
            renderMessages(res.messages, false);
            if (atBottom) scrollBottom();
          }
          if (typeof res.unread === 'number') updateBadges(res.unread, null);
          if (res.request) paintRequestProgress(res.request);
        })
        .catch(function () { /* offline: retry on next tick */ })
        .then(function () { setTimeout(poll, pollMs); });
    }

    function send() {
      if (!input) return;
      var text = input.value.trim();
      if (!text) return;
      sendBtn.disabled = true;
      postForm('api/send_message.php', { conversation_id: convId, body: text }).then(function (res) {
        sendBtn.disabled = false;
        if (!res.ok) { toast(res.error || 'تعذّر الإرسال.', true); return; }
        input.value = '';
        input.style.height = '';
        renderMessages([res.message]);
      }).catch(function () {
        sendBtn.disabled = false;
        toast('تعذّر الاتصال بالخادم.', true);
      });
    }

    if (form) {
      form.addEventListener('submit', function (e) { e.preventDefault(); send(); });
    }
    if (input) {
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
      });
      input.addEventListener('input', function () {
        input.style.height = 'auto';
        input.style.height = Math.min(120, input.scrollHeight) + 'px';
      });
    }
    scrollBottom();
    if (convId) setTimeout(poll, pollMs);
  }

  /* ---------- Live badges (messages / notifications) ---------- */
  var badgeMsg = qs('[data-badge="messages"]');
  var badgeNotif = qs('[data-badge="notifications"]');
  function updateBadges(msgCount, notifCount) {
    if (badgeMsg && typeof msgCount === 'number') {
      badgeMsg.textContent = msgCount > 99 ? '99+' : String(msgCount);
      badgeMsg.hidden = msgCount <= 0;
    }
    if (badgeNotif && typeof notifCount === 'number') {
      badgeNotif.textContent = notifCount > 99 ? '99+' : String(notifCount);
      badgeNotif.hidden = notifCount <= 0;
    }
  }
  window.QA_updateBadges = updateBadges;

  var notifBadge = qs('[data-badge="notifications"]');
  if (notifBadge) {
    var notifMs = parseInt(body.dataset.notifPoll, 10) || 15000;
    var pollNotifs = function () {
      getJson('api/notifications.php?poll=1').then(function (res) {
        if (!res || !res.ok) return;
        updateBadges(res.unread_messages, res.unread_notifications);
        if (res.unread_notifications > 0 && qs('#notifList')) {
          // on the notifications page itself: no live re-render needed
        }
      }).catch(function () {}).then(function () { setTimeout(pollNotifs, notifMs); });
    };
    setTimeout(pollNotifs, notifMs);
  }

  /* ---------- Read-all notifications ---------- */
  var markAll = qs('#markAllRead');
  if (markAll) {
    markAll.addEventListener('click', function () {
      postForm('api/notifications.php', { action: 'read_all' }).then(function (res) {
        if (!res.ok) { toast(res.error || 'تعذّر التنفيذ.', true); return; }
        qsa('.notif.unread').forEach(function (n) { n.classList.remove('unread'); });
        qsa('[data-badge="notifications"]').forEach(function (b) { b.hidden = true; });
        toast('تم تعليم كل الإشعارات كمقروءة.');
      });
    });
  }

  /* ---------- AI listing helper (non-blocking) ---------- */
  var aiBox = qs('#aiHelper');
  if (aiBox) {
    var runAi = function () {
      var form = qs('#addProductForm') || qs('#editProductForm');
      if (!form) return;
      var title = (qs('#title') || {}).value || '';
      var cat = (qs('#category_id') || {}).value || '';
      var cond = (qs('#condition_type') || {}).value || 'good';
      var type = qs('input[name="transaction_type"]:checked');
      var desc = (qs('#description') || {}).value || '';
      if (!title || !cat) {
        aiBox.innerHTML = '';
        return;
      }
      aiBox.innerHTML = '<div class="ai-loading"><span class="spinner"></span> يجري تحليل الأسعار المماثلة…</div>';
      getJson('api/ai_listing_helper.php?title=' + encodeURIComponent(title) +
        '&category_id=' + encodeURIComponent(cat) +
        '&condition=' + encodeURIComponent(cond) +
        '&transaction_type=' + encodeURIComponent(type ? type.value : 'sell') +
        '&description=' + encodeURIComponent(desc.slice(0, 400))
      ).then(function (res) {
        if (!res || !res.ok) { aiBox.innerHTML = ''; return; }
        var html = '<div class="ai-panel"><h4>' + (res.icon || '') + ' مساعد الإعلان</h4><div class="ai-body">';
        if (res.price_range) {
          html += '<div class="ai-range">' + esc(res.price_range.label) + '</div>';
          html += '<div class="ai-meta">' + esc(res.price_range.basis) + '</div>';
        }
        if (res.type_suggestion) {
          html += '<p><b>النوع المقترح:</b> ' + esc(res.type_suggestion.label) + ' — ' + esc(res.type_suggestion.reason) + '</p>';
        }
        if (res.description_polish) {
          html += '<p><b>وصف مقترح:</b> ' + esc(res.description_polish.text) + '</p>';
          if (res.description_polish.apply) {
            html += '<button type="button" class="btn btn-outline btn-sm" id="aiApplyDesc">استخدام الوصف المقترح</button>';
          }
        }
        html += '</div></div>';
        aiBox.innerHTML = html;
        var apply = qs('#aiApplyDesc');
        if (apply) {
          apply.addEventListener('click', function () {
            var ta = qs('#description');
            if (ta) { ta.value = res.description_polish.text; ta.focus(); }
            toast('تم إدراج الوصف المقترح.');
          });
        }
      }).catch(function () { aiBox.innerHTML = ''; });
    };

    var aiTimer = null;
    ['#title', '#category_id', '#condition_type', '#description'].forEach(function (sel) {
      var el = qs(sel);
      if (!el) return;
      var ev = el.tagName === 'SELECT' ? 'change' : 'input';
      el.addEventListener(ev, function () {
        clearTimeout(aiTimer);
        aiTimer = setTimeout(runAi, 700);
      });
    });
    qsa('input[name="transaction_type"]').forEach(function (r) {
      r.addEventListener('change', function () { clearTimeout(aiTimer); aiTimer = setTimeout(runAi, 250); });
    });
  }

  /* ---------- AI recommendations on product details ---------- */
  qsa('[data-recommendations]').forEach(function (box) {
    var productId = box.getAttribute('data-recommendations');
    getJson('api/ai_recommendations.php?product_id=' + encodeURIComponent(productId)).then(function (res) {
      if (!res || !res.ok || !res.items || !res.items.length) { box.closest('#recPanel').hidden = true; return; }
      var html = '';
      res.items.forEach(function (it) {
        html += '<a class="rec-item" href="' + esc(apiUrl('product_details.php?slug=' + encodeURIComponent(it.slug))) + '">' +
          '<img src="' + esc(apiUrl(it.image)) + '" alt="" loading="lazy">' +
          '<span class="grow"><b>' + esc(it.title) + '</b>' +
          '<span>' + esc(it.why) + '</span></span>' +
          '<b class="price" style="font-size:13px">' + esc(it.price_label || '') + '</b></a>';
      });
      box.innerHTML = html;
    }).catch(function () { var p = box.closest('#recPanel'); if (p) p.hidden = true; });
  });

  /* ---------- AI suggestions on the charity page ---------- */
  var aiCampaigns = qs('#aiCampaignMatches');
  if (aiCampaigns) {
    getJson('api/ai_recommendations.php?scope=charity').then(function (res) {
      if (!res || !res.ok || !res.items || !res.items.length) {
        var p = aiCampaigns.closest('.panel');
        if (p) p.hidden = true;
        return;
      }
      var html = '';
      res.items.forEach(function (it) {
        var pct = Math.max(0, Math.min(100, parseInt(it.match_pct, 10) || 0));
        html += '<div class="list-row"><span class="stat-ic" style="width:36px;height:36px;display:grid;place-items:center;border-radius:10px;background:var(--green-50);color:var(--green-700)">' + (it.icon || '') + '</span>' +
          '<span class="grow"><b>' + esc(it.title) + '</b><span>' + esc(it.why) + '</span>' +
          '<span class="progress" style="margin-top:6px"><i style="width:' + pct + '%"></i></span></span>' +
          (it.link ? '<a class="icon-btn" href="' + esc(apiUrl(it.link)) + '">عرض</a>' : '') + '</div>';
      });
      aiCampaigns.innerHTML = html;
    }).catch(function () { var p = aiCampaigns.closest('.panel'); if (p) p.hidden = true; });
  }

  /* ---------- Live filter on the marketplace grid ---------- */
  var grid = qs('#productGrid');
  var liveSearch = qs('#liveSearch');
  var cityFilter = qs('#cityFilter');
  var typeFilter = qs('#typeFilter');
  var countEl = qs('#resultsCount');
  if (grid && (liveSearch || cityFilter || typeFilter)) {
    var cards = qsa('.product-card', grid);
    var apply = function () {
      var q = (liveSearch && liveSearch.value || '').trim().toLowerCase();
      var city = cityFilter ? cityFilter.value : '';
      var type = typeFilter ? typeFilter.value : '';
      var visible = 0;
      cards.forEach(function (card) {
        var ok = true;
        if (q && (card.dataset.title || '').toLowerCase().indexOf(q) === -1) ok = false;
        if (city && card.dataset.city !== city) ok = false;
        if (type && card.dataset.type !== type) ok = false;
        card.style.display = ok ? '' : 'none';
        if (ok) visible++;
      });
      if (countEl) countEl.textContent = 'عرض ' + visible + ' من ' + cards.length + ' منتج';
    };
    [liveSearch, cityFilter, typeFilter].forEach(function (el) {
      if (!el) return;
      el.addEventListener('input', apply);
      el.addEventListener('change', apply);
    });
  }

  /* ---------- Copy-to-clipboard share buttons ---------- */
  qsa('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.getAttribute('data-copy') || window.location.href;
      if (text.charAt(0) === '/') text = window.location.origin + text;
      var done = function () { toast('تم نسخ الرابط — شاركه مع من يهمّه'); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done).catch(function () { window.prompt('انسخ الرابط:', text); });
      } else {
        window.prompt('انسخ الرابط:', text);
      }
    });
  });

  /* ---------- On-demand password reset request (profile) ---------- */
  qsa('[data-logout]').forEach(function (link) {
    link.addEventListener('click', function (e) {
      if (!window.confirm('هل تريد تسجيل الخروج؟')) e.preventDefault();
    });
  });
})();

/* ---------- Floating assistant (chat-bot) ---------- */
(function () {
  'use strict';

  /* This block lives outside the main IIFE, so it needs its own helpers. */
  function q(sel, root) { return (root || document).querySelector(sel); }
  function qa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function esc(t) {
    return String(t == null ? '' : t)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  var body   = document.body;
  var CSRF   = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var BASE   = body ? (body.dataset.base || '') : '';
  function apiUrl(path) {
    var p = String(path == null ? '' : path);
    if (/^(?:[a-z]+:)?\/\//i.test(p)) return p;
    var base = BASE.replace(/\/$/, '');
    var clean = p.replace(/^\//, '');
    if (base && (clean === base.replace(/^\//, '') || clean.indexOf(base.replace(/^\//, '') + '/') === 0)) {
      return '/' + clean;
    }
    return base + '/' + clean;
  }

  var fab = q('#botFab');
  var widget = q('#botWidget');
  if (!fab || !widget) return;

  var panelBody = q('#botBody', widget);
  var form      = q('#botForm', widget);
  var input     = q('#botInput', widget);
  var send      = q('#botSend', widget);
  var chips     = q('#botChips', widget);
  var endpoint  = widget.getAttribute('data-endpoint') || apiUrl('api/assistant.php');
  var started   = false;
  var loaded    = false;
  var busy      = false;

  /* Load this browser session's earlier questions once, so the conversation
     survives moving between pages (the server keeps them per session). */
  function loadHistory() {
    if (loaded) return;
    loaded = true;
    fetch(apiUrl('api/assistant.php') + '?action=history', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        var rows = (res && res.messages) || [];
        rows.slice(-6).forEach(function (m) {
          if (!m || !m.body) return;
          bubble(m.role === 'user' ? m.body : render(m.body), m.role === 'user');
        });
        if (rows.length) panelBody.scrollTop = panelBody.scrollHeight;
      })
      .catch(function () { /* history is a nicety, never an error surface */ });
  }

  function openBot() {
    widget.hidden = false;
    fab.setAttribute('aria-expanded', 'true');
    var dot = q('#botDot');
    if (dot) dot.hidden = true;
    loadHistory();
    input.focus();
    panelBody.scrollTop = panelBody.scrollHeight;
  }
  function closeBot() {
    widget.hidden = true;
    fab.setAttribute('aria-expanded', 'false');
    fab.focus();
  }

  function bubble(html, mine, meta) {
    var div = document.createElement('div');
    div.className = 'msg ' + (mine ? 'me' : 'them');
    var span = document.createElement('span');
    if (mine) span.textContent = html;
    else span.innerHTML = html;          // answers come from our own endpoint
    div.appendChild(span);
    if (meta) {
      var tag = document.createElement('span');
      tag.className = 'msg-time';
      tag.textContent = meta;
      div.appendChild(tag);
    }
    panelBody.appendChild(div);
    panelBody.scrollTop = panelBody.scrollHeight;
    return div;
  }

  /* Answers are plain text with **bold** and • bullets plus optional links. */
  function render(answer, links) {
    var safe = esc(answer)
      .replace(/\*\*(.+?)\*\*/g, '<b>$1</b>')
      .replace(/\n/g, '\n');
    var html = safe;
    if (links && links.length) {
      html += '<span>';
      links.forEach(function (l) {
        html += '<a href="' + esc(l.url) + '">' + esc(l.label) + '</a>';
      });
      html += '</span>';
    }
    return html;
  }

  function ask(text) {
    text = (text || '').trim();
    if (!text || busy) return;
    busy = true;
    bubble(text, true);
    input.value = '';
    send.disabled = true;

    var pending = bubble('<span class="bot-typing"><i></i><i></i><i></i></span>', false);

    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: 'question=' + encodeURIComponent(text) + '&_csrf=' + encodeURIComponent(CSRF)
    }).then(function (r) { return r.json(); }).then(function (res) {
      if (pending && pending.parentNode) pending.parentNode.removeChild(pending);
      busy = false;
      send.disabled = false;
      if (!res || !res.ok) {
        bubble(esc((res && res.error) || 'تعذّر الوصول إلى المساعد.'), false, 'خطأ');
        return;
      }
      var meta = res.engine === 'model'
        ? 'مساعد آلي · نموذج ذكي'
        : (res.degraded ? 'مساعد آلي · الدليل المحلي (تعذّر الوصول للنموذج)' : 'مساعد آلي · بيانات المنصة');
      bubble(render(res.answer, res.links), false, meta);
    }).catch(function () {
      if (pending && pending.parentNode) pending.parentNode.removeChild(pending);
      busy = false;
      send.disabled = false;
      bubble('تعذّر الاتصال بالخادم. تحقّق من الإنترنت وحاول مرة أخرى.', false, 'خطأ');
    });
    started = true;
  }

  fab.addEventListener('click', openBot);
  qa('[data-bot-close]', widget).forEach(function (b) { b.addEventListener('click', closeBot); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !widget.hidden) closeBot();
  });
  /* Tapping anywhere outside the panel closes it — the usual chat-bubble habit. */
  document.addEventListener('click', function (e) {
    if (widget.hidden) return;
    if (widget.contains(e.target) || fab.contains(e.target)) return;
    closeBot();
  });

  form.addEventListener('submit', function (e) { e.preventDefault(); ask(input.value); });
  chips.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-bot-ask]');
    if (btn) ask(btn.getAttribute('data-bot-ask'));
  });

  /* A quiet nudge: the dot appears once so the visitor notices the assistant. */
  if (!started) {
    setTimeout(function () {
      var dot = q('#botDot');
      if (dot && widget.hidden) dot.hidden = false;
    }, 6000);
  }
})();
