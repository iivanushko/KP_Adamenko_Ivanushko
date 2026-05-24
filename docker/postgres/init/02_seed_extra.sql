-- Extra demo data for the catering coursework database.
-- The script is intentionally idempotent enough to run on an already created
-- local database: reference rows are matched by names, order lines by order/date.

INSERT INTO Client (client_full_name, phone_number)
SELECT *
FROM (VALUES
    ('Кузнецова Анна Викторовна', '9051112233'),
    ('Морозов Дмитрий Игоревич', '9052223344'),
    ('Соколова Ирина Павловна', '9053334455'),
    ('Попов Артем Сергеевич', '9054445566'),
    ('Лебедева Марина Андреевна', '9055556677'),
    ('Егоров Максим Олегович', '9056667788'),
    ('Федорова Алиса Романовна', '9057778899'),
    ('Андреев Кирилл Михайлович', '9058889900'),
    ('Зайцева Полина Ильинична', '9061112233'),
    ('Комаров Владислав Денисович', '9062223344'),
    ('Григорьева Наталья Евгеньевна', '9063334455'),
    ('Белова Дарья Константиновна', '9064445566')
) AS seed(client_full_name, phone_number)
WHERE NOT EXISTS (
    SELECT 1 FROM Client c WHERE c.client_full_name = seed.client_full_name
);

INSERT INTO Manager (manager_full_name)
SELECT *
FROM (VALUES
    ('Соколова Елена Викторовна'),
    ('Кузьмин Дмитрий Андреевич'),
    ('Ларина Ольга Николаевна')
) AS seed(manager_full_name)
WHERE NOT EXISTS (
    SELECT 1 FROM Manager m WHERE m.manager_full_name = seed.manager_full_name
);

INSERT INTO Supplier (supplier_name)
SELECT *
FROM (VALUES
    ('АО "Фермерская линия"'),
    ('ООО "Морской вкус"'),
    ('ООО "Свежая зелень"'),
    ('ИП Климова - Выпечка и десерты')
) AS seed(supplier_name)
WHERE NOT EXISTS (
    SELECT 1 FROM Supplier s WHERE s.supplier_name = seed.supplier_name
);

INSERT INTO Product (product_name)
SELECT *
FROM (VALUES
    ('Свинина'),
    ('Лосось'),
    ('Картофель'),
    ('Морковь'),
    ('Лук репчатый'),
    ('Шампиньоны'),
    ('Сливки'),
    ('Рис'),
    ('Сливочное масло'),
    ('Яйца'),
    ('Мука'),
    ('Ягоды'),
    ('Хлеб багет'),
    ('Зелень'),
    ('Огурцы'),
    ('Креветки'),
    ('Макароны'),
    ('Сметана'),
    ('Мед'),
    ('Фрукты сезонные'),
    ('Сахар')
) AS seed(product_name)
WHERE NOT EXISTS (
    SELECT 1 FROM Product p WHERE p.product_name = seed.product_name
);

INSERT INTO product_stock (product_id, quantity, min_quantity, last_restock_date)
SELECT
    p.product_id,
    CASE p.product_name
        WHEN 'Куриное филе' THEN 42
        WHEN 'Помидоры' THEN 28
        WHEN 'Салат Айсберг' THEN 16
        WHEN 'Сыр Пармезан' THEN 8
        WHEN 'Сухарики' THEN 22
        WHEN 'Говядина' THEN 18
        WHEN 'Свинина' THEN 24
        WHEN 'Лосось' THEN 6
        WHEN 'Картофель' THEN 85
        WHEN 'Морковь' THEN 38
        WHEN 'Лук репчатый' THEN 31
        WHEN 'Шампиньоны' THEN 13
        WHEN 'Сливки' THEN 19
        WHEN 'Рис' THEN 54
        WHEN 'Сливочное масло' THEN 11
        WHEN 'Яйца' THEN 70
        WHEN 'Мука' THEN 62
        WHEN 'Ягоды' THEN 9
        WHEN 'Хлеб багет' THEN 26
        WHEN 'Зелень' THEN 7
        WHEN 'Огурцы' THEN 21
        WHEN 'Креветки' THEN 5
        WHEN 'Макароны' THEN 45
        WHEN 'Сметана' THEN 17
        WHEN 'Мед' THEN 10
        WHEN 'Фрукты сезонные' THEN 18
        WHEN 'Сахар' THEN 40
        ELSE 20
    END,
    CASE p.product_name
        WHEN 'Лосось' THEN 8
        WHEN 'Ягоды' THEN 12
        WHEN 'Зелень' THEN 10
        WHEN 'Креветки' THEN 6
        WHEN 'Сыр Пармезан' THEN 7
        ELSE 10
    END,
    CURRENT_DATE - INTERVAL '3 days'
