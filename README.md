# Conversor Universal → Moodle XML

Convierte exámenes en Word (.docx), PDF o TXT en un archivo **Moodle XML** (opción múltiple y ensayo, con puntajes,
imágenes y ecuaciones incluidas).

## Instalación
1. Copia la carpeta en tu servidor (XAMPP, hosting, etc.) con **PHP 7.4+** y las extensiones `zip` y `dom`.
2. Abre `index.php` en el navegador. No hay base de datos.
3. Opcional: `pdftotext` (poppler-utils) mejora la lectura de PDF; la extensión `gd` recorta imágenes PNG convertidas.

## Estructura (MVC)
```
index.php                  Controlador frontal
app/bootstrap.php          Arranque: autocarga de clases y sesión
app/Core/                  Config, Request, Router, View, ViewHelper, Text, Patterns, Shell, Uid
app/Controllers/           PageController, UploadController, ReviewController, ExportController
app/Models/                QuizRepository (datos en sesión), ReviewEditor (aplica correcciones)
app/Services/              UploadService (lee archivos y fragmentos)
app/Readers/               DocxReader, PdfReader, PlainTextReader (documento → líneas)
app/Parsing/               QuestionExtractor, AnswerResolver, AnswerKey, KeyLinker, DocumentParser
app/Images/                VectorImage (WMF/EMF → SVG en PHP), ImageConverter, ImageStore
app/Export/                MoodleXmlExporter, ExportService, ExportUtil, Downloader
app/Views/                 layout, upload, review y partials (unit, question)
assets/                    css/app.css · js/common.js, upload.js, review.js
tests/                     run_corpus.php + corpus/ (documentos de prueba con su resultado esperado)
```

## Flujo
`index.php` → `Router` → `Controller` → (`UploadService` / `ReviewEditor` / `ExportService`) → `View`.

## Notas técnicas
- **Autoguardado**: cada cambio se envía solo (JSON, unos instantes después de editar). No hay botón "Guardar".
- El **nombre de cada unidad** es editable (arriba de sus preguntas): es la categoría que Moodle crea al importar.
- Las confirmaciones y avisos usan ventanas propias (`ui.confirm` / `ui.notice` en `assets/js/common.js`), no las del navegador.
- Cada pregunta tiene un **id**; agregar y eliminar preguntas se hace al instante sin desordenar las demás.
- Sólo viajan las filas modificadas, en **un solo campo JSON** (`payload`): no se supera `max_input_vars`.
- La exportación incluye todo (opción múltiple y ensayo) con una categoría de Moodle por unidad. Si hay preguntas
  incompletas (sin enunciado o con menos de 2 opciones) se avisa antes de descargar.
- Los archivos se suben **por fragmentos de 512 KB**: no dependen de `upload_max_filesize`.
- Cada ingreso a la página empieza de cero (los datos viven sólo en la sesión).

## Reconocimiento de documentos
Prueba de formatos: `php tests/run_corpus.php` (37 documentos: numeraciones, opciones, claves finales, verdadero/falso,
tablas de Word, PDF con líneas partidas, etc.). Para agregar un caso, añade el archivo a `tests/corpus/` y su resultado
esperado a `expected.json` (índice de la respuesta correcta, `"E"` si es ensayo, `null` si no debe tener respuesta).
