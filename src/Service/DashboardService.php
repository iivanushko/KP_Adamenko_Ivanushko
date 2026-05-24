<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

class DashboardService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function getUpcoming(): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT o.event_date, o.status, o.total_cost, o.event_type, c.client_full_name
             FROM orders o
             JOIN client c ON c.client_id = o.client_id
             WHERE o.event_date >= CURRENT_DATE AND o.status NOT IN (:done, :cancelled)
             ORDER BY o.event_date
             LIMIT 6",
            ['done' => OrderService::STATUS_DONE, 'cancelled' => OrderService::STATUS_CANCELLED]
        );
    }

    public function getLogs(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT operation_date, operation_type, description
             FROM operation_log
             ORDER BY operation_date DESC
             LIMIT 8'
        );
    }

    public function getMonthlyRevenue(): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT to_char(event_date, 'YYYY-MM') AS month,
                    COALESCE(SUM(total_cost) FILTER (WHERE status = :done), 0)                      AS revenue,
                    COALESCE(SUM(total_cost) FILTER (WHERE status IN (:pending, :booked)), 0) AS forecast
             FROM orders
             WHERE status <> :cancelled
             GROUP BY month
             ORDER BY month DESC
             LIMIT 6",
            [
                'done' => OrderService::STATUS_DONE,
                'pending' => OrderService::STATUS_PENDING,
                'booked' => OrderService::STATUS_BOOKED,
                'cancelled' => OrderService::STATUS_CANCELLED,
            ]
        );
    }
}
