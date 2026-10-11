<section class="unit" data-bi="<?= (int)$bi ?>">
  <div class="unit-head">
    <button type="button" class="u-toggle" title="Plegar o desplegar la unidad" aria-label="Plegar o desplegar la unidad">▾</button>
    <div class="u-main">
      <div class="u-title-wrap" title="Nombre de la categoría que Moodle creará al importar">
        <input class="u-title-input" type="text" maxlength="120" value="<?= $h($batch['label']) ?>" data-bi="<?= (int)$bi ?>"
               data-fallback="<?= $h($batch['filename']) ?>" aria-label="Nombre de la unidad (categoría en Moodle)" placeholder="Nombre de la unidad">
        <span class="u-edit" aria-hidden="true">✏️</span>
      </div>
      <div class="u-sub">Categoría en Moodle · <?= $h($batch['filename']) ?> · <span class="ucount"><?= count($batch['questions']) ?></span> preguntas</div>
      <?php if (!empty($batch['imgFailed'])): ?>
        <div class="u-sub" style="color:var(--red)">⚠️ <?= (int)$batch['imgFailed'] ?> imagen(es) no se pudieron convertir en este servidor. Se exportan como [imagen].</div>
      <?php endif; ?>
      <?php if (!empty($batch['info'])): ?><div class="u-sub">🔎 <?= $h(ViewHelper::describeInfo($batch['info'])) ?></div><?php endif; ?>
    </div>
    <button class="btn ghost sm" type="submit" form="postForm" name="action" value="download_unit" data-unit="<?= (int)$bi ?>">⬇️ Descargar unidad</button>
  </div>

  <div class="qlist" data-bi="<?= (int)$bi ?>">
    <?php foreach ($batch['questions'] as $qi => $q): ?>
      <?= View::render('partials/question', ['bi' => $bi, 'qi' => $qi, 'q' => $q, 'images' => $batch['images'] ?? []]) ?>
    <?php endforeach; ?>
  </div>

  <div class="addbar">
    <button type="button" class="btn ghost sm addq" data-bi="<?= (int)$bi ?>" data-type="mcq">＋ Pregunta de opción múltiple</button>
    <button type="button" class="btn ghost sm addq" data-bi="<?= (int)$bi ?>" data-type="essay">＋ Pregunta de ensayo</button>
  </div>
</section>
