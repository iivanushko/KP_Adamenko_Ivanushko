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

class ReferenceController extends AbstractController
{
    #[Route('/references', name: 'references_index', methods: ['GET'])]
    public function references(ReferenceService $references): Response
    {
        return $this->render('references/index.html.twig', [
            'managers' => $references->managers(),
            'suppliers' => $references->suppliers(),
            'products' => $references->products(),
        ]);
    }

    #[Route('/references/managers/create', name: 'references_managers_create', methods: ['POST'])]
    public function createManager(Request $request, ReferenceService $references, ErrorMessageFormatter $errors): RedirectResponse
    {
        return $this->handle($request, $references, $errors, static fn () => $references->createManager($request->request->all()), 'Менеджер создан.', 'references_index');
    }

    #[Route('/references/managers/{id}/edit', name: 'references_managers_edit', methods: ['POST'])]
    public function editManager(int $id, Request $request, ReferenceService $references, ErrorMessageFormatter $errors): RedirectResponse
    {
        return $this->handle($request, $references, $errors, static fn () => $references->updateManager($id, $request->request->all()), 'Менеджер обновлен.', 'references_index');
    }

    #[Route('/references/managers/{id}/delete', name: 'references_managers_delete', methods: ['POST'])]
    public function deleteManager(int $id, Request $request, ReferenceService $references, ErrorMessageFormatter $errors): RedirectResponse
    {
        return $this->handle($request, $references, $errors, static fn () => $references->deleteManager($id), 'Менеджер удален.', 'references_index');
    }

    #[Route('/references/suppliers/create', name: 'references_suppliers_create', methods: ['POST'])]
    public function createSupplier(Request $request, ReferenceService $references, ErrorMessageFormatter $errors): RedirectResponse
    {
        return $this->handle($request, $references, $errors, static fn () => $references->createSupplier($request->request->all()), 'Поставщик создан.', 'references_index');
    }

    #[Route('/references/suppliers/{id}/edit', name: 'references_suppliers_edit', methods: ['POST'])]
    public function editSupplier(int $id, Request $request, ReferenceService $references, ErrorMessageFormatter $errors): RedirectResponse
    {
        return $this->handle($request, $references, $errors, static fn () => $references->updateSupplier($id, $request->request->all()), 'Поставщик обновлен.', 'references_index');
    }

    #[Route('/references/suppliers/{id}/delete', name: 'references_suppliers_delete', methods: ['POST'])]
    public function deleteSupplier(int $id, Request $request, ReferenceService $references, ErrorMessageFormatter $errors): RedirectResponse
    {
        return $this->handle($request, $references, $errors, static fn () => $references->deleteSupplier($id), 'Поставщик удален.', 'references_index');
    }

    #[Route('/references/products/create', name: 'references_products_create', methods: ['POST'])]
    public function createProduct(Request $request, ReferenceService $references, ErrorMessageFormatter $errors): RedirectResponse
    {
        return $this->handle($request, $references, $errors, static fn () => $references->createProduct($request->request->all()), 'Продукт создан.', 'references_index');
    }

    #[Route('/references/products/{id}/edit', name: 'references_products_edit', methods: ['POST'])]
    public function editProduct(int $id, Request $request, ReferenceService $references, ErrorMessageFormatter $errors): RedirectResponse
    {
        return $this->handle($request, $references, $errors, static fn () => $references->updateProduct($id, $request->request->all()), 'Продукт обновлен.', 'references_index');
    }

    #[Route('/references/products/{id}/delete', name: 'references_products_delete', methods: ['POST'])]
    public function deleteProduct(int $id, Request $request, ReferenceService $references, ErrorMessageFormatter $errors): RedirectResponse
    {
        return $this->handle($request, $references, $errors, static fn () => $references->deleteProduct($id), 'Продукт удален.', 'references_index');
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

        $redirect = $request->request->get('_redirect')
            ?: $request->headers->get('referer')
            ?: $this->generateUrl($fallbackRoute);

        return new RedirectResponse($redirect);
    }
}
