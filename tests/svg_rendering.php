<?php

/**
 * Checks SVG features ImageSVG draws as vectors: clip paths with transforms, pattern fills,
 * linear gradients in bounding-box units, <style> rules, paint server fallbacks and href.
 *
 * Every case draws one 100x100 SVG on a 100x100 pt page, renders the page with pdftoppm at
 * 72 dpi, so one pixel is one SVG unit, and reads the colour of chosen pixels.
 *
 * Usage: php tests/svg_rendering.php
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

const RED = array(255, 0, 0);
const GREEN = array(0, 255, 0);
const BLUE = array(0, 0, 255);
const WHITE = array(255, 255, 255);
const BLACK = array(0, 0, 0);

/**
 * A page with its content stream readable.
 */
class SvgRenderingPdf extends TCPDF
{
    public function pageContent($page = 1)
    {
        return $this->getPageBuffer($page);
    }
}

function createPdf()
{
    $pdf = new SvgRenderingPdf('P', 'pt', array(100, 100), true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->setCompression(false);
    $pdf->setMargins(0, 0, 0);
    $pdf->setAutoPageBreak(false);
    $pdf->AddPage();
    return $pdf;
}

/**
 * The page as rows of RGB triplets, one pixel per point.
 */
function rasterize(TCPDF $pdf)
{
    $dir = sys_get_temp_dir() . '/tcpdf-svg-' . uniqid();
    mkdir($dir, 0777, true);
    file_put_contents($dir . '/page.pdf', $pdf->Output('', 'S'));
    exec('pdftoppm -r 72 -aa no -aaVector no -singlefile ' . escapeshellarg($dir . '/page.pdf') . ' ' . escapeshellarg($dir . '/page'), $output, $status);
    $ppm = $status === 0 ? file_get_contents($dir . '/page.ppm') : false;
    @unlink($dir . '/page.pdf');
    @unlink($dir . '/page.ppm');
    @rmdir($dir);
    if ($ppm === false || !preg_match('/^P6\s+(\d+)\s+(\d+)\s+(\d+)\s/s', $ppm, $header)) {
        return null;
    }
    $data = substr($ppm, strlen($header[0]));
    return array('width' => (int) $header[1], 'height' => (int) $header[2], 'data' => $data);
}

function pixel($image, $x, $y)
{
    if ($image === null || $x >= $image['width'] || $y >= $image['height']) {
        return null;
    }
    $offset = (($y * $image['width']) + $x) * 3;
    return array(ord($image['data'][$offset]), ord($image['data'][$offset + 1]), ord($image['data'][$offset + 2]));
}

function near($colour, $expected, $tolerance = 40)
{
    if ($colour === null) {
        return false;
    }
    foreach ($expected as $i => $value) {
        if (abs($colour[$i] - $value) > $tolerance) {
            return false;
        }
    }
    return true;
}

/**
 * Draws the SVG over the whole page and returns the rendered page.
 */
function render($svg, &$content = null)
{
    $pdf = createPdf();
    $pdf->ImageSVG('@' . $svg, 0, 0, 100, 100);
    $content = $pdf->pageContent();
    return rasterize($pdf);
}

function svg($body)
{
    return '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="100" height="100" viewBox="0 0 100 100">' . $body . '</svg>';
}

/**
 * Whether every q in the stream is closed by a Q, and never more Q than q.
 */
function balanced($content)
{
    $depth = 0;
    foreach (preg_split('/\s+/', $content) as $token) {
        if ($token === 'q') {
            $depth++;
        } elseif ($token === 'Q') {
            if (--$depth < 0) {
                return false;
            }
        }
    }
    return $depth === 0;
}

$probe = rasterize(createPdf());
check('pdftoppm renders a blank page white', near(pixel($probe, 50, 50), WHITE, 0));
if ($probe === null) {
    echo 'pdftoppm could not be run; install poppler-utils' . PHP_EOL;
    exit(1);
}

// --- the root element ------------------------------------------------------------------

foreach (array('"' => 'double', "'" => 'single') as $quote => $name) {
    $pdf = createPdf();
    $pdf->ImageSVG('@<svg xmlns=' . $quote . 'http://www.w3.org/2000/svg' . $quote . ' viewBox=' . $quote . '0 0 100 100' . $quote . '>'
        . '<rect width="100" height="100" fill="red"/></svg>', 0, 0, 100, 50);
    $image = rasterize($pdf);
    check('a viewBox in ' . $name . ' quotes fits the box, centred', near(pixel($image, 50, 25), RED) && near(pixel($image, 10, 25), WHITE)
        && near(pixel($image, 90, 25), WHITE) && near(pixel($image, 50, 75), WHITE));
}

$pdf = createPdf();
$pdf->ImageSVG("@<svg xmlns='http://www.w3.org/2000/svg' width='200' height='100' viewBox='0,0,200,100' preserveAspectRatio='xMinYMin meet'>"
    . '<rect width="200" height="100" fill="red"/></svg>', 0, 0, 100, 100);
$image = rasterize($pdf);
check('single-quoted size, a comma-separated viewBox and preserveAspectRatio are read', near(pixel($image, 50, 25), RED) && near(pixel($image, 50, 75), WHITE));

// --- clip paths ------------------------------------------------------------------------

$flip = 'matrix(1,0,0,-1,0,100)';

$image = render(svg('<defs><clipPath id="c"><path transform="' . $flip . '" d="M0 0H100V100H0Z"/></clipPath></defs>'
    . '<g clip-path="url(#c)"><rect x="10" y="10" width="30" height="20" fill="red"/></g>'), $content);
check('a clip path\'s transform does not move the content it clips', near(pixel($image, 25, 20), RED) && near(pixel($image, 25, 80), WHITE));
check('a clipped group leaves q and Q balanced', balanced($content));

$image = render(svg('<defs><clipPath id="c"><path transform="' . $flip . '" d="M0 0H100V100H0Z"/></clipPath></defs>'
    . '<g clip-path="url(#c)"><rect transform="' . $flip . '" x="10" y="10" width="30" height="20" fill="red"/></g>'));
check('content with its own transform keeps it inside a transformed clip', near(pixel($image, 25, 80), RED) && near(pixel($image, 25, 20), WHITE));

$image = render(svg('<defs><clipPath id="c"><rect transform="translate(50,0)" width="50" height="100"/></clipPath></defs>'
    . '<rect clip-path="url(#c)" width="100" height="100" fill="red"/>'));
check('a transformed clip still scissors', near(pixel($image, 75, 50), RED) && near(pixel($image, 25, 50), WHITE));

$image = render(svg('<defs><clipPath id="c"><circle cx="50" cy="50" r="40"/></clipPath></defs>'
    . '<g clip-path="url(#c)"><rect width="100" height="100" fill="red"/></g>'));
check('a clip without a transform scissors', near(pixel($image, 50, 50), RED) && near(pixel($image, 3, 3), WHITE));

$image = render(svg('<defs><clipPath id="left"><rect transform="scale(0.5,1)" width="100" height="100"/></clipPath>'
    . '<clipPath id="top"><rect transform="scale(1,0.5)" width="100" height="100"/></clipPath></defs>'
    . '<g clip-path="url(#left)"><g clip-path="url(#top)"><rect width="100" height="100" fill="red"/></g></g>'
    . '<rect x="80" y="80" width="10" height="10" fill="blue"/>'), $content);
check('nested clips keep only what both let through', near(pixel($image, 25, 25), RED) && near(pixel($image, 75, 25), WHITE) && near(pixel($image, 25, 75), WHITE));
check('content after nested clips is neither clipped nor moved', near(pixel($image, 85, 85), BLUE));
check('nested clips leave q and Q balanced', balanced($content));

$image = render(svg('<defs><clipPath id="c" transform="' . $flip . '"><rect width="100" height="40"/></clipPath></defs>'
    . '<rect clip-path="url(#c)" width="100" height="100" fill="red"/>'));
check('a clipPath element\'s own transform moves only the clip', near(pixel($image, 50, 80), RED) && near(pixel($image, 50, 20), WHITE));

$image = render(svg('<defs><clipPath id="ring"><path transform="' . $flip . '" d="M20 20H80V80H20ZM35 35H65V65H35Z" clip-rule="evenodd"/></clipPath></defs>'
    . '<rect clip-path="url(#ring)" width="100" height="100" fill="red"/>'));
check('an even-odd clip path keeps its hole', near(pixel($image, 25, 50), RED) && near(pixel($image, 50, 50), WHITE));

$image = render(svg('<defs><clipPath id="ring"><path d="M20 20H80V80H20ZM35 35H65V65H35Z"/></clipPath></defs>'
    . '<rect clip-path="url(#ring)" width="100" height="100" fill="red"/>'));
check('a nonzero clip path fills its inner square drawn the same way round', near(pixel($image, 50, 50), RED));

// --- linear gradients ------------------------------------------------------------------

$image = render(svg('<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#ff0000"/>'
    . '<stop offset="1" stop-color="#0000ff"/></linearGradient></defs><rect width="100" height="100" fill="url(#g)"/>'));
$middle = pixel($image, 50, 50);
check('a bounding-box linear gradient with plain fractions blends across the shape',
    near(pixel($image, 3, 50), RED, 60) && near(pixel($image, 96, 50), BLUE, 60)
    && $middle !== null && $middle[0] > 80 && $middle[0] < 175 && $middle[2] > 80 && $middle[2] < 175);

$image = render(svg('<defs><linearGradient id="g" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ff0000"/>'
    . '<stop offset="1" stop-color="#0000ff"/></linearGradient></defs><rect x="50" y="0" width="50" height="100" fill="url(#g)"/>'));
check('a vertical bounding-box gradient runs top to bottom of an offset shape',
    near(pixel($image, 75, 3), RED, 60) && near(pixel($image, 75, 96), BLUE, 60) && near(pixel($image, 25, 50), WHITE));

$image = render(svg('<defs><linearGradient id="g" gradientUnits="userSpaceOnUse" x1="0" y1="0" x2="100" y2="0">'
    . '<stop offset="0" stop-color="#ff0000"/><stop offset="1" stop-color="#0000ff"/></linearGradient></defs>'
    . '<rect width="100" height="100" fill="url(#g)"/>'));
check('a user-space linear gradient still blends', near(pixel($image, 3, 50), RED, 60) && near(pixel($image, 96, 50), BLUE, 60));

$image = render(svg('<defs><linearGradient id="g" gradientUnits="userSpaceOnUse" x1="0" y1="20" x2="0" y2="60">'
    . '<stop offset="0" stop-color="#ff0000"/><stop offset="1" stop-color="#0000ff"/></linearGradient></defs>'
    . '<rect width="100" height="100" fill="url(#g)"/>'));
$middle = pixel($image, 50, 40);
check('a vertical gradient off the middle of the shape starts and ends where it says',
    near(pixel($image, 50, 10), RED, 30) && near(pixel($image, 50, 80), BLUE, 30)
    && $middle !== null && abs($middle[0] - $middle[2]) < 50);

$image = render(svg('<defs><linearGradient id="g"><stop offset="0" stop-color="#ff0000"/><stop offset="1" stop-color="#ff0000"/></linearGradient></defs>'
    . '<path d="M20 20H80V80H20ZM35 35H65V65H35Z" fill-rule="evenodd" fill="url(#g)"/>'));
check('a gradient fill keeps the hole of an even-odd shape', near(pixel($image, 25, 50), RED) && near(pixel($image, 50, 50), WHITE));

// --- patterns --------------------------------------------------------------------------

$stripes = '<defs><pattern id="p" width="10" height="10" patternUnits="userSpaceOnUse">'
    . '<rect width="5" height="10" fill="#0000ff"/></pattern></defs>';

$image = render(svg($stripes . '<rect x="0" y="0" width="100" height="100" fill="url(#p)"/>'), $content);
check('a user-space pattern repeats its tile', near(pixel($image, 2, 50), BLUE) && near(pixel($image, 7, 50), WHITE)
    && near(pixel($image, 92, 50), BLUE) && near(pixel($image, 97, 50), WHITE));
check('a pattern fill leaves q and Q balanced', balanced($content));
check('a pattern draws its tile once, as an XObject', substr_count($content, ' Do') > 1 && preg_match_all('/\/(XT\d+) Do/', $content, $m) && count(array_unique($m[1])) === 1);

$image = render(svg($stripes . '<circle cx="50" cy="50" r="30" fill="url(#p)" stroke="#ff0000" stroke-width="4"/>'));
check('a pattern stays inside the shape it fills', near(pixel($image, 52, 50), BLUE) && near(pixel($image, 5, 5), WHITE));
check('a pattern-filled shape keeps its stroke', near(pixel($image, 50, 20), RED));

$image = render(svg('<defs><pattern id="p" x="0" y="0" width="0.5" height="0.5">'
    . '<rect width="25" height="25" fill="#00ff00"/></pattern></defs><rect width="100" height="100" fill="url(#p)"/>'));
check('a bounding-box pattern tiles in fractions of the shape', near(pixel($image, 12, 12), GREEN) && near(pixel($image, 37, 37), WHITE)
    && near(pixel($image, 62, 62), GREEN) && near(pixel($image, 62, 12), GREEN));

$image = render(svg('<defs><pattern id="p" width="1" height="1" patternContentUnits="objectBoundingBox">'
    . '<rect width="0.5" height="1" fill="#0000ff"/></pattern></defs><rect x="20" y="20" width="60" height="60" fill="url(#p)"/>'));
check('bounding-box pattern content scales with the shape', near(pixel($image, 30, 50), BLUE) && near(pixel($image, 70, 50), WHITE)
    && near(pixel($image, 10, 50), WHITE));

$image = render(svg('<defs><pattern id="p" width="20" height="20" patternUnits="userSpaceOnUse" viewBox="0 0 2 2">'
    . '<rect width="1" height="2" fill="#0000ff"/></pattern></defs><rect width="100" height="100" fill="url(#p)"/>'));
check('a pattern viewBox scales its content into the tile', near(pixel($image, 5, 50), BLUE) && near(pixel($image, 15, 50), WHITE));

$image = render(svg('<defs><pattern id="p" width="10" height="100" patternUnits="userSpaceOnUse" patternTransform="translate(5,0)">'
    . '<rect width="5" height="100" fill="#0000ff"/></pattern></defs><rect width="100" height="100" fill="url(#p)"/>'));
check('a patternTransform moves the tiles', near(pixel($image, 7, 50), BLUE) && near(pixel($image, 2, 50), WHITE));

$image = render(svg('<defs><pattern id="base" width="10" height="10" patternUnits="userSpaceOnUse"><rect width="5" height="10" fill="#0000ff"/></pattern>'
    . '<pattern id="p" href="#base" x="5"/></defs><rect width="100" height="100" fill="url(#p)"/>'));
check('a pattern takes its tile from the pattern it references', near(pixel($image, 7, 50), BLUE) && near(pixel($image, 2, 50), WHITE));

$image = render(svg('<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#ff0000"/><stop offset="1" stop-color="#ff0000"/></linearGradient>'
    . '<pattern id="p" width="20" height="20" patternUnits="userSpaceOnUse"><rect width="10" height="20" fill="url(#g)"/></pattern></defs>'
    . '<rect width="100" height="100" fill="url(#p)"/>'));
check('a pattern tile can hold a gradient', near(pixel($image, 5, 50), RED) && near(pixel($image, 15, 50), WHITE));

$pdf = createPdf();
$template = $pdf->startTemplate(100, 100);
$pdf->ImageSVG('@' . svg($stripes . '<rect width="100" height="100" fill="url(#p)"/>'), 0, 0, 100, 100);
$pdf->endTemplate();
$pdf->printTemplate($template, 0, 0, 100, 100);
$image = rasterize($pdf);
check('a pattern draws inside an XObject template', near(pixel($image, 2, 50), BLUE) && near(pixel($image, 7, 50), WHITE));

$image = render(svg('<defs><pattern id="p" width="0.001" height="0.001" patternUnits="userSpaceOnUse"><rect width="0.0005" height="0.001" fill="#0000ff"/></pattern></defs>'
    . '<rect width="100" height="100" fill="url(#p) #00ff00"/>'), $content);
check('a pattern with too many tiles falls back instead of drawing them', near(pixel($image, 50, 50), GREEN) && strlen($content) < 20000);

// --- paint server fallbacks ------------------------------------------------------------

$image = render(svg('<rect width="50" height="100" fill="url(#missing) #00ff00"/>'));
check('a fill that references nothing uses its fallback colour', near(pixel($image, 25, 50), GREEN));

$image = render(svg('<rect x="10" y="10" width="40" height="40" fill="url(#missing)" stroke="#0000ff" stroke-width="4"/>'
    . '<rect x="60" y="60" width="30" height="30" fill="#ff0000"/>'), $content);
check('a fill that references nothing paints nothing', near(pixel($image, 30, 30), WHITE));
check('a fill that references nothing keeps the stroke', near(pixel($image, 10, 30), BLUE));
check('a fill that references nothing does not clip what follows', near(pixel($image, 75, 75), RED));
check('a fill that references nothing leaves q and Q balanced', balanced($content));

// --- <style> ---------------------------------------------------------------------------

$image = render(svg('<style>.a{fill:#0000ff}#b{fill:#00ff00}circle{fill:#ff0000}</style>'
    . '<rect class="a" width="50" height="50"/><rect id="b" x="50" width="50" height="50"/><circle cx="25" cy="75" r="20"/>'));
check('a class rule styles an element', near(pixel($image, 25, 25), BLUE));
check('an id rule styles an element', near(pixel($image, 75, 25), GREEN));
check('a type rule styles an element', near(pixel($image, 25, 75), RED));

$image = render(svg('<defs><style><![CDATA[ /* shapes */ .x, .y { fill: #00ff00 } rect.z { fill: #0000ff; stroke: none } ]]></style></defs>'
    . '<rect class="y" width="50" height="50"/><rect class="z w" x="50" width="50" height="50"/>'));
check('a rule in <defs> with CDATA, a comment and a selector list applies', near(pixel($image, 25, 25), GREEN));
check('a compound selector applies', near(pixel($image, 75, 25), BLUE));

$image = render(svg('<style>.a{fill:#0000ff} .b{fill:#00ff00}</style>'
    . '<rect class="a" fill="#ff0000" width="50" height="50"/><rect class="a" style="fill:#00ff00" x="50" width="50" height="50"/>'
    . '<rect class="b a" y="50" width="50" height="50"/>'));
check('a rule beats a presentation attribute', near(pixel($image, 25, 25), BLUE));
check('an inline style beats a rule', near(pixel($image, 75, 25), GREEN));
check('the later of two equally specific rules wins', near(pixel($image, 25, 75), GREEN));

$image = render(svg('<style>#i{fill:#00ff00} .c{fill:#0000ff}</style><rect id="i" class="c" width="50" height="50"/>'));
check('an id rule beats a class rule', near(pixel($image, 25, 25), GREEN));

$image = render(svg('<style>.g{fill:#0000ff}</style><g class="g"><rect width="50" height="50"/></g>'));
check('a rule on a group is inherited', near(pixel($image, 25, 25), BLUE));

$image = render(svg('<style>rect:hover{fill:#ff0000} rect[x]{fill:#ff0000} g + rect{fill:#ff0000} @media print { rect{fill:#ff0000} }</style>'
    . '<g/><rect x="0" width="50" height="50" fill="#0000ff"/>'));
check('rules with a pseudo-class, an attribute selector, a sibling combinator or inside an at-rule are ignored', near(pixel($image, 25, 25), BLUE));

$image = render(svg('<style>g circle{fill:#000000} .warm{fill:#ff0000}</style>'
    . '<g class="warm"><circle cx="25" cy="25" r="20"/></g><circle cx="75" cy="75" r="20" class="warm"/>'));
check('a descendant rule beats a property inherited from the group', near(pixel($image, 25, 25), BLACK));
check('a descendant rule applies only inside its ancestor', near(pixel($image, 75, 75), RED));

$image = render(svg('<style>.outer > rect{fill:#0000ff} .outer rect{fill:#00ff00}</style>'
    . '<g class="outer"><rect width="40" height="40"/><g><rect x="50" y="50" width="40" height="40"/></g></g>'));
check('of a child and a descendant rule equally specific, the later wins', near(pixel($image, 20, 20), GREEN));
check('a descendant rule reaches through several levels', near(pixel($image, 70, 70), GREEN));

$image = render(svg('<style>.outer rect{fill:#00ff00} .outer > rect{fill:#0000ff} svg g.a.b > rect#x{fill:#ff0000}</style>'
    . '<g class="outer"><rect width="40" height="40"/><g><rect x="50" y="50" width="40" height="40"/></g></g>'
    . '<g class="a b"><rect id="x" x="50" width="40" height="40"/></g>'));
check('a child rule applies only to a direct child', near(pixel($image, 20, 20), BLUE) && near(pixel($image, 70, 70), GREEN));
check('a combinator selector with compound parts matches', near(pixel($image, 70, 20), RED));

$image = render(svg('<style>.outer rect{fill:#0000ff} #i{fill:#00ff00} rect{fill:#ff0000}</style>'
    . '<g class="outer"><rect width="40" height="40"/><rect id="i" x="50" width="40" height="40"/></g>'));
check('specificity counts every part of a combined selector', near(pixel($image, 20, 20), BLUE) && near(pixel($image, 70, 20), GREEN));

$image = render(svg('<style>.icons rect{fill:#0000ff}</style><defs><g class="icons"><rect id="r" width="20" height="20"/></g></defs>'
    . '<use href="#r" x="50" y="50"/>'));
check('a used element keeps the rules that match it where it is defined', near(pixel($image, 60, 60), BLUE));

$image = render(svg('<defs><rect id="own" width="20" height="20" fill="#0000ff"/><rect id="bare" width="20" height="20"/></defs>'
    . '<use href="#own" fill="#ff0000"/><use href="#bare" x="50" y="50" fill="#00ff00"/>'));
check('a used element keeps its own fill over the use element\'s', near(pixel($image, 10, 10), BLUE));
check('a used element takes the use element\'s fill where it has none', near(pixel($image, 60, 60), GREEN));

$image = render(svg('<style>.s{fill:none;stroke:#0000ff;stroke-width:10}</style><rect class="s" x="20" y="20" width="60" height="60"/>'));
check('a rule can set the stroke', near(pixel($image, 20, 50), BLUE) && near(pixel($image, 50, 50), WHITE));

$image = render(svg('<style>.p{fill:url(#p)}</style>' . $stripes . '<rect class="p" width="100" height="100"/>'));
check('a rule can fill with a pattern', near(pixel($image, 2, 50), BLUE) && near(pixel($image, 7, 50), WHITE));

$image = render(svg('<style>.a{fill:#0000ff}</style><defs><rect id="r" class="a" width="40" height="40"/></defs><use href="#r" x="50" y="50"/>'));
check('a rule styles a used element', near(pixel($image, 70, 70), BLUE));

// --- href without xlink ----------------------------------------------------------------

$image = render(svg('<defs><rect id="r" width="40" height="40" fill="#0000ff"/></defs><use href="#r" x="50" y="50"/>'));
check('use takes href without the xlink prefix', near(pixel($image, 70, 70), BLUE) && near(pixel($image, 20, 20), WHITE));

$image = render(svg('<defs><linearGradient id="base"><stop offset="0" stop-color="#00ff00"/><stop offset="1" stop-color="#00ff00"/></linearGradient>'
    . '<linearGradient id="g" href="#base"/></defs><rect width="100" height="100" fill="url(#g)"/>'));
check('a gradient takes its stops from an href without the xlink prefix', near(pixel($image, 50, 50), GREEN));

// --- use and inheritance from the root -------------------------------------------------

$image = render(svg('<defs><polygon id="t" points="0,0 20,0 20,20 0,20" fill="#0000ff"/></defs><use href="#t" x="50" y="50"/>'));
check('use x and y move an element that has no x and y of its own', near(pixel($image, 60, 60), BLUE) && near(pixel($image, 10, 10), WHITE));

$image = render(svg('<defs><rect id="r" width="20" height="20" transform="translate(10,0)" fill="#0000ff"/></defs>'
    . '<use href="#r" x="50" y="50" transform="translate(-20,-20)"/>'));
check('use applies its transform, then x and y, then the element\'s own transform', near(pixel($image, 50, 40), BLUE) && near(pixel($image, 35, 40), WHITE)
    && near(pixel($image, 70, 60), WHITE));

$image = render('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" fill="none" stroke="#0000ff" stroke-width="10" color="#00ff00">'
    . '<rect x="10" y="10" width="40" height="40"/><circle cx="75" cy="75" r="15" fill="currentColor" stroke="none"/></svg>');
check('presentation attributes on the root svg are inherited', near(pixel($image, 10, 30), BLUE) && near(pixel($image, 30, 30), WHITE)
    && near(pixel($image, 75, 75), GREEN));

$image = render(svg('<rect x="10" y="10" width="20" height="20" fill="#ff0000"/>'));
check('an SVG drawn after one with root attributes does not inherit them', near(pixel($image, 20, 20), RED));

echo PHP_EOL . ($failures === 0 ? 'All checks passed' : $failures . ' check(s) failed') . PHP_EOL;
exit($failures === 0 ? 0 : 1);
