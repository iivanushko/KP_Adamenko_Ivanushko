<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Throwable;

class ErrorMessageFormatter
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function format(Throwable $exception): string
    {
        $message = $exception->getMessage();

        // 1. Обработка нового формата сообщений (из PHP-кода)
        if (preg_match('/Недостаточно продукта "([^"]+)" на складе! Нужно: ([\d.]+), доступно: ([\d.]+)/u', $message, $matches)) {
            return sprintf(
                'Недостаточно продукта "%s" на складе для выполнения заказа. Требуется %s, в наличии на складе %s. Пожалуйста, пополните запасы или скорректируйте состав заказа.',
                $matches[1],
                number_format((float)$matches[2], 2, ',', ' '),
                number_format((float)$matches[3], 2, ',', ' ')
            );
        }

        // 2. Обработка старого или технического формата ошибок (например, из процедур/триггеров БД с ID)
        if (preg_match('/Недостаточно продукта \(ID: (\d+)\).*Нужно: ([\d.]+), Доступно: ([\d.]+)/u', $message, $matches)) {
            $productId = (int) $matches[1];
            
            // Запрашиваем имя продукта по его ID
            $productName = $this->connection->fetchOne(
                'SELECT product_name FROM product WHERE product_id = :id',
                ['id' => $productId]
            );

            if (!$productName) {
                $productName = 'с кодом ' . $productId;
            } else {
                $productName = '"' . $productName . '"';
            }

            return sprintf(
                'Недостаточно продукта %s на складе для выполнения заказа. Требуется %s, в наличии на складе %s. Пожалуйста, пополните запасы или скорректируйте состав заказа.',
                $productName,
                number_format((float)$matches[2], 2, ',', ' '),
                number_format((float)$matches[3], 2, ',', ' ')
            );
        }

        // 3. Обработка ошибки недоступного блюда из хранимой процедуры
        if (preg_match('/Блюдо \(ID: (\d+)\) недоступно или неактивно!/u', $message, $matches)) {
            $dishId = (int) $matches[1];
            $dishName = $this->connection->fetchOne('SELECT dish_name FROM dish WHERE dish_id = :id', ['id' => $dishId]);
            
            $dishName = $dishName ? '"' . $dishName . '"' : 'с кодом ' . $dishId;
            
            return sprintf('Блюдо %s в данный момент недоступно или скрыто из меню. Пожалуйста, обновите страницу и скорректируйте состав заказа.', $dishName);
        }

        if (str_contains($message, 'Дата нового или активного заказа не может быть в прошлом')) {
            return 'Дата активного заказа не может быть в прошлом. Выберите сегодняшнюю или будущую дату.';
        }

        if (str_contains($message, 'Нельзя редактировать отмененный заказ')) {
            return 'Отмененный заказ нельзя редактировать.';
        }

        if (str_contains($message, 'Нельзя отменить выполненный заказ')) {
            return 'Выполненный заказ нельзя отменить.';
        }

        if (str_contains($message, 'value too long for type character varying') || str_contains($message, 'String data, right truncated')) {
            return 'Введенный текст слишком длинный. Пожалуйста, сократите его (максимум 50-100 символов в зависимости от поля).';
        }
        
        if (str_contains($message, 'not-null constraint')) {
            return 'Пожалуйста, заполните все обязательные поля.';
        }

        if ($exception instanceof \Doctrine\DBAL\Exception\DriverException) {
            $sqlState = $exception->getSQLState();

            if ($sqlState === '23503') { // foreign_key_violation
                return 'Запись используется в заказах, рецептах или поставках, поэтому ее нельзя удалить.';
            }

            if ($sqlState === '23505') { // unique_violation
                return 'Такая запись уже существует.';
            }
        }

        if (str_contains($message, 'violates foreign key constraint')) {
            return 'Запись используется в заказах, рецептах или поставках, поэтому ее нельзя удалить.';
        }

        if (str_contains($message, 'duplicate key value')) {
            return 'Такая запись уже существует.';
        }

        return preg_replace('/^An exception occurred while executing a query: /', '', $message) ?: 'Операция не выполнена.';
    }
}