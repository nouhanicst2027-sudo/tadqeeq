/* صفحة تفاصيل التدقيق: مقارنة المواد يدوياً + أدوات النموذج. بدون مكتبات خارجية. */
(function () {
  'use strict';

  var SRC = { diwan: 'ديوان التشريع والرأي', qistas: 'قسطاس' };
  var NOT_FOUND = 'لم يتم العثور على هذه المادة في هذا المصدر.';

  function el(tag, attrs, children) {
    var e = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      if (k === 'text') e.textContent = attrs[k];
      else if (k === 'class') e.className = attrs[k];
      else if (k.indexOf('on') === 0) e.addEventListener(k.slice(2), attrs[k]);
      else e.setAttribute(k, attrs[k]);
    });
    (children || []).forEach(function (c) { if (c != null) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }
  function normDigits(s) {
    return String(s || '').replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); }).trim();
  }
  function normWord(w) {
    return w.replace(/[ً-ٰٟـ]/g, '')
      .replace(/[أإآٱ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي').replace(/ؤ/g, 'و').replace(/ئ/g, 'ي')
      .replace(/[.,،؛;:!؟?()\[\]{}"'«»\-–—_/\\|¦*]/g, '');
  }

  /* ========== مقارنة المواد ========== */
  var root = document.getElementById('article-compare');
  if (root) initCompare(root);

  function initCompare(root) {
    var editable = root.dataset.editable === '1';
    var state = {
      diwan: { id: root.dataset.diwanId, data: null, idx: -1, msg: '' },
      qistas: { id: root.dataset.qistasId, data: null, idx: -1, msg: '' }
    };
    var opts = { diff: false, norm: true };
    var panels = {};

    var diffChk = el('input', { type: 'checkbox', id: 'ac-diff' });
    var normChk = el('input', { type: 'checkbox', id: 'ac-norm', checked: 'checked' });
    diffChk.addEventListener('change', function () { opts.diff = diffChk.checked; renderBoth(); });
    normChk.addEventListener('change', function () { opts.norm = normChk.checked; renderBoth(); });
    var toolbar = el('div', { class: 'ac-toolbar' }, [
      el('label', { class: 'check' }, [diffChk, el('span', { text: 'إبراز الكلمات المختلفة بين المادتين المعروضتين' })]),
      el('label', { class: 'check' }, [normChk, el('span', { text: 'تجاهل فروق التشكيل والهمزات وعلامات الترقيم عند الإبراز' })])
    ]);
    var summary = el('div', { class: 'ac-summary', 'aria-live': 'polite' });
    var wrap = el('div', { class: 'ac-panels' });
    root.appendChild(toolbar);
    root.appendChild(summary);
    root.appendChild(wrap);
    ['diwan', 'qistas'].forEach(function (s) { panels[s] = buildPanel(s); wrap.appendChild(panels[s].root); });
    load('diwan', state.diwan.id);
    load('qistas', state.qistas.id);

    function other(s) { return s === 'diwan' ? 'qistas' : 'diwan'; }

    function buildPanel(s) {
      var p = {};
      p.root = el('div', { class: 'ac-panel ' + s, 'aria-label': SRC[s] });
      p.head = el('div', { class: 'ac-head' });
      p.title = el('div', { class: 'ac-title', text: SRC[s] });
      p.meta = el('div', { class: 'ac-meta muted small' });
      p.changeBtn = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', text: 'اختيار سجل آخر للمقارنة' });
      p.head.appendChild(p.title); p.head.appendChild(p.meta); p.head.appendChild(p.changeBtn);

      // أداة البحث اليدوي عن سجل في فهرس المصدر
      p.finder = el('div', { class: 'ac-finder', hidden: 'hidden' });
      var fNum = el('input', { type: 'text', placeholder: 'الرقم', 'aria-label': 'رقم التشريع', inputmode: 'numeric' });
      var fYear = el('input', { type: 'text', placeholder: 'السنة', 'aria-label': 'السنة', inputmode: 'numeric' });
      var fName = el('input', { type: 'text', placeholder: 'كلمات من الاسم', 'aria-label': 'كلمات من الاسم' });
      var fMag = el('input', { type: 'text', placeholder: 'عدد الجريدة', 'aria-label': 'عدد الجريدة', inputmode: 'numeric' });
      var fId = el('input', { type: 'text', placeholder: 'أو المعرّف مباشرة', 'aria-label': 'المعرّف', inputmode: 'numeric' });
      var fRes = el('ul', { class: 'ac-results' });
      var fGo = el('button', { type: 'button', class: 'btn btn-sm', text: 'بحث' });
      var fLoad = el('button', { type: 'button', class: 'btn btn-sm', text: 'تحميل بالمعرّف' });
      var fReset = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', text: 'العودة للسجل الأصلي' });
      fGo.addEventListener('click', function () {
        fRes.innerHTML = '';
        fRes.appendChild(el('li', { class: 'muted', text: 'جارٍ البحث...' }));
        var qs = 'action=search&source=' + s + '&number=' + encodeURIComponent(fNum.value) + '&year=' + encodeURIComponent(fYear.value) +
          '&name=' + encodeURIComponent(fName.value) + '&magazine=' + encodeURIComponent(fMag.value);
        fetch('api.php?' + qs, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
          fRes.innerHTML = '';
          if (!j.ok) { fRes.appendChild(el('li', { class: 'muted', text: j.message || 'تعذر البحث.' })); return; }
          if (!j.results.length) { fRes.appendChild(el('li', { class: 'muted', text: 'لا توجد نتائج في فهرس ' + SRC[s] + '. أدخل رقماً أو سنة أو كلمات من الاسم.' })); return; }
          j.results.forEach(function (r) {
            var b = el('button', { type: 'button', class: 'linklike' }, [
              (r.name || 'بدون اسم') + ' — رقم ' + (r.number || '؟') + ' لسنة ' + (r.year || '؟') + ' · جريدة ' + (r.magazine_number || '—') + ' ص ' + (r.magazine_page || '—') + ' · ' + r.count + ' مادة · معرّف ' + r.id
            ]);
            b.addEventListener('click', function () { load(s, String(r.id)); p.finder.hidden = true; });
            fRes.appendChild(el('li', null, [b]));
          });
        }).catch(function () { fRes.innerHTML = ''; fRes.appendChild(el('li', { class: 'muted', text: 'تعذر الاتصال بالخادم.' })); });
      });
      fLoad.addEventListener('click', function () { if (/^\d+$/.test(fId.value.trim())) { load(s, fId.value.trim()); p.finder.hidden = true; } });
      fReset.addEventListener('click', function () { load(s, root.dataset['default' + (s === 'diwan' ? 'Diwan' : 'Qistas')]); p.finder.hidden = true; });
      p.finder.appendChild(el('div', { class: 'muted small', text: 'بحث يدوي في فهرس ' + SRC[s] + ' (أداة مساعدة، لا تجري أي مطابقة تلقائية):' }));
      p.finder.appendChild(el('div', { class: 'ac-finder-row' }, [fNum, fYear, fMag, fName, fGo]));
      p.finder.appendChild(el('div', { class: 'ac-finder-row' }, [fId, fLoad, fReset]));
      p.finder.appendChild(fRes);
      p.changeBtn.addEventListener('click', function () { p.finder.hidden = !p.finder.hidden; });

      // التنقل بين المواد
      p.nav = el('div', { class: 'ac-nav' });
      p.prev = el('button', { type: 'button', class: 'btn btn-sm', text: '→ السابقة', 'aria-label': 'المادة السابقة' });
      p.next = el('button', { type: 'button', class: 'btn btn-sm', text: 'التالية ←', 'aria-label': 'المادة التالية' });
      p.select = el('select', { 'aria-label': 'اختيار المادة من ' + SRC[s] });
      p.search = el('input', { type: 'search', placeholder: 'ابحث برقم المادة أو بكلمة في النص', 'aria-label': 'البحث عن مادة في ' + SRC[s] });
      p.prev.addEventListener('click', function () { go(s, state[s].idx - 1); });
      p.next.addEventListener('click', function () { go(s, state[s].idx + 1); });
      p.select.addEventListener('change', function () { go(s, parseInt(p.select.value, 10)); });
      p.search.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); find(s, p.search.value); } });
      p.nav.appendChild(p.prev); p.nav.appendChild(p.select); p.nav.appendChild(p.next);
      p.searchRow = el('div', { class: 'ac-search' }, [p.search, el('button', { type: 'button', class: 'btn btn-sm', text: 'بحث', onclick: function () { find(s, p.search.value); } })]);
      p.searchMsg = el('div', { class: 'muted small', 'aria-live': 'polite' });

      p.body = el('div', { class: 'ac-body' });
      p.actions = el('div', { class: 'ac-actions' });
      if (editable) {
        var docBtn = el('button', { type: 'button', class: 'btn btn-sm btn-primary', text: 'توثيق اختلاف لهاتين المادتين' });
        docBtn.addEventListener('click', addDiffFromCompare);
        p.actions.appendChild(docBtn);
      }
      [p.head, p.finder, p.nav, p.searchRow, p.searchMsg, p.body, p.actions].forEach(function (x) { p.root.appendChild(x); });
      return p;
    }

    function load(s, id) {
      var st = state[s], p = panels[s];
      st.id = id || ''; st.data = null; st.idx = -1; st.msg = '';
      p.body.innerHTML = ''; p.body.appendChild(el('div', { class: 'muted', text: 'جارٍ تحميل المواد...' }));
      syncHidden();
      if (!st.id) { st.msg = 'لا يوجد سجل مقابل في ' + SRC[s] + ' ضمن بيانات المطابقة الأولية. يمكنك البحث عن سجل للمقارنة يدوياً عبر «اختيار سجل آخر للمقارنة».'; renderPanel(s); renderSummary(); return; }
      fetch('api.php?action=articles&source=' + s + '&id=' + encodeURIComponent(st.id), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok) { st.msg = j.message || 'تعذر تحميل المواد.'; }
          else { st.data = j; st.idx = j.articles.length ? 0 : -1; if (!j.articles.length) st.msg = 'لا توجد مواد مسجلة لهذا التشريع في ملف ' + SRC[s] + '.'; }
          fillSelect(s);
          if (state[other(s)].data && state[other(s)].idx >= 0) syncFrom(other(s)); else syncFrom(s);
          renderBoth(); renderSummary();
        })
        .catch(function () { st.msg = 'تعذر الاتصال بالخادم لتحميل المواد. أعد تحميل الصفحة.'; renderPanel(s); });
    }

    function syncHidden() {
      var f = document.getElementById('f-compare-' + 'diwan');
      var g = document.getElementById('f-compare-' + 'qistas');
      if (f && f.value !== state.diwan.id) { f.value = state.diwan.id; markDirty(); }
      if (g && g.value !== state.qistas.id) { g.value = state.qistas.id; markDirty(); }
    }

    function fillSelect(s) {
      var p = panels[s], st = state[s];
      p.select.innerHTML = '';
      var arts = st.data ? st.data.articles : [];
      arts.forEach(function (a, i) {
        var label = 'المادة ' + (a.number || '؟') + (a.deleted ? ' (محذوفة)' : '');
        p.select.appendChild(el('option', { value: String(i), text: label }));
      });
      var m = st.data ? st.data.meta : null;
      p.meta.textContent = m ? ((m.name || '') + ' — رقم ' + (m.number || '؟') + ' لسنة ' + (m.year || '؟') + ' · ' + arts.length + ' مادة · معرّف ' + m.id) : (st.id ? 'معرّف ' + st.id : '');
    }

    /* اختيار مادة في لوحة يعرض تلقائياً المادة نفسها الرقم في اللوحة الأخرى إن وُجدت؛ وإلا تبقى الأخرى كما هي. */
    function go(s, i) {
      var st = state[s];
      if (!st.data || i < 0 || i >= st.data.articles.length) return;
      st.idx = i; panels[s].searchMsg.textContent = '';
      syncFrom(s);
      renderBoth();
    }

    function syncFrom(s) {
      var o = other(s), st = state[s], so = state[o];
      if (!st.data || st.idx < 0 || !so.data) return;
      var num = normDigits(st.data.articles[st.idx].number);
      if (num === '') return;
      if (so.idx >= 0 && normDigits(so.data.articles[so.idx].number) === num) return;
      for (var k = 0; k < so.data.articles.length; k++) {
        if (normDigits(so.data.articles[k].number) === num) { so.idx = k; panels[o].searchMsg.textContent = ''; return; }
      }
    }

    function find(s, q) {
      var st = state[s], p = panels[s];
      q = normDigits(q);
      if (!st.data || !q) return;
      var arts = st.data.articles, i;
      for (i = 0; i < arts.length; i++) if (normDigits(arts[i].number) === q) { go(s, i); return; }
      var nq = q.split(/\s+/).map(normWord).join(' ');
      for (i = 0; i < arts.length; i++) {
        var j = (st.idx + 1 + i) % arts.length;
        if (arts[j].text.split(/\s+/).map(normWord).join(' ').indexOf(nq) !== -1) { go(s, j); p.searchMsg.textContent = 'تم العثور على النص في المادة ' + arts[j].number + ' (اضغط بحث مجدداً للنتيجة التالية).'; return; }
      }
      p.searchMsg.textContent = NOT_FOUND;
    }

    function current(s) {
      var st = state[s];
      return st.data && st.idx >= 0 ? st.data.articles[st.idx] : null;
    }

    function renderBoth() { renderPanel('diwan'); renderPanel('qistas'); }

    function renderPanel(s) {
      var st = state[s], p = panels[s];
      p.body.innerHTML = '';
      var disabled = !st.data || !st.data.articles.length;
      p.prev.disabled = disabled || st.idx <= 0;
      p.next.disabled = disabled || st.idx < 0 || st.idx >= st.data.articles.length - 1;
      p.select.disabled = disabled; p.search.disabled = disabled;
      if (st.idx >= 0) p.select.value = String(st.idx);
      if (!st.data || st.idx < 0) {
        p.body.appendChild(el('div', { class: 'empty-note', text: st.msg || NOT_FOUND }));
        return;
      }
      var a = st.data.articles[st.idx];
      var metaItems = [];
      if (a.title) metaItems.push('العنوان في المصدر: ' + a.title);
      if (a.enforcement_date) metaItems.push('تاريخ النفاذ: ' + a.enforcement_date);
      if (a.id) metaItems.push('معرّف المادة: ' + a.id);
      if (a.deleted) metaItems.push('مُعلّمة كمحذوفة في المصدر');
      if (st.data.meta.duplicate_numbers && st.data.meta.duplicate_numbers.indexOf(a.number) !== -1) metaItems.push('تنبيه: رقم المادة مكرر في هذا المصدر');
      var textBox = el('div', { class: 'ac-text', lang: 'ar' });
      var o = current(other(s));
      if (opts.diff && o && state[other(s)].idx >= 0) renderDiff(textBox, a.text, o.text);
      else textBox.textContent = a.text || '(نص المادة فارغ في المصدر)';
      p.body.appendChild(el('div', { class: 'ac-art-head' }, [el('strong', { text: 'المادة ' + (a.number || '؟') }), el('span', { class: 'muted small', text: ' — ' + SRC[s] })]));
      if (metaItems.length) p.body.appendChild(el('ul', { class: 'ac-art-meta' }, metaItems.map(function (m) { return el('li', { text: m }); })));
      p.body.appendChild(textBox);
      if (a.old_text) {
        var det = el('details', { class: 'ac-old' }, [el('summary', { text: 'عرض النص السابق للمادة (OldArticle)' }), el('div', { class: 'ac-text', text: a.old_text })]);
        p.body.appendChild(det);
      }
    }

    /* إبراز الكلمات غير المشتركة (LCS على مستوى الكلمات). أداة بصرية فقط. */
    function renderDiff(box, a, b) {
      var A = (a || '').split(/(\s+)/), B = (b || '').split(/(\s+)/);
      var wa = [], wb = [], ia = [], ib = [];
      A.forEach(function (t, i) { if (t.trim()) { wa.push(opts.norm ? normWord(t) : t); ia.push(i); } });
      B.forEach(function (t, i) { if (t.trim()) { wb.push(opts.norm ? normWord(t) : t); } });
      var n = wa.length, m = wb.length;
      if (n * m > 4000000) { box.textContent = a; box.appendChild(el('div', { class: 'muted small', text: '(النص طويل جداً لإبراز الفروق)' })); return; }
      var dp = new Array(n + 1);
      for (var i = 0; i <= n; i++) dp[i] = new Uint32Array(m + 1);
      for (i = n - 1; i >= 0; i--) for (var j = m - 1; j >= 0; j--)
        dp[i][j] = wa[i] === wb[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
      var common = {}; i = 0; j = 0;
      while (i < n && j < m) {
        if (wa[i] === wb[j]) { common[ia[i]] = true; i++; j++; }
        else if (dp[i + 1][j] >= dp[i][j + 1]) i++; else j++;
      }
      A.forEach(function (t, k) {
        if (!t.trim() || common[k]) box.appendChild(document.createTextNode(t));
        else box.appendChild(el('mark', { class: 'diff', text: t }));
      });
    }

    function renderSummary() {
      summary.innerHTML = '';
      if (!state.diwan.data || !state.qistas.data) return;
      var dn = state.diwan.data.articles.map(function (a) { return normDigits(a.number); });
      var qn = state.qistas.data.articles.map(function (a) { return normDigits(a.number); });
      var onlyD = dn.filter(function (x, i) { return qn.indexOf(x) === -1 && dn.indexOf(x) === i; });
      var onlyQ = qn.filter(function (x, i) { return dn.indexOf(x) === -1 && qn.indexOf(x) === i; });
      summary.appendChild(el('div', { class: 'small' }, ['عدد المواد: الديوان ' + dn.length + ' · قسطاس ' + qn.length + '. المقارنة هنا بأرقام المواد فقط، وقد تختلف الأرقام دون أن تختلف المواد.']));
      [['أرقام موجودة في الديوان وغير موجودة في قسطاس:', onlyD, 'diwan'], ['أرقام موجودة في قسطاس وغير موجودة في الديوان:', onlyQ, 'qistas']].forEach(function (row) {
        var line = el('div', { class: 'chips' }, [el('span', { class: 'small', text: row[0] + ' ' })]);
        if (!row[1].length) line.appendChild(el('span', { class: 'muted small', text: 'لا يوجد' }));
        row[1].slice(0, 80).forEach(function (num) {
          line.appendChild(el('button', { type: 'button', class: 'chip', text: num, onclick: function () { find(row[2], num); } }));
        });
        if (row[1].length > 80) line.appendChild(el('span', { class: 'muted small', text: ' و' + (row[1].length - 80) + ' أخرى' }));
        summary.appendChild(line);
      });
    }

    function addDiffFromCompare() {
      var a = current('diwan'), b = current('qistas');
      var type = a && b ? 'text_difference' : (a ? 'missing_in_qistas' : 'missing_in_diwan');
      addDiffRow(a ? a.number : '', b ? b.number : '', type);
    }
  }

  /* ========== النموذج ========== */
  var form = document.getElementById('review-form');
  var dirty = false;
  function markDirty() { if (form) { dirty = true; var s = document.getElementById('save-state'); if (s) s.textContent = 'توجد تغييرات غير محفوظة'; } }
  window.markDirty = markDirty;

  function addDiffRow(da, qa, type) {
    var tpl = document.getElementById('diff-template');
    var box = document.getElementById('diffs');
    if (!tpl || !box) return;
    var node = tpl.content.firstElementChild.cloneNode(true);
    node.querySelector('[name="diff_diwan[]"]').value = da || '';
    node.querySelector('[name="diff_qistas[]"]').value = qa || '';
    if (type) node.querySelector('[name="diff_type[]"]').value = type;
    box.appendChild(node);
    node.querySelector('textarea').focus();
    markDirty();
  }

  if (form) {
    form.addEventListener('input', markDirty);
    form.addEventListener('change', markDirty);
    form.addEventListener('submit', function (e) {
      var btn = e.submitter;
      var msg = btn && btn.dataset.confirm;
      if (btn && btn.value === 'submit') {
        var un = unresolvedList();
        if (un.length) msg = 'تنبيه: يوجد ' + un.length + ' حقل غير محسوم (لم تُحدَّد قيمته المعتمدة):\n- ' + un.join('\n- ') + '\n\nهل تريد إرسال النتيجة رغم ذلك؟ يمكن للمسؤول إعادتها لك للاستكمال.';
      }
      if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
      dirty = false;
      form.querySelectorAll('button[type=submit]').forEach(function (b) { b.disabled = true; });
      // زر الإرسال المعطّل لا يُرسَل؛ نضيف قيمته في حقل مخفي
      if (btn) { var h = document.createElement('input'); h.type = 'hidden'; h.name = btn.name; h.value = btn.value; form.appendChild(h); }
      var s = document.getElementById('save-state'); if (s) s.textContent = 'جارٍ الحفظ...';
    });
    window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
    document.getElementById('add-diff').addEventListener('click', function () { addDiffRow('', '', 'text_difference'); });
    form.addEventListener('click', function (e) {
      var t = e.target;
      if (t.classList && t.classList.contains('remove-row')) { t.closest('.repeat-row').remove(); markDirty(); }
    });
  }

  /* ========== دوائر ✓ ✗ ✎ في البطاقتين ========== */
  function stEl(fk) { return document.querySelector('.av-state[data-f="' + fk + '"]'); }
  function inp(fk, n) { return stEl(fk).querySelector('input[name="' + n + '[' + fk + ']"]'); }
  function getSt(fk) {
    var w = inp(fk, 'av_wrong').value;
    return { src: inp(fk, 'av_src').value, wrong: w ? w.split(',') : [], val: inp(fk, 'av_val').value, card: inp(fk, 'av_card').value,
      equal: stEl(fk).dataset.equal === '1',
      present: Array.prototype.map.call(document.querySelectorAll('.ic[data-f="' + fk + '"]'), function (x) { return x.dataset.c; }) };
  }
  function putSt(fk, st) {
    inp(fk, 'av_src').value = st.src; inp(fk, 'av_wrong').value = st.wrong.join(',');
    inp(fk, 'av_val').value = st.val; inp(fk, 'av_card').value = st.card;
    paintField(fk); markDirty();
  }
  function paintField(fk) {
    var st = getSt(fk);
    document.querySelectorAll('.ic[data-f="' + fk + '"]').forEach(function (box) {
      var c = box.dataset.c;
      var on = { ok: st.src === c || ((st.src === 'diwan' || st.src === 'qistas') && st.equal), no: st.wrong.indexOf(c) !== -1, pe: st.src === 'manual' && st.card === c };
      box.querySelectorAll('.icb').forEach(function (b) { var v = on[b.dataset.s]; b.classList.toggle('on', v); b.setAttribute('aria-pressed', v ? 'true' : 'false'); });
      var mv = document.querySelector('.mv[data-mv="' + fk + '"][data-c="' + c + '"]');
      if (mv) { mv.hidden = !on.pe; mv.querySelector('strong').textContent = on.pe ? st.val : ''; }
    });
  }
  function unresolvedList() {
    var out = [];
    document.querySelectorAll('.av-state[data-need="1"]').forEach(function (n) { if (!getSt(n.dataset.f).src) out.push(n.dataset.label); });
    return out;
  }
  var sources = document.getElementById('sources');
  if (sources && form) {
    sources.addEventListener('click', function (e) {
      var save = e.target.closest('[data-save]');
      if (save) {
        var box = save.closest('.mv-edit'), fk = box.dataset.edit, c = box.dataset.c, st = getSt(fk);
        var v = box.querySelector('input').value.trim();
        if (v) { st.src = 'manual'; st.val = v; st.card = c; st.wrong = st.present.slice(); }
        else if (st.src === 'manual') { st.src = ''; st.val = ''; st.card = ''; }
        box.hidden = true; putSt(fk, st); return;
      }
      var b = e.target.closest('.icb'); if (!b) return;
      var ic = b.closest('.ic'), fk = ic.dataset.f, c = ic.dataset.c, s2 = b.dataset.s, st = getSt(fk);
      var other = c === 'diwan' ? 'qistas' : 'diwan';
      if (s2 === 'pe') {
        var ed = document.querySelector('.mv-edit[data-edit="' + fk + '"][data-c="' + c + '"]');
        ed.hidden = !ed.hidden;
        if (!ed.hidden) { var t = ed.querySelector('input'); t.value = st.src === 'manual' ? st.val : ''; t.focus(); }
        return;
      }
      if (s2 === 'ok') {
        var isOn = st.src === c || ((st.src === 'diwan' || st.src === 'qistas') && st.equal);
        if (isOn) { st.src = ''; }
        else {
          st.src = c; st.val = ''; st.card = '';
          st.wrong = st.wrong.filter(function (x) { return x !== c; });
          if (!st.equal && st.present.indexOf(other) !== -1 && st.wrong.indexOf(other) === -1) st.wrong.push(other);
        }
      } else {
        var i = st.wrong.indexOf(c);
        if (i === -1) { st.wrong.push(c); if (st.src === c || ((st.src === 'diwan' || st.src === 'qistas') && st.equal)) st.src = ''; }
        else st.wrong.splice(i, 1);
      }
      putSt(fk, st);
    });
    sources.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && e.target.closest('.mv-edit')) { e.preventDefault(); e.target.closest('.mv-edit').querySelector('[data-save]').click(); }
    });
  }

  document.querySelectorAll('form:not(#review-form) [data-confirm]').forEach(function (b) {
    b.addEventListener('click', function (e) { if (!window.confirm(b.dataset.confirm)) e.preventDefault(); });
  });
})();
