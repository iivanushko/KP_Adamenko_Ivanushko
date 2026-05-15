<?php

namespace App\Controller;

use App\Service\OrderService;
use Doctrine\DBAL\Connection;
use Dompdf\Dompdf;
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

    #[Route('/warehouse', name: 'warehouse', methods: ['GET'])]
    public function warehouse(Request $request, Connection $connection): Response
    {
        $report = $this->buildWarehouseReport($connection);
        $format = (string) $request->query->get('format', 'html');

        if ($format === 'pdf') {
            $html = $this->renderView('reports/warehouse_pdf.html.twig', ['report' => $report]);
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
            "SELECT o.event_date, o.status, o.event_type, o.rental_cost, o.prepayment_amount, o.is_fully_paid,
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
            'total_revenue' => array_sum(array_map(static fn (array $row): float => (float) $row['rental_cost'], $orders)),
            'total_prepayment' => array_sum(array_map(static fn (array $row): float => (float) $row['prepayment_amount'], $orders)),
        ];
    }

    private function buildWarehouseReport(Connection $connection): array
    {
        $stock = $connection->fetchAllAssociative(
            'SELECT p.product_name, s.quantity, s.min_quantity, s.last_restock_date,
                    CASE WHEN s.quantity <= s.min_quantity THEN TRUE ELSE FALSE END AS is_low
             FROM product_stock s
             JOIN product p ON p.product_id = s.product_id
             ORDER BY is_low DESC, p.product_name'
        );

        $requests = $connection->fetchAllAssociative(
            "SELECT sr.request_date, sr.status, s.supplier_name, m.manager_full_name,
                    COALESCE(SUM(rd.products_number), 0) AS total_products,
                    COALESCE(string_agg(p.product_name || ' x ' || rd.products_number, ', ' ORDER BY p.product_name), '') AS products
             FROM supplier_request sr
             JOIN supplier s ON s.supplier_id = sr.supplier_id
             JOIN manager m ON m.manager_id = sr.manager_id
             LEFT JOIN request_details rd ON rd.request_id = sr.request_id
             LEFT JOIN product p ON p.product_id = rd.product_id
             GROUP BY sr.request_id, s.supplier_name, m.manager_full_name
             ORDER BY sr.request_date DESC, sr.request_id DESC
             LIMIT 30"
        );

        $bySupplier = $connection->fetchAllAssociative(
            'SELECT s.supplier_name, COUNT(sr.request_id) AS requests_count, COALESCE(SUM(rd.products_number), 0) AS total_products
             FROM supplier s
             LEFT JOIN supplier_request sr ON sr.supplier_id = s.supplier_id
             LEFT JOIN request_details rd ON rd.request_id = sr.request_id
             GROUP BY s.supplier_id, s.supplier_name
             ORDER BY total_products DESC, s.supplier_name'
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
                (float) $order['rental_cost'],
                (float) $order['prepayment_amount'],
                $order['is_fully_paid'] ? 'Да' : 'Нет',
            ], null, 'A'.$rowNumber);
            $rowNumber++;
        }

        $rowNumber++;
        $sheet->fromArray(['Итого', '', '', '', '', (float) $report['total_revenue'], (float) $report['total_prepayment'], 'К оплате: '.number_format((float) ($report['total_revenue'] - $report['total_prepayment']), 2, ',', ' ')], null, 'A'.$rowNumber);
        $sheet->getStyle('A'.$rowNumber.':H'.$rowNumber)->getFont()->setBold(true);

        foreach (range('A', 'H') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return new StreamedResponse(static function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
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

        return new StreamedResponse(static function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="warehouse-report.xlsx"',
        ]);
    }
}