FROM Product p
WHERE NOT EXISTS (
    SELECT 1 FROM product_stock s WHERE s.product_id = p.product_id
);

INSERT INTO Dish (dish_name, cost_price, sale_price, seasonality, is_active)
SELECT *
FROM (VALUES
    ('Брускетта с томатами', 95.00, 280.00, 'Лето', TRUE),
    ('Крем-суп грибной', 150.00, 360.00, 'Осень', TRUE),
    ('Лосось с рисом и зеленью', 390.00, 720.00, 'Всесезонное', TRUE),
    ('Жульен с курицей', 185.00, 420.00, 'Всесезонное', TRUE),
    ('Картофельный гратен', 120.00, 310.00, 'Зима', TRUE),
    ('Овощные канапе', 70.00, 190.00, 'Лето', TRUE),
    ('Мини-бургеры с говядиной', 240.00, 520.00, 'Всесезонное', TRUE),
    ('Паста с креветками', 330.00, 680.00, 'Всесезонное', TRUE),
    ('Медовик порционный', 105.00, 260.00, 'Всесезонное', TRUE),
    ('Фруктовая тарелка', 170.00, 390.00, 'Лето', TRUE),
    ('Морс ягодный', 42.00, 120.00, 'Всесезонное', TRUE),
    ('Свинина в сливочном соусе', 275.00, 610.00, 'Зима', FALSE)
) AS seed(dish_name, cost_price, sale_price, seasonality, is_active)
WHERE NOT EXISTS (
    SELECT 1 FROM Dish d WHERE d.dish_name = seed.dish_name
);

INSERT INTO Recipe (product_id, dish_id, number_in_recipe)
SELECT p.product_id, d.dish_id, seed.number_in_recipe
FROM (VALUES
    ('Помидоры', 'Брускетта с томатами', 0.120),
    ('Хлеб багет', 'Брускетта с томатами', 0.080),
    ('Зелень', 'Брускетта с томатами', 0.010),
    ('Шампиньоны', 'Крем-суп грибной', 0.160),
    ('Сливки', 'Крем-суп грибной', 0.080),
    ('Лук репчатый', 'Крем-суп грибной', 0.030),
    ('Лосось', 'Лосось с рисом и зеленью', 0.220),
    ('Рис', 'Лосось с рисом и зеленью', 0.120),
    ('Зелень', 'Лосось с рисом и зеленью', 0.015),
    ('Куриное филе', 'Жульен с курицей', 0.160),
    ('Шампиньоны', 'Жульен с курицей', 0.080),
    ('Сливки', 'Жульен с курицей', 0.060),
    ('Сыр Пармезан', 'Жульен с курицей', 0.025),
    ('Картофель', 'Картофельный гратен', 0.220),
    ('Сливки', 'Картофельный гратен', 0.050),
    ('Сливочное масло', 'Картофельный гратен', 0.020),
    ('Огурцы', 'Овощные канапе', 0.070),
    ('Помидоры', 'Овощные канапе', 0.060),
    ('Сыр Пармезан', 'Овощные канапе', 0.020),
    ('Говядина', 'Мини-бургеры с говядиной', 0.140),
    ('Хлеб багет', 'Мини-бургеры с говядиной', 0.090),
    ('Огурцы', 'Мини-бургеры с говядиной', 0.030),
    ('Креветки', 'Паста с креветками', 0.140),
    ('Макароны', 'Паста с креветками', 0.150),
    ('Сливки', 'Паста с креветками', 0.050),
    ('Мука', 'Медовик порционный', 0.045),
    ('Яйца', 'Медовик порционный', 0.050),
    ('Сметана', 'Медовик порционный', 0.070),
    ('Мед', 'Медовик порционный', 0.030),
    ('Фрукты сезонные', 'Фруктовая тарелка', 0.250),
    ('Ягоды', 'Фруктовая тарелка', 0.060),
    ('Ягоды', 'Морс ягодный', 0.070),
    ('Сахар', 'Морс ягодный', 0.025),
    ('Свинина', 'Свинина в сливочном соусе', 0.220),
    ('Сливки', 'Свинина в сливочном соусе', 0.060),
    ('Лук репчатый', 'Свинина в сливочном соусе', 0.035)
) AS seed(product_name, dish_name, number_in_recipe)
JOIN Product p ON p.product_name = seed.product_name
JOIN Dish d ON d.dish_name = seed.dish_name
ON CONFLICT (product_id, dish_id) DO UPDATE
SET number_in_recipe = EXCLUDED.number_in_recipe;

