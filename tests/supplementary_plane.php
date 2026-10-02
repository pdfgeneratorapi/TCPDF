<?php

/**
 * Checks support for characters outside the Basic Multilingual Plane (above U+FFFF):
 * TTF import, CID allocation, content stream encoding, widths, ToUnicode and subsetting.
 *
 * Usage: php tests/supplementary_plane.php
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
 * Return the identity ranges and the single mappings of a ToUnicode CMap.
 */
function parseToUnicode($cmap)
{
    $ranges = array();
    $chars = array();
    preg_match_all('#(\d+) beginbfrange\n(.*?)endbfrange#s', $cmap, $sections, PREG_SET_ORDER);
    foreach ($sections as $section) {
        preg_match_all('#<([0-9a-f]{4})> <([0-9a-f]{4})> <([0-9a-f]{4})>#', $section[2], $lines, PREG_SET_ORDER);
        $ranges[] = array('count' => (int) $section[1], 'lines' => count($lines));
        foreach ($lines as $line) {
            $ranges[] = array(hexdec($line[1]), hexdec($line[2]), hexdec($line[3]));
        }
    }
    preg_match_all('#(\d+) beginbfchar\n(.*?)endbfchar#s', $cmap, $sections, PREG_SET_ORDER);
    foreach ($sections as $section) {
        preg_match_all('#<([0-9a-f]{4})> <([0-9a-f]+)>#', $section[2], $lines, PREG_SET_ORDER);
        $ranges[] = array('count' => (int) $section[1], 'lines' => count($lines));
        foreach ($lines as $line) {
            $chars[hexdec($line[1])] = $line[2];
        }
    }
    return array($ranges, $chars);
}

/**
 * Return the widths of the /W array of a PDF, keyed by CID.
 */
function parseWidths($data)
{
    $widths = array();
    if (!preg_match('#/W \[(.*?)\]\s*/CIDToGIDMap#s', $data, $match)) {
        return $widths;
    }
    preg_match_all('#(\d+) \[ ([\d ]+) \]|(\d+) (\d+) (\d+)#', $match[1], $entries, PREG_SET_ORDER);
    foreach ($entries as $entry) {
        if ($entry[1] !== '') {
            foreach (explode(' ', $entry[2]) as $i => $width) {
                $widths[$entry[1] + $i] = (int) $width;
            }
        } else {
            for ($cid = (int) $entry[3]; $cid <= (int) $entry[4]; ++$cid) {
                $widths[$cid] = (int) $entry[5];
            }
        }
    }
    return $widths;
}

/**
 * Return the length of a glyph in the glyf table of a TrueType font.
 */
function glyphLength($font, $gid)
{
    $numTables = TCPDF_STATIC::_getUSHORT($font, 4);
    $table = array();
    for ($i = 0; $i < $numTables; ++$i) {
        $offset = 12 + ($i * 16);
        $table[substr($font, $offset, 4)] = TCPDF_STATIC::_getULONG($font, $offset + 8);
    }
    $short = (TCPDF_STATIC::_getSHORT($font, $table['head'] + 50) == 0);
    if ($short) {
        $start = TCPDF_STATIC::_getUSHORT($font, $table['loca'] + ($gid * 2)) * 2;
        $end = TCPDF_STATIC::_getUSHORT($font, $table['loca'] + (($gid + 1) * 2)) * 2;
    } else {
        $start = TCPDF_STATIC::_getULONG($font, $table['loca'] + ($gid * 4));
        $end = TCPDF_STATIC::_getULONG($font, $table['loca'] + (($gid + 1) * 4));
    }
    return $end - $start;
}

/**
 * Generate a document with the given font and text, uncompressed so the content can be inspected.
 */
function generatePdf($fontfile, $text, $pdfa = false, $subset = true)
{
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false, $pdfa);
    $pdf->setCompression(false);
    if ($pdfa) {
        $pdf->setPdfaFontSubsetting($subset);
    }
    $pdf->setFontSubsetting($subset);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->setTCPDFLink(false);
    $pdf->AddPage();
    $family = ($fontfile === null) ? 'dejavusans' : 'mplustest';
    $pdf->AddFont($family, '', ($fontfile === null) ? '' : $fontfile);
    $pdf->setFont($family, '', 12);
    $pdf->Cell(0, 0, $text, 0, 1);
    return $pdf;
}

$fixtures = __DIR__ . '/fixtures/';
$tmpdir = sys_get_temp_dir() . '/tcpdf-supplementary-' . getmypid() . '/';
@mkdir($tmpdir, 0777, true);

// ---------- CID allocation ----------

$cidmap = TCPDF_FONTS::allocateSupplementaryCids(array(0x41 => 34, 0x20BB7 => 99, 0x7530 => 96, 0x1D538 => 97));
check('allocation: supplementary code points get surrogate CIDs in code point order', $cidmap === array(0x1D538 => 0xD800, 0x20BB7 => 0xD801));

$cidmap = TCPDF_FONTS::allocateSupplementaryCids(array(0x41 => 34, 0xD800 => 5, 0x20BB7 => 99));
check('allocation: a CID used by the BMP cmap is never reused', $cidmap === array(0x20BB7 => 0xD801));

