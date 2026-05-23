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
    #[Route('/clients', name: 'clients_index', methods: ['GET'])]
    public function clients(Request $request, ReferenceService $references): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $filters = ['q' => trim((string) $request->query->get('q', ''))];

        return $this->render('references/clients.html.twig', [
            'result' => $references->clients($filters, $page, 10),
            'filters' => $filters,
            'page' => $page,
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
    public function deleteClient(int $id, Request $request, ReferenceService $references, ErrorMessageFormatter $errors): RedirectResponse
    {
        return $this->handle($request, $references, $errors, static fn () => $references->deleteClient($id), 'Клиент удален.', 'clients_index');
    }

    #[Route('/menu', name: 'menu_index', methods: ['GET'])]
    public function menu(Request $request, ReferenceService $references): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $filters = [
            'q' => trim((string) $request->query->get('q', '')),
            'active' => (string) $request->query->get('active', ''),
            'seasonality' => (string) $request->query->get('seasonality', ''),
        ];
        $result = $references->dishes($filters, $page, 10);

        return $this->render('references/menu.html.twig', [
            'result' => $result,
            'filters' => $filters,
            'page' => $page,
            'products' => $references->recipeProducts(),
            'recipes' => $references->recipesByDish(array_column($result['items'], 'dish_id')),
        ]);
    }

    #[Route('/menu/create', name: 'menu_create', methods: ['POST'])]
    public function createDish(Request $request, ReferenceService $references, ErrorMessageFormatter $errors): RedirectResponse
    {
        return $this->handle($request, $references, $errors, static fn () => $references->createDish($request->request->all()), 'Блюдо создано.', 'menu_index');
    }

    #[Route('/menu/{id}/edit', name: 'menu_edit', methods: ['POST'])]
    public function editDish(int $id, Request $request, ReferenceService $references, ErrorMessageFormatter $errors): RedirectResponse
    {
        return $this->handle($request, $references, $errors, static fn () => $references->updateDish($id, $request->request->all()), 'Блюдо обновлено.', 'menu_index');
    }

    #[Route('/menu/{id}/toggle', name: 'menu_toggle', methods: ['POST'])]
    public function toggleDish(int $id, Request $request, ReferenceService $references, ErrorMessageFormatter $errors): RedirectResponse
    {
        return $this->handle($request, $references, $errors, static fn () => $references->toggleDish($id, (bool) $request->request->get('is_active')), 'Доступность блюда обновлена.', 'menu_index');
    }

    #[Route('/menu/{id}/delete', name: 'menu_delete', methods: ['POST'])]
    public function deleteDish(int $id, Request $request, ReferenceService $references, ErrorMessageFormatter $errors): RedirectResponse
    {
        return $this->handle($request, $references, $errors, static fn () => $references->deleteDish($id), 'Блюдо удалено.', 'menu_index');
    }

    #[Route('/menu/{id}/recipe', name: 'menu_recipe_save', methods: ['POST'])]
    public function saveRecipeItem(int $id, Request $request, ReferenceService $references, ErrorMessageFormatter $errors): RedirectResponse
    {
        return $this->handle($request, $references, $errors, static fn () => $references->upsertRecipeItem($id, $request->request->all()), 'Рецептура обновлена.', 'menu_index');
    }

    #[Route('/menu/{dishId}/recipe/{productId}/delete', name: 'menu_recipe_delete', methods: ['POST'])]
    public function deleteRecipeItem(int $dishId, int $productId, Request $request, ReferenceService $references, ErrorMessageFormatter $errors): RedirectResponse
    {
        return $this->handle($request, $references, $errors, static fn () => $references->deleteRecipeItem($dishId, $productId), 'Строка рецептуры удалена.', 'menu_index');
    }

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
