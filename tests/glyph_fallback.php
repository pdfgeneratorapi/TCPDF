<?php

/**
 * Checks the glyph fallback fonts: a character the current font has no glyph for is measured
 * and printed with the first fallback font that has it, in the current style, and text the
 * current font can draw is written as without a fallback.
 *
 * FreeSans has no ★ (U+2605), ✦ (U+2726) or ✓ (U+2713); DejaVu Sans has all three.
 *
 * Usage: php tests/glyph_fallback.php
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
 * A document with the page buffer and the font state readable.
 */
class GlyphFallbackPdf extends TCPDF
{
    public function pageContent($page = 1)
    {
        return $this->getPageBuffer($page);
    }

    public function fontKeys()
    {
        return $this->fontkeys;
    }

    public function fontIndex($fontkey)
    {
        $font = $this->getFontBuffer($fontkey);
        return $font === false ? null : $font['i'];
    }

    public function subsetChars($fontkey)
    {
        $font = $this->getFontBuffer($fontkey);
        return $font === false ? array() : $font['subsetchars'];
    }

    public function isUnderlined()
    {
        return $this->underline;
    }
}

function createPdf($fallbacks, $style = '')
{
    $pdf = new GlyphFallbackPdf('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->setCompression(false);
    $pdf->setGlyphFallbackFonts($fallbacks);
    $pdf->AddPage();
    $pdf->setFont('freesans', $style, 12);
    return $pdf;
}

/**
 * The CID string of a BMP character, escaped as it is in the content stream.
 */
function cid($char)
{
    return TCPDF_STATIC::_escape(chr($char >> 8) . chr($char & 0xFF));
}

/**
 * The CID string of BMP text, escaped as it is in the content stream.
 */
function cids($text)
{
    $font = array('subsetchars' => array());
    return TCPDF_STATIC::_escape(TCPDF_FONTS::arrUTF8ToUTF16BE(TCPDF_FONTS::UTF8StringToArray($text, true, $font), false));
}

function dejavuWidth($char, $file = 'dejavusans')
{
    $cw = null;
    include dirname(__DIR__) . '/fonts/' . $file . '.php';
    return $cw[$char];
}

// The setting
$pdf = createPdf(array('DejaVu Sans', 'dejavusans', ''));
check('fallback families are normalised and kept once', $pdf->getGlyphFallbackFonts() === array('dejavusans'));
check('no fallback by default', createPdf(array())->getGlyphFallbackFonts() === array());

// Widths
$without = createPdf(array());
$with = createPdf(array('dejavusans'));
$size = 12 / $with->getScaleFactor();
check('a missing glyph is measured with the fallback font', abs($with->GetStringWidth('★') - (dejavuWidth(0x2605) * $size / 1000)) < 0.0001);
check('without a fallback a missing glyph keeps the default width', abs($without->GetStringWidth('★') - $with->GetStringWidth('★')) > 0.0001);
check('a glyph the font has is measured with the font', abs($with->GetStringWidth('Rate') - $without->GetStringWidth('Rate')) < 0.0001);
check('measuring adds no font to the document', !in_array('dejavusans', $with->fontKeys(), true));

// Text the font can draw is written as without a fallback
$plain = createPdf(array());
$plain->Cell(0, 10, 'Rate the service', 0, 1);
$plain->MultiCell(60, 0, 'A longer paragraph that wraps over several lines of text, justified.', 0, 'J');
$plain->writeHTML('<p>Some <b>HTML</b> text</p>');
$fallback = createPdf(array('dejavusans'));
$fallback->Cell(0, 10, 'Rate the service', 0, 1);
$fallback->MultiCell(60, 0, 'A longer paragraph that wraps over several lines of text, justified.', 0, 'J');
$fallback->writeHTML('<p>Some <b>HTML</b> text</p>');
check('text without missing glyphs is written byte for byte as before', $plain->pageContent() === $fallback->pageContent());
check('no fallback font is added for it', !in_array('dejavusans', $fallback->fontKeys(), true));

// Printing
$pdf = createPdf(array('dejavusans'));
$pdf->Cell(0, 10, 'Rate ★★ ✦ ok', 0, 1);
$content = $pdf->pageContent();
$freesans = $pdf->fontIndex('freesans');
$dejavu = $pdf->fontIndex('dejavusans');
check('the fallback font is added once a character is printed with it', $dejavu !== null);
check('the run of missing glyphs is printed with the fallback font', strpos($content, sprintf('/F%d 12.000000 Tf [(%s)] TJ', $dejavu, cid(0x2605) . cid(0x2605))) !== false);
check('the text around it keeps the current font', strpos($content, sprintf('[(%s)] TJ /F%d 12.000000 Tf', cids('Rate '), $dejavu)) !== false);
check('the font switches back after a fallback run', strpos($content, sprintf('/F%d 12.000000 Tf [(%s)] TJ /F%d 12.000000 Tf [(%s)] TJ', $dejavu, cid(0x2726), $freesans, cids(' ok'))) !== false);
$subset = $pdf->subsetChars('dejavusans');
check('the printed characters are in the fallback font subset', isset($subset[0x2605]) && isset($subset[0x2726]));

$pdf = createPdf(array('dejavusans'));
$pdf->Cell(0, 10, '★', 0, 1);
check('text that ends in a fallback run switches back to the current font', preg_match(sprintf('#\[\(%s\)\] TJ /F%d 12\.000000 Tf ET#', preg_quote(cid(0x2605), '#'), $pdf->fontIndex('freesans')), $pdf->pageContent()) === 1);

// Styles
$pdf = createPdf(array('dejavusans'), 'B');
$pdf->Cell(0, 10, 'Bold ★', 0, 1);
check('bold text falls back to the bold fallback font', in_array('dejavusansB', $pdf->fontKeys(), true) && !in_array('dejavusans', $pdf->fontKeys(), true));
check('the bold fallback is measured with the bold widths', abs(createPdf(array('dejavusans'), 'B')->GetStringWidth('★') - (dejavuWidth(0x2605, 'dejavusansb') * $size / 1000)) < 0.0001);

$pdf = createPdf(array('dejavusans'), 'U');
$pdf->Cell(0, 10, 'Underlined ★', 0, 1);
check('adding the fallback font keeps the underline', $pdf->isUnderlined());

// A character no font has stays with the current font
$pdf = createPdf(array('dejavusans'));
$pdf->Cell(0, 10, '字', 0, 1);
check('a character the fallback has not either is printed with the current font', !in_array('dejavusans', $pdf->fontKeys(), true));

// Fallbacks in order of preference: the first that has the glyph
$pdf = createPdf(array('freeserifi', 'dejavusans'));
$pdf->Cell(0, 10, '★', 0, 1);
check('a fallback without the glyph is skipped', in_array('dejavusans', $pdf->fontKeys(), true) && !in_array('freeserifi', $pdf->fontKeys(), true));
$pdf = createPdf(array('freeserif', 'dejavusans'));
$pdf->Cell(0, 10, '★', 0, 1);
check('the first fallback with the glyph prints it', in_array('freeserif', $pdf->fontKeys(), true) && !in_array('dejavusans', $pdf->fontKeys(), true));

// Justification adds the space width to the current font runs
$pdf = createPdf(array('dejavusans'));
$pdf->MultiCell(80, 0, 'Rate the service ★ from one to five stars, please, and tell us why ✦ in a few words.', 0, 'J');
$content = $pdf->pageContent();
check('justified text with a fallback run is spaced', preg_match('#\) -?[0-9.]+ \(#', $content) === 1 && strpos($content, sprintf('/F%d 12.000000 Tf [(%s)] TJ', $pdf->fontIndex('dejavusans'), cid(0x2605))) !== false);

// Line breaking uses the width the character is printed with
$pdf = createPdf(array('dejavusans'));
$starWidth = $pdf->GetStringWidth('★');
$lines = $pdf->getNumLines(str_repeat('★', 10), (10 * $starWidth) + $pdf->getCellPaddings()['L'] + $pdf->getCellPaddings()['R'] + 0.001);
check('ten fallback glyphs fit in the width of ten', $lines === 1);

// HTML
$pdf = createPdf(array('dejavusans'));
$pdf->writeHTML('<p>Rate <b>★</b> now</p>');
check('HTML text falls back too, in its style', in_array('dejavusansB', $pdf->fontKeys(), true));

// The document
$pdf = createPdf(array('dejavusans'));
$pdf->Cell(0, 10, 'Rate ★ ✦ ✓', 0, 1);
$out = $pdf->Output('', 'S');
check('the fallback font is embedded', preg_match('#/BaseFont /[A-Z]{6}\+DejaVuSans\b#', $out) === 1);
// CID = code point under Identity-H, so the identity ToUnicode CMap gives the characters back
check('both fonts are Identity-H with a ToUnicode CMap', substr_count($out, '/Subtype /Type0') === 2 && substr_count($out, '/Encoding /Identity-H') === 2 && substr_count($out, '/ToUnicode') === 2);

echo PHP_EOL . ($failures === 0 ? 'All checks passed.' : $failures . ' check(s) failed.') . PHP_EOL;
exit($failures === 0 ? 0 : 1);