WITH seed_orders(client_name, manager_name, status, event_offset, event_type, total_cost) AS (
    VALUES
        ('Смирнов Павел Александрович', 'Иванов Алексей Петрович', 'Выполнен', -5, 'Свадьба', 4470.00),
        ('Волкова Екатерина Олеговна', 'Петрова Мария Сергеевна', 'В обработке', 5, 'Корпоратив', 7540.00),
        ('Кузнецова Анна Викторовна', 'Соколова Елена Викторовна', 'Выполнен', -35, 'Свадьба', 48480.00),
        ('Морозов Дмитрий Игоревич', 'Кузьмин Дмитрий Андреевич', 'Выполнен', -22, 'Корпоратив', 42250.00),
        ('Соколова Ирина Павловна', 'Ларина Ольга Николаевна', 'Выполнен', -14, 'День рождения', 24660.00),
        ('Попов Артем Сергеевич', 'Иванов Алексей Петрович', 'Отменен', -8, 'Фуршет', 18100.00),
        ('Лебедева Марина Андреевна', 'Петрова Мария Сергеевна', 'Выполнен', -2, 'Банкет', 29180.00),
        ('Егоров Максим Олегович', 'Соколова Елена Викторовна', 'В обработке', 0, 'Банкет', 18880.00),
        ('Федорова Алиса Романовна', 'Кузьмин Дмитрий Андреевич', 'Забронирован', 3, 'Свадьба', 55200.00),
        ('Андреев Кирилл Михайлович', 'Ларина Ольга Николаевна', 'В обработке', 7, 'Фуршет', 18400.00),
        ('Зайцева Полина Ильинична', 'Иванов Алексей Петрович', 'Забронирован', 10, 'Корпоратив', 33200.00),
        ('Комаров Владислав Денисович', 'Петрова Мария Сергеевна', 'В обработке', 16, 'День рождения', 12600.00),
        ('Григорьева Наталья Евгеньевна', 'Соколова Елена Викторовна', 'Забронирован', 25, 'Банкет', 40500.00),
        ('Белова Дарья Константиновна', 'Кузьмин Дмитрий Андреевич', 'В обработке', 40, 'Свадьба', 68720.00)
)
INSERT INTO Orders (status, manager_id, client_id, event_date, total_cost, event_type, prepayment_amount)
SELECT so.status, m.manager_id, c.client_id, (CURRENT_DATE + so.event_offset * INTERVAL '1 day')::DATE,
       so.total_cost, so.event_type, 0
FROM seed_orders so
JOIN Client c ON c.client_full_name = so.client_name
JOIN Manager m ON m.manager_full_name = so.manager_name
ON CONFLICT (client_id, event_date) DO NOTHING;

