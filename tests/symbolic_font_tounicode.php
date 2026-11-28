<?php

/**
 * Checks that the symbolic standard fonts, Symbol and ZapfDingbats, carry a ToUnicode CMap,
 * both as core fonts and as the fonts embedded in PDF/A mode.
 *
 * Their glyph names are not Unicode names, so without the CMap the text drawn with them
 * cannot be extracted, and PDF/UA-1 (ISO 14289-1, 7.21.7) fails on every glyph used: a check
 * mark drawn with ZapfDingbats "4", for one.
 *
 * The expected mappings are read from the encodings Adobe publishes, kept in tests/fixtures,
 * so that they do not come from the tables under test.
 *
 * Usage: php tests/symbolic_font_tounicode.php [output.pdf]
 * With an output file, also writes a PDF/A-1b document that uses both fonts, for veraPDF.
 * Exits with code 0 when all checks pass, 1 otherwise.
 */

require_once dirname(__DIR__) . '/tcpdf.php';

$failures = 0;

function check($name, $condition)
{
    global $failures;
    echo ($condition ? 'PASS' : 'FAIL') . ': ' . $name . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}

/**
 * Read a unicode.org vendor mapping: for each code, its Unicode values in file order
 * (as four lower case hex digits) and its PostScript glyph name.
 */
function readVendorMapping($file)
{
    $codes = array();
    foreach (file($file) as $line) {
        if (($line[0] === '#') || (trim($line) === '')) {
            continue;
        }
        $fields = explode("\t", rtrim($line, "\r\n"));
        $code = hexdec($fields[1]);
        $codes[$code]['unicode'][] = strtolower($fields[0]);
        $glyph = preg_split('#\s+#', trim(ltrim(trim($fields[3]), '#')));
        $codes[$code]['name'] = $glyph[0];
    }
    return $codes;
}

/**
 * The ZapfDingbats mapping: the glyph named for each code, mapped by Adobe's Zapf Dingbats glyph
 * list. The list gives the ornament brackets (0x80-0x8D) the code points Unicode has had for them
 * since 3.2, where the older vendor mapping still has Corporate Use values. The list has no space.
 */
function expectedZapfDingbats($fixtures)
{
    $glyphs = array();
    foreach (file($fixtures . 'agl-zapfdingbats.txt') as $line) {
        if (($line[0] === '#') || (trim($line) === '')) {
            continue;
        }
        list($name, $unicode) = explode(';', trim($line));
        $glyphs[$name] = strtolower($unicode);
    }
    $expected = array();
    foreach (readVendorMapping($fixtures . 'adobe-zdingbat.txt') as $code => $entry) {
        $expected[$code] = isset($glyphs[$entry['name']]) ? $glyphs[$entry['name']] : $entry['unicode'][0];
    }
    return $expected;
}

/**
 * The Symbol mapping. Where Adobe gives a code two values, the Greek letter is taken
 * (Delta, Omega and mu rather than increment, ohm and micro), as Symbol is a Greek font;
 * otherwise the first one (space rather than no-break space).
 */
function expectedSymbol($fixtures)
{
    $expected = array();
    foreach (readVendorMapping($fixtures . 'adobe-symbol.txt') as $code => $entry) {
        $expected[$code] = $entry['unicode'][0];
        foreach ($entry['unicode'] as $unicode) {
            if ((hexdec($unicode) >= 0x0370) && (hexdec($unicode) <= 0x03FF)) {
                $expected[$code] = $unicode;
                break;
            }
        }
    }
    return $expected;
}

/**
 * Generate a page with a check mark and a space in ZapfDingbats, an alpha in Symbol
 * and a letter in Helvetica.
 */
function generatePdf($pdfa, $compress = true)
{
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false, $pdfa);
    $pdf->setCompression($compress);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->setFont('zapfdingbats', '', 12);
    $pdf->Write(0, '4 ');
    $pdf->setFont('symbol', '', 12);
    $pdf->Write(0, 'a');
    $pdf->setFont('helvetica', '', 12);
    $pdf->Write(0, 'A');
    return $pdf->Output('test.pdf', 'S');
}

/**
 * Generate a form with a check box field, which TCPDF draws in ZapfDingbats.
 */
function generateFormPdf()
{
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false, false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->setFont('helvetica', '', 12);
    $pdf->CheckBox('agree', 5, true);
    return $pdf->Output('test.pdf', 'S');
}

/**
 * Return the objects of a PDF keyed by object number.
 */
function pdfObjects($data)
{
    preg_match_all('#(?<![0-9])(\d+) 0 obj\s*(.*?)\nendobj#s', $data, $matches, PREG_SET_ORDER);
    $objects = array();
    foreach ($matches as $match) {
        $objects[(int) $match[1]] = $match[2];
    }
    return $objects;
}

/**
 * Return the dictionary of the font with the given base font name, or null.
 */
function fontDictionary($objects, $baseFont)
{
    foreach ($objects as $object) {
        if (preg_match('#^<<\s*/Type /Font\b.*?/BaseFont /' . preg_quote($baseFont, '#') . '[\s/>]#s', $object)) {
            return $object;
        }
    }
    return null;
}

/**
 * Return the decoded data of a stream object.
 */
