DROP SCHEMA public CASCADE;
CREATE SCHEMA public;

CREATE TABLE Client (
    client_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    client_full_name VARCHAR(100) NOT NULL,
    phone_number VARCHAR(20) NOT NULL
);

CREATE TABLE Manager (
    manager_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    manager_full_name VARCHAR(100) NOT NULL
);

CREATE TABLE Dish (
    dish_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    dish_name VARCHAR(100) NOT NULL,
    cost_price NUMERIC(10,2) NOT NULL,
    sale_price NUMERIC(10,2) NOT NULL,
    price_category VARCHAR(20),
    profit NUMERIC(10,2),
    seasonality VARCHAR(50) DEFAULT 'Всесезонное',
    is_active BOOLEAN DEFAULT TRUE
);

CREATE TABLE Product (
    product_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    product_name VARCHAR(100) NOT NULL
);

CREATE TABLE Supplier (
    supplier_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    supplier_name VARCHAR(100) NOT NULL
);

CREATE TABLE Orders (
    order_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    status VARCHAR(100) DEFAULT 'В обработке' NOT NULL,
    manager_id INT NOT NULL,
    client_id INT NOT NULL,
    event_date DATE NOT NULL,
    rental_cost NUMERIC(10,2) DEFAULT 0 NOT NULL,
    event_type VARCHAR(50) DEFAULT 'Банкет',
    prepayment_amount NUMERIC(10, 2) DEFAULT 0.00,
    is_fully_paid BOOLEAN DEFAULT FALSE
);

CREATE TABLE Order_Details (
    dish_id INT NOT NULL,
    order_id INT NOT NULL,
    serving_number NUMERIC(10,2) NOT NULL,
    CONSTRAINT PK_ORDER_DETAILS PRIMARY KEY (dish_id, order_id)
);

CREATE TABLE Recipe (
    product_id INT NOT NULL,
    dish_id INT NOT NULL,
    number_in_recipe NUMERIC(10,3) NOT NULL,
    CONSTRAINT PK_RECIPE PRIMARY KEY (product_id, dish_id)
);

CREATE TABLE Supplier_Request (
    request_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    request_date DATE NOT NULL,
    created_at DATE DEFAULT CURRENT_DATE,
    manager_id INT NOT NULL,
    supplier_id INT NOT NULL,
    status VARCHAR(50) DEFAULT 'В пути' NOT NULL,
    linked_order_id INT
);

CREATE TABLE Request_Details (
    product_id INT NOT NULL,
    request_id INT NOT NULL,
    products_number NUMERIC(10,2) NOT NULL,
    CONSTRAINT PK_REQUEST_DETAILS PRIMARY KEY (product_id, request_id)
);

CREATE TABLE operation_log (
    log_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    operation_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    operation_type VARCHAR(50),
    table_name VARCHAR(50),
    record_id INT,
    description TEXT,
    user_info TEXT
);

CREATE TABLE product_stock (
    product_id INT PRIMARY KEY,
    quantity NUMERIC(10,2) NOT NULL DEFAULT 0,
    min_quantity NUMERIC(10,2) NOT NULL DEFAULT 10,
    last_restock_date DATE
);

CREATE TABLE reserved_products (
    reservation_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity NUMERIC(10,2) NOT NULL,
    reservation_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(20) DEFAULT 'RESERVED'
);

