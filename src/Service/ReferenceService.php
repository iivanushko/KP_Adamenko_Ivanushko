<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use RuntimeException;

class ReferenceService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function clients(array $filters = [], int $page = 1, int $limit = 20): array
    {
        $where = [];
        $params = [];
        if (($filters['q'] ?? '') !== '') {
            $where[] = '(client_full_name ILIKE :q OR phone_number ILIKE :q)';
            $params['q'] = '%'.$filters['q'].'%';
        }

        $whereSql = $where === [] ? '' : 'WHERE '.implode(' AND ', $where);
        $offset = max(0, ($page - 1) * $limit);
        $limitInt = max(1, (int) $limit);
        $offsetInt = max(0, (int) $offset);

        $items = $this->connection->fetchAllAssociative(
            "SELECT client_id, client_full_name, phone_number
             FROM client
             $whereSql
             ORDER BY client_full_name
             LIMIT $limitInt OFFSET $offsetInt",
            $params
        );
        $total = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM client $whereSql", $params);

        return ['items' => $items, 'total' => $total, 'pages' => max(1, (int) ceil($total / $limit))];
    }

    /**
     * Убирает лишние пробелы (в начале, конце, двойные внутри) из строк ФИО / названий.
     * Не меняет регистр, не переставляет слова — порядок ФИО ответственность пользователя.
     */
    private function normalizeName(string $name): string
    {
        return implode(' ', array_filter(array_map('trim', explode(' ', $name))));
    }

    public function createClient(array $data): void
    {
        $this->requireText($data['client_full_name'] ?? '', 'Укажите ФИО клиента.');
        $this->requireText($data['phone_number'] ?? '', 'Укажите телефон клиента.');
        $this->validatePhone((string) $data['phone_number']);

        $id = (int) $this->connection->fetchOne(
            'INSERT INTO client (client_full_name, phone_number) VALUES (:name, :phone) RETURNING client_id',
            ['name' => $this->normalizeName((string) $data['client_full_name']), 'phone' => trim((string) $data['phone_number'])]
        );
        $this->log('CREATE', 'Client', $id, 'Создан клиент из интерфейса');
    }

    public function updateClient(int $id, array $data): void
    {
        $this->requireText($data['client_full_name'] ?? '', 'Укажите ФИО клиента.');
        $this->requireText($data['phone_number'] ?? '', 'Укажите телефон клиента.');
        $this->validatePhone((string) $data['phone_number']);

        $this->connection->executeStatement(
            'UPDATE client SET client_full_name = :name, phone_number = :phone WHERE client_id = :id',
            ['id' => $id, 'name' => $this->normalizeName((string) $data['client_full_name']), 'phone' => trim((string) $data['phone_number'])]
        );
        $this->log('UPDATE', 'Client', $id, 'Обновлен клиент из интерфейса');
    }

    public function deleteClient(int $id): void
    {
        $this->connection->executeStatement('DELETE FROM client WHERE client_id = :id', ['id' => $id]);
        $this->log('DELETE', 'Client', $id, 'Удален клиент из интерфейса');
    }

    public function dishes(array $filters = [], int $page = 1, int $limit = 20): array
    {
        $where = [];
        $params = [];
        $types = [];
        if (($filters['q'] ?? '') !== '') {
            $where[] = 'dish_name ILIKE :q';
            $params['q'] = '%'.$filters['q'].'%';
        }
        if (($filters['active'] ?? '') !== '') {
            $where[] = 'is_active = :active';
            $params['active'] = $filters['active'] === '1';
            $types['active'] = ParameterType::BOOLEAN;
        }
        if (($filters['seasonality'] ?? '') !== '') {
            $where[] = 'seasonality = :seasonality';
            $params['seasonality'] = $filters['seasonality'];
        }

        $whereSql = $where === [] ? '' : 'WHERE '.implode(' AND ', $where);
        $offset = max(0, ($page - 1) * $limit);
        $limitInt = max(1, (int) $limit);
        $offsetInt = max(0, (int) $offset);

        $items = $this->connection->fetchAllAssociative(
            "SELECT dish_id, dish_name, cost_price, sale_price, price_category, (sale_price - cost_price) AS profit, seasonality, is_active
             FROM dish
             $whereSql
             ORDER BY is_active DESC, dish_name
             LIMIT $limitInt OFFSET $offsetInt",
            $params,
            $types
        );
        $total = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM dish $whereSql", $params, $types);

        return ['items' => $items, 'total' => $total, 'pages' => max(1, (int) ceil($total / $limit))];
    }

    public function createDish(array $data): void
    {
        $this->requireText($data['dish_name'] ?? '', 'Укажите название блюда.');
        $id = (int) $this->connection->fetchOne(
            'INSERT INTO dish (dish_name, cost_price, sale_price, seasonality, is_active)
             VALUES (:name, :cost, :sale, :seasonality, :active)
             RETURNING dish_id',
            $this->dishParams($data),
            ['active' => ParameterType::BOOLEAN]
        );
        $this->log('CREATE', 'Dish', $id, 'Создано блюдо из интерфейса');
    }

    public function updateDish(int $id, array $data): void
    {
        $params = $this->dishParams($data);
        $params['id'] = $id;

        $this->connection->executeStatement(
            'UPDATE dish
             SET dish_name = :name, cost_price = :cost, sale_price = :sale, seasonality = :seasonality, is_active = :active
             WHERE dish_id = :id',
            $params,
            ['active' => ParameterType::BOOLEAN]
        );
        $this->log('UPDATE', 'Dish', $id, 'Обновлено блюдо из интерфейса');
    }

    public function toggleDish(int $id, bool $isActive): void
    {
        $this->connection->executeStatement(
            'UPDATE dish SET is_active = :active WHERE dish_id = :id',
            ['id' => $id, 'active' => $isActive],
            ['active' => ParameterType::BOOLEAN]
        );
        $this->log('UPDATE', 'Dish', $id, $isActive ? 'Блюдо включено в меню' : 'Блюдо скрыто из меню');
    }

    public function deleteDish(int $id): void
    {
        $this->connection->executeStatement('DELETE FROM dish WHERE dish_id = :id', ['id' => $id]);
        $this->log('DELETE', 'Dish', $id, 'Удалено блюдо из интерфейса');
    }

    public function recipeProducts(): array
    {
        return $this->connection->fetchAllAssociative('SELECT product_id, product_name FROM product ORDER BY product_name');
    }

    public function recipesByDish(array $dishIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $dishIds)));
        if ($ids === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT r.dish_id, r.product_id, p.product_name, r.number_in_recipe
             FROM recipe r
             JOIN product p ON p.product_id = r.product_id
             WHERE r.dish_id IN (:ids)
             ORDER BY r.dish_id, p.product_name',
            ['ids' => $ids],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['dish_id']][] = $row;
        }

        return $grouped;
    }

    public function upsertRecipeItem(int $dishId, array $data): void
    {
        $productId = (int) ($data['product_id'] ?? 0);
        $quantity = $this->number($data['number_in_recipe'] ?? 0);
        if ($productId <= 0 || $quantity <= 0) {
            throw new RuntimeException('Выберите продукт и укажите положительное количество в рецепте.');
        }

        $this->connection->executeStatement(
            'INSERT INTO recipe (dish_id, product_id, number_in_recipe)
             VALUES (:dish, :product, :quantity)
             ON CONFLICT (product_id, dish_id) DO UPDATE SET number_in_recipe = EXCLUDED.number_in_recipe',
            ['dish' => $dishId, 'product' => $productId, 'quantity' => $quantity]
        );
        $this->log('UPDATE', 'Recipe', $dishId, 'Обновлена рецептура блюда из интерфейса');
    }

    public function deleteRecipeItem(int $dishId, int $productId): void
    {
        $this->connection->executeStatement(
            'DELETE FROM recipe WHERE dish_id = :dish AND product_id = :product',
            ['dish' => $dishId, 'product' => $productId]
        );
        $this->log('DELETE', 'Recipe', $dishId, 'Удалена строка рецептуры из интерфейса');
    }

    public function managers(): array
    {
        return $this->connection->fetchAllAssociative('SELECT manager_id, manager_full_name FROM manager ORDER BY manager_full_name');
    }

    public function createManager(array $data): void
    {
        $this->createNamed('manager', 'manager_id', 'manager_full_name', 'Manager', $data['manager_full_name'] ?? '');
    }

    public function updateManager(int $id, array $data): void
    {
        $this->updateNamed('manager', 'manager_id', 'manager_full_name', 'Manager', $id, $data['manager_full_name'] ?? '');
    }

    public function deleteManager(int $id): void
    {
        $this->deleteNamed('manager', 'manager_id', 'Manager', $id);
    }

    public function suppliers(): array
    {
        return $this->connection->fetchAllAssociative('SELECT supplier_id, supplier_name FROM supplier ORDER BY supplier_name');
    }

    public function createSupplier(array $data): void
    {
        $this->createNamed('supplier', 'supplier_id', 'supplier_name', 'Supplier', $data['supplier_name'] ?? '');
    }

    public function updateSupplier(int $id, array $data): void
    {
        $this->updateNamed('supplier', 'supplier_id', 'supplier_name', 'Supplier', $id, $data['supplier_name'] ?? '');
    }

    public function deleteSupplier(int $id): void
    {
        $this->deleteNamed('supplier', 'supplier_id', 'Supplier', $id);
    }

    public function products(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT p.product_id, p.product_name, s.quantity, s.min_quantity
             FROM product p
             LEFT JOIN product_stock s ON s.product_id = p.product_id
             ORDER BY p.product_name'
        );
    }

    public function createProduct(array $data): void
    {
        $this->requireText($data['product_name'] ?? '', 'Укажите название продукта.');
        $this->connection->transactional(function () use ($data): void {
            $id = (int) $this->connection->fetchOne(
                'INSERT INTO product (product_name) VALUES (:name) RETURNING product_id',
                ['name' => trim((string) $data['product_name'])]
            );
            $this->connection->executeStatement(
                'INSERT INTO product_stock (product_id, quantity, min_quantity, last_restock_date)
                 VALUES (:id, :quantity, :min_quantity, CURRENT_DATE)',
                [
                    'id' => $id,
                    'quantity' => $this->number($data['quantity'] ?? 0),
                    'min_quantity' => $this->number($data['min_quantity'] ?? 10),
                ]
            );
            $this->log('CREATE', 'Product', $id, 'Создан продукт из интерфейса');
        });
    }

    public function updateProduct(int $id, array $data): void
    {
        $this->requireText($data['product_name'] ?? '', 'Укажите название продукта.');
        $this->connection->executeStatement(
            'UPDATE product SET product_name = :name WHERE product_id = :id',
            ['id' => $id, 'name' => trim((string) $data['product_name'])]
        );
        $this->log('UPDATE', 'Product', $id, 'Обновлен продукт из интерфейса');
    }

    public function deleteProduct(int $id): void
    {
        $this->deleteNamed('product', 'product_id', 'Product', $id);
    }

    private function createNamed(string $table, string $idColumn, string $nameColumn, string $logTable, mixed $name): void
    {
        $this->requireText($name, 'Укажите название записи.');
        $id = (int) $this->connection->fetchOne(
            "INSERT INTO $table ($nameColumn) VALUES (:name) RETURNING $idColumn",
            ['name' => $this->normalizeName((string) $name)]
        );
        $this->log('CREATE', $logTable, $id, 'Создана запись справочника из интерфейса');
    }

    private function updateNamed(string $table, string $idColumn, string $nameColumn, string $logTable, int $id, mixed $name): void
    {
        $this->requireText($name, 'Укажите название записи.');
        $this->connection->executeStatement(
            "UPDATE $table SET $nameColumn = :name WHERE $idColumn = :id",
            ['id' => $id, 'name' => $this->normalizeName((string) $name)]
        );
        $this->log('UPDATE', $logTable, $id, 'Обновлена запись справочника из интерфейса');
    }

    private function deleteNamed(string $table, string $idColumn, string $logTable, int $id): void
    {
        $this->connection->executeStatement("DELETE FROM $table WHERE $idColumn = :id", ['id' => $id]);
        $this->log('DELETE', $logTable, $id, 'Удалена запись справочника из интерфейса');
    }

    private function dishParams(array $data): array
    {
        $cost = $this->number($data['cost_price'] ?? 0);
        $sale = $this->number($data['sale_price'] ?? 0);
        if ($cost < 0 || $sale <= 0) {
            throw new RuntimeException('Укажите корректную себестоимость и цену продажи.');
        }

        return [
            'name' => trim((string) $data['dish_name']),
            'cost' => $cost,
            'sale' => $sale,
            'seasonality' => trim((string) ($data['seasonality'] ?? 'Всесезонное')) ?: 'Всесезонное',
            'active' => !empty($data['is_active']),
        ];
    }

    private function number(mixed $value): float
    {
        return (float) str_replace(',', '.', (string) $value);
    }

    private function requireText(mixed $value, string $message): void
    {
        if (trim((string) $value) === '') {
            throw new RuntimeException($message);
        }
    }

    private function validatePhone(string $phone): void
    {
        if (!preg_match('/^[0-9]{10,15}$/', $phone)) {
            throw new RuntimeException('Телефон должен содержать от 10 до 15 цифр.');
        }
    }

    private function log(string $operation, string $table, int $id, string $description): void
    {
        $this->connection->executeStatement(
            "SELECT log_operation(:operation, :table_name, :id, :description)",
            ['operation' => $operation, 'table_name' => $table, 'id' => $id, 'description' => $description]
        );
    }
}
