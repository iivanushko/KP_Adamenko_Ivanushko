DROP TRIGGER IF EXISTS trg_received_request_detail ON Request_Details;
DROP TRIGGER IF EXISTS trg_received_request_status ON Supplier_Request;

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
