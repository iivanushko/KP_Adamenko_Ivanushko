<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use RuntimeException;

class OrderService
{
    public const STATUS_PENDING   = 'В обработке';
    public const STATUS_BOOKED    = 'Забронирован';
    public const STATUS_DONE      = 'Выполнен';
    public const STATUS_CANCELLED = 'Отменен';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_BOOKED,
        self::STATUS_DONE,
        self::STATUS_CANCELLED,
    ];
    public const EVENT_TYPES = ['Банкет', 'Свадьба', 'Корпоратив', 'День рождения', 'Фуршет'];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function getSummary(): array
    {
        return [
            'orders_total' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM orders'),
            'active_orders' => (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM orders WHERE status NOT IN (:done, :cancelled)",
                ['done' => self::STATUS_DONE, 'cancelled' => self::STATUS_CANCELLED]
            ),
            'revenue' => (float) $this->connection->fetchOne(
                "SELECT COALESCE(SUM(total_cost), 0) FROM orders WHERE status = :status",
                ['status' => self::STATUS_DONE]
            ),
            'revenue_forecast' => (float) $this->connection->fetchOne(
                "SELECT COALESCE(SUM(total_cost), 0) FROM orders WHERE status IN (:pending, :booked)",
                ['pending' => self::STATUS_PENDING, 'booked' => self::STATUS_BOOKED]
            ),
            'low_stock' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM product_stock WHERE quantity <= min_quantity'),
        ];
    }

    public function getOrders(array $filters, int $page, int $limit): array
    {
        [$where, $params] = $this->buildOrderFilter($filters);
        $offset = max(0, ($page - 1) * $limit);
        $limitInt = max(1, (int) $limit);
        $offsetInt = max(0, (int) $offset);
        $orderBy = $this->buildOrdersOrderBy(
            (string) ($filters['sort'] ?? ''),
            (string) ($filters['direction'] ?? '')
        );

        $items = $this->connection->fetchAllAssociative(
            "SELECT o.order_id, o.client_id, o.manager_id, o.status, o.event_date, o.total_cost, o.event_type, o.prepayment_amount, o.is_fully_paid,
                    c.client_full_name, c.phone_number, m.manager_full_name,
                    COALESCE(string_agg(d.dish_name || ' x ' || od.serving_number, ', ' ORDER BY d.dish_name), 'Блюда не выбраны') AS dishes
             FROM orders o
             JOIN client c ON c.client_id = o.client_id
             JOIN manager m ON m.manager_id = o.manager_id
             LEFT JOIN order_details od ON od.order_id = o.order_id
             LEFT JOIN dish d ON d.dish_id = od.dish_id
             $where
             GROUP BY o.order_id, c.client_full_name, c.phone_number, m.manager_full_name
             ORDER BY $orderBy
             LIMIT $limitInt OFFSET $offsetInt",
            $params
        );

        $total = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM orders o JOIN client c ON c.client_id = o.client_id $where",
            $params
        );

        return ['items' => $items, 'total' => $total, 'pages' => max(1, (int) ceil($total / $limit))];
    }

    public function getOrder(int $id): ?array
    {
        $order = $this->connection->fetchAssociative(
            'SELECT o.*, o.total_cost, c.client_full_name, m.manager_full_name
             FROM orders o
             JOIN client c ON c.client_id = o.client_id
             JOIN manager m ON m.manager_id = o.manager_id
             WHERE o.order_id = :id',
            ['id' => $id]
        );

        return $order ?: null;
    }

    public function getOrderDetails(int $id): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT d.dish_id, d.dish_name, od.serving_number, d.sale_price, od.serving_number * d.sale_price AS line_total
             FROM order_details od
             JOIN dish d ON d.dish_id = od.dish_id
             WHERE od.order_id = :id
             ORDER BY d.dish_name',
            ['id' => $id]
        );
    }

    public function getDetailsForOrders(array $orderIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $orderIds)));
        if ($ids === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT od.order_id, d.dish_id, d.dish_name, od.serving_number, d.sale_price, od.serving_number * d.sale_price AS line_total
             FROM order_details od
             JOIN dish d ON d.dish_id = od.dish_id
             WHERE od.order_id IN (:ids)
             ORDER BY od.order_id, d.dish_name',
            ['ids' => $ids],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['order_id']][] = $row;
        }

        return $grouped;
    }

    public function createComplexOrder(array $data): array
    {
        if (($data['event_date'] ?? '') < date('Y-m-d')) {
            throw new RuntimeException('Дата активного заказа не может быть в прошлом. Выберите сегодняшнюю или будущую дату.');
        }
        $eventType = $this->validatedEventType((string) ($data['event_type'] ?? ''));

        $dishes = $this->parseDishLines($data);

        return $this->connection->transactional(function () use ($data, $eventType, $dishes): array {
            $orderId = (int) $this->connection->fetchOne(
                'INSERT INTO orders (client_id, manager_id, event_date, event_type, status, total_cost)
                 VALUES (:client, :manager, :event_date, :event_type, :status, 0)
                 RETURNING order_id',
                [
                    'client' => (int) $data['client_id'],
                    'manager' => (int) $data['manager_id'],
                    'event_date' => $data['event_date'],
                    'event_type' => $eventType,
                    'status' => self::STATUS_PENDING,
                ]
            );

            $this->replaceOrderDishes($orderId, $dishes);

            return ['p_status' => 'SUCCESS', 'p_message' => 'Заказ создан.', 'p_order_id' => $orderId, 'p_total_cost' => $this->connection->fetchOne('SELECT total_cost FROM orders WHERE order_id = :id', ['id' => $orderId])];
        });
    }

    public function updateOrder(int $id, array $data): void
    {
        $this->connection->transactional(function () use ($id, $data) {
            $clientId = (int) $data['client_id'];
            $managerId = (int) $data['manager_id'];
            $eventType = $this->validatedEventType((string) ($data['event_type'] ?? ''));
            $status = $this->validatedStatus((string) ($data['status'] ?? ''));
            $currentStatus = (string) $this->connection->fetchOne(
                'SELECT status FROM orders WHERE order_id = :id FOR UPDATE',
                ['id' => $id]
            );
            if ($currentStatus === '') {
                throw new RuntimeException('Заказ не найден.');
            }

            $clientExists = $this->connection->fetchOne('SELECT 1 FROM client WHERE client_id = :id', ['id' => $clientId]);
            if (!$clientExists) {
                throw new RuntimeException('Выбранный клиент не существует.');
            }

            $managerExists = $this->connection->fetchOne('SELECT 1 FROM manager WHERE manager_id = :id', ['id' => $managerId]);
            if (!$managerExists) {
                throw new RuntimeException('Выбранный менеджер не существует.');
            }

            if (array_key_exists('dish_id', $data)) {
                if (in_array($currentStatus, [self::STATUS_DONE, self::STATUS_CANCELLED], true)) {
                    throw new RuntimeException('Состав выполненного или отмененного заказа нельзя изменять.');
                }

                $this->replaceOrderDishes($id, $this->parseDishLines($data));
            }

            $this->connection->executeStatement(
                'UPDATE orders
                 SET client_id = :client, manager_id = :manager, event_date = :event_date, event_type = :event_type,
                     status = :status, prepayment_amount = :prepayment
                 WHERE order_id = :id',
                [
                    'id' => $id,
                    'client' => $clientId,
                    'manager' => $managerId,
                    'event_date' => $data['event_date'],
                    'event_type' => $eventType,
                    'status' => $status,
                    'prepayment' => (float) str_replace(',', '.', (string) $data['prepayment_amount']),
                ]
            );
        });
    }

    public function inlineUpdate(int $id, string $field, string $value): void
    {
        match ($field) {
            'status' => $this->connection->executeStatement(
                "UPDATE orders SET status = :value WHERE order_id = :id",
                ['id' => $id, 'value' => $value]
            ),
            'prepayment_amount' => $this->connection->executeStatement(
                "UPDATE orders SET prepayment_amount = :value WHERE order_id = :id",
                ['id' => $id, 'value' => (float) str_replace(',', '.', $value)]
            ),
            default => throw new RuntimeException('Недопустимое поле для быстрого редактирования.'),
        };
    }

    public function cancelOrder(int $id): void
    {
        $this->connection->executeStatement('CALL cancel_order_logic(:id)', ['id' => $id]);
    }

    public function clients(): array
    {
        return $this->connection->fetchAllAssociative('SELECT client_id, client_full_name, phone_number FROM client ORDER BY client_full_name');
    }

    public function managers(): array
    {
        return $this->connection->fetchAllAssociative('SELECT manager_id, manager_full_name FROM manager ORDER BY manager_full_name');
    }

    public function activeDishes(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT dish_id, dish_name, sale_price, price_category FROM dish WHERE is_active = TRUE ORDER BY dish_name'
        );
    }

    private function buildOrderFilter(array $filters): array
    {
        $where = [];
        $params = [];

        if (($filters['q'] ?? '') !== '') {
            $where[] = '(c.client_full_name ILIKE :q OR c.phone_number ILIKE :q)';
            $params['q'] = '%'.$filters['q'].'%';
        }
        if (($filters['status'] ?? '') !== '') {
            $where[] = 'o.status = :status';
            $params['status'] = $filters['status'];
        }
        if (($filters['event_type'] ?? '') !== '') {
            $where[] = 'o.event_type = :event_type';
            $params['event_type'] = $filters['event_type'];
        }
        if (($filters['date_from'] ?? '') !== '') {
            $where[] = 'o.event_date >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }
        if (($filters['date_to'] ?? '') !== '') {
            $where[] = 'o.event_date <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }

        return [$where === [] ? '' : 'WHERE '.implode(' AND ', $where), $params];
    }

    private function buildOrdersOrderBy(string $sort, string $direction): string
    {
        $column = match ($sort) {
            'client' => 'c.client_full_name',
            'manager' => 'm.manager_full_name',
            'cost' => 'o.total_cost',
            'status' => 'o.status',
            'prepayment' => 'o.prepayment_amount',
            default => 'o.event_date',
        };

        $directionSql = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';

        return $column.' '.$directionSql.', o.order_id DESC';
    }

    private function validatedEventType(string $eventType): string
    {
        if (!in_array($eventType, self::EVENT_TYPES, true)) {
            throw new RuntimeException('Выберите корректный тип мероприятия.');
        }

        return $eventType;
    }

    private function validatedStatus(string $status): string
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new RuntimeException('Выберите корректный статус заказа.');
        }

        return $status;
    }

    private function parseDishLines(array $data): array
    {
        $dishes = [];
        $seen = [];
        foreach (($data['dish_id'] ?? []) as $index => $dishId) {
            $quantity = (float) str_replace(',', '.', (string) ($data['quantity'][$index] ?? 0));
            if ((int) $dishId > 0 && $quantity > 0) {
                if (isset($seen[(int) $dishId])) {
                    throw new RuntimeException('Одно блюдо нельзя добавлять в заказ дважды. Объедините количество порций в одной строке.');
                }
                $seen[(int) $dishId] = true;
                $dishes[] = ['dish_id' => (int) $dishId, 'quantity' => $quantity];
            }
        }

        if ($dishes === []) {
            throw new RuntimeException('Выберите хотя бы одно блюдо и укажите количество порций.');
        }

        return $dishes;
    }

    private function replaceOrderDishes(int $orderId, array $dishes): void
    {
        foreach ($this->connection->fetchAllAssociative('SELECT product_id, quantity FROM reserved_products WHERE order_id = :id', ['id' => $orderId]) as $reservation) {
            $this->connection->executeStatement(
                'UPDATE product_stock SET quantity = quantity + :quantity WHERE product_id = :product',
                ['product' => (int) $reservation['product_id'], 'quantity' => (float) $reservation['quantity']]
            );
        }

        $this->connection->executeStatement('DELETE FROM reserved_products WHERE order_id = :id', ['id' => $orderId]);
        $this->connection->executeStatement('DELETE FROM order_details WHERE order_id = :id', ['id' => $orderId]);

        $totalCost = 0.0;
        foreach ($dishes as $dish) {
            $dishRow = $this->connection->fetchAssociative(
                'SELECT dish_id, sale_price FROM dish WHERE dish_id = :id AND is_active = TRUE',
                ['id' => $dish['dish_id']]
            );
            if (!$dishRow) {
                throw new RuntimeException('Выбранное блюдо недоступно или скрыто из меню.');
            }

            $this->connection->executeStatement(
                'INSERT INTO order_details (order_id, dish_id, serving_number)
                 VALUES (:order, :dish, :quantity)',
                ['order' => $orderId, 'dish' => $dish['dish_id'], 'quantity' => $dish['quantity']]
            );

            $totalCost += (float) $dishRow['sale_price'] * (float) $dish['quantity'];

            // Вложенный цикл по рецепту (содержит JOIN для получения названия продукта)
            foreach ($this->connection->fetchAllAssociative(
                'SELECT r.product_id, r.number_in_recipe, p.product_name 
                 FROM recipe r 
                 JOIN product p ON p.product_id = r.product_id 
                 WHERE r.dish_id = :id', 
                ['id' => $dish['dish_id']]
            ) as $recipe) {
                $requiredQuantity = (float) $recipe['number_in_recipe'] * (float) $dish['quantity'];
                $availableQuantity = $this->connection->fetchOne(
                    'SELECT quantity FROM product_stock WHERE product_id = :id FOR UPDATE',
                    ['id' => (int) $recipe['product_id']]
                );
                $availableQuantity = $availableQuantity === false ? 0.0 : (float) $availableQuantity;
                if ($availableQuantity < $requiredQuantity) {
                    throw new RuntimeException(sprintf(
                        'Недостаточно продукта "%s" на складе! Нужно: %.3f, доступно: %.3f',
                        $recipe['product_name'],
                        $requiredQuantity,
                        $availableQuantity
                    ));
                }

                $this->connection->executeStatement(
                    'UPDATE product_stock SET quantity = quantity - :quantity WHERE product_id = :product',
                    ['product' => (int) $recipe['product_id'], 'quantity' => $requiredQuantity]
                );
                $this->connection->executeStatement(
                    'INSERT INTO reserved_products (order_id, product_id, quantity)
                     VALUES (:order, :product, :quantity)',
                    ['order' => $orderId, 'product' => (int) $recipe['product_id'], 'quantity' => $requiredQuantity]
                );
            } // Конец цикла рецепта (закрывает внутренний foreach)
        } // Конец цикла блюд (закрывает внешний foreach)

        $this->connection->executeStatement(
            'UPDATE orders SET total_cost = :total WHERE order_id = :id',
            ['id' => $orderId, 'total' => $totalCost]
        );
        $this->connection->executeStatement(
            "SELECT log_operation('UPDATE_DISHES', 'Orders', :id, 'Состав заказа обновлен, складские резервы пересчитаны')",
            ['id' => $orderId]
        );
    }
}