function streamData($object)
{
    if (!preg_match('#/Length (\d+).*?>>\s*stream\n#s', $object, $match, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $data = substr($object, $match[0][1] + strlen($match[0][0]), (int) $match[1][0]);
    if (strpos(substr($object, 0, $match[0][1] + strlen($match[0][0])), '/FlateDecode') !== false) {
        $data = gzuncompress($data);
    }
    return $data;
}

/**
 * Parse the ToUnicode CMap of a font dictionary: its code space ranges, whether every
 * bfchar block has as many lines as it declares and no more than 100, and the mappings
 * keyed by code. Returns null if the font has no ToUnicode entry.
 */
function toUnicode($objects, $font)
{
    if (!preg_match('#/ToUnicode (\d+) 0 R#', $font, $match) || !isset($objects[(int) $match[1]])) {
        return null;
    }
    $cmap = streamData($objects[(int) $match[1]]);
    preg_match_all('#\d+ begincodespacerange\n(.*?)endcodespacerange#s', $cmap, $spaces);
    $blocksValid = true;
    $map = array();
    preg_match_all('#(\d+) beginbfchar\n(.*?)endbfchar#s', $cmap, $blocks, PREG_SET_ORDER);
    foreach ($blocks as $block) {
        preg_match_all('#^<([0-9a-fA-F]{2})> <([0-9a-fA-F]{4})>$#m', $block[2], $lines, PREG_SET_ORDER);
        if (((int) $block[1] !== count($lines)) || (count($lines) > 100)) {
            $blocksValid = false;
        }
        foreach ($lines as $line) {
            $map[hexdec($line[1])] = strtolower($line[2]);
        }
    }
    return array(
        'codespace' => array_map('trim', $spaces[1]),
        'blocks_valid' => $blocksValid && (count($blocks) > 0),
        'map' => $map,
    );
}

$fixtures = __DIR__ . '/fixtures/';
$expected = array(
    'ZapfDingbats' => expectedZapfDingbats($fixtures),
    'Symbol' => expectedSymbol($fixtures),
);
check('ZapfDingbats fixture maps 202 codes', count($expected['ZapfDingbats']) === 202);
check('ZapfDingbats fixture maps the check mark "4" to U+2714', $expected['ZapfDingbats'][0x34] === '2714');
check('Symbol fixture maps 189 codes', count($expected['Symbol']) === 189);
check('Symbol fixture maps "a" to U+03B1', $expected['Symbol'][0x61] === '03b1');

$runs = array(
    'core fonts' => array(false, true, array('ZapfDingbats' => 'ZapfDingbats', 'Symbol' => 'Symbol', 'Helvetica' => 'Helvetica')),
    'core fonts uncompressed' => array(false, false, array('ZapfDingbats' => 'ZapfDingbats', 'Symbol' => 'Symbol', 'Helvetica' => 'Helvetica')),
    'PDF/A-1' => array(1, true, array('ZapfDingbats' => 'PDFAZapfdingbats', 'Symbol' => 'PDFASymbol', 'Helvetica' => 'PDFAHelvetica')),
    'PDF/A-3' => array(3, true, array('ZapfDingbats' => 'PDFAZapfdingbats', 'Symbol' => 'PDFASymbol', 'Helvetica' => 'PDFAHelvetica')),
);
foreach ($runs as $label => $run) {
    list($pdfa, $compress, $baseFonts) = $run;
    $objects = pdfObjects(generatePdf($pdfa, $compress));
    foreach (array('ZapfDingbats', 'Symbol') as $family) {
        $font = fontDictionary($objects, $baseFonts[$family]);
        check($label . ': ' . $baseFonts[$family] . ' font is written', $font !== null);
        $cmap = ($font === null) ? null : toUnicode($objects, $font);
        check($label . ': ' . $baseFonts[$family] . ' has a ToUnicode entry', $cmap !== null);
        if ($cmap === null) {
            continue;
        }
        check($label . ': ' . $baseFonts[$family] . ' codes are one byte', $cmap['codespace'] === array('<00> <FF>'));
        check($label . ': ' . $baseFonts[$family] . ' bfchar blocks are well formed', $cmap['blocks_valid']);
        $missing = array_diff_key($expected[$family], $cmap['map']);
        $extra = array_diff_key($cmap['map'], $expected[$family]);
        $wrong = array();
        foreach (array_intersect_key($cmap['map'], $expected[$family]) as $code => $unicode) {
            if ($unicode !== $expected[$family][$code]) {
                $wrong[] = sprintf('%02x=>%s (expected %s)', $code, $unicode, $expected[$family][$code]);
            }
        }
        check(
            $label . ': ' . $baseFonts[$family] . ' maps every code as Adobe does'
            . ($missing ? ' - missing ' . implode(' ', array_map('dechex', array_keys($missing))) : '')
            . ($extra ? ' - extra ' . implode(' ', array_map('dechex', array_keys($extra))) : '')
            . ($wrong ? ' - wrong ' . implode(' ', $wrong) : ''),
            !$missing && !$extra && !$wrong
        );
    }
    $font = fontDictionary($objects, $baseFonts['Helvetica']);
    check($label . ': ' . $baseFonts['Helvetica'] . ' keeps its standard encoding and no ToUnicode', ($font !== null) && (strpos($font, '/ToUnicode') === false));
}

$objects = pdfObjects(generateFormPdf());
$font = fontDictionary($objects, 'ZapfDingbats');
$cmap = ($font === null) ? null : toUnicode($objects, $font);
check('check box field: ZapfDingbats has a ToUnicode entry mapping "4" to U+2714', ($cmap !== null) && isset($cmap['map'][0x34]) && ($cmap['map'][0x34] === '2714'));

if (isset($argv[1])) {
    file_put_contents($argv[1], generatePdf(1));
}

echo ($failures === 0 ? 'All checks passed' : $failures . ' check(s) failed') . PHP_EOL;
exit($failures === 0 ? 0 : 1);
