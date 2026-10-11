<div id="editor">
  <div class="card">
    <h2>Revisa lo detectado</h2>
    <span class="muted">Todo lo que cambies se guarda solo. El nombre de cada unidad (arriba de sus preguntas) es la categoría que Moodle creará; puedes editarlo. Las preguntas en rojo no tienen una respuesta clara: marca la correcta. Puedes editar enunciados, opciones y puntajes, y agregar o eliminar preguntas.</span>
    <div class="chips">
      <div class="chip"><b><?= count($batches) ?></b><span>unidades</span></div>
      <div class="chip"><b id="stTotal"><?= $stats['total'] ?></b><span>preguntas</span></div>
      <div class="chip"><b id="stMcq"><?= $stats['mcq'] ?></b><span>opción múltiple</span></div>
      <div class="chip"><b id="stEssay"><?= $stats['essay'] ?></b><span>ensayo</span></div>
      <div class="chip"><b id="totalPts">0</b><span>puntaje total</span></div>
      <div class="chip"><b id="stUnsure" style="color:<?= $stats['unsure'] ? 'var(--red)' : 'var(--brand)' ?>"><?= $stats['unsure'] ?></b><span>por confirmar</span></div>
    </div>
    <?php foreach ($keys as $k): ?>
      <div class="keyinfo">🔑 <b><?= $h($k['filename']) ?></b> — <?= !empty($k['target'])
          ? 'clave de ' . count($k['map']) . ' respuestas aplicada a <b>' . $h($k['target']) . '</b> (' . (int)$k['applied'] . ' preguntas)'
          : 'no se encontró un examen al que aplicarla' ?></div>
    <?php endforeach; ?>
    <div class="tabs" id="tabs">
      <button type="button" class="tab on" data-f="all">Todas</button>
      <button type="button" class="tab" data-f="unsure">⚠️ Por revisar</button>
      <button type="button" class="tab" data-f="mcq">Opción múltiple</button>
      <button type="button" class="tab" data-f="essay">Ensayo</button>
    </div>
  </div>

  <?php foreach ($batches as $bi => $batch): ?>
    <?= View::render('partials/unit', ['bi' => $bi, 'batch' => $batch]) ?>
  <?php endforeach; ?>
</div>

<?php /* Descargas y reinicio: el formulario sólo lleva las filas con cambios pendientes (JSON en "payload"). */ ?>
<form id="postForm" method="post">
  <input type="hidden" name="payload" id="payload">
  <input type="hidden" name="units" id="units">
  <input type="hidden" name="batch_index" id="batchIndex" value="0">
</form>

<div class="bar"><div class="bar-in">
  <label>Puntaje en bloque
    <input class="sel num" type="number" step="0.01" min="0" id="gradeMcq" value="1" title="Opción múltiple"> múltiple
    <input class="sel num" type="number" step="0.01" min="0" id="gradeEssay" value="1" title="Ensayo"> ensayo
  </label>
  <button class="btn ghost sm" type="button" id="applyGrades">Aplicar a todas</button>
  <span class="saved" id="saveState">✓ Los cambios se guardan automáticamente</span>
  <span class="sp"></span>
  <button class="btn grey sm" type="submit" form="postForm" name="action" value="reset" formnovalidate>🔄 Empezar de nuevo</button>
  <button class="btn ghost sm" type="submit" form="postForm" name="action" value="download_zip">📦 Un XML por unidad (.zip)</button>
  <button class="btn red" type="submit" form="postForm" name="action" value="download_combined">⬇️ Descargar XML</button>
</div></div>