WITH seed_details(client_name, event_offset, event_type, dish_name, serving_number) AS (
    VALUES
        ('Смирнов Павел Александрович', -5, 'Свадьба', 'Салат Цезарь', 4.00),
        ('Смирнов Павел Александрович', -5, 'Свадьба', 'Стейк из говядины', 3.00),
        ('Волкова Екатерина Олеговна', 5, 'Корпоратив', 'Салат Цезарь', 6.00),
        ('Волкова Екатерина Олеговна', 5, 'Корпоратив', 'Мини-бургеры с говядиной', 5.00),
        ('Волкова Екатерина Олеговна', 5, 'Корпоратив', 'Брускетта с томатами', 8.00),
        ('Кузнецова Анна Викторовна', -35, 'Свадьба', 'Салат Цезарь', 25.00),
        ('Кузнецова Анна Викторовна', -35, 'Свадьба', 'Стейк из говядины', 15.00),
        ('Кузнецова Анна Викторовна', -35, 'Свадьба', 'Лосось с рисом и зеленью', 20.00),
        ('Кузнецова Анна Викторовна', -35, 'Свадьба', 'Фруктовая тарелка', 12.00),
        ('Кузнецова Анна Викторовна', -35, 'Свадьба', 'Морс ягодный', 40.00),
        ('Морозов Дмитрий Игоревич', -22, 'Корпоратив', 'Брускетта с томатами', 30.00),
        ('Морозов Дмитрий Игоревич', -22, 'Корпоратив', 'Паста с креветками', 20.00),
        ('Морозов Дмитрий Игоревич', -22, 'Корпоратив', 'Картофельный гратен', 25.00),
        ('Морозов Дмитрий Игоревич', -22, 'Корпоратив', 'Медовик порционный', 25.00),
        ('Морозов Дмитрий Игоревич', -22, 'Корпоратив', 'Морс ягодный', 50.00),
        ('Соколова Ирина Павловна', -14, 'День рождения', 'Овощные канапе', 20.00),
        ('Соколова Ирина Павловна', -14, 'День рождения', 'Мини-бургеры с говядиной', 18.00),
        ('Соколова Ирина Павловна', -14, 'День рождения', 'Жульен с курицей', 15.00),
        ('Соколова Ирина Павловна', -14, 'День рождения', 'Медовик порционный', 20.00),
        ('Попов Артем Сергеевич', -8, 'Фуршет', 'Овощные канапе', 30.00),
        ('Попов Артем Сергеевич', -8, 'Фуршет', 'Брускетта с томатами', 25.00),
        ('Попов Артем Сергеевич', -8, 'Фуршет', 'Фруктовая тарелка', 14.00),
        ('Лебедева Марина Андреевна', -2, 'Банкет', 'Стейк из говядины', 12.00),
        ('Лебедева Марина Андреевна', -2, 'Банкет', 'Картофельный гратен', 20.00),
        ('Лебедева Марина Андреевна', -2, 'Банкет', 'Салат Цезарь', 18.00),
        ('Лебедева Марина Андреевна', -2, 'Банкет', 'Морс ягодный', 35.00),
        ('Егоров Максим Олегович', 0, 'Банкет', 'Крем-суп грибной', 12.00),
        ('Егоров Максим Олегович', 0, 'Банкет', 'Жульен с курицей', 14.00),
        ('Егоров Максим Олегович', 0, 'Банкет', 'Картофельный гратен', 16.00),
        ('Егоров Максим Олегович', 0, 'Банкет', 'Медовик порционный', 14.00),
        ('Федорова Алиса Романовна', 3, 'Свадьба', 'Лосось с рисом и зеленью', 35.00),
        ('Федорова Алиса Романовна', 3, 'Свадьба', 'Салат Цезарь', 28.00),
        ('Федорова Алиса Романовна', 3, 'Свадьба', 'Паста с креветками', 20.00),
        ('Федорова Алиса Романовна', 3, 'Свадьба', 'Фруктовая тарелка', 10.00),
        ('Андреев Кирилл Михайлович', 7, 'Фуршет', 'Овощные канапе', 30.00),
        ('Андреев Кирилл Михайлович', 7, 'Фуршет', 'Брускетта с томатами', 25.00),
        ('Андреев Кирилл Михайлович', 7, 'Фуршет', 'Мини-бургеры с говядиной', 10.00),
        ('Андреев Кирилл Михайлович', 7, 'Фуршет', 'Морс ягодный', 5.00),
        ('Зайцева Полина Ильинична', 10, 'Корпоратив', 'Паста с креветками', 18.00),
        ('Зайцева Полина Ильинична', 10, 'Корпоратив', 'Стейк из говядины', 10.00),
        ('Зайцева Полина Ильинична', 10, 'Корпоратив', 'Крем-суп грибной', 16.00),
        ('Зайцева Полина Ильинична', 10, 'Корпоратив', 'Медовик порционный', 26.00),
        ('Комаров Владислав Денисович', 16, 'День рождения', 'Мини-бургеры с говядиной', 12.00),
        ('Комаров Владислав Денисович', 16, 'День рождения', 'Морс ягодный', 18.00),
        ('Комаров Владислав Денисович', 16, 'День рождения', 'Медовик порционный', 9.00),
        ('Григорьева Наталья Евгеньевна', 25, 'Банкет', 'Салат Цезарь', 24.00),
        ('Григорьева Наталья Евгеньевна', 25, 'Банкет', 'Лосось с рисом и зеленью', 20.00),
        ('Григорьева Наталья Евгеньевна', 25, 'Банкет', 'Картофельный гратен', 20.00),
        ('Григорьева Наталья Евгеньевна', 25, 'Банкет', 'Фруктовая тарелка', 14.00),
        ('Григорьева Наталья Евгеньевна', 25, 'Банкет', 'Морс ягодный', 30.00),
        ('Белова Дарья Константиновна', 40, 'Свадьба', 'Стейк из говядины', 20.00),
        ('Белова Дарья Константиновна', 40, 'Свадьба', 'Лосось с рисом и зеленью', 24.00),
        ('Белова Дарья Константиновна', 40, 'Свадьба', 'Паста с креветками', 22.00),
        ('Белова Дарья Константиновна', 40, 'Свадьба', 'Брускетта с томатами', 35.00),
        ('Белова Дарья Константиновна', 40, 'Свадьба', 'Морс ягодный', 80.00)
)
INSERT INTO Order_Details (dish_id, order_id, serving_number)
SELECT d.dish_id, o.order_id, seed.serving_number
FROM seed_details seed
JOIN Client c ON c.client_full_name = seed.client_name
JOIN Orders o ON o.client_id = c.client_id
    AND o.event_date = (CURRENT_DATE + seed.event_offset * INTERVAL '1 day')::DATE
    AND o.event_type = seed.event_type
