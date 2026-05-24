<?php

namespace App\Controller;

use App\Service\DashboardService;
use App\Service\OrderService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'dashboard')]
    public function index(OrderService $orders, DashboardService $dashboard): Response
    {
        $upcoming = $dashboard->getUpcoming();
        $logs = $dashboard->getLogs();
        $monthlyRevenue = $dashboard->getMonthlyRevenue();

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
