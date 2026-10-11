<?php
/**
 * Prueba de reconocimiento: lee cada documento de tests/corpus y compara lo detectado
 * (cantidad de preguntas y respuesta correcta) con lo esperado en expected.json.
 * Uso:  php tests/run_corpus.php
 */
require dirname(__DIR__) . '/app/bootstrap_cli.php';

$cases = json_decode(file_get_contents(__DIR__ . '/corpus/expected.json'), true);
$ok = 0;
foreach ($cases as $name => $c) {
    try {
        $questions = DocumentParser::parse(__DIR__ . '/corpus/' . $c['file'], $c['ext'])['questions'];
    } catch (Throwable $e) {
        echo "FAIL $name  EXCEPCIÓN: " . $e->getMessage() . "\n"; continue;
    }
    $got = array_map(function ($q) { return $q['type'] === 'essay' ? 'E' : ($q['confident'] ? $q['correct'] : null); }, $questions);
    if ($got === $c['exp']) { $ok++; echo "OK   $name\n"; }
    else echo "FAIL $name  esperado=" . json_encode($c['exp']) . " obtenido=" . json_encode($got) . "\n";
}
echo "\n$ok / " . count($cases) . " correctos\n";
exit($ok === count($cases) ? 0 : 1);
