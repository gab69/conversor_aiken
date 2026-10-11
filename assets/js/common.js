/* Muestra los avisos que dejó la subida de archivos antes de recargar la página. */
(function () {
  try {
    var saved = sessionStorage.getItem('upErrs');
    if (!saved) return;
    sessionStorage.removeItem('upErrs');
    var box = document.getElementById('flashErr');
    JSON.parse(saved).forEach(function (html) {
      var d = document.createElement('div'); d.className = 'alert'; d.innerHTML = '⚠️ ' + html; box.appendChild(d);
    });
  } catch (e) { /* sin avisos */ }
})();


/* Ventanas de confirmación y aviso con el diseño de la aplicación (en lugar de confirm() / alert() del navegador). */
window.ui = (function () {
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text) n.textContent = text; return n; }

  function open(o) {
    return new Promise(function (resolve) {
      var previous = document.activeElement;
      var back = el('div', 'modal-back'), box = el('div', 'modal' + (o.danger ? ' danger' : ''));
      box.setAttribute('role', 'dialog'); box.setAttribute('aria-modal', 'true');
      box.appendChild(el('div', 'm-ico', o.icon || 'ℹ️'));
      box.appendChild(el('h3', '', o.title));
      box.appendChild(el('p', '', o.message));
      if (o.quote) box.appendChild(el('div', 'm-quote', o.quote));

      var actions = el('div', 'm-actions'), cancel = null;
      if (o.cancelText) { cancel = el('button', 'btn grey sm', o.cancelText); cancel.type = 'button'; actions.appendChild(cancel); }
      var ok = el('button', 'btn sm' + (o.danger ? ' red' : ''), o.confirmText || 'Aceptar'); ok.type = 'button'; actions.appendChild(ok);
      box.appendChild(actions); back.appendChild(box); document.body.appendChild(back);

      function close(value) {
        document.removeEventListener('keydown', onKey, true); back.remove();
        if (previous && previous.focus) previous.focus();
        resolve(value);
      }
      function onKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); close(false); }
        else if (e.key === 'Tab' && cancel) { e.preventDefault(); (document.activeElement === ok ? cancel : ok).focus(); }
      }
      ok.addEventListener('click', function () { close(true); });
      if (cancel) cancel.addEventListener('click', function () { close(false); });
      back.addEventListener('mousedown', function (e) { if (e.target === back) close(false); });
      document.addEventListener('keydown', onKey, true);
      (cancel && o.danger ? cancel : ok).focus();                     // en acciones destructivas el foco empieza en "Cancelar"
    });
  }

  return {
    /** Pregunta Aceptar / Cancelar. Devuelve una promesa con true o false */
    confirm: function (o) { return open(Object.assign({ cancelText: 'Cancelar', confirmText: 'Aceptar' }, o)); },
    /** Aviso con un solo botón */
    notice: function (o) { return open(Object.assign({ confirmText: 'Entendido' }, o)).then(function () {}); }
  };
})();
