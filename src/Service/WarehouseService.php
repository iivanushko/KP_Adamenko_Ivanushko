<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

class WarehouseService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function getStock(array $filters, int $page, int $limit): array
    {
        $where = [];
        $params = [];
        if (($filters['q'] ?? '') !== '') {
            $where[] = 'p.product_name ILIKE :q';
            $params['q'] = '%'.$filters['q'].'%';
        }
        if (($filters['low'] ?? '') === '1') {
            $where[] = 's.quantity <= s.min_quantity';
        }

        $whereSql = $where === [] ? '' : 'WHERE '.implode(' AND ', $where);
        $offset = max(0, ($page - 1) * $limit);

        $items = $this->connection->fetchAllAssociative(
            "SELECT p.product_id, p.product_name, s.quantity, s.min_quantity, s.last_restock_date,
                    CASE WHEN s.quantity <= s.min_quantity THEN TRUE ELSE FALSE END AS is_low
             FROM product_stock s
             JOIN product p ON p.product_id = s.product_id
             $whereSql
             ORDER BY is_low DESC, p.product_name
             LIMIT $limit OFFSET $offset",
            $params
        );
        $total = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM product_stock s JOIN product p ON p.product_id = s.product_id $whereSql",
            $params
        );

        return ['items' => $items, 'total' => $total, 'pages' => max(1, (int) ceil($total / $limit))];
    }

    public function updateStock(int $productId, float $quantity, float $minQuantity): void
    {
        $this->connection->executeStatement(
            'UPDATE product_stock
             SET quantity = :quantity, min_quantity = :min_quantity, last_restock_date = CURRENT_DATE
             WHERE product_id = :product_id',
            ['product_id' => $productId, 'quantity' => $quantity, 'min_quantity' => $minQuantity]
        );
    }

    public function getRequests(): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT sr.request_id, sr.request_date, sr.created_at, sr.status, s.supplier_name, m.manager_full_name,
                    COALESCE(string_agg(p.product_name || ' x ' || rd.products_number, ', ' ORDER BY p.product_name), '') AS products
             FROM supplier_request sr
             JOIN supplier s ON s.supplier_id = sr.supplier_id
             JOIN manager m ON m.manager_id = sr.manager_id
             LEFT JOIN request_details rd ON rd.request_id = sr.request_id
             LEFT JOIN product p ON p.product_id = rd.product_id
             GROUP BY sr.request_id, s.supplier_name, m.manager_full_name
             ORDER BY sr.request_date DESC, sr.request_id DESC
             LIMIT 20"
        );
    }

    public function createRequest(array $data): void
    {
        $products = [];
        foreach (($data['product_id'] ?? []) as $index => $productId) {
            $quantity = (float) str_replace(',', '.', (string) ($data['products_number'][$index] ?? 0));
            if ((int) $productId > 0 && $quantity > 0) {
                $products[] = ['product_id' => (int) $productId, 'quantity' => $quantity];
            }
        }

        $this->connection->transactional(function () use ($data, $products): void {
            $requestId = (int) $this->connection->fetchOne(
                'INSERT INTO supplier_request (request_date, manager_id, supplier_id, status, linked_order_id)
                 VALUES (:request_date, :manager, :supplier, :status, NULL)
                 RETURNING request_id',
                [
                    'request_date' => $data['request_date'],
                    'manager' => (int) $data['manager_id'],
                    'supplier' => (int) $data['supplier_id'],
                    'status' => $data['status'],
                ]
            );

            foreach ($products as $product) {
                $this->connection->executeStatement(
                    'INSERT INTO request_details (request_id, product_id, products_number)
                     VALUES (:request, :product, :quantity)',
                    ['request' => $requestId, 'product' => $product['product_id'], 'quantity' => $product['quantity']]
                );

                if ($data['status'] === 'Получено') {
                    $this->connection->executeStatement(
                        'UPDATE product_stock
                         SET quantity = quantity + :quantity, last_restock_date = :request_date
                         WHERE product_id = :product',
                        ['quantity' => $product['quantity'], 'request_date' => $data['request_date'], 'product' => $product['product_id']]
                    );
                }
            }

            $this->connection->executeStatement(
                "SELECT log_operation('CREATE', 'Supplier_Request', :id, 'Создана заявка поставщику из интерфейса')",
                ['id' => $requestId]
            );
        });
    }

    public function products(): array
    {
        return $this->connection->fetchAllAssociative('SELECT product_id, product_name FROM product ORDER BY product_name');
    }

    public function suppliers(): array
    {
        return $this->connection->fetchAllAssociative('SELECT supplier_id, supplier_name FROM supplier ORDER BY supplier_name');
    }
}
