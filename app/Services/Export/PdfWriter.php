<?php
/**
 * Service: PdfWriter
 * Status: Active - Phase 13 (Data Analysis Portal — PDF export)
 *
 * Writes a genuine, PDF-1.4-compliant document by hand (no
 * Composer/dompdf/mPDF dependency) using a monospaced core font
 * (Courier) so header/value columns stay aligned. Good enough for the
 * tabular exports this portal produces; multi-page automatically once a
 * page's line budget is exceeded.
 *
 * Note: PDF's 14 standard fonts only cover Latin-1, so any non-Latin
 * (e.g. Arabic) characters are rendered as "?" — full Unicode would
 * require embedding a font file, which this dependency-free writer
 * intentionally doesn't do. CSV/Excel exports are unaffected.
 * @package UIP
 */

namespace App\Services\Export;

class PdfWriter
{
    private const PAGE_WIDTH = 595.28;  // A4, points
    private const PAGE_HEIGHT = 841.89;
    private const MARGIN = 40;
    private const FONT_SIZE = 9;
    private const LINE_HEIGHT = 13;
    private const CHART_HEIGHT = 130;       // bar height budget, points
    private const CHART_LABEL_SPACE = 26;   // room below bars for axis labels

    /**
     * @param string $path absolute path to write the .pdf file to
     * @param string $title printed at the top of the report
     * @param string[] $header
     * @param array<int,array<int,mixed>> $rows
     */
    public static function writeTable(string $path, string $title, array $header, array $rows): void
    {
        $lines = self::buildLines($title, $header, $rows);
        $pages = self::paginate($lines);
        file_put_contents($path, self::buildPdf($pages));
    }

    /**
     * Multi-section report with an optional real vector bar chart —
     * closes the "التقارير مينفعش تتضمن Charts / Sections منسّقة" gap:
     * PdfWriter used to be a single flat table only. Used by the AI Code
     * Review exports (project/university/faculty/comparison reports)
     * below for a document with distinct Project Info / Scores /
     * Findings / Recommendations / Admin Decision sections plus a bar
     * chart of the six review scores — a genuine filled-rectangle PDF
     * chart drawn with real content-stream operators, not an image.
     *
     * @param string $path absolute path to write the .pdf file to
     * @param string $title report title
     * @param array<string,string> $meta label => value lines under the title (e.g. "Project", "Generated At")
     * @param array<int,array{title:string,header:string[],rows:array<int,array<int,mixed>>}> $sections
     * @param ?array{labels:string[],values:array<int,?int>} $chart 0-100 scale bar values, drawn on page 1 only
     */
    public static function writeReport(string $path, string $title, array $meta, array $sections, ?array $chart = null): void
    {
        $headerLines = self::buildHeaderLines($title, $meta);
        $bodyLines = [];
        foreach ($sections as $i => $section) {
            if ($i > 0) {
                $bodyLines[] = '';
            }
            $bodyLines = array_merge($bodyLines, self::buildSectionLines($section['title'], $section['header'], $section['rows']));
        }

        $allLines = array_merge($headerLines, [''], $bodyLines);

        $perPage = max((int) floor((self::PAGE_HEIGHT - 2 * self::MARGIN) / self::LINE_HEIGHT), 1);
        $reservedForChart = ($chart && !empty($chart['values']))
            ? (int) ceil((self::CHART_HEIGHT + self::CHART_LABEL_SPACE + self::LINE_HEIGHT) / self::LINE_HEIGHT)
            : 0;
        $perPageFirst = max($perPage - $reservedForChart, 1);

        $pages = [];
        $remaining = $allLines;
        $pages[] = array_splice($remaining, 0, $perPageFirst);
        while ($remaining) {
            $pages[] = array_splice($remaining, 0, $perPage);
        }

        $chartForPage0 = ($chart && !empty($chart['values'])) ? self::chartStream($chart['labels'] ?? [], $chart['values']) : null;

        file_put_contents($path, self::buildPdf($pages, $chartForPage0));
    }

    /** @return string[] */
    private static function buildHeaderLines(string $title, array $meta): array
    {
        $lines = [self::clampWidth($title)];
        foreach ($meta as $label => $value) {
            $lines[] = self::clampWidth($label . ': ' . $value);
        }
        return $lines;
    }

