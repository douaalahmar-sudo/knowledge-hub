<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

class DocumentTextExtractor
{
    public function extract(string $storedPath): string
    {
        $absolutePath = Storage::disk('local')->path($storedPath);
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        if ($extension === 'pdf') {
            return $this->extractFromPdf($absolutePath);
        }

        if (in_array($extension, ['txt', 'md', 'csv', 'log'], true)) {
            return trim((string) file_get_contents($absolutePath));
        }

        if (in_array($extension, ['png', 'jpg', 'jpeg', 'webp'], true)) {
            return $this->extractFromImage($absolutePath);
        }

        return trim((string) file_get_contents($absolutePath));
    }

    private function extractFromPdf(string $absolutePath): string
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($absolutePath);

        return trim($pdf->getText());
    }

    private function extractFromImage(string $absolutePath): string
    {
        $binaryPath = $this->resolveTesseractBinary();

        if ($binaryPath === null) {
            return '';
        }

        $outputBase = tempnam(sys_get_temp_dir(), 'kh_ocr_');

        if ($outputBase === false) {
            return '';
        }

        $outputBase = $outputBase . '_txt';
        $command = sprintf('"%s" "%s" "%s"', $binaryPath, $absolutePath, $outputBase);
        exec($command, $output, $exitCode);

        $textFile = $outputBase . '.txt';

        if ($exitCode !== 0 || ! file_exists($textFile)) {
            @unlink($outputBase);

            return '';
        }

        $text = trim((string) file_get_contents($textFile));
        @unlink($textFile);
        @unlink($outputBase);

        return $text;
    }

    private function resolveTesseractBinary(): ?string
    {
        $output = [];
        $exitCode = 0;
        exec('where tesseract', $output, $exitCode);

        if ($exitCode !== 0 || empty($output[0])) {
            return null;
        }

        return trim($output[0]);
    }
}