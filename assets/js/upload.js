/* Pantalla de subida: lista de archivos acumulable, arrastrar y soltar y envío por fragmentos. */
(function () {
  var form = document.getElementById('upForm'),
      inp = document.getElementById('docs'), drop = document.getElementById('drop'),
      list = document.getElementById('fileList'), go = document.getElementById('goBtn'),
      prog = document.getElementById('prog'), bar = document.getElementById('progBar'),
      ptxt = document.getElementById('progTxt'), cerr = document.getElementById('clientErr'),
      hasPending = form.dataset.hasPending === '1',
      CHUNK = 512 * 1024, queue = [], busy = false;

  function esc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }

  function addFiles(fl) {
    Array.prototype.forEach.call(fl, function (f) {
      if (!queue.some(function (x) { return x.name === f.name && x.size === f.size; })) queue.push(f);
    });
    render();
  }

  function render() {
    list.innerHTML = '';
    queue.forEach(function (f, i) {
      var li = document.createElement('li'); li.textContent = '📄 ' + f.name;
      var x = document.createElement('button'); x.type = 'button'; x.textContent = '✕'; x.title = 'Quitar';
      x.onclick = function (e) { e.preventDefault(); e.stopPropagation(); if (!busy) { queue.splice(i, 1); render(); } };
      li.appendChild(x); list.appendChild(li);
    });
    go.disabled = busy || !queue.length;
    go.textContent = queue.length ? 'Procesar ' + queue.length + ' documento' + (queue.length > 1 ? 's' : '') + ' →' : 'Procesar documentos →';
  }

  function newId() {
    var a = new Uint8Array(12); (window.crypto || window.msCrypto).getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }

  /* Cada archivo se parte en fragmentos pequeños: no depende de upload_max_filesize. */
  async function sendOne(file, first, onPart) {
    var id = newId(), total = Math.max(1, Math.ceil(file.size / CHUNK));
    for (var i = 0; i < total; i++) {
      var fd = new FormData();
      fd.append('action', 'upload_chunk'); fd.append('uid', id); fd.append('index', i);
      fd.append('total', total); fd.append('name', file.name);
      if (!first) fd.append('append', '1');
      fd.append('chunk', file.slice(i * CHUNK, (i + 1) * CHUNK), 'chunk.bin');
      try {
        var res = await fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' });
        var j = await res.json();
        if (!j.ok) return { ok: false, errors: j.errors || [] };
      } catch (e) {
        return { ok: false, errors: ['Error al subir "' + esc(file.name) + '" (conexión o límite del servidor).'] };
      }
      onPart((i + 1) / total);
    }
    return { ok: true, errors: [] };
  }

  inp.addEventListener('change', function () { addFiles(inp.files); inp.value = ''; });
  ['dragenter', 'dragover'].forEach(function (e) { drop.addEventListener(e, function (ev) { ev.preventDefault(); drop.classList.add('over'); }); });
  ['dragleave', 'drop'].forEach(function (e) { drop.addEventListener(e, function (ev) { ev.preventDefault(); drop.classList.remove('over'); }); });
  drop.addEventListener('drop', function (ev) { addFiles(ev.dataTransfer.files); });

  form.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    if (busy || !queue.length) return;
    busy = true; go.disabled = true; cerr.hidden = true; prog.hidden = false; ptxt.hidden = false;
    var errs = [], okCount = 0, total = queue.length;
    for (var i = 0; i < total; i++) {
      ptxt.textContent = 'Procesando ' + (i + 1) + ' de ' + total + ': ' + queue[i].name;
      var r = await sendOne(queue[i], i === 0 && !hasPending, function (f) { bar.style.width = Math.round(((i + f) / total) * 100) + '%'; });
      if (r.ok) okCount++; else errs = errs.concat(r.errors);
    }
    if (okCount === 0) {
      busy = false; prog.hidden = true; ptxt.hidden = true; bar.style.width = '0'; render();
      cerr.hidden = false; cerr.innerHTML = '⚠️ ' + errs.join('<br>⚠️ ');
      return;
    }
    if (errs.length) sessionStorage.setItem('upErrs', JSON.stringify(errs));
    location.href = location.pathname;
  });

  render();
})();