$ctg = array_fill(0, 0x21, 1);
for ($cp = 0x20000; $cp < 0x20000 + 0x801; ++$cp) {
    $ctg[$cp] = 1;
}
$cidmap = TCPDF_FONTS::allocateSupplementaryCids($ctg);
check('allocation: the surrogate block is filled first', ($cidmap[0x20000] === 0xD800) && ($cidmap[0x207FF] === 0xDFFF));
check('allocation: then the lowest CID unused by the BMP cmap', $cidmap[0x20800] === 0x21);

$ctg = array_fill(0, 0x10000, 1);
$ctg[0x20BB7] = 99;
check('allocation: nothing is allocated when every CID is taken', TCPDF_FONTS::allocateSupplementaryCids($ctg) === array());

// ---------- ToUnicode CMap ----------

check('ToUnicode: an empty map returns the standard Identity-H CMap', TCPDF_FONTS::getToUnicodeCMap(array()) === TCPDF_FONT_DATA::$uni_identity_h);

$cmap = TCPDF_FONTS::getToUnicodeCMap(array(0x20BB7 => 0xD801, 0x1D538 => 0xD800, 0x20B9F => 0x0041));
list($ranges, $chars) = parseToUnicode($cmap);
check('ToUnicode: remapped CIDs map to UTF-16BE surrogate pairs', $chars === array(0x0041 => 'd842df9f', 0xD800 => 'd835dd38', 0xD801 => 'd842dfb7'));
$covered = array();
$valid = true;
foreach ($ranges as $range) {
    if (isset($range['count'])) {
        $valid = $valid && ($range['count'] === $range['lines']) && ($range['count'] <= 100);
        continue;
    }
    list($first, $last, $dest) = $range;
    $valid = $valid && (($first >> 8) === ($last >> 8)) && ($first === $dest);
    for ($cid = $first; $cid <= $last; ++$cid) {
        $covered[$cid] = isset($covered[$cid]) ? 2 : 1;
    }
}
check('ToUnicode: ranges are identity, stay within one high byte and sections hold at most 100 entries', $valid);
$expected = array_fill(0, 0x10000, 1);
unset($expected[0xD800], $expected[0xD801], $expected[0x0041]);
ksort($covered);
check('ToUnicode: every other CID is covered once by an identity range', $covered === $expected);

// ---------- content stream encoding ----------

$font = array('cw' => array(0x20BB7 => 1000, 0x7530 => 1000), 'cidmap' => array(0x20BB7 => 0xD801));
check('encoding: a font without a CID map keeps UTF-16BE', TCPDF_FONTS::arrUTF8ToCIDString(array(0x20BB7, 0x7530), array('cidmap' => array())) === "\xD8\x42\xDF\xB7\x75\x30");
check('encoding: mapped supplementary code points use their CID', TCPDF_FONTS::arrUTF8ToCIDString(array(0x20BB7, 0x7530), $font) === "\xD8\x01\x75\x30");
check('encoding: unmapped supplementary code points use CID 0', TCPDF_FONTS::arrUTF8ToCIDString(array(0x1F600), $font) === "\x00\x00");
$spill = array('cw' => array(0x20000 => 1000), 'cidmap' => array(0x20000 => 0x21));
check('encoding: a missing BMP character whose CID is taken uses CID 0', TCPDF_FONTS::arrUTF8ToCIDString(array(0x21, 0x20000), $spill) === "\x00\x00\x00\x21");
check('encoding: zero width space is skipped and the replacement character kept', TCPDF_FONTS::arrUTF8ToCIDString(array(0x200B, 0xFFFD), $font) === "\xFF\xFD");

// ---------- TTF import ----------

$fontname = TCPDF_FONTS::addTTFfont($fixtures . 'mplus1p-supplementary-subset.ttf', 'TrueTypeUnicode', '', 32, $tmpdir);
$definition = file_get_contents($tmpdir . $fontname . '.php');
$cw = null;
$cidmap = null;
include $tmpdir . $fontname . '.php';
check('import: the definition has a CID map', is_array($cidmap) && (count($cidmap) === 3));
check('import: supplementary code points are mapped to surrogate CIDs', $cidmap === array(0x1D538 => 0xD800, 0x20B9F => 0xD801, 0x20BB7 => 0xD802));
check('import: supplementary widths are keyed by code point', isset($cw[0x20BB7], $cw[0x1D538]) && ($cw[0x20BB7] == 1000) && ($cw[0x1D538] == 770));
check('import: BMP widths are unchanged', isset($cw[0x7530], $cw[0x41]) && ($cw[0x7530] == 1000) && ($cw[0x41] == 693));
$ctgmap = gzuncompress(file_get_contents($tmpdir . $fontname . '.ctg.z'));
check('import: the CIDToGIDMap maps the remapped CID to the glyph', TCPDF_STATIC::_getUSHORT($ctgmap, 0xD802 * 2) === 99);
check('import: the CIDToGIDMap keeps BMP CIDs', TCPDF_STATIC::_getUSHORT($ctgmap, 0x7530 * 2) === 96);

