/* Pantalla de revisión.
   - Autoguardado: cada cambio se envía solo (JSON) unos instantes después, sin botón "Guardar".
   - Agregar / eliminar preguntas y opciones al instante.
   - Las filas viajan por su id y sólo las modificadas (así tampoco se supera max_input_vars). */
(function () {
  var LETTERS = 'ABCDEFGH';
  var editor = document.getElementById('editor');
  var form = document.getElementById('postForm');
  var stateEl = document.getElementById('saveState');
  var dirty = {};          // id de pregunta → versión del último cambio sin guardar
  var dirtyUnits = {};     // nº de unidad → versión del último cambio de nombre sin guardar
  var version = 0, timer = null, queue = Promise.resolve();

  function rows() { return document.querySelectorAll('.qrow'); }
  function field(row, name) { return row.querySelector('[data-f="' + name + '"]'); }

  // ---------- estado de guardado ----------
  function say(text, cls) { stateEl.textContent = text; stateEl.className = 'saved ' + (cls || ''); }

  // ---------- recoger las filas modificadas ----------
  function collect() {
    var edits = [];
    rows().forEach(function (row) {
      if (!dirty[row.dataset.id]) return;
      var e = { id: row.dataset.id, text: field(row, 'text').value, grade: field(row, 'grade').value, oneline: field(row, 'oneline').checked };
      var ctx = field(row, 'ctx');   if (ctx) e.ctx = ctx.value;
      var type = field(row, 'type'); if (type) e.type = type.value;
      var gr = field(row, 'grader'); if (gr) e.grader = gr.value;
      var opts = row.querySelectorAll('.optrow');
      if (opts.length) {
        e.opts = []; e.ans = null; e.ansSet = row.dataset.ansSet === '1';
        opts.forEach(function (r, i) {
          e.opts.push(r.querySelector('.optin').value);
          if (r.querySelector('input[type=radio]').checked) e.ans = i;
        });
      }
      edits.push(e);
    });
    return edits;
  }

  /** Nombres de unidad modificados */
  function collectUnits() {
    var out = [];
    document.querySelectorAll('.u-title-input').forEach(function (inp) {
      if (dirtyUnits[inp.dataset.bi]) out.push({ bi: +inp.dataset.bi, label: inp.value });
    });
    return out;
  }
  function pending() { return Object.keys(dirty).length + Object.keys(dirtyUnits).length; }

  // ---------- comunicación con el servidor ----------
  function send(action, extra, keepalive) {
    var sent = version;
    var ids = Object.keys(dirty), units = Object.keys(dirtyUnits);
    var fd = new FormData();
    fd.append('action', action);
    fd.append('payload', JSON.stringify(collect()));
    fd.append('units', JSON.stringify(collectUnits()));
    Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
    return fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin', keepalive: !!keepalive })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) throw new Error('fallo');
        ids.forEach(function (id) { if (dirty[id] <= sent) delete dirty[id]; });   // los editados mientras tanto siguen pendientes
        units.forEach(function (u) { if (dirtyUnits[u] <= sent) delete dirtyUnits[u]; });
        return j;
      });
  }

  /** Guarda ya (en cola, para que las peticiones no se crucen) */
  function flush() {
    clearTimeout(timer);
    queue = queue.then(function () {
      if (!pending()) { say('✓ Cambios guardados'); return; }
      say('Guardando…', 'busy');
      return send('autosave').then(function () {
        say(pending() ? 'Guardando…' : '✓ Cambios guardados', pending() ? 'busy' : '');
      }).catch(function () { say('⚠ No se pudo guardar: se reintentará al seguir editando', 'err'); });
    });
    return queue;
  }

  function schedule() {
    say('Guardando…', 'busy');
    clearTimeout(timer);
    timer = setTimeout(flush, 700);
  }
  function touch(row) { dirty[row.dataset.id] = ++version; schedule(); }

  // ---------- nombre de la unidad (categoría de Moodle) ----------
  document.querySelectorAll('.u-title-input').forEach(function (inp) {
    inp.addEventListener('input', function () { dirtyUnits[inp.dataset.bi] = ++version; schedule(); });
    inp.addEventListener('blur', function () {
      if (inp.value.trim() === '') { inp.value = inp.dataset.fallback; dirtyUnits[inp.dataset.bi] = ++version; schedule(); }
    });
  });

  // ---------- resumen (conteos y puntaje) ----------
  function updateStats() {
    var all = rows(), mcq = 0, essay = 0, unsure = 0, pts = 0;
    all.forEach(function (row) {
      if (row.dataset.k.indexOf('essay') > -1) essay++; else mcq++;
      if (row.classList.contains('unsure')) unsure++;
      pts += parseFloat(String(field(row, 'grade').value).replace(',', '.')) || 0;
    });
    document.getElementById('stTotal').textContent = all.length;
    document.getElementById('stMcq').textContent = mcq;
    document.getElementById('stEssay').textContent = essay;
    var u = document.getElementById('stUnsure'); u.textContent = unsure; u.style.color = unsure ? 'var(--red)' : 'var(--brand)';
    document.getElementById('totalPts').textContent = Math.round(pts * 100) / 100;
    document.querySelectorAll('.qlist').forEach(function (list) {
      list.querySelectorAll('.qrow').forEach(function (row, i) { row.querySelector('.num').textContent = i + 1; });
      list.closest('.unit').querySelector('.ucount').textContent = list.children.length;
    });
  }

  // ---------- cuadros de texto que crecen con el contenido ----------
  function fit(t) { t.style.height = 'auto'; t.style.height = (t.scrollHeight + 2) + 'px'; }
  function fitAll(root) { (root || document).querySelectorAll('textarea.auto').forEach(fit); }

  // ---------- filtros por tipo ----------
  document.getElementById('tabs').addEventListener('click', function (e) {
    var b = e.target.closest('.tab'); if (!b) return;
    document.querySelectorAll('.tab').forEach(function (t) { t.classList.toggle('on', t === b); });
    var f = b.dataset.f;
    rows().forEach(function (r) { r.style.display = (f === 'all' || r.dataset.k.split(' ').indexOf(f) > -1) ? '' : 'none'; });
  });

  // ---------- cualquier cambio dentro de una pregunta se guarda solo ----------
  function onEdit(e) {
    var row = e.target.closest ? e.target.closest('.qrow') : null;
    if (!row) return;
    if (e.target.matches('textarea.auto')) fit(e.target);
    if (e.type === 'change' && e.target.matches('input[type=radio]')) confirmAnswer(row);
    if (e.target.matches('[data-f="grade"]')) updateStats();
    touch(row);
    if (e.type === 'change' && e.target.matches('[data-f="type"]') && e.target.value === 'essay') convertNow();
  }
  editor.addEventListener('input', onEdit);
  editor.addEventListener('change', onEdit);

  /** La persona eligió la respuesta: la pregunta deja de estar "por revisar" */
  function confirmAnswer(row) {
    row.dataset.ansSet = '1';
    if (!row.classList.contains('unsure')) return;
    row.classList.remove('unsure'); row.dataset.k = 'mcq';
    var b = row.querySelector('.status'); if (b) { b.className = 'badge b-ok status'; b.textContent = 'confirmada'; }
    updateStats();
  }

  /** Pasar una pregunta a ensayo cambia su estructura: se guarda y se vuelve a dibujar la página */
  function convertNow() {
    flush().then(function () {
      var h = document.createElement('input'); h.type = 'hidden'; h.name = 'action'; h.value = 'save'; form.appendChild(h);
      document.getElementById('payload').value = '[]';
      document.getElementById('units').value = '[]';
      form.submit();
    });
  }

  // ---------- puntaje en bloque ----------
  document.getElementById('applyGrades').addEventListener('click', function () {
    var m = document.getElementById('gradeMcq').value, es = document.getElementById('gradeEssay').value;
    rows().forEach(function (row) {
      var essay = row.dataset.k.indexOf('essay') > -1, v = essay ? es : m;
      if (v === '') return;
      field(row, 'grade').value = v; touch(row);
    });
    updateStats();
  });

  // ---------- clics: opciones, eliminar y agregar preguntas, descargas ----------
  function renumber(box) {
    var list = box.querySelectorAll('.optrow'), any = false;
    list.forEach(function (r, i) {
      r.querySelector('.l').textContent = LETTERS[i] + ')';
      if (r.querySelector('input[type=radio]').checked) any = true;
    });
    if (!any && list.length) list[0].querySelector('input[type=radio]').checked = true;
    var add = box.querySelector('.addopt'); if (add) add.style.display = list.length >= LETTERS.length ? 'none' : '';
  }

  document.addEventListener('click', function (e) {
    var unit = e.target.closest('[data-unit]');
    if (unit) document.getElementById('batchIndex').value = unit.dataset.unit;

    var tg = e.target.closest('.u-toggle');
    if (tg) { tg.closest('.unit').classList.toggle('collapsed'); return; }

    var rm = e.target.closest('.rm');
    if (rm) {
      var box = rm.closest('.opts');
      if (box.querySelectorAll('.optrow').length <= 2) {
        ui.notice({ icon: 'ℹ️', title: 'Mínimo 2 opciones', message: 'Una pregunta de opción múltiple necesita al menos 2 opciones.' });
        return;
      }
      var row = rm.closest('.qrow'); rm.closest('.optrow').remove(); renumber(box); touch(row); return;
    }
    var add = e.target.closest('.addopt');
    if (add) {
      var b = add.closest('.opts'); if (b.querySelectorAll('.optrow').length >= LETTERS.length) return;
      var r = document.createElement('div'); r.className = 'opt optrow';
      r.innerHTML = '<input type="radio" title="Respuesta correcta"><span class="l"></span>' +
                    '<input class="optin" type="text" placeholder="Nueva opción"><button type="button" class="rm" title="Quitar opción">✕</button>';
      r.querySelector('input[type=radio]').name = b.dataset.radio;
      b.insertBefore(r, add); renumber(b); r.querySelector('.optin').focus(); touch(add.closest('.qrow')); return;
    }
    var del = e.target.closest('.delq');
    if (del) { deleteQuestion(del.closest('.qrow')); return; }
    var addq = e.target.closest('.addq');
    if (addq) addQuestion(addq.dataset.bi, addq.dataset.type);
  });

  function deleteQuestion(row) {
    var stem = field(row, 'text').value.replace(/\s+/g, ' ').trim();
    ui.confirm({
      icon: '🗑', danger: true, title: 'Eliminar pregunta',
      message: 'Se eliminará de la unidad y no aparecerá en el XML. Esta acción no se puede deshacer.',
      quote: 'Pregunta ' + row.querySelector('.num').textContent + ': ' + (stem ? '«' + (stem.length > 140 ? stem.slice(0, 140) + '…' : stem) + '»' : '(sin enunciado)'),
      confirmText: 'Eliminar', cancelText: 'Cancelar'
    }).then(function (yes) {
      if (!yes) return;
      var id = row.dataset.id;
      delete dirty[id];
      row.remove(); updateStats();
      queue = queue.then(function () {
        say('Guardando…', 'busy');
        return send('delete_question', { id: id }).then(function () { say('✓ Cambios guardados'); })
          .catch(function () { say('⚠ No se pudo eliminar en el servidor; recarga e inténtalo de nuevo', 'err'); });
      });
    });
  }

  function addQuestion(bi, type) {
    queue = queue.then(function () {
      say('Agregando pregunta…', 'busy');
      return send('add_question', { bi: bi, type: type }).then(function (j) {
        var list = document.querySelector('.qlist[data-bi="' + bi + '"]');
        var tmp = document.createElement('div'); tmp.innerHTML = j.html.trim();
        var row = tmp.firstElementChild; row.classList.add('fresh');
        list.appendChild(row); fitAll(row); updateStats();
        row.querySelector('[data-f="text"]').focus();
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        say('✓ Cambios guardados');
      }).catch(function () { say('⚠ No se pudo agregar la pregunta', 'err'); });
    });
  }

  // ---------- descargas y reinicio: se envían las filas pendientes ----------
  form.addEventListener('submit', function () {
    document.getElementById('payload').value = JSON.stringify(collect());
    document.getElementById('units').value = JSON.stringify(collectUnits());
  });

  // ---------- al cerrar la pestaña: último guardado con sendBeacon ----------
  window.addEventListener('pagehide', function () {
    if (!pending() || !navigator.sendBeacon) return;
    var fd = new FormData(); fd.append('action', 'autosave');
    fd.append('payload', JSON.stringify(collect())); fd.append('units', JSON.stringify(collectUnits()));
    navigator.sendBeacon(location.pathname, fd);
  });

  fitAll(); updateStats();
  document.addEventListener('toggle', function (e) {
    if (e.target.matches && e.target.matches('details.ctxbox')) fitAll(e.target);
  }, true);
})();