JOIN Dish d ON d.dish_name = seed.dish_name
ON CONFLICT (dish_id, order_id) DO UPDATE
SET serving_number = EXCLUDED.serving_number;

WITH seed_keys(client_name, event_date, event_type) AS (
    VALUES
        ('Смирнов Павел Александрович',    (CURRENT_DATE - INTERVAL '5 days')::DATE,  'Свадьба'),
        ('Волкова Екатерина Олеговна',      (CURRENT_DATE + INTERVAL '5 days')::DATE,  'Корпоратив'),
        ('Кузнецова Анна Викторовна',       (CURRENT_DATE - INTERVAL '35 days')::DATE, 'Свадьба'),
        ('Морозов Дмитрий Игоревич',        (CURRENT_DATE - INTERVAL '22 days')::DATE, 'Корпоратив'),
        ('Соколова Ирина Павловна',         (CURRENT_DATE - INTERVAL '14 days')::DATE, 'День рождения'),
        ('Лебедева Марина Андреевна',       (CURRENT_DATE - INTERVAL '2 days')::DATE,  'Банкет'),
        ('Егоров Максим Олегович',          CURRENT_DATE::DATE,                        'Банкет'),
        ('Федорова Алиса Романовна',        (CURRENT_DATE + INTERVAL '3 days')::DATE,  'Свадьба'),
        ('Андреев Кирилл Михайлович',       (CURRENT_DATE + INTERVAL '7 days')::DATE,  'Фуршет'),
        ('Зайцева Полина Ильинична',        (CURRENT_DATE + INTERVAL '10 days')::DATE, 'Корпоратив'),
        ('Комаров Владислав Денисович',     (CURRENT_DATE + INTERVAL '16 days')::DATE, 'День рождения'),
        ('Григорьева Наталья Евгеньевна',   (CURRENT_DATE + INTERVAL '25 days')::DATE, 'Банкет'),
        ('Белова Дарья Константиновна',     (CURRENT_DATE + INTERVAL '40 days')::DATE, 'Свадьба')
),
seeded_orders AS (
    -- MIN(order_id) гарантирует ровно одну строку на каждый seed-ключ,
    -- исключая чужие заказы, случайно совпавшие по (client, date, type).
    SELECT MIN(o.order_id) AS order_id
    FROM seed_keys sk
    JOIN Client c ON c.client_full_name = sk.client_name
    JOIN Orders o ON o.client_id    = c.client_id
                 AND o.event_date   = sk.event_date
                 AND o.event_type   = sk.event_type
    GROUP BY c.client_id, o.event_date, o.event_type
),
totals AS (
    SELECT od.order_id, SUM(od.serving_number * d.sale_price) AS total_cost
    FROM Order_Details od
    JOIN Dish d ON d.dish_id = od.dish_id
    WHERE od.order_id IN (SELECT order_id FROM seeded_orders)
    GROUP BY od.order_id
)
UPDATE Orders o
SET total_cost = totals.total_cost
FROM totals
WHERE o.order_id = totals.order_id;

