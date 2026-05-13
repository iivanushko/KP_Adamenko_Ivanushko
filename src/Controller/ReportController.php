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
            return $this->xlsx($report);
        }

        return $this->render('reports/orders.html.twig', [
            'filters' => $filters,
            'report' => $report,
            'statuses' => OrderService::STATUSES,
            'event_types' => OrderService::EVENT_TYPES,
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

    private function xlsx(array $report): StreamedResponse
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
}