ALTER TABLE Orders ADD CONSTRAINT FK_ORDER_CLIENT FOREIGN KEY (client_id) REFERENCES Client (client_id) ON DELETE RESTRICT;
ALTER TABLE Orders ADD CONSTRAINT FK_ORDER_MANAGER FOREIGN KEY (manager_id) REFERENCES Manager (manager_id) ON DELETE RESTRICT;
ALTER TABLE Order_Details ADD CONSTRAINT FK_OD_DISH FOREIGN KEY (dish_id) REFERENCES Dish (dish_id) ON DELETE CASCADE;
ALTER TABLE Order_Details ADD CONSTRAINT FK_OD_ORDER FOREIGN KEY (order_id) REFERENCES Orders (order_id) ON DELETE CASCADE;
ALTER TABLE Recipe ADD CONSTRAINT FK_RECIPE_DISH FOREIGN KEY (dish_id) REFERENCES Dish (dish_id) ON DELETE CASCADE;
ALTER TABLE Recipe ADD CONSTRAINT FK_RECIPE_PRODUCT FOREIGN KEY (product_id) REFERENCES Product (product_id) ON DELETE RESTRICT;
ALTER TABLE Supplier_Request ADD CONSTRAINT FK_SR_MANAGER FOREIGN KEY (manager_id) REFERENCES Manager (manager_id) ON DELETE RESTRICT;
ALTER TABLE Supplier_Request ADD CONSTRAINT FK_SR_SUPPLIER FOREIGN KEY (supplier_id) REFERENCES Supplier (supplier_id) ON DELETE RESTRICT;
ALTER TABLE Supplier_Request ADD CONSTRAINT FK_SR_ORDER FOREIGN KEY (linked_order_id) REFERENCES Orders (order_id) ON DELETE SET NULL;
ALTER TABLE Request_Details ADD CONSTRAINT FK_RD_REQUEST FOREIGN KEY (request_id) REFERENCES Supplier_Request (request_id) ON DELETE CASCADE;
ALTER TABLE Request_Details ADD CONSTRAINT FK_RD_PRODUCT FOREIGN KEY (product_id) REFERENCES Product (product_id) ON DELETE RESTRICT;
ALTER TABLE product_stock ADD CONSTRAINT FK_STOCK_PRODUCT FOREIGN KEY (product_id) REFERENCES Product (product_id) ON DELETE CASCADE;
ALTER TABLE reserved_products ADD CONSTRAINT FK_RES_ORDER FOREIGN KEY (order_id) REFERENCES Orders (order_id) ON DELETE CASCADE;
ALTER TABLE reserved_products ADD CONSTRAINT FK_RES_PRODUCT FOREIGN KEY (product_id) REFERENCES Product (product_id) ON DELETE CASCADE;

CREATE OR REPLACE FUNCTION log_operation(p_operation_type VARCHAR, p_table_name VARCHAR, p_record_id INT, p_description TEXT)
RETURNS VOID AS $$
BEGIN
    INSERT INTO operation_log (operation_type, table_name, record_id, description)
    VALUES (p_operation_type, p_table_name, p_record_id, p_description);
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION set_dish_price_category() RETURNS TRIGGER AS $$
BEGIN
    IF NEW.sale_price < 300 THEN NEW.price_category := 'Эконом';
    ELSIF NEW.sale_price <= 600 THEN NEW.price_category := 'Стандарт';
    ELSE NEW.price_category := 'Премиум';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_set_price_category BEFORE INSERT OR UPDATE OF sale_price ON Dish
FOR EACH ROW EXECUTE FUNCTION set_dish_price_category();

CREATE OR REPLACE FUNCTION calculate_dish_profit() RETURNS TRIGGER AS $$
BEGIN
    NEW.profit := NEW.sale_price - NEW.cost_price;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_calculate_profit BEFORE INSERT OR UPDATE OF cost_price, sale_price ON Dish
FOR EACH ROW EXECUTE FUNCTION calculate_dish_profit();

CREATE OR REPLACE FUNCTION check_order_date_and_status() RETURNS TRIGGER AS $$
BEGIN
    IF (TG_OP = 'INSERT') OR (NEW.event_date <> OLD.event_date) THEN
        IF NEW.event_date < CURRENT_DATE AND NEW.status NOT IN ('Выполнен', 'Отменен') THEN
            RAISE EXCEPTION 'Дата нового или активного заказа не может быть в прошлом: %', NEW.event_date;
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_order_date_check BEFORE INSERT OR UPDATE ON Orders
FOR EACH ROW EXECUTE FUNCTION check_order_date_and_status();

CREATE OR REPLACE FUNCTION prevent_edit_cancelled_order() RETURNS TRIGGER AS $$
BEGIN
    IF OLD.status = 'Отменен' THEN
        RAISE EXCEPTION 'Ошибка: Нельзя редактировать отмененный заказ (ID: %)', OLD.order_id;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_prevent_edit_cancelled BEFORE UPDATE ON Orders
FOR EACH ROW EXECUTE FUNCTION prevent_edit_cancelled_order();

