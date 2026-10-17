<?php

require_once __DIR__ . '/../vendor/tfpdf/tfpdf.php';
require_once __DIR__ . '/../vendor/tfpdf/font/unifont/ttfonts.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/color_classifier.php';
require_once __DIR__ . '/aggregates.php';

class BsPdf extends tFPDF
{
    public function Footer(): void
    {
        $this->SetY(-15);
        $this->SetFont('DejaVu', '', 8);
        $this->SetTextColor(130, 130, 130);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'C');
    }
}

function bs_status_rgb(string $color): array
{
    return match ($color) {
        'red'    => [255, 0, 0],
        'yellow' => [255, 255, 0],
        'green'  => [0, 128, 0],
        default  => [120, 120, 120],
    };
}

/**
 * Builds the report and streams it directly to the browser as a download.
 * Ends the script (Output('D', ...) sends headers + content and this function
 * is only ever called from export_pdf.php as the final step of the request).
 */
function bs_stream_pdf_download(): void
{
    $thresholds = bs_thresholds();
    $averages = bs_all_averages();

    $pdo = bs_db();
    $rows = $pdo->query('SELECT * FROM measurements ORDER BY timestamp DESC')->fetchAll();

    $pdf = new BsPdf('P', 'mm', 'A4');
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(false, 0);
    $pdf->AddFont('DejaVu', '', 'DejaVuSans.ttf', true);
    $pdf->AddFont('DejaVu', 'B', 'DejaVuSans-Bold.ttf', true);
    $pdf->AliasNbPages();
    $pdf->AddPage();

    $leftX = 15;
    $pageRightY = 297 - 20; // A4 height minus bottom margin reserved for footer

    // --- Header ---
    $pdf->SetFont('DejaVu', 'B', 18);
    $pdf->Cell(0, 10, 'Bloodsuger — Blood Glucose Report', 0, 1);

    $pdf->SetFont('DejaVu', '', 10);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 6, 'Generated ' . (new DateTime())->format('d M Y, H:i'), 0, 1);

    if (!empty($rows)) {
        $oldest = end($rows);
        $newest = $rows[0];
        $pdf->Cell(0, 6, 'Data from ' . bs_format_dt_display($oldest['timestamp']) . ' to ' . bs_format_dt_display($newest['timestamp']), 0, 1);
    } else {
        $pdf->Cell(0, 6, 'No measurements imported yet.', 0, 1);
    }

    $pdf->Cell(0, 6, sprintf(
        'Thresholds (mmol/L) — Red below %.1f, Yellow %.1f-%.1f and %.1f-%.1f, Green %.1f-%.1f',
        $thresholds['red_low_max'],
        $thresholds['red_low_max'], $thresholds['green_min'],
        $thresholds['green_max'], $thresholds['red_high_min'],
        $thresholds['green_min'], $thresholds['green_max']
    ), 0, 1);
    // Status is never color-only in this report — each row also carries a text code
    // (L/BL/OK/BH/H) so it reads correctly for colorblind readers or in grayscale print.
    $pdf->Cell(0, 6, 'Status codes — L = Low, BL = Borderline low, OK = In range, BH = Borderline high, H = High', 0, 1);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(4);

    // --- Summary block ---
    $pdf->SetFont('DejaVu', 'B', 13);
    $pdf->Cell(0, 8, 'Averages', 0, 1);
    $pdf->SetFont('DejaVu', '', 11);

    $labels = [
        'd7'  => '7-day average',
        'd14' => '14-day average',
        'd30' => '30-day average',
        'all' => 'All-time average',
    ];
    foreach ($labels as $key => $label) {
        $avg = $averages[$key];
        $text = $avg['avg'] !== null
            ? sprintf('%.1f mmol/L  (%d reading%s)', $avg['avg'], $avg['n'], $avg['n'] === 1 ? '' : 's')
            : 'No data';
        $pdf->Cell(60, 7, $label, 0, 0);
        $pdf->Cell(0, 7, $text, 0, 1);
    }
    $pdf->Ln(2);
    $y = $pdf->GetY();
    $pdf->SetDrawColor(210, 210, 200);
    $pdf->Line($leftX, $y, 195, $y);
    $pdf->Ln(6);

    // --- Measurement table (borderless, editorial style) ---
    // Columns sum to exactly 180mm: the printable width of A4 (210mm) minus 15mm margins each side.
    $colDateW = 38;
    $colValueW = 20;
    $colSwatchW = 6;
    $colStatusW = 12;
    $colNotesW = 104;
    $lineHeight = 5;

    $drawTableHeader = function () use ($pdf, $leftX, $colDateW, $colValueW, $colSwatchW, $colStatusW, $colNotesW) {
        $pdf->SetXY($leftX, $pdf->GetY());
        $pdf->SetFont('DejaVu', 'B', 10);
        $pdf->SetFillColor(235, 235, 228);
        $pdf->Cell($colDateW, 8, 'Date / time', 0, 0, 'L', true);
        $pdf->Cell($colValueW, 8, 'mmol/L', 0, 0, 'L', true);
        $pdf->Cell($colSwatchW, 8, '', 0, 0, 'C', true);
        $pdf->Cell($colStatusW, 8, 'Status', 0, 0, 'L', true);
        $pdf->Cell($colNotesW, 8, 'Notes', 0, 1, 'L', true);
        $pdf->SetFont('DejaVu', '', 9);
    };
    $drawTableHeader();

    foreach ($rows as $row) {
        $notes = (string) ($row['notes'] ?? '');
        if (mb_strlen($notes) > 220) {
            $notes = mb_substr($notes, 0, 220) . '…';
        }
        $notes = str_replace("\n", ' ', $notes);

        $pdf->SetFont('DejaVu', '', 9);
        // Word-wrapping breaks only at spaces, so it never packs as tightly as this raw
        // width division assumes — pad the estimate to stay a safe upper bound.
        $estimatedLines = max(1, (int) ceil(($pdf->GetStringWidth($notes) * 1.2) / max(1, $colNotesW - 2)));
        $estimatedHeight = max($lineHeight + 2, $estimatedLines * $lineHeight + 2);

        if ($pdf->GetY() + $estimatedHeight > $pageRightY) {
            $pdf->AddPage();
            $drawTableHeader();
        }

        $rowX = $leftX;
        $rowY = $pdf->GetY();

        $pdf->SetXY($rowX, $rowY);
        $pdf->Cell($colDateW, $lineHeight, bs_format_dt_display($row['timestamp']));
        $pdf->Cell($colValueW, $lineHeight, number_format((float) $row['value'], 1));

        $detail = bs_classify_detail((float) $row['value'], $thresholds);
        [$r, $g, $b] = bs_status_rgb($detail['color']);
        $pdf->SetFillColor($r, $g, $b);
        $swatchSize = 4;
        $pdf->Rect($rowX + $colDateW + $colValueW + ($colSwatchW - $swatchSize) / 2, $rowY + 0.5, $swatchSize, $swatchSize, 'F');

        // Status is never color-only: the letter code (L/BL/OK/BH/H) carries the
        // same meaning as the swatch color, so it still reads in grayscale or for
        // colorblind readers.
        $pdf->SetFont('DejaVu', 'B', 8);
        $pdf->SetXY($rowX + $colDateW + $colValueW + $colSwatchW, $rowY);
        $pdf->Cell($colStatusW, $lineHeight, $detail['code']);
        $pdf->SetFont('DejaVu', '', 9);

        $pdf->SetXY($rowX + $colDateW + $colValueW + $colSwatchW + $colStatusW, $rowY);
        $pdf->MultiCell($colNotesW, $lineHeight, $notes, 0, 'L');

        $bottomY = max($rowY + $estimatedHeight, $pdf->GetY());
        $pdf->SetDrawColor(232, 232, 225);
        $pdf->Line($rowX, $bottomY, $rowX + $colDateW + $colValueW + $colSwatchW + $colStatusW + $colNotesW, $bottomY);
        $pdf->SetXY($leftX, $bottomY + 1.5);
    }

    $filename = 'bloodsuger-report-' . (new DateTime())->format('Y-m-d') . '.pdf';
    $pdf->Output('D', $filename);
}

