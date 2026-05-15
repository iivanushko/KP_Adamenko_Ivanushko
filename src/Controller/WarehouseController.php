<?php

namespace App\Controller;

use App\Service\OrderService;
use App\Service\ErrorMessageFormatter;
use App\Service\WarehouseService;
use Throwable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/warehouse', name: 'warehouse_')]
class WarehouseController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, WarehouseService $warehouse, OrderService $orders): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $filters = [
            'q' => trim((string) $request->query->get('q', '')),
            'low' => (string) $request->query->get('low', ''),
            'sort' => (string) $request->query->get('sort', 'state'),
            'direction' => (string) $request->query->get('direction', 'desc'),
        ];

        return $this->render('warehouse/index.html.twig', [
            'result' => $warehouse->getStock($filters, $page, 10),
            'page' => $page,
            'filters' => $filters,
            'requests' => $warehouse->getRequests(),
            'products' => $warehouse->products(),
            'suppliers' => $warehouse->suppliers(),
            'managers' => $orders->managers(),
        ]);
    }

    #[Route('/stock/{productId}/update', name: 'stock_update', methods: ['POST'])]
    public function updateStock(int $productId, Request $request, WarehouseService $warehouse, ErrorMessageFormatter $errors): RedirectResponse
    {
        try {
            $warehouse->updateStock(
                $productId,
                (float) str_replace(',', '.', (string) $request->request->get('quantity')),
                (float) str_replace(',', '.', (string) $request->request->get('min_quantity'))
            );
            $this->addFlash('success', 'Остаток обновлен.');
        } catch (Throwable $exception) {
            $this->addFlash('danger', $errors->format($exception));
        }

        return new RedirectResponse($request->headers->get('referer') ?: $this->generateUrl('warehouse_index'));
    }

    #[Route('/request/create', name: 'request_create', methods: ['POST'])]
    public function createRequest(Request $request, WarehouseService $warehouse, ErrorMessageFormatter $errors): RedirectResponse
    {
        try {
            $warehouse->createRequest($request->request->all());
            $this->addFlash('success', 'Заявка поставщику создана.');
        } catch (Throwable $exception) {
            $this->addFlash('danger', $errors->format($exception));
        }

        return $this->redirectToRoute('warehouse_index');
    }
}
