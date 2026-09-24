<?php

/**
 * Checks font subsetting behaviour in PDF/A mode.
 *
 * Usage: php tests/pdfa_font_subsetting.php
 * Exits with code 0 when all checks pass, 1 otherwise.
 */

require_once dirname(__DIR__) . '/tcpdf.php';

/**
 * Generate a small document and return the option states and the PDF data.
 * The states are read before Output(), because Output() destroys the object properties.
 */
function generatePdf($pdfa, $pdfaSubsetting, $subsetting)
{
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false, $pdfa);
    if ($pdfaSubsetting !== null) {
        $pdf->setPdfaFontSubsetting($pdfaSubsetting);
    }
    $pdf->setFontSubsetting($subsetting);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->setFont('dejavusans', '', 12);
    $pdf->Write(0, 'Hello PDF/A: Ä Ö Ü õ');
    $state = array(
        'pdfa_subsetting' => $pdf->getPdfaFontSubsetting(),
        'subsetting' => $pdf->getFontSubsetting(),
    );
    return array($state, $pdf->Output('test.pdf', 'S'));
}

/**
 * Return true if the DejaVuSans font is embedded as a subset.
 */
function isSubset($data)
{
    return preg_match('#/BaseFont /[A-Z]{6}\+DejaVuSans#', $data) === 1;
}

$failures = 0;

function check($name, $condition)
{
    global $failures;
    echo ($condition ? 'PASS' : 'FAIL') . ': ' . $name . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}

// default: subsetting is disabled in PDF/A mode
list($state, $data) = generatePdf(3, null, true);
check('PDF/A-3 without option: getPdfaFontSubsetting() is false', $state['pdfa_subsetting'] === false);
check('PDF/A-3 without option: getFontSubsetting() is false', $state['subsetting'] === false);
check('PDF/A-3 without option: font is fully embedded', !isSubset($data));
$fullSize = strlen($data);

// PDF/A-3 with the option enabled
list($state, $data) = generatePdf(3, true, true);
check('PDF/A-3 with option: getPdfaFontSubsetting() is true', $state['pdfa_subsetting'] === true);
check('PDF/A-3 with option: getFontSubsetting() is true', $state['subsetting'] === true);
check('PDF/A-3 with option: font is subset', isSubset($data));
check('PDF/A-3 with option: document is still PDF/A-3', strpos($data, '<pdfaid:part>3</pdfaid:part>') !== false);
check('PDF/A-3 with option: document is smaller than with full font', strlen($data) < $fullSize);

// PDF/A-3 with the option enabled but subsetting disabled
list($state, $data) = generatePdf(3, true, false);
check('PDF/A-3 with option and subsetting off: font is fully embedded', !isSubset($data));

// PDF/A-1 requires a CIDSet that TCPDF does not write, so the option is ignored
list($state, $data) = generatePdf(1, true, true);
check('PDF/A-1 with option: getFontSubsetting() is false', $state['subsetting'] === false);
check('PDF/A-1 with option: font is fully embedded', !isSubset($data));
check('PDF/A-1 with option: document is still PDF/A-1', strpos($data, '<pdfaid:part>1</pdfaid:part>') !== false);

// non PDF/A documents are not affected by the option
list($state, $data) = generatePdf(false, false, true);
check('Non PDF/A: font is subset', isSubset($data));

echo ($failures === 0 ? 'All checks passed' : $failures . ' check(s) failed') . PHP_EOL;
exit($failures === 0 ? 0 : 1);