CREATE OR REPLACE FUNCTION check_order_payment_status() RETURNS TRIGGER AS $$
BEGIN
    IF NEW.prepayment_amount > 0 AND (TG_OP = 'INSERT' OR OLD.status = 'В обработке') THEN
        NEW.status := 'Забронирован';
    END IF;

    NEW.is_fully_paid := (NEW.prepayment_amount >= NEW.rental_cost AND NEW.rental_cost > 0);

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_payment_status BEFORE INSERT OR UPDATE OF prepayment_amount, rental_cost ON Orders
FOR EACH ROW EXECUTE FUNCTION check_order_payment_status();

CREATE OR REPLACE FUNCTION log_order_status_change() RETURNS TRIGGER AS $$
BEGIN
    IF OLD.status <> NEW.status THEN
        PERFORM log_operation('UPDATE_STATUS', 'Orders', NEW.order_id, 'Статус изменен с ' || OLD.status || ' на ' || NEW.status);
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_log_order_status AFTER UPDATE OF status ON Orders
FOR EACH ROW EXECUTE FUNCTION log_order_status_change();

CREATE OR REPLACE FUNCTION apply_received_supplier_request_detail() RETURNS TRIGGER AS $$
DECLARE
    v_status VARCHAR;
    v_request_date DATE;
BEGIN
    SELECT status, request_date INTO v_status, v_request_date
    FROM Supplier_Request
    WHERE request_id = NEW.request_id;

    IF v_status = 'Получено' THEN
        UPDATE product_stock
        SET quantity = quantity + NEW.products_number,
            last_restock_date = v_request_date
        WHERE product_id = NEW.product_id;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_received_request_detail AFTER INSERT ON Request_Details
FOR EACH ROW EXECUTE FUNCTION apply_received_supplier_request_detail();

CREATE OR REPLACE FUNCTION apply_received_supplier_request_status() RETURNS TRIGGER AS $$
DECLARE
    v_detail RECORD;
BEGIN
    IF NEW.status = 'Получено' AND OLD.status <> 'Получено' THEN
        FOR v_detail IN
            SELECT product_id, products_number
            FROM Request_Details
            WHERE request_id = NEW.request_id
        LOOP
            UPDATE product_stock
            SET quantity = quantity + v_detail.products_number,
                last_restock_date = NEW.request_date
            WHERE product_id = v_detail.product_id;
        END LOOP;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_received_request_status AFTER UPDATE OF status ON Supplier_Request
FOR EACH ROW EXECUTE FUNCTION apply_received_supplier_request_status();

CREATE OR REPLACE PROCEDURE create_complex_order_full(
    IN p_client_id INT,
    IN p_manager_id INT,
    IN p_event_date DATE,
    IN p_dishes JSONB,
    OUT p_order_id INT,
    OUT p_total_cost NUMERIC,
    OUT p_status VARCHAR(20),
    OUT p_message TEXT
)
LANGUAGE plpgsql AS $$
DECLARE
    v_dish_record JSONB; v_dish_id INT; v_quantity NUMERIC; v_dish_price NUMERIC;
    v_product_id INT; v_required_qty NUMERIC; v_available_qty NUMERIC;
