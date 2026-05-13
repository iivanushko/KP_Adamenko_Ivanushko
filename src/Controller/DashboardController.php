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
            "SELECT o.event_date, o.status, o.rental_cost, o.event_type, c.client_full_name
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

        return $this->render('dashboard/index.html.twig', [
            'summary' => $orders->getSummary(),
            'upcoming' => $upcoming,
            'logs' => $logs,
        ]);
    }
}