-- Предоплата и статус обновляются одним шагом. Триггер trg_payment_status
-- теперь меняет статус на «Забронирован» только при реальном изменении предоплаты,
-- поэтому побочный эффект при обновлении total_cost исключён.
WITH payments(client_name, event_offset, event_type, status, prepayment_amount) AS (
    VALUES
        ('Смирнов Павел Александрович', -5, 'Свадьба', 'Выполнен', 4470.00),
        ('Кузнецова Анна Викторовна', -35, 'Свадьба', 'Выполнен', 48480.00),
        ('Морозов Дмитрий Игоревич', -22, 'Корпоратив', 'Выполнен', 42250.00),
        ('Соколова Ирина Павловна', -14, 'День рождения', 'Выполнен', 24660.00),
        ('Лебедева Марина Андреевна', -2, 'Банкет', 'Выполнен', 29180.00),
        ('Федорова Алиса Романовна', 3, 'Свадьба', 'Забронирован', 30000.00),
        ('Зайцева Полина Ильинична', 10, 'Корпоратив', 'Забронирован', 15000.00),
        ('Григорьева Наталья Евгеньевна', 25, 'Банкет', 'Забронирован', 40500.00)
)
UPDATE Orders o
SET prepayment_amount = payments.prepayment_amount,
    status = payments.status
FROM payments
JOIN Client c ON c.client_full_name = payments.client_name
WHERE o.client_id = c.client_id
  AND o.event_date = (CURRENT_DATE + payments.event_offset * INTERVAL '1 day')::DATE
  AND o.event_type = payments.event_type
  AND o.status <> 'Отменен';

WITH seed_requests(supplier_name, manager_name, request_offset, status, linked_client_name, linked_event_offset) AS (
    VALUES
        ('АО "Фермерская линия"', 'Соколова Елена Викторовна', -18, 'Получено', 'Морозов Дмитрий Игоревич', -22),
        ('ООО "Морской вкус"', 'Кузьмин Дмитрий Андреевич', -7, 'Получено', 'Лебедева Марина Андреевна', -2),
        ('ООО "Свежая зелень"', 'Ларина Ольга Николаевна', -1, 'В пути', 'Федорова Алиса Романовна', 3),
        ('ИП Климова - Выпечка и десерты', 'Иванов Алексей Петрович', 1, 'В пути', 'Григорьева Наталья Евгеньевна', 25),
        ('ООО "ПродуктыОпт"', 'Петрова Мария Сергеевна', 4, 'В пути', 'Белова Дарья Константиновна', 40)
)
INSERT INTO Supplier_Request (request_date, manager_id, supplier_id, status, linked_order_id)
SELECT (CURRENT_DATE + sr.request_offset * INTERVAL '1 day')::DATE,
       m.manager_id,
       s.supplier_id,
       sr.status,
       o.order_id
FROM seed_requests sr
JOIN Supplier s ON s.supplier_name = sr.supplier_name
JOIN Manager m ON m.manager_full_name = sr.manager_name
LEFT JOIN Client c ON c.client_full_name = sr.linked_client_name
LEFT JOIN Orders o ON o.client_id = c.client_id
    AND o.event_date = (CURRENT_DATE + sr.linked_event_offset * INTERVAL '1 day')::DATE
WHERE NOT EXISTS (
    SELECT 1
    FROM Supplier_Request existing
    WHERE existing.supplier_id = s.supplier_id
      AND existing.request_date = (CURRENT_DATE + sr.request_offset * INTERVAL '1 day')::DATE
);

WITH seed_request_details(supplier_name, request_offset, product_name, products_number) AS (
    VALUES
        ('АО "Фермерская линия"', -18, 'Картофель', 60.00),
        ('АО "Фермерская линия"', -18, 'Морковь', 25.00),
        ('АО "Фермерская линия"', -18, 'Куриное филе', 30.00),
        ('ООО "Морской вкус"', -7, 'Лосось', 18.00),
        ('ООО "Морской вкус"', -7, 'Креветки', 16.00),
        ('ООО "Свежая зелень"', -1, 'Зелень', 12.00),
        ('ООО "Свежая зелень"', -1, 'Огурцы', 20.00),
        ('ООО "Свежая зелень"', -1, 'Помидоры', 24.00),
        ('ИП Климова - Выпечка и десерты', 1, 'Мука', 35.00),
        ('ИП Климова - Выпечка и десерты', 1, 'Ягоды', 18.00),
        ('ИП Климова - Выпечка и десерты', 1, 'Сметана', 20.00),
        ('ООО "ПродуктыОпт"', 4, 'Говядина', 28.00),
        ('ООО "ПродуктыОпт"', 4, 'Сыр Пармезан', 12.00),
        ('ООО "ПродуктыОпт"', 4, 'Сливки', 24.00)
)
INSERT INTO Request_Details (product_id, request_id, products_number)
SELECT p.product_id, sr.request_id, seed.products_number
FROM seed_request_details seed
JOIN Supplier s ON s.supplier_name = seed.supplier_name
JOIN Supplier_Request sr ON sr.supplier_id = s.supplier_id
    AND sr.request_date = (CURRENT_DATE + seed.request_offset * INTERVAL '1 day')::DATE