BEGIN
    p_status := 'ERROR'; p_message := ''; p_total_cost := 0;

    INSERT INTO Orders (client_id, manager_id, event_date, status, rental_cost)
    VALUES (p_client_id, p_manager_id, p_event_date, 'В обработке', 0)
    RETURNING order_id INTO p_order_id;

    FOR v_dish_record IN SELECT * FROM jsonb_array_elements(p_dishes) LOOP
        v_dish_id := (v_dish_record->>'dish_id')::INT;
        v_quantity := (v_dish_record->>'quantity')::NUMERIC;

        SELECT sale_price INTO v_dish_price FROM Dish WHERE dish_id = v_dish_id AND is_active = TRUE;
        IF v_dish_price IS NULL THEN CONTINUE; END IF;

        INSERT INTO Order_Details (order_id, dish_id, serving_number)
        VALUES (p_order_id, v_dish_id, v_quantity);

        p_total_cost := p_total_cost + (v_dish_price * v_quantity);

        FOR v_product_id, v_required_qty IN
            SELECT r.product_id, r.number_in_recipe * v_quantity
            FROM Recipe r WHERE r.dish_id = v_dish_id
        LOOP
            SELECT quantity INTO v_available_qty FROM product_stock WHERE product_id = v_product_id;
            IF v_available_qty IS NULL THEN v_available_qty := 0; END IF;

            IF v_available_qty >= v_required_qty THEN
                UPDATE product_stock SET quantity = quantity - v_required_qty WHERE product_id = v_product_id;
                INSERT INTO reserved_products (order_id, product_id, quantity) VALUES (p_order_id, v_product_id, v_required_qty);
            ELSE
                RAISE EXCEPTION 'Недостаточно продукта (ID: %) на складе! Нужно: %, Доступно: %',
                                v_product_id, v_required_qty, v_available_qty;
            END IF;
        END LOOP;
    END LOOP;

    UPDATE Orders SET rental_cost = p_total_cost WHERE order_id = p_order_id;

    p_status := 'SUCCESS';
    p_message := 'Заказ успешно создан, продукты зарезервированы.';

EXCEPTION WHEN OTHERS THEN
    p_status := 'ERROR';
    p_message := 'Ошибка создания заказа: ' || SQLERRM;
END;
$$;

CREATE OR REPLACE PROCEDURE cancel_order_logic(p_order_id INT)
LANGUAGE plpgsql AS $$
DECLARE
    v_status VARCHAR; v_rec RECORD;
BEGIN
    SELECT status INTO v_status FROM Orders WHERE order_id = p_order_id;
    IF v_status = 'Отменен' THEN RAISE EXCEPTION 'Заказ уже отменен!'; END IF;
    IF v_status = 'Выполнен' THEN RAISE EXCEPTION 'Нельзя отменить выполненный заказ!'; END IF;

    FOR v_rec IN (SELECT product_id, quantity FROM reserved_products WHERE order_id = p_order_id) LOOP
        UPDATE product_stock SET quantity = quantity + v_rec.quantity WHERE product_id = v_rec.product_id;
    END LOOP;

    DELETE FROM reserved_products WHERE order_id = p_order_id;
    UPDATE Orders SET status = 'Отменен' WHERE order_id = p_order_id;
    PERFORM log_operation('CANCEL', 'Orders', p_order_id, 'Заказ отменен, продукты возвращены');
END;
$$;

INSERT INTO Client (client_full_name, phone_number) VALUES
('Смирнов Павел Александрович', '9012345678'), ('Волкова Екатерина Олеговна', '9023456789'),
('Николаев Иван Петрович', '9034567890'), ('Орлова София Дмитриевна', '9045678901');

INSERT INTO Manager (manager_full_name) VALUES
('Иванов Алексей Петрович'), ('Петрова Мария Сергеевна');

INSERT INTO Supplier (supplier_name) VALUES
('ООО "ПродуктыОпт"'), ('ИП Сидоров - Мясо');

INSERT INTO Product (product_name) VALUES
('Куриное филе'), ('Помидоры'), ('Салат Айсберг'), ('Сыр Пармезан'), ('Сухарики'), ('Говядина');

INSERT INTO product_stock (product_id, quantity, min_quantity, last_restock_date)
SELECT product_id, 100, 10, CURRENT_DATE FROM Product;

INSERT INTO Dish (dish_name, cost_price, sale_price, seasonality) VALUES
('Салат Цезарь', 180.50, 450.00, 'Лето'),
('Стейк из говядины', 350.25, 890.00, 'Всесезонное');

INSERT INTO Recipe (product_id, dish_id, number_in_recipe) VALUES
(1, 1, 0.2), (3, 1, 0.15), (4, 1, 0.05), (5, 1, 0.03),
(6, 2, 0.3);

INSERT INTO Orders (status, manager_id, client_id, event_date, rental_cost, event_type, is_fully_paid) VALUES
('Выполнен', 1, 1, CURRENT_DATE - INTERVAL '5 days', 5000.00, 'Свадьба', TRUE),
('В обработке', 2, 2, CURRENT_DATE + INTERVAL '5 days', 7500.00, 'Корпоратив', FALSE);
