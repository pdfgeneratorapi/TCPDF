<?php

/**
 * Checks the hidden meta link written by Close() and that disabling it does not break the document.
 *
 * Usage: php tests/tcpdf_link.php
 * Exits with code 0 when all checks pass, 1 otherwise.
 */

require_once dirname(__DIR__) . '/tcpdf.php';

class LinkTestPdf extends TCPDF
{
    public function Footer()
    {
        $this->setY(-15);
        $this->setFont('helvetica', '', 8);
        $this->Cell(0, 10, 'FOOTER-MARK-' . $this->getAliasNumPage(), 0, 0, 'C');
    }
}

/**
 * Generate a 3 page document, go back to page 1 before closing, and return the state and the PDF data.
 * The state is read before Output(), because Output() destroys the object properties.
 */
function generatePdf($setLink, $enable = true, $text = null, $url = null)
{
    $pdf = new LinkTestPdf('P', 'mm', 'A4', false, 'ISO-8859-1', false);
    $pdf->setCompression(false);
    $pdf->setPrintHeader(false);
    $pdf->setFont('helvetica', '', 12);
    if ($setLink) {
        $pdf->setTCPDFLink($enable, $text, $url);
    }
    for ($i = 1; $i <= 3; $i++) {
        $pdf->AddPage();
        $pdf->Write(0, 'Page ' . $i);
    }
    // write on an earlier page after the content, as templates do for page numbers
    $pdf->setPage(1);
    $pdf->Write(0, ' (edited)');
    $state = $pdf->getTCPDFLink();
    return array($state, $pdf->Output('test.pdf', 'S'));
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

function footerCount($data)
{
    return preg_match_all('#FOOTER-MARK-\d#', $data);
}

// default: the TCPDF link is printed
list($state, $data) = generatePdf(false);
check('Default: getTCPDFLink() is true', $state === true);
check('Default: TCPDF link annotation is present', strpos($data, '/URI (http://www.tcpdf.org)') !== false);
check('Default: footer is printed on all 3 pages', footerCount($data) === 3);

// link disabled
list($state, $data) = generatePdf(true, false);
check('Disabled: getTCPDFLink() is false', $state === false);
check('Disabled: no TCPDF link text', strpos($data, 'Powered by TCPDF') === false);
check('Disabled: no TCPDF link annotation', strpos($data, '/URI (http://www.tcpdf.org)') === false);
check('Disabled: footer is printed on all 3 pages', footerCount($data) === 3);
check('Disabled: document has 3 pages', strpos($data, '/Count 3') !== false);

// custom text and URL
list($state, $data) = generatePdf(true, true, 'Powered by Example', 'https://example.com');
check('Custom: custom link annotation is present', strpos($data, '/URI (https://example.com)') !== false);
check('Custom: custom link text is present', strpos($data, 'Powered by Example') !== false);
check('Custom: no TCPDF link annotation', strpos($data, '/URI (http://www.tcpdf.org)') === false);
check('Custom: footer is printed on all 3 pages', footerCount($data) === 3);

echo ($failures === 0 ? 'All checks passed' : $failures . ' check(s) failed') . PHP_EOL;
exit($failures === 0 ? 0 : 1);