    /** @return string[] */
    private static function buildSectionLines(string $sectionTitle, array $header, array $rows): array
    {
        if (empty($header) && empty($rows)) {
            return [self::clampWidth('-- ' . $sectionTitle . ' --'), self::clampWidth('  (none)')];
        }

        $all = array_merge([$header], $rows);
        $widths = [];
        foreach ($all as $row) {
            foreach (array_values($row) as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen((string) $cell));
            }
        }
        $formatRow = function (array $row) use ($widths): string {
            $parts = [];
            foreach (array_values($row) as $i => $cell) {
                $parts[] = str_pad((string) $cell, $widths[$i] ?? 0);
            }
            return self::clampWidth(implode('  ', $parts));
        };
        $sepWidth = array_sum($widths) + max(count($widths) - 1, 0) * 2;

        $lines = [];
        $lines[] = self::clampWidth('-- ' . $sectionTitle . ' --');
        $lines[] = $formatRow($header);
        $lines[] = str_repeat('-', min($sepWidth, self::maxChars()));
        foreach ($rows as $row) {
            $lines[] = $formatRow($row);
        }
        return $lines;
    }

    /** @return string[] */
    private static function buildLines(string $title, array $header, array $rows): array
    {
        $all = array_merge([$header], $rows);

        $widths = [];
        foreach ($all as $row) {
            foreach (array_values($row) as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen((string) $cell));
            }
        }

        $formatRow = function (array $row) use ($widths): string {
            $parts = [];
            foreach (array_values($row) as $i => $cell) {
                $parts[] = str_pad((string) $cell, $widths[$i] ?? 0);
            }
            return self::clampWidth(implode('  ', $parts));
        };

        $sepWidth = array_sum($widths) + max(count($widths) - 1, 0) * 2;

        $lines = [];
        $lines[] = self::clampWidth($title);
        $lines[] = '';
        $lines[] = $formatRow($header);
        $lines[] = str_repeat('-', max(min($sepWidth, self::maxChars()), mb_strlen($title)));
        foreach ($rows as $row) {
            $lines[] = $formatRow($row);
        }
        return $lines;
    }

    private static function maxChars(): int
    {
        // Courier is exactly 0.6em wide per character.
        return (int) floor((self::PAGE_WIDTH - 2 * self::MARGIN) / (0.6 * self::FONT_SIZE));
    }

    private static function clampWidth(string $line): string
    {
        $max = self::maxChars();
        if (mb_strlen($line) <= $max) {
            return $line;
        }
        return mb_substr($line, 0, max($max - 1, 0)) . '…';
    }

    /** @param string[] $lines @return array<int,string[]> */
    private static function paginate(array $lines): array
    {
        $perPage = max((int) floor((self::PAGE_HEIGHT - 2 * self::MARGIN) / self::LINE_HEIGHT), 1);
        $pages = array_chunk($lines, $perPage);
        return $pages ?: [[]];
    }

    /** @param array<int,string[]> $pages @param ?string $chartForPage0 raw content-stream ops appended after page 1's text */
    private static function buildPdf(array $pages, ?string $chartForPage0 = null): string
    {
        $pageCount = count($pages);
        $objects = [];

        // Numbering: 1 catalog, 2 pages, 3 font, then page/content pairs.
        $kids = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = (4 + $i * 2) . ' 0 R';
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $pageCount . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>';

        foreach ($pages as $i => $lines) {
            $pageObjNum = 4 + $i * 2;
            $contentObjNum = 5 + $i * 2;

            $objects[$pageObjNum] = '<< /Type /Page /Parent 2 0 R '
                . '/MediaBox [0 0 ' . self::PAGE_WIDTH . ' ' . self::PAGE_HEIGHT . '] '
                . '/Resources << /Font << /F1 3 0 R >> >> '
                . '/Contents ' . $contentObjNum . ' 0 R >>';

            $stream = self::contentStream($lines);
            if ($i === 0 && $chartForPage0 !== null) {
                $stream .= "\n" . $chartForPage0;
            }
            $objects[$contentObjNum] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        }

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsetMap = [];
        foreach ($objects as $num => $content) {
            $offsetMap[$num] = strlen($pdf);
            $pdf .= $num . " 0 obj\n" . $content . "\nendobj\n";
        }

        $maxObjNum = max(array_keys($objects));
        $xrefOffset = strlen($pdf);
        $pdf .= 'xref' . "\n" . '0 ' . ($maxObjNum + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($n = 1; $n <= $maxObjNum; $n++) {
            $pdf .= isset($offsetMap[$n])
                ? sprintf("%010d 00000 n \n", $offsetMap[$n])
                : "0000000000 00000 f \n";
        }
        $pdf .= "trailer\n<< /Size " . ($maxObjNum + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }

    /** @param string[] $lines */
    private static function contentStream(array $lines): string
    {
        $top = self::PAGE_HEIGHT - self::MARGIN;
        $stream = "BT\n/F1 " . self::FONT_SIZE . " Tf\n" . self::LINE_HEIGHT . " TL\n"
            . self::MARGIN . ' ' . $top . " Td\n";

        foreach ($lines as $i => $line) {
            $escaped = self::escapeText($line);
            $stream .= $i === 0 ? "($escaped) Tj\n" : "T*\n($escaped) Tj\n";
        }

        return $stream . 'ET';
    }

    private static function escapeText(string $text): string
    {
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        // Core Type1 fonts only cover Latin-1 — anything else becomes "?".
        return preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';
    }

    /**
     * Real vector bar chart — filled rectangles (`re`/`f`) plus an axis
     * line, drawn directly with PDF content-stream operators (no image,
     * no external library). Anchored to the bottom-left of the page so it
     * sits in the space reserved by writeReport()'s $reservedForChart,
     * independent of how many text lines happen to be above it. Bars are
     * color-coded by value the same way the admin UI badges scores
     * (>=70 green / >=40 amber / else red) so the exported PDF matches
     * what an admin sees on screen.
     * @param string[] $labels
     * @param array<int,?int> $values 0-100 scale; null renders as an empty/zero bar
     */
    private static function chartStream(array $labels, array $values): string
    {
        $n = max(count($values), 1);
        $chartLeft = self::MARGIN;
        $chartRight = self::PAGE_WIDTH - self::MARGIN;
        $chartWidth = $chartRight - $chartLeft;
        $gap = 8;
        $barWidth = max((($chartWidth - $gap * ($n - 1)) / $n), 4);
        $chartBottom = self::MARGIN + self::CHART_LABEL_SPACE;
        $chartTop = $chartBottom + self::CHART_HEIGHT;

        $stream = "q\n";
        // Baseline axis.
        $stream .= "0.6 0.6 0.6 RG\n0.75 w\n"
            . $chartLeft . ' ' . $chartBottom . " m " . $chartRight . ' ' . $chartBottom . " l S\n";

        foreach (array_values($values) as $i => $value) {
            $v = max(0, min(100, (int) ($value ?? 0)));
            $barH = ($v / 100) * self::CHART_HEIGHT;
            $x = $chartLeft + $i * ($barWidth + $gap);
            $y = $chartBottom;

            [$r, $g, $b] = $v >= 70 ? [0.11, 0.62, 0.42] : ($v >= 40 ? [0.87, 0.62, 0.09] : [0.83, 0.24, 0.24]);
            $stream .= sprintf("%.2F %.2F %.2F rg\n", $r, $g, $b);
            $stream .= sprintf("%.2F %.2F %.2F %.2F re f\n", $x, $y, $barWidth, max($barH, 1));

            // Value, centered above the bar.
            $valueText = self::escapeText((string) $v);
            $tx = $x + ($barWidth / 2) - (strlen($valueText) * self::FONT_SIZE * 0.3);
            $ty = $y + $barH + 4;
            $stream .= "0 0 0 rg\nBT\n/F1 " . (self::FONT_SIZE - 1) . " Tf\n1 0 0 1 " . round($tx, 2) . ' ' . round($ty, 2) . " Tm\n($valueText) Tj\nET\n";

            // Label, below the axis.
            $label = self::escapeText((string) ($labels[$i] ?? ''));
            $maxLabelChars = (int) floor(($barWidth + $gap) / (0.55 * (self::FONT_SIZE - 2)));
            if (strlen($label) > $maxLabelChars) {
                $label = substr($label, 0, max($maxLabelChars - 1, 1)) . '.';
            }
            $lx = $x + ($barWidth / 2) - (strlen($label) * (self::FONT_SIZE - 2) * 0.3);
            $ly = $chartBottom - 12;
            $stream .= "BT\n/F1 " . (self::FONT_SIZE - 2) . " Tf\n1 0 0 1 " . round($lx, 2) . ' ' . round($ly, 2) . " Tm\n($label) Tj\nET\n";
        }

        $stream .= "Q";
        return $stream;
    }
}
