<?php

/**
 * Checks the AcroForm default appearance (/DA) names a font the document actually contains.
 *
 * The /DA font is looked up by the current PDF/A mode, so it can be missing when the mode changes after the
 * default font was loaded, or when PDF_FONT_NAME_MAIN is not Helvetica.
 *
 * Usage: php tests/acroform_default_font.php [without-helvetica]
 * Run it once without and once with the argument: the constant that sets the default font cannot be redefined.
 * Exits with code 0 when all checks pass, 1 otherwise.
 */

$withoutHelvetica = (isset($argv[1]) AND ($argv[1] === 'without-helvetica'));
if ($withoutHelvetica) {
    define('K_TCPDF_EXTERNAL_CONFIG', true);
    define('PDF_FONT_NAME_MAIN', 'times');
}

require_once dirname(__DIR__) . '/tcpdf.php';

class AcroFormTestPdf extends TCPDF
{
    /**
     * Switch PDF/A mode after the constructor loaded the default font, as callers do before adding form fields.
     */
    public function switchPdfaMode($mode)
    {
        $this->pdfa_mode = $mode;
    }
}

$warnings = array();
set_error_handler(function ($errno, $errstr) use (&$warnings) {
    $warnings[] = $errstr;
    return true;
});

function generatePdf($pdfa, $switchPdfaOff, $withTextField)
{
    $pdf = new AcroFormTestPdf('P', 'mm', 'A4', true, 'UTF-8', false, $pdfa);
    $pdf->setCompression(false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->setTCPDFLink(false);
    $pdf->AddPage();
    if ($switchPdfaOff) {
        $pdf->switchPdfaMode(false);
    }
    if ($withTextField) {
        $pdf->TextField('name', 50, 5, array(), array(), 10, 20);
    } else {
        $pdf->addEmptySignatureAppearance(10, 40, 50, 20);
    }
    return $pdf->Output('test.pdf', 'S');
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

/**
 * Return the AcroForm dictionary of the document, or an empty string.
 */
function acroForm($data)
{
    return preg_match('#/AcroForm <<.*?/Q \d#s', $data, $m) ? $m[0] : '';
}

/**
 * Return the font resource named by the AcroForm /DA entry, or null.
 */
function daFont($acroForm)
{
    return preg_match('#/DA \(/(F\d+) 0 Tf 0 g\)#', $acroForm, $m) ? $m[1] : null;
}

/**
 * True if a font dictionary of the document (page resources or /DR) maps the resource name to an object.
 */
function isFontResource($data, $name)
{
    return preg_match('#/Font <<[^>]*/' . $name . ' \d+ 0 R#', $data) === 1;
}

function checkScenario($label, $data, $expectDa)
{
    global $warnings;
    $acroForm = acroForm($data);
    $font = daFont($acroForm);
    check($label . ': AcroForm is present', $acroForm !== '');
    if ($expectDa) {
        check($label . ': /DA names a font resource of the document', ($font !== null) AND isFontResource($data, $font));
    } else {
        check($label . ': no /DA without a font to name', strpos($acroForm, '/DA') === false);
    }
    check($label . ': no PHP warnings', $warnings === array());
    $warnings = array();
}

if (!$withoutHelvetica) {
    checkScenario('Helvetica field', generatePdf(false, false, true), true);
    checkScenario('PDF/A field', generatePdf(3, false, true), true);
    checkScenario('PDF/A switched off, field', generatePdf(3, true, true), true);
    checkScenario('PDF/A switched off, signature', generatePdf(3, true, false), true);
} else {
    $data = generatePdf(false, false, true);
    checkScenario('Without Helvetica, field', $data, true);
    check(
        'Without Helvetica, field: /DA font is listed in /DR',
        preg_match('#/DR << /Font <<[^>]*/' . daFont(acroForm($data)) . ' \d+ 0 R#', acroForm($data)) === 1
    );
    checkScenario('Without Helvetica, signature', generatePdf(false, false, false), false);
}

echo ($failures === 0 ? 'All checks passed' : $failures . ' check(s) failed') . PHP_EOL;
exit($failures === 0 ? 0 : 1);
