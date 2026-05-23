<?php

namespace App\Controller;

use App\Service\OrderService;
use Doctrine\DBAL\Connection;
use Dompdf\Dompdf;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/reports', name: 'reports_')]
class ReportController extends AbstractController
{
    #[Route('/orders', name: 'orders', methods: ['GET'])]
    public function orders(Request $request, Connection $connection): Response
    {
        $filters = $this->filters($request);
        $report = $this->buildReport($connection, $filters);
        $format = (string) $request->query->get('format', 'html');

        if ($format === 'pdf') {
            $html = $this->renderView('reports/orders_pdf.html.twig', ['filters' => $filters, 'report' => $report]);
            $dompdf = new Dompdf(['defaultFont' => 'DejaVu Sans']);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();

            return new Response($dompdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="orders-report.pdf"',
            ]);
        }

        if ($format === 'xlsx') {
            return $this->ordersXlsx($report);
        }

        return $this->render('reports/orders.html.twig', [
            'filters' => $filters,
            'report' => $report,
            'statuses' => OrderService::STATUSES,
            'event_types' => OrderService::EVENT_TYPES,
        ]);
    }

    #[Route('/profitability', name: 'profitability', methods: ['GET'])]
    public function profitability(Connection $connection): Response
    {
        $report = $connection->fetchAllAssociative(
            "SELECT d.dish_name, d.price_category, d.cost_price, d.sale_price, d.profit,
                    COALESCE(SUM(CASE WHEN o.status <> 'Отменен' THEN od.serving_number ELSE 0 END), 0) AS total_sold,
                    COALESCE(SUM(CASE WHEN o.status <> 'Отменен' THEN od.serving_number ELSE 0 END) * d.profit, 0) AS total_profit
             FROM dish d
             LEFT JOIN order_details od ON od.dish_id = d.dish_id
             LEFT JOIN orders o ON o.order_id = od.order_id
             GROUP BY d.dish_id, d.dish_name, d.price_category, d.cost_price, d.sale_price, d.profit
             ORDER BY total_profit DESC, total_sold DESC"
        );

        $totalOverallProfit = array_sum(array_column($report, 'total_profit'));

        return $this->render('reports/profitability.html.twig', [
            'report' => $report,
            'total_overall_profit' => $totalOverallProfit,
        ]);
    }

    #[Route('/warehouse', name: 'warehouse', methods: ['GET'])]
    public function warehouse(Request $request, Connection $connection): Response
    {
        $filters = $this->warehouseFilters($request);
        $report = $this->buildWarehouseReport($connection, $filters);
        $format = (string) $request->query->get('format', 'html');

        if ($format === 'pdf') {
            $html = $this->renderView('reports/warehouse_pdf.html.twig', ['report' => $report, 'filters' => $filters]);
            $dompdf = new Dompdf(['defaultFont' => 'DejaVu Sans']);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();

            return new Response($dompdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="warehouse-report.pdf"',
            ]);
        }

        if ($format === 'xlsx') {
            return $this->warehouseXlsx($report);
        }

        return $this->render('reports/warehouse.html.twig', [
            'report' => $report,
            'filters' => $filters,
        ]);
    }

    private function filters(Request $request): array
    {
        return [
            'date_from' => (string) $request->query->get('date_from', date('Y-m-01')),
            'date_to' => (string) $request->query->get('date_to', date('Y-m-d')),
            'status' => (string) $request->query->get('status', ''),
            'event_type' => (string) $request->query->get('event_type', ''),
        ];
    }

    private function warehouseFilters(Request $request): array
    {
        return [
            'q' => trim((string) $request->query->get('q', '')),
            'low' => (string) $request->query->get('low', ''),
            'status' => (string) $request->query->get('status', ''),
            'date_from' => (string) $request->query->get('date_from', ''),
            'date_to' => (string) $request->query->get('date_to', ''),
        ];
    }

    private function buildReport(Connection $connection, array $filters): array
    {
        $where = ['o.event_date BETWEEN :date_from AND :date_to'];
        $params = ['date_from' => $filters['date_from'], 'date_to' => $filters['date_to']];

        if ($filters['status'] !== '') {
            $where[] = 'o.status = :status';
            $params['status'] = $filters['status'];
        }
        if ($filters['event_type'] !== '') {
            $where[] = 'o.event_type = :event_type';
            $params['event_type'] = $filters['event_type'];
        }

        $whereSql = 'WHERE '.implode(' AND ', $where);
        $orders = $connection->fetchAllAssociative(
            "SELECT o.event_date, o.status, o.event_type, o.rental_cost AS total_cost, o.prepayment_amount, o.is_fully_paid,
                    c.client_full_name, m.manager_full_name
             FROM orders o
             JOIN client c ON c.client_id = o.client_id
             JOIN manager m ON m.manager_id = o.manager_id
             $whereSql
             ORDER BY o.event_date, c.client_full_name",
            $params
        );

        $byType = $connection->fetchAllAssociative(
            "SELECT o.event_type, COUNT(*) AS orders_count, COALESCE(SUM(o.rental_cost), 0) AS revenue
             FROM orders o $whereSql
             GROUP BY o.event_type
             ORDER BY revenue DESC",
            $params
        );

        return [
            'orders' => $orders,
            'by_type' => $byType,
            'max_type_revenue' => max([1, ...array_map(static fn (array $row): float => (float) $row['revenue'], $byType)]),
            'total_count' => count($orders),
            'total_revenue' => array_sum(array_map(static fn (array $row): float => (float) $row['total_cost'], $orders)),
            'total_prepayment' => array_sum(array_map(static fn (array $row): float => (float) $row['prepayment_amount'], $orders)),
        ];
    }

    private function buildWarehouseReport(Connection $connection, array $filters): array
    {
        $stockWhere = [];
        $stockParams = [];
        if ($filters['q'] !== '') {
            $stockWhere[] = 'p.product_name ILIKE :q';
            $stockParams['q'] = '%'.$filters['q'].'%';
        }
        if ($filters['low'] === '1') {
            $stockWhere[] = 's.quantity <= s.min_quantity';
        }
        $stockWhereSql = $stockWhere === [] ? '' : 'WHERE '.implode(' AND ', $stockWhere);

        $stock = $connection->fetchAllAssociative(
            "SELECT p.product_name, s.quantity, s.min_quantity, s.last_restock_date,
                    CASE WHEN s.quantity <= s.min_quantity THEN TRUE ELSE FALSE END AS is_low
             FROM product_stock s
             JOIN product p ON p.product_id = s.product_id
             $stockWhereSql
             ORDER BY is_low DESC, p.product_name",
            $stockParams
        );

        $requestWhere = [];
        $requestParams = [];
        if ($filters['status'] !== '') {
            $requestWhere[] = 'sr.status = :status';
            $requestParams['status'] = $filters['status'];
        }
        if ($filters['date_from'] !== '') {
            $requestWhere[] = 'sr.request_date >= :date_from';
            $requestParams['date_from'] = $filters['date_from'];
        }
        if ($filters['date_to'] !== '') {
            $requestWhere[] = 'sr.request_date <= :date_to';
            $requestParams['date_to'] = $filters['date_to'];
        }
        $requestWhereSql = $requestWhere === [] ? '' : 'WHERE '.implode(' AND ', $requestWhere);

        $requests = $connection->fetchAllAssociative(
            "SELECT sr.request_date, sr.status, s.supplier_name, m.manager_full_name,
                    COALESCE(SUM(rd.products_number), 0) AS total_products,
                    COALESCE(string_agg(p.product_name || ' x ' || rd.products_number, ', ' ORDER BY p.product_name), '') AS products
             FROM supplier_request sr
             JOIN supplier s ON s.supplier_id = sr.supplier_id
             JOIN manager m ON m.manager_id = sr.manager_id
             LEFT JOIN request_details rd ON rd.request_id = sr.request_id
             LEFT JOIN product p ON p.product_id = rd.product_id
             $requestWhereSql
             GROUP BY sr.request_id, s.supplier_name, m.manager_full_name
             ORDER BY sr.request_date DESC, sr.request_id DESC
             LIMIT 30",
            $requestParams
        );

        $bySupplier = $connection->fetchAllAssociative(
            "SELECT s.supplier_name, COUNT(sr.request_id) AS requests_count, COALESCE(SUM(rd.products_number), 0) AS total_products
             FROM supplier s
             LEFT JOIN supplier_request sr ON sr.supplier_id = s.supplier_id
             LEFT JOIN request_details rd ON rd.request_id = sr.request_id
             ".($requestWhere === [] ? '' : 'WHERE '.implode(' AND ', array_map(static fn (string $clause): string => str_replace('sr.', 'sr.', $clause), $requestWhere)))."
             GROUP BY s.supplier_id, s.supplier_name
             ORDER BY total_products DESC, s.supplier_name",
            $requestParams
        );

        return [
            'stock' => $stock,
            'requests' => $requests,
            'by_supplier' => $bySupplier,
            'max_supplier_total' => max([1, ...array_map(static fn (array $row): float => (float) $row['total_products'], $bySupplier)]),
            'products_count' => count($stock),
            'low_count' => count(array_filter($stock, static fn (array $row): bool => (bool) $row['is_low'])),
            'total_quantity' => array_sum(array_map(static fn (array $row): float => (float) $row['quantity'], $stock)),
            'pending_requests' => count(array_filter($requests, static fn (array $row): bool => $row['status'] !== 'Получено')),
            'received_requests' => count(array_filter($requests, static fn (array $row): bool => $row['status'] === 'Получено')),
        ];
    }

    private function ordersXlsx(array $report): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Заказы');
        $sheet->fromArray(['Дата', 'Клиент', 'Менеджер', 'Тип', 'Статус', 'Стоимость', 'Предоплата', 'Оплачен'], null, 'A1');

        $rowNumber = 2;
        foreach ($report['orders'] as $order) {
            $sheet->fromArray([
                $order['event_date'],
                $order['client_full_name'],
                $order['manager_full_name'],
                $order['event_type'],
                $order['status'],
                (float) $order['total_cost'],
                (float) $order['prepayment_amount'],
                $order['is_fully_paid'] ? 'Да' : 'Нет',
            ], null, 'A'.$rowNumber);
            $rowNumber++;
        }

        $rowNumber++;
        $sheet->fromArray(['Итого', '', '', '', '', (float) $report['total_revenue'], (float) $report['total_prepayment'], 'К оплате: '.number_format((float) ($report['total_revenue'] - $report['total_prepayment']), 2, ',', ' ')], null, 'A'.$rowNumber);
        $sheet->getStyle('A'.$rowNumber.':H'.$rowNumber)->getFont()->setBold(true);

        $sheet->fromArray(['Тип мероприятия', 'Выручка'], null, 'J1');
        $chartRow = 2;
        foreach ($report['by_type'] as $row) {
            $sheet->fromArray([$row['event_type'], (float) $row['revenue']], null, 'J'.$chartRow);
            $chartRow++;
        }
        $this->addBarChart($sheet, 'ordersRevenueChart', 'Выручка по типам мероприятий', 'J', 'K', $chartRow - 1, 'J5', 'N18');

        foreach (range('A', 'K') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return new StreamedResponse(static function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->setIncludeCharts(true);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="orders-report.xlsx"',
        ]);
    }

    private function warehouseXlsx(array $report): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Склад');
        $sheet->fromArray(['Продукт', 'Остаток', 'Минимум', 'Последнее пополнение', 'Состояние'], null, 'A1');

        $rowNumber = 2;
        foreach ($report['stock'] as $item) {
            $sheet->fromArray([
                $item['product_name'],
                (float) $item['quantity'],
                (float) $item['min_quantity'],
                $item['last_restock_date'],
                $item['is_low'] ? 'Нужно пополнить' : 'Достаточно',
            ], null, 'A'.$rowNumber);
            $rowNumber++;
        }

        $rowNumber++;
        $sheet->fromArray(['Итого', (float) $report['total_quantity'], '', '', 'Низких остатков: '.$report['low_count']], null, 'A'.$rowNumber);
        $sheet->getStyle('A'.$rowNumber.':E'.$rowNumber)->getFont()->setBold(true);

        $sheet->setTitle('Склад');
        $requestsSheet = $spreadsheet->createSheet();
        $requestsSheet->setTitle('Поставки');
        $requestsSheet->fromArray(['Дата', 'Поставщик', 'Менеджер', 'Состав', 'Статус'], null, 'A1');
        $rowNumber = 2;
        foreach ($report['requests'] as $request) {
            $requestsSheet->fromArray([
                $request['request_date'],
                $request['supplier_name'],
                $request['manager_full_name'],
                $request['products'],
                $request['status'],
            ], null, 'A'.$rowNumber);
            $rowNumber++;
        }

        foreach ([$sheet, $requestsSheet] as $worksheet) {
            foreach (range('A', 'E') as $column) {
                $worksheet->getColumnDimension($column)->setAutoSize(true);
            }
        }

        $requestsSheet->fromArray(['Поставщик', 'Количество'], null, 'G1');
        $chartRow = 2;
        foreach ($report['by_supplier'] as $row) {
            $requestsSheet->fromArray([$row['supplier_name'], (float) $row['total_products']], null, 'G'.$chartRow);
            $chartRow++;
        }
        $this->addBarChart($requestsSheet, 'supplierProductsChart', 'Объем поставок по поставщикам', 'G', 'H', $chartRow - 1, 'G5', 'K18');
        foreach (range('G', 'H') as $column) {
            $requestsSheet->getColumnDimension($column)->setAutoSize(true);
        }

        return new StreamedResponse(static function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->setIncludeCharts(true);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="warehouse-report.xlsx"',
        ]);
    }

    private function addBarChart($sheet, string $name, string $title, string $labelColumn, string $valueColumn, int $lastRow, string $topLeft, string $bottomRight): void
    {
        if ($lastRow < 2) {
            return;
        }

        $sheetTitle = $sheet->getTitle();
        $labelsRange = sprintf("'%s'!\$%s\$2:\$%s\$%d", $sheetTitle, $labelColumn, $labelColumn, $lastRow);
        $valuesRange = sprintf("'%s'!\$%s\$2:\$%s\$%d", $sheetTitle, $valueColumn, $valueColumn, $lastRow);
        $seriesLabelRange = sprintf("'%s'!\$%s\$1", $sheetTitle, $valueColumn);
        $labels = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $labelsRange, null, $lastRow - 1)];
        $values = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $valuesRange, null, $lastRow - 1)];
        $seriesLabels = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $seriesLabelRange, null, 1)];

        $series = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_CLUSTERED,
            range(0, count($values) - 1),
            $seriesLabels,
            $labels,
            $values
        );
        $series->setPlotDirection(DataSeries::DIRECTION_COL);

        $chart = new Chart($name, new Title($title), new Legend(Legend::POSITION_RIGHT, null, false), new PlotArea(null, [$series]));
        $chart->setTopLeftPosition($topLeft);
        $chart->setBottomRightPosition($bottomRight);
        $sheet->addChart($chart);
    }
}
