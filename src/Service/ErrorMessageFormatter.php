<?php

namespace App\Service;

use Throwable;

class ErrorMessageFormatter
{
    public function format(Throwable $exception): string
    {
        $message = $exception->getMessage();

        if (preg_match('/Недостаточно продукта \(ID: (\d+)\).*Нужно: ([\d.]+), Доступно: ([\d.]+)/u', $message, $matches)) {
            return sprintf(
                'Недостаточно продукта на складе: требуется %s, доступно %s. Проверьте остатки или создайте заявку поставщику.',
                $matches[2],
                $matches[3]
            );
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

        if (str_contains($message, 'violates foreign key constraint')) {
            return 'Запись используется в заказах, рецептах или поставках, поэтому ее нельзя удалить.';
        }

        if (str_contains($message, 'duplicate key value')) {
            return 'Такая запись уже существует.';
        }

        return preg_replace('/^An exception occurred while executing a query: /', '', $message) ?: 'Операция не выполнена.';
    }
}
