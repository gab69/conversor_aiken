<?php


final class Patterns
{
    const LETTER = '/^\s*((?:\*|[✓✔☑☒✅])\s*)?(?:opci[oó]n\s+|alternativa\s+)?[\(\[]?([A-H])(?:[\)\]:]\s*(?=\S|$)|\.\s+(?=\S)|-\s+(?=\S))/iu';
    const BULLET = '/^\s*((?:[•●○◦▪■□☐☑☒✓✔✅➢➤►◆◇]|[-–—](?=\s)|\[\s*[xX✓✔]?\s*\]|\(\s*[xX✓✔]?\s*\))\s*)(?=\S)/u';

    const QNUM  = '/^\s*[\(\[]?(\d{1,3})\s*[\.\)\]:]\s+(\S.*)$/u';
    const QNUM2 = '/^\s*(\d{1,3})\s*[-–]\s+(\S.*)$/u';
    const QNUM3 = '/^\s*(\d{1,3})\s{2,}(\S.*)$/u';
    const QHEAD = '/^\s*(?:pregunta|question|ejercicio|problema|reactivo|[ií]tem)\s*(?:n[°º\.o]*\s*)?(\d{1,3})\s*[\.\)\]:\-–]?\s*(.*)$/iu';

    const NOISE = '/^(?:n[°º\.o]*|nro\.?|n[uú]mero|preguntas?|opciones(?:\s+de\s+respuestas?)?|alternativas|respuestas?|enunciado|#)$/iu';
    const EXPLANATION = '/^\s*(?:justificaci[oó]n|explicaci[oó]n|fundamento|retroalimentaci[oó]n|feedback|soluci[oó]n|comentario|sustento|c[aá]lculo|desarrollo|nota|referencia)s?\s*[:\.\-]/iu';

    const ANSWER = '/^\s*(?:respuesta\s+correcta|alternativa\s+correcta|opci[oó]n\s+correcta|respuesta|rpta\.?|resp\.?|clave|correcta|answer|ans\.?|sol\.?|r)\s*[:=\-–]\s*(.+)$/iu';
    const ANSWER_ALT  = '/^\s*(?:respuesta(?:\s+correcta)?|rpta\.?|answer)\s+[\(\[]?([A-H])[\)\]\.]?\s*$/iu';
    const ANSWER_LTR  = '/^[\(\[]?([A-H])(?:[\)\]\.:,;]|\s*$|\s+[-–]\s)/iu';
    const KEY_HEADING = '/^\W*(?:claves?(?:\s+(?:r[aá]pida|de\s+(?:las\s+)?(?:respuestas?|correcci[oó]n)))?|hoja\s+de\s+respuestas|solucionario|soluciones|respuestas?(?:\s+correctas)?|answer\s*key|respuestario|tabla\s+de\s+respuestas|gabarito)\b[\s:.\-–]*/iu';

    const ESSAY_VERB = '/^\s*¿?\s*(?:explique|explica|expliquen|describa|describe|analice|analiza|argumente|argumenta|discuta|justifique|justifica|desarrolle|desarrolla|compare|compara|elabore|elabora|redacte|redacta|comente|comenta|fundamente|fundamenta|defina|define|enumere|enumera|resuma|resume|eval[uú]e|eval[uú]a|sustente|sustenta|identifique|se[ñn]ale|mencione|menciona|cite|exponga|expone|detalle|relacione|relaciona|plantee|proponga|propone|dise[ñn]e|interprete|interpreta|sintetice|caracterice|clasifique|ejemplifique|valore|reflexione|opine|demuestre|demuestra|calcule|resuelva|determine|halle|construya|indique|precise|distinga|diferencie|qu[eé]|c[oó]mo|por\s*qu[eé]|cu[aá]l(?:es)?|cu[aá]nt[oa]s?|qui[eé]n(?:es)?|d[oó]nde|cu[aá]ndo|explain|discuss|analy[sz]e|evaluate|justify|write|outline|summari[sz]e|why|how|what|which)\b/iu';
    const GRADER     = '/^\s*(?:respuesta\s+(?:esperada|modelo|sugerida|tipo|desarrollada)|criterios?(?:\s+de\s+(?:evaluaci[oó]n|calificaci[oó]n|correcci[oó]n))?|r[uú]brica|gu[ií]a\s+de\s+(?:calificaci[oó]n|correcci[oó]n|respuesta)|indicadores?(?:\s+de\s+logro)?|pauta(?:\s+de\s+correcci[oó]n)?)\s*[:\.\-–]\s*(.*)$/iu';
    const CTX_HEAD   = '/^\s*(?:texto|lectura|text|reading|fragmento|pasaje|passage|read\s+the\s+(?:text|passage))\s*(?:n[°º\.o]*\s*)?\d*\s*[:\.\-–]?\s*$/iu';
    const CTX_REF    = '/\b(?:(?:del|al|el|este|dicho|seg[uú]n(?:\s+el)?|en\s+el|the|this)\s+(?:texto|text|fragmento|pasaje|passage|lectura)|(?:el|del|al|the)\s+(?:autor|author))\b/iu';
    const ORDER      = '/\b(?:ordena|ordenar|ordene|ordenen|ordenan|ordenamiento|order|reorder|arrange|sort|secuencia\s+correcta)\b/iu';
    const TRUE_FALSE = '/\(\s*v\s*(?:\/|o|y|-|,)\s*f\s*\)|\bverdadero\s*(?:o|\/|-|y)\s*falso\b|\btrue\s*(?:or|\/)\s*false\b|\bv\s*\/\s*f\b/iu';
    const TF_TAG     = '/\s*\(\s*v\s*(?:\/|o|y|-|,)\s*f\s*\)/iu';
    const POINTS     = '/[\(\[]\s*(\d+(?:[\.,]\d+)?)\s*(?:puntos?|pts?\.?|p\.|points?|marks?)\s*[\)\]]/iu';
}
