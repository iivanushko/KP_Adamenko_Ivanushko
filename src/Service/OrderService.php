<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use RuntimeException;

class OrderService
{
    public const STATUSES = ['В обработке', 'Забронирован', 'Выполнен', 'Отменен'];
    public const EVENT_TYPES = ['Банкет', 'Свадьба', 'Корпоратив', 'День рождения', 'Фуршет'];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function getSummary(): array
    {
        return [
            'orders_total' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM orders'),
            'active_orders' => (int) $this->connection->fetchOne("SELECT COUNT(*) FROM orders WHERE status NOT IN ('Выполнен', 'Отменен')"),
            'revenue' => (float) $this->connection->fetchOne("SELECT COALESCE(SUM(rental_cost), 0) FROM orders WHERE status <> 'Отменен'"),
            'low_stock' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM product_stock WHERE quantity <= min_quantity'),
        ];
    }

    public function getOrders(array $filters, int $page, int $limit): array
    {
        [$where, $params] = $this->buildOrderFilter($filters);
        $offset = max(0, ($page - 1) * $limit);
        $sortMap = [
            'date' => 'o.event_date',
            'client' => 'c.client_full_name',
            'manager' => 'm.manager_full_name',
            'cost' => 'o.rental_cost',
            'status' => 'o.status',
            'prepayment' => 'o.prepayment_amount',
        ];
        $sort = array_key_exists((string) ($filters['sort'] ?? ''), $sortMap) ? (string) $filters['sort'] : 'date';
        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $orderBy = $sortMap[$sort].' '.$direction.', o.order_id DESC';

        $items = $this->connection->fetchAllAssociative(
            "SELECT o.order_id, o.client_id, o.manager_id, o.status, o.event_date, o.rental_cost, o.event_type, o.prepayment_amount, o.is_fully_paid,
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
             LIMIT $limit OFFSET $offset",
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
            'SELECT o.*, c.client_full_name, m.manager_full_name
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
            'SELECT d.dish_name, od.serving_number, d.sale_price, od.serving_number * d.sale_price AS line_total
             FROM order_details od
             JOIN dish d ON d.dish_id = od.dish_id
             WHERE od.order_id = :id
             ORDER BY d.dish_name',
            ['id' => $id]
        );
    }

    public function createComplexOrder(array $data): array
    {
        if (($data['event_date'] ?? '') < date('Y-m-d')) {
            throw new RuntimeException('Дата активного заказа не может быть в прошлом. Выберите сегодняшнюю или будущую дату.');
        }

        $dishes = [];
        foreach (($data['dish_id'] ?? []) as $index => $dishId) {
            $quantity = (float) str_replace(',', '.', (string) ($data['quantity'][$index] ?? 0));
            if ((int) $dishId > 0 && $quantity > 0) {
                $dishes[] = ['dish_id' => (int) $dishId, 'quantity' => $quantity];
            }
        }

        if ($dishes === []) {
            throw new RuntimeException('Выберите хотя бы одно блюдо и укажите количество порций.');
        }

        $row = $this->connection->fetchAssociative(
            'CALL create_complex_order_full(:client, :manager, :event_date, CAST(:dishes AS jsonb), NULL, NULL, NULL, NULL)',
            [
                'client' => (int) $data['client_id'],
                'manager' => (int) $data['manager_id'],
                'event_date' => $data['event_date'],
                'dishes' => json_encode($dishes, JSON_UNESCAPED_UNICODE),
            ]
        );

        if (!$row || ($row['p_status'] ?? 'ERROR') !== 'SUCCESS') {
            throw new RuntimeException($row['p_message'] ?? 'Заказ не был создан.');
        }

        return $row;
    }

    public function updateOrder(int $id, array $data): void
    {
        $this->connection->executeStatement(
            'UPDATE orders
             SET client_id = :client, manager_id = :manager, event_date = :event_date, event_type = :event_type,
                 status = :status, prepayment_amount = :prepayment
             WHERE order_id = :id',
            [
                'id' => $id,
                'client' => (int) $data['client_id'],
                'manager' => (int) $data['manager_id'],
                'event_date' => $data['event_date'],
                'event_type' => $data['event_type'],
                'status' => $data['status'],
                'prepayment' => (float) str_replace(',', '.', (string) $data['prepayment_amount']),
            ]
        );
    }

    public function inlineUpdate(int $id, string $field, string $value): void
    {
        $allowed = ['status', 'prepayment_amount'];
        if (!in_array($field, $allowed, true)) {
            throw new RuntimeException('Недопустимое поле для быстрого редактирования.');
        }

        $this->connection->executeStatement("UPDATE orders SET $field = :value WHERE order_id = :id", [
            'id' => $id,
            'value' => $field === 'prepayment_amount' ? (float) str_replace(',', '.', $value) : $value,
        ]);
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
            'SELECT dish_id, dish_name, sale_price, price_category, seasonality FROM dish WHERE is_active = TRUE ORDER BY dish_name'
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
}
