<?php
// Export an end-of-day report as CSV, Excel (.xlsx) or PDF, and log the export.

// The report as sections: title, column headings, rows
function reportSections(array $rep): array {
    $S = [];
    $sec = function (string $title, array $headers, array $rows) use (&$S) {
        if (!$rows) $rows = [['No entries']];
        $S[] = [$title, $headers, $rows];
    };

    $eggRows = [];
    foreach ($rep['eggs'] ?? [] as $e) {
        $hd = $e['hens'] > 0 ? round($e['eggs'] / $e['hens'] * 100, 1) : '';
        $eggRows[] = [$e['flock_name'], (int)$e['records'], (int)$e['eggs'], (int)$e['broken'], (int)$e['rejected'], $hd];
    }
    $sec('Eggs by flock', ['Flock', 'Records', 'Eggs', 'Broken', 'Rejected', 'HDEP %'], $eggRows);

    $crateRows = [];
    foreach ($rep['crates'] ?? [] as $c) $crateRows[] = [$c['name'], (int)$c['packs'], (int)$c['eggs']];
    $sec('Crates added to stock', ['Product', 'Crates', 'Eggs'], $crateRows);

    $payRows = [];
    foreach ($rep['sales_by_payment'] ?? [] as $p) $payRows[] = [ucwords(str_replace('_', ' ', $p['payment_method'])), (int)$p['n'], round((float)$p['amt'], 2)];
    $sec('Sales by payment', ['Payment', 'Sales', 'Amount (GHS)'], $payRows);

    $chanRows = [];
    foreach ($rep['sales_by_type'] ?? [] as $t) $chanRows[] = [ucfirst($t['type']), (int)$t['n'], round((float)$t['amt'], 2)];
    $sec('Sales by channel', ['Channel', 'Sales', 'Amount (GHS)'], $chanRows);

    $orderRows = [];
    foreach ($rep['orders'] ?? [] as $o) $orderRows[] = [ucwords(str_replace('_', ' ', $o['order_status'])), (int)$o['n']];
    $sec('Orders by stage', ['Status', 'Orders'], $orderRows);

    $mortRows = [];
    foreach ($rep['mortality'] ?? [] as $d) $mortRows[] = [$d['flock_name'], $d['cause'] ?: 'Unknown', (int)$d['deaths']];
    $sec('Mortality', ['Flock', 'Cause', 'Deaths'], $mortRows);

    $healthRows = [];
    foreach ($rep['health'] ?? [] as $h) $healthRows[] = [$h['condition_name'], (int)$h['n'], (int)$h['birds']];
    $sec('Health cases', ['Condition', 'Cases', 'Birds affected'], $healthRows);

    $vacRows = [];
    foreach ($rep['vaccinations'] ?? [] as $v) $vacRows[] = [$v['vaccine_name'], (int)$v['n'], (int)$v['birds']];
    $sec('Vaccinations', ['Vaccine', 'Given', 'Birds'], $vacRows);

    $feedRows = [];
    foreach ($rep['feed'] ?? [] as $f) $feedRows[] = [$f['feed_type'], round((float)$f['kg'], 2), round((float)$f['cost'], 2)];
    $sec('Feed used', ['Feed', 'Kg', 'Cost (GHS)'], $feedRows);

    $stockRows = [];
    foreach ($rep['stock']['eggs'] ?? [] as $e) $stockRows[] = [$e['name'], $e['stock_quantity'] . ' crates (' . ($e['stock_quantity'] * $e['egg_units']) . ' eggs)'];
    foreach ($rep['stock']['batches'] ?? [] as $b) $stockRows[] = ['Batch: ' . $b['flock_name'], $b['quantity'] . ' birds'];
    foreach ($rep['stock']['feed'] ?? [] as $f) $stockRows[] = ['Feed: ' . $f['feed_type'] . ($f['brand'] ? ' (' . $f['brand'] . ')' : ''), $f['quantity_kg'] . ' kg'];
    $sec('Stock at close', ['Item', 'On hand'], $stockRows);

    $actRows = [];
    foreach ($rep['activity'] ?? [] as $a) $actRows[] = [$a['user_name'], (int)$a['n']];
    $sec('Who logged what', ['User', 'Entries'], $actRows);

    return $S;
}

function reportMetaLines(array $meta): array {
    return [
        ['Coopers Farm — Day report'],
        ['Day', $meta['day_date'] ?? ''],
        ['Started', ($meta['started_at'] ?? '') . ' by ' . ($meta['started_by'] ?? '')],
        ['Ended', ($meta['ended_at'] ?? '') . ' by ' . ($meta['ended_by'] ?? '')],
    ];
}