$bmpname = TCPDF_FONTS::addTTFfont($fixtures . 'mplus1p-bmp-subset.ttf', 'TrueTypeUnicode', '', 32, $tmpdir);
check('import: a BMP-only font writes no CID map', strpos(file_get_contents($tmpdir . $bmpname . '.php'), '$cidmap') === false);

// importing from the (3,10) format 12 subtable directly still reads its BMP part
@mkdir($tmpdir . 'encid10/', 0777, true);
$name10 = TCPDF_FONTS::addTTFfont($fixtures . 'mplus1p-supplementary-subset.ttf', 'TrueTypeUnicode', '', 32, $tmpdir . 'encid10/', 3, 10);
$cw = null;
include $tmpdir . 'encid10/' . $name10 . '.php';
check('import from (3,10): BMP widths are read from the format 12 subtable', isset($cw[0x7530], $cw[0x41]) && ($cw[0x7530] == 1000));
array_map('unlink', glob($tmpdir . 'encid10/*'));
rmdir($tmpdir . 'encid10/');

// ---------- rendering ----------

$fontfile = $tmpdir . $fontname . '.php';
$pdf = generatePdf($fontfile, '𠮷田 😀');
check('rendering: isCharDefined() finds the supplementary character', $pdf->isCharDefined(0x20BB7));
check('rendering: GetStringWidth() measures the supplementary character', abs($pdf->GetStringWidth('𠮷') - $pdf->GetStringWidth('田')) < 0.0001 && $pdf->GetStringWidth('𠮷') > 0);
$data = $pdf->Output('test.pdf', 'S');
check('rendering: the content stream uses the remapped CID', strpos($data, "[(\xD8\x02\x75\x30\x00\x20\x00\x00)] TJ") !== false);
check('rendering: the content stream has no surrogate pair', strpos($data, "\xD8\x42\xDF\xB7") === false);
check('rendering: the ToUnicode CMap maps the remapped CID to the character', strpos($data, '<d802> <d842dfb7>') !== false);
$widths = parseWidths($data);
check('rendering: the widths array has the remapped CID', isset($widths[0xD802]) && ($widths[0xD802] === 1000));
check('rendering: the widths array leaves out unused supplementary characters', !isset($widths[0xD800]) && !isset($widths[0xD801]));
check('rendering: the widths array has no CID above U+FFFF', max(array_keys($widths)) <= 0xFFFF);
check('rendering: the font is embedded as a subset', preg_match('#/BaseFont /[A-Z]{6}\+#', $data) === 1);

$pdf = generatePdf($fontfile, '𠮷田', false, false);
$data = $pdf->Output('test.pdf', 'S');
$widths = parseWidths($data);
check('rendering without subsetting: the widths array has every remapped CID', isset($widths[0xD800], $widths[0xD801], $widths[0xD802]) && ($widths[0xD800] === 770));

$pdf = generatePdf(null, '😀');
$data = $pdf->Output('test.pdf', 'S');
check('rendering: a font without a CID map keeps the surrogate pair', strpos($data, "[(\xD8\x3D\xDE\x00)] TJ") !== false);

// ---------- subsetting ----------

$ttf = file_get_contents($fixtures . 'mplus1p-supplementary-subset.ttf');
$subset = TCPDF_FONTS::_getTrueTypeFontSubset($ttf, array(0x20BB7 => true));
check('subsetting: the supplementary glyph is kept', glyphLength($subset, 99) > 0);
check('subsetting: unused glyphs are dropped', glyphLength($subset, 96) === 0);

// ---------- PDF/A ----------

$pdf = generatePdf($fontfile, '𠮷田', 3, true);
$data = $pdf->Output('test.pdf', 'S');
check('PDF/A-3: the document is PDF/A-3', strpos($data, '<pdfaid:part>3</pdfaid:part>') !== false);
check('PDF/A-3: the font is subset and has a CIDToGIDMap', preg_match('#/BaseFont /[A-Z]{6}\+#', $data) === 1 && strpos($data, '/CIDToGIDMap') !== false);
check('PDF/A-3: the ToUnicode CMap maps the remapped CID', strpos($data, '<d802> <d842dfb7>') !== false);
file_put_contents($tmpdir . 'pdfa3.pdf', $data);
if (isset($argv[1])) {
    // keep the PDF/A file for external validation
    copy($tmpdir . 'pdfa3.pdf', $argv[1]);
}

// ---------- text extraction ----------

exec('command -v pdftotext', $output, $status);
if ($status === 0) {
    $text = shell_exec('pdftotext ' . escapeshellarg($tmpdir . 'pdfa3.pdf') . ' -');
    check('pdftotext: the text is extracted as 𠮷田', trim($text, " \n\f") === '𠮷田');
} else {
    echo 'SKIP: pdftotext not found' . PHP_EOL;
}

array_map('unlink', glob($tmpdir . '*'));
rmdir($tmpdir);

echo ($failures === 0 ? 'All checks passed' : $failures . ' check(s) failed') . PHP_EOL;
exit($failures === 0 ? 0 : 1);