/**
 * Draws the same conceptual chart as assets/js/chart.js (bands, dotted threshold
 * lines, polyline + markers, legend) using tFPDF primitives instead of SVG, and
 * streams it as a downloadable PDF. $from/$to are plain 'Y-m-d' strings, already
 * validated by export_chart_pdf.php before this is called.
 *
 * This duplicates chart.js's geometry/band logic by necessity — PHP/tFPDF and
 * browser SVG can't share code — the same accepted duplication as
 * bs_classify_detail() / dashboard.js's classify(). Both sides read the 4
 * threshold numbers from their own single source of truth (bs_thresholds()
 * here, the data-threshold-* DOM attributes there).
 */
function bs_stream_chart_pdf_download(string $from, string $to): void
{
    $thresholds = bs_thresholds();

    $pdo = bs_db();
    $stmt = $pdo->prepare('SELECT timestamp, value FROM measurements WHERE timestamp >= ? AND timestamp <= ? ORDER BY timestamp ASC');
    $stmt->execute([$from . 'T00:00:00', $to . 'T23:59:59']);
    $points = $stmt->fetchAll();

    $pdf = new BsPdf('P', 'mm', 'A4');
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(false, 0);
    $pdf->AddFont('DejaVu', '', 'DejaVuSans.ttf', true);
    $pdf->AddFont('DejaVu', 'B', 'DejaVuSans-Bold.ttf', true);
    $pdf->AliasNbPages();
    $pdf->AddPage();

    $leftX = 15;
    $rightX = 195;
    $filename = 'bloodsuger-chart-' . $from . '-to-' . $to . '.pdf';

    $fromDisplay = DateTime::createFromFormat('Y-m-d', $from);
    $toDisplay = DateTime::createFromFormat('Y-m-d', $to);

    // --- Header ---
    $pdf->SetFont('DejaVu', 'B', 18);
    $pdf->Cell(0, 10, 'Bloodsuger — Glucose Chart', 0, 1);

    $pdf->SetFont('DejaVu', '', 10);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 6, 'Generated ' . (new DateTime())->format('d M Y, H:i'), 0, 1);
    $pdf->Cell(0, 6, 'Period: ' . ($fromDisplay ? $fromDisplay->format('d M Y') : $from) . ' to ' . ($toDisplay ? $toDisplay->format('d M Y') : $to), 0, 1);
    $pdf->Cell(0, 6, sprintf(
        'Thresholds (mmol/L) — Red below %.1f, Yellow %.1f-%.1f and %.1f-%.1f, Green %.1f-%.1f',
        $thresholds['red_low_max'],
        $thresholds['red_low_max'], $thresholds['green_min'],
        $thresholds['green_max'], $thresholds['red_high_min'],
        $thresholds['green_min'], $thresholds['green_max']
    ), 0, 1);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(4);

    if (empty($points)) {
        $pdf->SetFont('DejaVu', '', 12);
        $pdf->Cell(0, 10, 'No measurements in this date range.', 0, 1);
        $pdf->Output('D', $filename);
        return;
    }

    // --- Chart geometry (mm) ---
    $axisGutter = 14; // left-hand gutter reserved for Y-axis numeric labels
    $chartLeft = $leftX + $axisGutter;
    $chartRight = $rightX;
    $chartWidth = $chartRight - $chartLeft;
    $chartTop = $pdf->GetY() + 8;
    $chartHeight = 130;
    $chartBottom = $chartTop + $chartHeight;

    $values = array_map(static fn ($p) => (float) $p['value'], $points);
    $dataMin = min($values);
    $dataMax = max($values);
    $allMin = min($dataMin, $thresholds['red_low_max']);
    $allMax = max($dataMax, $thresholds['red_high_min']);
    $pad = ($allMax - $allMin) * 0.1;
    if ($pad <= 0) {
        $pad = 1;
    }
    $yMin = $allMin - $pad;
    $yMax = $allMax + $pad;

    $yScale = static function (float $v) use ($chartTop, $chartHeight, $yMin, $yMax): float {
        return $chartTop + $chartHeight * (1 - ($v - $yMin) / ($yMax - $yMin));
    };
    $n = count($points);
    $xScale = static function (int $i) use ($chartLeft, $chartWidth, $n): float {
        return $n <= 1 ? $chartLeft + $chartWidth / 2 : $chartLeft + $chartWidth * $i / ($n - 1);
    };

    // Shaded yellow/red threshold bands — green ("in range") is left as the
    // plain page background, matching the on-screen chart.
    $tint = static fn (int $c): int => (int) round($c + (255 - $c) * 0.82);
    $bandDefs = [
        ['from' => $yMin, 'to' => $thresholds['red_low_max'], 'color' => 'red'],
        ['from' => $thresholds['red_low_max'], 'to' => $thresholds['green_min'], 'color' => 'yellow'],
        ['from' => $thresholds['green_max'], 'to' => $thresholds['red_high_min'], 'color' => 'yellow'],
        ['from' => $thresholds['red_high_min'], 'to' => $yMax, 'color' => 'red'],
    ];
    foreach ($bandDefs as $band) {
        $top = $yScale(min($band['to'], $yMax));
        $bottom = $yScale(max($band['from'], $yMin));
        if ($bottom <= $top) {
            continue;
        }
        [$r, $g, $b] = bs_status_rgb($band['color']);
        // tFPDF fills have no opacity control, so these are drawn as fully-opaque
        // pastel tints rather than overlaying a translucent rect like the SVG version.
        $pdf->SetFillColor($tint($r), $tint($g), $tint($b));
        $pdf->Rect($chartLeft, $top, $chartWidth, $bottom - $top, 'F');
    }

    $pdf->SetDrawColor(210, 210, 200);
    $pdf->Rect($chartLeft, $chartTop, $chartWidth, $chartHeight);

    // Dotted boundary lines at each of the 4 thresholds — this vendored tFPDF
    // has no dashed-line primitive, so each is hand-drawn as a run of short
    // Line() segments rather than a single styled stroke.
    $pdf->SetDrawColor(130, 130, 130);
    $pdf->SetFont('DejaVu', '', 8);
    $drawDottedLine = static function (BsPdf $pdf, float $x1, float $x2, float $y): void {
        $dash = 2.0;
        $gap = 1.5;
        $x = $x1;
        while ($x < $x2) {
            $segEnd = min($x + $dash, $x2);
            $pdf->Line($x, $y, $segEnd, $y);
            $x += $dash + $gap;
        }
    };
    foreach ([$thresholds['red_low_max'], $thresholds['green_min'], $thresholds['green_max'], $thresholds['red_high_min']] as $t) {
        $y = $yScale($t);
        $drawDottedLine($pdf, $chartLeft, $chartRight, $y);
        $pdf->SetXY($leftX, $y - 2.5);
        $pdf->Cell($axisGutter - 1, 5, number_format($t, 1), 0, 0, 'R');
    }

    // Y-axis min/max labels.
    $pdf->SetXY($leftX, $yScale($yMax) - 2.5);
    $pdf->Cell($axisGutter - 1, 5, number_format($yMax, 1), 0, 0, 'R');
    $pdf->SetXY($leftX, $yScale($yMin) - 2.5);
    $pdf->Cell($axisGutter - 1, 5, number_format($yMin, 1), 0, 0, 'R');

    // The glucose line itself, plus a small square marker at each reading
    // (this tFPDF build has no circle primitive to rely on).
    $pdf->SetDrawColor(204, 120, 92); // approximates --accent (#cc785c); not a
                                      // status color, so bs_status_rgb() doesn't cover it.
    $pdf->SetFillColor(204, 120, 92);
    $prevX = null;
    $prevY = null;
    foreach ($points as $i => $point) {
        $x = $xScale($i);
        $y = $yScale((float) $point['value']);
        if ($prevX !== null) {
            $pdf->Line($prevX, $prevY, $x, $y);
        }
        $pdf->Rect($x - 0.75, $y - 0.75, 1.5, 1.5, 'F');
        $prevX = $x;
        $prevY = $y;
    }

    // X-axis: first/last reading dates only, same simplification as the
    // on-screen chart.
    $pdf->SetFont('DejaVu', '', 8);
    $pdf->SetTextColor(100, 100, 100);
    $firstDt = DateTime::createFromFormat('Y-m-d\TH:i:s', $points[0]['timestamp']);
    $lastDt = DateTime::createFromFormat('Y-m-d\TH:i:s', $points[$n - 1]['timestamp']);
    $pdf->SetXY($chartLeft, $chartBottom + 2);
    $pdf->Cell($chartWidth / 2, 5, $firstDt ? $firstDt->format('d M Y') : '', 0, 0, 'L');
    $pdf->SetXY($chartLeft + $chartWidth / 2, $chartBottom + 2);
    $pdf->Cell($chartWidth / 2, 5, $lastDt ? $lastDt->format('d M Y') : '', 0, 0, 'R');
    $pdf->SetTextColor(0, 0, 0);

    // --- Legend (reusing the swatch-Rect + adjacent-Cell idiom from the main report table) ---
    $legendY = $chartBottom + 14;
    $pdf->SetFont('DejaVu', '', 9);
    $legendItems = [
        ['color' => [204, 120, 92], 'label' => 'Reading'],
        ['color' => bs_status_rgb('yellow'), 'label' => 'Borderline zone (BL/BH)'],
        ['color' => bs_status_rgb('red'), 'label' => 'Out-of-range zone (L/H)'],
    ];
    $legendX = $chartLeft;
    foreach ($legendItems as $item) {
        $pdf->SetFillColor($item['color'][0], $item['color'][1], $item['color'][2]);
        $pdf->Rect($legendX, $legendY + 1, 4, 4, 'F');
        $pdf->SetXY($legendX + 6, $legendY);
        $pdf->Cell(60, 6, $item['label']);
        $legendX += 60;
    }
    $pdf->SetXY($chartLeft, $legendY + 8);
    $pdf->Cell(0, 6, 'Dotted line: threshold boundary (value shown on the left)');

    $pdf->Output('D', $filename);
}