// Every export is written to report_exports and the audit log
function logReportExport(PDO $pdo, int $reportId, string $format): void {
    $pdo->prepare("INSERT INTO report_exports (day_report_id, format, exported_by, exported_by_name, exported_at, ip_address)
        VALUES (?,?,?,?,NOW(),?)")
        ->execute([$reportId, $format, $_SESSION['user_id'] ?? null, $_SESSION['name'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null]);
    auditLog($pdo, 'export_day_report', 'day_report', $reportId, 'Exported day report as ' . strtoupper($format));
}

function exportCsv(array $meta, array $sections, string $filename): void {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // UTF-8 marker so Excel reads the characters correctly
    foreach (reportMetaLines($meta) as $l) fputcsv($out, $l);
    foreach ($sections as [$title, $headers, $rows]) {
        fputcsv($out, []);
        fputcsv($out, [$title]);
        fputcsv($out, $headers);
        foreach ($rows as $r) fputcsv($out, $r);
    }
    fclose($out);
    exit;
}

// A real .xlsx file, written as a zip of XML parts
function exportXlsx(array $meta, array $sections, string $filename): void {
    $rows = []; $rn = 0;
    $addRow = function (array $cells) use (&$rows, &$rn) {
        $rn++;
        $xml = '';
        foreach (array_values($cells) as $i => $v) {
            $ref = chr(65 + min($i, 25)) . $rn;
            if (is_int($v) || is_float($v)) $xml .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
            elseif ($v === '' || $v === null) continue;
            else $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars((string)$v, ENT_XML1) . '</t></is></c>';
        }
        $rows[] = '<row r="' . $rn . '">' . $xml . '</row>';
    };
    foreach (reportMetaLines($meta) as $l) $addRow($l);
    foreach ($sections as [$title, $headers, $data]) {
        $addRow([]);
        $addRow([$title]);
        $addRow($headers);
        foreach ($data as $r) $addRow($r);
    }

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . implode('', $rows) . '</sheetData></worksheet>';
    $parts = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Day report" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
        'xl/worksheets/sheet1.xml' => $sheet,
    ];
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) { exportCsv($meta, $sections, $filename); }
    foreach ($parts as $name => $body) $zip->addFromString($name, $body);
    $zip->close();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

function pdfText(string $s): string {
    $s = str_replace('₵', 'GHS ', $s);
    $s = preg_replace('/[\x00-\x08\x0B-\x1F]/', '', $s);
    $t = @iconv('UTF-8', 'windows-1252//TRANSLIT', $s);
    if ($t === false) $t = $s;
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $t);
}

// A plain PDF with Helvetica text, one line per row, several pages if needed
function makePdf(array $lines): string {
    $perPage = 58;
    $pages = $lines ? array_chunk($lines, $perPage) : [[]];
    $n = count($pages);
    $objs = [];
    $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $kids = [];
    for ($i = 0; $i < $n; $i++) $kids[] = (4 + 2 * $i) . ' 0 R';
    $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $n . ' >>';
    $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    for ($i = 0; $i < $n; $i++) {
        $pageObj = 4 + 2 * $i; $contentObj = 5 + 2 * $i;
        $content = "BT /F1 9 Tf 12 TL 40 800 Td\n";
        foreach ($pages[$i] as $line) $content .= '(' . pdfText((string)$line) . ") Tj T*\n";
        $content .= "ET";
        $objs[$pageObj] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Resources << /Font << /F1 3 0 R >> >> /Contents ' . $contentObj . ' 0 R >>';
        $objs[$contentObj] = '<< /Length ' . strlen($content) . ' >>' . "\nstream\n" . $content . "\nendstream";
    }
    ksort($objs);
    $pdf = "%PDF-1.4\n"; $offsets = [];
    foreach ($objs as $num => $body) {
        $offsets[$num] = strlen($pdf);
        $pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $count = max(array_keys($objs)) + 1;
    $pdf .= "xref\n0 " . $count . "\n0000000000 65535 f \n";
    for ($i = 1; $i < $count; $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
    $pdf .= "trailer\n<< /Size " . $count . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    return $pdf;
}

function exportPdf(array $meta, array $sections, string $filename): void {
    $lines = [];
    foreach (reportMetaLines($meta) as $l) $lines[] = implode('  ', $l);
    foreach ($sections as [$title, $headers, $rows]) {
        $lines[] = '';
        $lines[] = strtoupper($title);
        $lines[] = implode(' | ', $headers);
        foreach ($rows as $r) $lines[] = implode(' | ', array_map(fn($v) => (string)$v, $r));
    }
    $lines = array_map(fn($l) => mb_strlen($l) > 120 ? mb_substr($l, 0, 117) . '...' : $l, $lines);
    $pdf = makePdf($lines);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}
