<?php
//============================================================+
// File name   : example_069.php
// Begin       : 2026-09-24
// Last Update : 2026-09-24
//
// Description : Example 069 for TCPDF class
//               Creates an example PDF/A-3b document with font subsetting
//
//============================================================+

/**
 * Creates an example PDF/A-3b document with font subsetting
 * @package com.tecnick.tcpdf
 * @abstract TCPDF - Example: PDF/A-3b mode with font subsetting
 * @since 2026-09-24
 * @group A-3b
 * @group pdf
 */

// Include the main TCPDF library (search for installation path).
require_once('tcpdf_include.php');

// create new PDF document
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false, 3);

// set document information
$pdf->setCreator(PDF_CREATOR);
$pdf->setAuthor('Nicola Asuni');
$pdf->setTitle('TCPDF Example 069');
$pdf->setSubject('TCPDF Tutorial');
$pdf->setKeywords('TCPDF, PDF, example, test, guide');

// set default header data
$pdf->setHeaderData(PDF_HEADER_LOGO, PDF_HEADER_LOGO_WIDTH, PDF_HEADER_TITLE.' 069', PDF_HEADER_STRING);

// set header and footer fonts
$pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
$pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));

// set default monospaced font
$pdf->setDefaultMonospacedFont(PDF_FONT_MONOSPACED);

// set margins
$pdf->setMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
$pdf->setHeaderMargin(PDF_MARGIN_HEADER);
$pdf->setFooterMargin(PDF_MARGIN_FOOTER);

// set auto page breaks
$pdf->setAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

// set image scale factor
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

// set some language-dependent strings (optional)
if (@file_exists(dirname(__FILE__).'/lang/eng.php')) {
	require_once(dirname(__FILE__).'/lang/eng.php');
	$pdf->setLanguageArray($l);
}

// ---------------------------------------------------------

// allow font subsetting in PDF/A mode (PDF/A-2 and PDF/A-3 only)
// this must be called before setFontSubsetting()
$pdf->setPdfaFontSubsetting(true);

// set default font subsetting mode
$pdf->setFontSubsetting(true);

// Set font
$pdf->setFont('dejavusans', '', 14, '', true);

// Add a page
$pdf->AddPage();

// Set some content to print
$html = <<<EOD
<h1>PDF/A-3b document with font subsetting</h1>
<i>This document conforms to the standard <b>PDF/A-3b (ISO 19005-3:2012)</b> and embeds only the used glyphs of each font.</i>
<p>Unicode sample: Tähepõld, Ä Ö Ü õ š ž, Ελληνικά, Кириллица.</p>
EOD;

// Print text using writeHTMLCell()
$pdf->writeHTMLCell(0, 0, '', '', $html, 0, 1, 0, true, '', true);

// ---------------------------------------------------------

// Close and output PDF document
$pdf->Output('example_069.pdf', 'I');

//============================================================+
// END OF FILE
//============================================================+
