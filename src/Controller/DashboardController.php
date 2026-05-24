<?php

namespace App\Controller;

use App\Service\OrderService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'dashboard')]
    public function index(OrderService $orders, Connection $connection): Response
    {
        $upcoming = $connection->fetchAllAssociative(
            "SELECT o.event_date, o.status, o.total_cost, o.event_type, c.client_full_name
             FROM orders o
             JOIN client c ON c.client_id = o.client_id
             WHERE o.event_date >= CURRENT_DATE AND o.status NOT IN ('Выполнен', 'Отменен')
             ORDER BY o.event_date
             LIMIT 6"
        );

        $logs = $connection->fetchAllAssociative(
            'SELECT operation_date, operation_type, description
             FROM operation_log
             ORDER BY operation_date DESC
             LIMIT 8'
        );

        $monthlyRevenue = $connection->fetchAllAssociative(
            "SELECT to_char(event_date, 'YYYY-MM') AS month,
                    COALESCE(SUM(total_cost) FILTER (WHERE status = 'Выполнен'), 0)                      AS revenue,
                    COALESCE(SUM(total_cost) FILTER (WHERE status IN ('В обработке', 'Забронирован')), 0) AS forecast
             FROM orders
             WHERE status <> 'Отменен'
             GROUP BY month
             ORDER BY month DESC
             LIMIT 6"
        );

        $chartData = [];
        $maxRevenue = 0;

        foreach (array_reverse($monthlyRevenue) as $row) {
            $rev = (float) $row['revenue'];
            $forecast = (float) $row['forecast'];
            $peak = $rev + $forecast; // stacked height for scale
            if ($peak > $maxRevenue) {
                $maxRevenue = $peak;
            }
            $chartData[] = [
                'month'    => $row['month'],
                'revenue'  => $rev,
                'forecast' => $forecast,
            ];
        }

        return $this->render('dashboard/index.html.twig', [
            'summary'     => $orders->getSummary(),
            'upcoming'    => $upcoming,
            'logs'        => $logs,
            'chart_data'  => $chartData,
            'max_revenue' => $maxRevenue > 0 ? $maxRevenue : 1,
        ]);
    }
}
