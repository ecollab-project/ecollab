<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/BlankPresentationTemplate.php';

if (!class_exists('ZipArchive')) {
    fwrite(STDOUT, "SKIP: PHP zip extension unavailable in this CLI environment.\n");
    exit(0);
}

$target = tempnam(sys_get_temp_dir(), 'ecollab-pptx-');
if ($target === false) throw new RuntimeException('Cannot create temporary file.');
try {
    if (!BlankPresentationTemplate::create($target)) {
        throw new RuntimeException('Fallback failed to create the PowerPoint package.');
    }
    $zip = new ZipArchive();
    if ($zip->open($target) !== true) throw new RuntimeException('Fallback is not a valid ZIP file.');
    $expected = [
        '[Content_Types].xml', '_rels/.rels', 'ppt/presentation.xml',
        'ppt/slides/slide1.xml', 'ppt/slideLayouts/slideLayout1.xml',
        'ppt/slideMasters/slideMaster1.xml', 'ppt/theme/theme1.xml'
    ];
    foreach ($expected as $part) {
        $xml = $zip->getFromName($part);
        if ($xml === false) throw new RuntimeException("Missing OOXML component: $part");
        $doc = new DOMDocument();
        if (!$doc->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException("Invalid XML component: $part");
        }
    }
    $zip->close();
    echo "PASS: fallback PowerPoint contains a valid OOXML package.\n";
} finally {
    @unlink($target);
}
