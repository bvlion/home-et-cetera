<?php

declare(strict_types=1);

use BvlionBatch5\Mail\HtmlToPdfConverter;

require_once __DIR__ . '/../vendor/autoload.php';

if ($argc < 2 || $argc > 3) {
    fwrite(
        STDERR,
        "Usage: php bin/convert-html-to-pdf.php <html-file> [pdf-file]\n",
    );
    exit(1);
}

$htmlPath = $argv[1];
$pdfPath = $argv[2] ?? $htmlPath . '.pdf';
$html = file_get_contents($htmlPath);

if (!is_string($html)) {
    fwrite(STDERR, "HTML file could not be read.\n");
    exit(1);
}

try {
    $pdf = (new HtmlToPdfConverter())->convert(
        $html,
        mailLogContext: ['conversion_context' => 'cli'],
    );
} catch (Throwable) {
    fwrite(STDERR, "HTML to PDF conversion failed.\n");
    exit(1);
}

if (file_put_contents($pdfPath, $pdf) === false) {
    fwrite(STDERR, "PDF file could not be written.\n");
    exit(1);
}

fwrite(STDOUT, "PDF written: {$pdfPath}\n");
