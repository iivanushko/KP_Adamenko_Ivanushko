<?php

$file = 'src/Controller/ReportController.php';
$content = file_get_contents($file);

// Find the start of buildReport
$start = strpos($content, '    private function buildReport(Connection $connection, array $filters): array');

if ($start !== false) {
    // Find the end by looking for ordersXlsx
    $end = strpos($content, '    private function ordersXlsx(array $report): StreamedResponse', $start);
    if ($end !== false) {
        $content = substr($content, 0, $start) . substr($content, $end);
        file_put_contents($file, $content);
    }
}
