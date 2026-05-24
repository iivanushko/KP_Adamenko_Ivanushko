<?php

namespace App\Controller;

use App\Service\ErrorMessageFormatter;
use App\Service\ReferenceService;
use Throwable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class ClientController extends AbstractController
{
    #[Route('/clients', name: 'clients_index', methods: ['GET'])]
    public function clients(Request $request, ReferenceService $references): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $filters = ['q' => trim((string) $request->query->get('q', ''))];

        return $this->render('references/clients.html.twig', [
            'result' => $references->getClients($filters, $page, 10),
            'page' => $page,
            'filters' => $filters,
        ]);
    }

    #[Route('/clients/create', name: 'clients_create', methods: ['POST'])]
    public function createClient(Request $request, ReferenceService $references, ErrorMessageFormatter $errors): RedirectResponse
    {
        return $this->handle($request, $references, $errors, static fn () => $references->createClient($request->request->all()), 'Клиент создан.', 'clients_index');
    }

    #[Route('/clients/{id}/edit', name: 'clients_edit', methods: ['POST'])]
    public function editClient(int $id, Request $request, ReferenceService $references, ErrorMessageFormatter $errors): RedirectResponse
    {
        return $this->handle($request, $references, $errors, static fn () => $references->updateClient($id, $request->request->all()), 'Клиент обновлен.', 'clients_index');
    }

    #[Route('/clients/{id}/delete', name: 'clients_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteClient(int $id, Request $request, ReferenceService $references, ErrorMessageFormatter $errors): RedirectResponse
    {
        return $this->handle($request, $references, $errors, static fn () => $references->deleteClient($id), 'Клиент удален.', 'clients_index');
    }

    private function handle(Request $request, ReferenceService $references, ErrorMessageFormatter $errors, callable $operation, string $success, string $fallbackRoute): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('default', $request->request->get('_csrf_token'))) {
            $this->addFlash('danger', 'Неверный CSRF-токен.');
            return new RedirectResponse($request->headers->get('referer') ?: $this->generateUrl($fallbackRoute));
        }
        try {
            $operation();
            $this->addFlash('success', $success);
        } catch (Throwable $exception) {
            $this->addFlash('danger', $errors->format($exception));
        }

        return new RedirectResponse(
            $request->headers->get('referer')
            ?: $this->generateUrl($fallbackRoute)
        );
    }
}
