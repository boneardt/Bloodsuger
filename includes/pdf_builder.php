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
