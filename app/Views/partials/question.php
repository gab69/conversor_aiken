<?php
$isEssay = ($q['type'] === Config::TYPE_ESSAY);
$src     = $q['source'] ?? 'none';
$sure    = !empty($q['confident']);
$kind    = $isEssay ? 'essay' : ($sure ? 'mcq' : 'mcq unsure');
$rows    = max(2, min(12, substr_count($q['text'], "\n") + 1 + (int)ceil(strlen($q['text']) / 90)));
?>
<div class="qrow <?= (!$isEssay && !$sure) ? 'unsure' : '' ?>" data-k="<?= $kind ?>" data-bi="<?= $bi ?>" data-id="<?= $h($q['id']) ?>">
  <div class="qhead">
    <span class="num"><?= $qi + 1 ?></span>
    <textarea class="qtext auto" data-f="text" rows="<?= $rows ?>" placeholder="Enunciado (cada línea es un párrafo)"><?= $h($q['text']) ?></textarea>
    <?php if ($isEssay): ?><span class="badge b-info status">ensayo</span>
    <?php elseif ($sure && $src === 'clave_final'): ?><span class="badge b-info status">clave final</span>
    <?php elseif ($sure && $src === 'respuesta'): ?><span class="badge b-info status">línea "Respuesta"</span>
    <?php elseif ($sure && $src === 'manual'): ?><span class="badge b-ok status">confirmada</span>
    <?php elseif ($sure): ?><span class="badge b-ok status">detectada</span>
    <?php else: ?><span class="badge b-warn status"><?= ($q['text'] === '' ? 'nueva' : 'revisar') ?></span><?php endif; ?>
    <div class="tools">
      <?php if (!$isEssay): ?>
        <select data-f="type"><option value="mcq">Opción múltiple</option><option value="essay">→ Ensayo</option></select>
      <?php endif; ?>
      <label class="pt">Pts <input class="ptin" data-f="grade" type="number" step="0.01" min="0" value="<?= $h(ViewHelper::grade($q['grade'] ?? 1)) ?>"></label>
      <button type="button" class="delq" title="Eliminar pregunta">🗑</button>
    </div>
  </div>

  <div class="subrow">
    <label class="chk" title="Une el enunciado y sus ítems en un solo párrafo (recomendado en preguntas de ordenar)">
      <input type="checkbox" data-f="oneline" <?= !empty($q['oneline']) ? 'checked' : '' ?>> Un solo párrafo</label>
  </div>

  <?php if (($q['ctx'] ?? '') !== ''): ?>
    <details class="ctxbox"><summary>📖 Texto de lectura (se muestra antes de la pregunta)</summary>
      <textarea class="ctxta auto" data-f="ctx" rows="5"><?= $h($q['ctx']) ?></textarea></details>
  <?php endif; ?>

  <?php if (strpos($q['text'], '⟦img:') !== false): ?><div class="prev"><?= ViewHelper::rich($q['text'], $images) ?></div><?php endif; ?>

  <?php if ($isEssay): ?>
    <input type="text" class="gi" data-f="grader" value="<?= $h($q['grader'] ?? '') ?>" placeholder="Guía o respuesta esperada para el docente (opcional)">
  <?php else: ?>
    <div class="opts" data-radio="r_<?= $h($q['id']) ?>">
      <?php foreach ($q['options'] as $oi => $optText): ?>
        <div class="opt optrow">
          <input type="radio" name="r_<?= $h($q['id']) ?>" <?= $oi === $q['correct'] ? 'checked' : '' ?> title="Respuesta correcta">
          <span class="l"><?= Config::LETTERS[$oi] ?>)</span>
          <input class="optin" type="text" value="<?= $h($optText) ?>" placeholder="Opción">
          <?php if (strpos($optText, '⟦img:') !== false): ?><span class="oprev"><?= ViewHelper::rich($optText, $images) ?></span><?php endif; ?>
          <button type="button" class="rm" title="Quitar opción">✕</button>
        </div>
      <?php endforeach; ?>
      <button type="button" class="addopt btn ghost sm">＋ Añadir opción</button>
    </div>
  <?php endif; ?>
</div>
