<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= Config::TITLE ?></title>
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="hero"><div class="hero-in">
  <h1>📝 Conversor a Moodle</h1>
  <div class="steps">
    <div class="step <?= $onReview ? '' : 'on' ?>"><i>1</i>Subir</div>
    <div class="step <?= $onReview ? 'on' : '' ?>"><i>2</i>Revisar</div>
    <div class="step"><i>3</i>Descargar</div>
  </div>
</div></div>

<div class="wrap">
  <?php foreach ($errors as $err): ?><div class="alert">⚠️ <?= $err ?></div><?php endforeach; ?>
  <div id="flashErr"></div>
  <?= $content ?>
</div>

<script src="assets/js/common.js"></script>
<script src="assets/js/<?= $onReview ? 'review' : 'upload' ?>.js"></script>
</body>
</html>