JOIN Product p ON p.product_name = seed.product_name
ON CONFLICT (product_id, request_id) DO UPDATE
SET products_number = EXCLUDED.products_number;

UPDATE product_stock ps
SET quantity = seed.quantity,
    min_quantity = seed.min_quantity,
    last_restock_date = seed.last_restock_date
FROM (
    VALUES
        ('Куриное филе', 42.00, 10.00, CURRENT_DATE - INTERVAL '4 days'),
        ('Помидоры', 28.00, 10.00, CURRENT_DATE - INTERVAL '1 day'),
        ('Салат Айсберг', 16.00, 10.00, CURRENT_DATE - INTERVAL '6 days'),
        ('Сыр Пармезан', 8.00, 7.00, CURRENT_DATE - INTERVAL '4 days'),
        ('Сухарики', 22.00, 10.00, CURRENT_DATE - INTERVAL '9 days'),
        ('Говядина', 18.00, 10.00, CURRENT_DATE - INTERVAL '4 days'),
        ('Свинина', 24.00, 10.00, CURRENT_DATE - INTERVAL '8 days'),
        ('Лосось', 6.00, 8.00, CURRENT_DATE - INTERVAL '7 days'),
        ('Картофель', 85.00, 10.00, CURRENT_DATE - INTERVAL '18 days'),
        ('Морковь', 38.00, 10.00, CURRENT_DATE - INTERVAL '18 days'),
        ('Лук репчатый', 31.00, 10.00, CURRENT_DATE - INTERVAL '12 days'),
        ('Шампиньоны', 13.00, 10.00, CURRENT_DATE - INTERVAL '5 days'),
        ('Сливки', 19.00, 10.00, CURRENT_DATE - INTERVAL '4 days'),
        ('Рис', 54.00, 10.00, CURRENT_DATE - INTERVAL '15 days'),
        ('Сливочное масло', 11.00, 10.00, CURRENT_DATE - INTERVAL '11 days'),
        ('Яйца', 70.00, 10.00, CURRENT_DATE - INTERVAL '2 days'),
        ('Мука', 62.00, 10.00, CURRENT_DATE - INTERVAL '1 day'),
        ('Ягоды', 9.00, 12.00, CURRENT_DATE - INTERVAL '1 day'),
        ('Хлеб багет', 26.00, 10.00, CURRENT_DATE - INTERVAL '2 days'),
        ('Зелень', 7.00, 10.00, CURRENT_DATE - INTERVAL '1 day'),
        ('Огурцы', 21.00, 10.00, CURRENT_DATE - INTERVAL '1 day'),
        ('Креветки', 5.00, 6.00, CURRENT_DATE - INTERVAL '7 days'),
        ('Макароны', 45.00, 10.00, CURRENT_DATE - INTERVAL '16 days'),
        ('Сметана', 17.00, 10.00, CURRENT_DATE - INTERVAL '1 day'),
        ('Мед', 10.00, 10.00, CURRENT_DATE - INTERVAL '20 days'),
        ('Фрукты сезонные', 18.00, 10.00, CURRENT_DATE - INTERVAL '3 days'),
        ('Сахар', 40.00, 10.00, CURRENT_DATE - INTERVAL '14 days')
) AS seed(product_name, quantity, min_quantity, last_restock_date)
JOIN Product p ON p.product_name = seed.product_name
WHERE ps.product_id = p.product_id;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM operation_log
        WHERE operation_type = 'SEED'
          AND table_name = 'Database'
          AND description = 'Добавлен расширенный демонстрационный набор данных для курсовой работы'
    ) THEN
        PERFORM log_operation('SEED', 'Database', 0, 'Добавлен расширенный демонстрационный набор данных для курсовой работы');
    END IF;
END;
$$;
