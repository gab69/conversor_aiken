<div class="card">
  <h2>Sube tus documentos</h2>
  <span class="muted">Sube todos los documentos que quieras. Cada uno se trata como una unidad.</span>
  <form method="post" enctype="multipart/form-data" id="upForm" data-has-pending="<?= $keys ? '1' : '0' ?>">
    <input type="hidden" name="action" value="upload">
    <label class="drop" id="drop">
      <div class="ico">📂</div>
      <strong>Arrastra tus archivos aquí o haz clic para elegirlos</strong>
      <span class="muted">Puedes añadir más archivos varias veces: no hay límite de cantidad.</span>
      <span class="muted">.docx · .pdf · .txt — hasta <?= Config::MAX_FILE_BYTES / 1048576 ?> MB cada uno</span>
      <input type="file" name="docs[]" id="docs" accept=".docx,.pdf,.txt" multiple>
      <ul class="files" id="fileList"></ul>
    </label>
    <?php foreach ($keys as $k): ?>
      <div class="keyinfo">🔑 Clave cargada: <b><?= $h($k['filename']) ?></b> (<?= count($k['map']) ?> respuestas). Ahora sube el documento con las preguntas.</div>
    <?php endforeach; ?>
    <div class="prog" id="prog" hidden><i id="progBar"></i></div>
    <p class="muted ptxt" id="progTxt" hidden></p>
    <div class="alert" id="clientErr" hidden style="margin-top:14px"></div>
    <div class="center"><button class="btn" id="goBtn" type="submit" disabled>Procesar documentos →</button></div>
  </form>
</div>

