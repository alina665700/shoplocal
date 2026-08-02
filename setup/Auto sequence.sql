
BEGIN EXECUTE IMMEDIATE 'DROP SEQUENCE seq_customer_id'; EXCEPTION WHEN OTHERS THEN IF SQLCODE != -2289 THEN RAISE; END IF; END;
/
BEGIN EXECUTE IMMEDIATE 'DROP SEQUENCE seq_trader_id'; EXCEPTION WHEN OTHERS THEN IF SQLCODE != -2289 THEN RAISE; END IF; END;
/
BEGIN EXECUTE IMMEDIATE 'DROP SEQUENCE seq_admin_id'; EXCEPTION WHEN OTHERS THEN IF SQLCODE != -2289 THEN RAISE; END IF; END;
/
BEGIN EXECUTE IMMEDIATE 'DROP SEQUENCE seq_category_id'; EXCEPTION WHEN OTHERS THEN IF SQLCODE != -2289 THEN RAISE; END IF; END;
/
BEGIN EXECUTE IMMEDIATE 'DROP SEQUENCE seq_shop_id'; EXCEPTION WHEN OTHERS THEN IF SQLCODE != -2289 THEN RAISE; END IF; END;
/
BEGIN EXECUTE IMMEDIATE 'DROP SEQUENCE seq_product_id'; EXCEPTION WHEN OTHERS THEN IF SQLCODE != -2289 THEN RAISE; END IF; END;
/
BEGIN EXECUTE IMMEDIATE 'DROP SEQUENCE seq_discount_id'; EXCEPTION WHEN OTHERS THEN IF SQLCODE != -2289 THEN RAISE; END IF; END;
/
BEGIN EXECUTE IMMEDIATE 'DROP SEQUENCE seq_voucher_id'; EXCEPTION WHEN OTHERS THEN IF SQLCODE != -2289 THEN RAISE; END IF; END;
/
BEGIN EXECUTE IMMEDIATE 'DROP SEQUENCE seq_slot_id'; EXCEPTION WHEN OTHERS THEN IF SQLCODE != -2289 THEN RAISE; END IF; END;
/
BEGIN EXECUTE IMMEDIATE 'DROP SEQUENCE seq_order_id'; EXCEPTION WHEN OTHERS THEN IF SQLCODE != -2289 THEN RAISE; END IF; END;
/
BEGIN EXECUTE IMMEDIATE 'DROP SEQUENCE seq_payment_id'; EXCEPTION WHEN OTHERS THEN IF SQLCODE != -2289 THEN RAISE; END IF; END;
/
BEGIN EXECUTE IMMEDIATE 'DROP SEQUENCE seq_cart_id'; EXCEPTION WHEN OTHERS THEN IF SQLCODE != -2289 THEN RAISE; END IF; END;
/
BEGIN EXECUTE IMMEDIATE 'DROP SEQUENCE seq_wishlist_id'; EXCEPTION WHEN OTHERS THEN IF SQLCODE != -2289 THEN RAISE; END IF; END;
/
BEGIN EXECUTE IMMEDIATE 'DROP SEQUENCE seq_review_id'; EXCEPTION WHEN OTHERS THEN IF SQLCODE != -2289 THEN RAISE; END IF; END;
/

BEGIN EXECUTE IMMEDIATE 'DROP SEQUENCE seq_trader_payout_id'; EXCEPTION WHEN OTHERS THEN IF SQLCODE != -2289 THEN RAISE; END IF; END;
/
BEGIN EXECUTE IMMEDIATE 'DROP SEQUENCE seq_trader_payout_item_id'; EXCEPTION WHEN OTHERS THEN IF SQLCODE != -2289 THEN RAISE; END IF; END;
/


CREATE SEQUENCE seq_customer_id START WITH 1 INCREMENT BY 1 NOCACHE;
CREATE SEQUENCE seq_trader_id   START WITH 1 INCREMENT BY 1 NOCACHE;
CREATE SEQUENCE seq_admin_id    START WITH 1 INCREMENT BY 1 NOCACHE;
CREATE SEQUENCE seq_category_id START WITH 1 INCREMENT BY 1 NOCACHE;
CREATE SEQUENCE seq_shop_id     START WITH 1 INCREMENT BY 1 NOCACHE;
CREATE SEQUENCE seq_product_id  START WITH 1 INCREMENT BY 1 NOCACHE;
CREATE SEQUENCE seq_discount_id START WITH 1 INCREMENT BY 1 NOCACHE;
CREATE SEQUENCE seq_voucher_id  START WITH 1 INCREMENT BY 1 NOCACHE;
CREATE SEQUENCE seq_slot_id     START WITH 1 INCREMENT BY 1 NOCACHE;
CREATE SEQUENCE seq_order_id    START WITH 1 INCREMENT BY 1 NOCACHE;
CREATE SEQUENCE seq_payment_id  START WITH 1 INCREMENT BY 1 NOCACHE;
CREATE SEQUENCE seq_cart_id     START WITH 1 INCREMENT BY 1 NOCACHE;
CREATE SEQUENCE seq_wishlist_id START WITH 1 INCREMENT BY 1 NOCACHE;
CREATE SEQUENCE seq_review_id   START WITH 1 INCREMENT BY 1 NOCACHE;
CREATE SEQUENCE seq_trader_payout_id      START WITH 1 INCREMENT BY 1 NOCACHE;
CREATE SEQUENCE seq_trader_payout_item_id START WITH 1 INCREMENT BY 1 NOCACHE;


CREATE OR REPLACE TRIGGER trg_generate_user_id
BEFORE INSERT ON "USER"
FOR EACH ROW
BEGIN
    :NEW.user_role := UPPER(NVL(:NEW.user_role, 'CUSTOMER'));

    IF :NEW.user_id IS NULL THEN
        IF :NEW.user_role = 'ADMIN' THEN
            :NEW.user_id := 'A' || LPAD(seq_admin_id.NEXTVAL, 9, '0');
        ELSIF :NEW.user_role = 'TRADER' THEN
            :NEW.user_id := 'T' || LPAD(seq_trader_id.NEXTVAL, 9, '0');
        ELSE
            :NEW.user_id := 'C' || LPAD(seq_customer_id.NEXTVAL, 9, '0');
        END IF;
    END IF;
END;
/

CREATE OR REPLACE TRIGGER trg_insert_role_table
AFTER INSERT ON "USER"
FOR EACH ROW
BEGIN
    IF UPPER(:NEW.user_role) = 'ADMIN' THEN
        INSERT INTO ADMIN (user_id) VALUES (:NEW.user_id);
    ELSIF UPPER(:NEW.user_role) = 'TRADER' THEN
        INSERT INTO TRADER (user_id) VALUES (:NEW.user_id);
    ELSIF UPPER(:NEW.user_role) = 'CUSTOMER' THEN
        INSERT INTO CUSTOMER (user_id) VALUES (:NEW.user_id);
    END IF;
END;
/

CREATE OR REPLACE TRIGGER trg_generate_category_id
BEFORE INSERT ON CATEGORY
FOR EACH ROW
BEGIN
    IF :NEW.category_id IS NULL THEN
        :NEW.category_id := 'CAT' || LPAD(seq_category_id.NEXTVAL, 7, '0');
    END IF;
END;
/

MERGE INTO CATEGORY c
USING (
    SELECT
        'CAT0000000' AS category_id,
        'Others' AS category_name,
        NULL AS category_image,
        'Default fallback category for products without a specific category.' AS description
    FROM dual
) src
ON (c.category_id = src.category_id)
WHEN NOT MATCHED THEN
    INSERT (category_id, category_name, category_image, description)
    VALUES (src.category_id, src.category_name, src.category_image, src.description);



CREATE OR REPLACE TRIGGER trg_generate_shop_id
BEFORE INSERT ON SHOP
FOR EACH ROW
BEGIN
    IF :NEW.shop_id IS NULL THEN
        :NEW.shop_id := 'S' || LPAD(seq_shop_id.NEXTVAL, 9, '0');
    END IF;
END;
/

CREATE OR REPLACE TRIGGER trg_generate_product_id
BEFORE INSERT ON PRODUCT
FOR EACH ROW
BEGIN
    IF :NEW.product_id IS NULL THEN
        :NEW.product_id := 'P' || LPAD(seq_product_id.NEXTVAL, 9, '0');
    END IF;
END;
/

CREATE OR REPLACE TRIGGER trg_generate_discount_id
BEFORE INSERT ON DISCOUNT
FOR EACH ROW
BEGIN
    IF :NEW.discount_id IS NULL THEN
        :NEW.discount_id := 'D' || LPAD(seq_discount_id.NEXTVAL, 9, '0');
    END IF;
END;
/

CREATE OR REPLACE TRIGGER trg_generate_voucher_id
BEFORE INSERT ON VOUCHER
FOR EACH ROW
BEGIN
    IF :NEW.voucher_id IS NULL THEN
        :NEW.voucher_id := 'V' || LPAD(seq_voucher_id.NEXTVAL, 9, '0');
    END IF;
END;
/

CREATE OR REPLACE TRIGGER trg_generate_slot_id
BEFORE INSERT ON PICKUP_SLOT
FOR EACH ROW
BEGIN
    IF :NEW.slot_id IS NULL THEN
        :NEW.slot_id := 'SL' || LPAD(seq_slot_id.NEXTVAL, 8, '0');
    END IF;
END;
/

CREATE OR REPLACE TRIGGER trg_generate_order_id
BEFORE INSERT ON ORDERS
FOR EACH ROW
BEGIN
    IF :NEW.order_id IS NULL THEN
        :NEW.order_id := 'O' || LPAD(seq_order_id.NEXTVAL, 9, '0');
    END IF;
END;
/

CREATE OR REPLACE TRIGGER trg_generate_payment_id
BEFORE INSERT ON PAYMENT
FOR EACH ROW
BEGIN
    IF :NEW.payment_id IS NULL THEN
        :NEW.payment_id := 'PAY' || LPAD(seq_payment_id.NEXTVAL, 7, '0');
    END IF;
END;
/

CREATE OR REPLACE TRIGGER trg_generate_cart_id
BEFORE INSERT ON CART
FOR EACH ROW
BEGIN
    IF :NEW.cart_id IS NULL THEN
        :NEW.cart_id := 'CART' || LPAD(seq_cart_id.NEXTVAL, 6, '0');
    END IF;
END;
/

CREATE OR REPLACE TRIGGER trg_generate_wishlist_id
BEFORE INSERT ON WISHLIST
FOR EACH ROW
BEGIN
    IF :NEW.wishlist_id IS NULL THEN
        :NEW.wishlist_id := 'WISH' || LPAD(seq_wishlist_id.NEXTVAL, 6, '0');
    END IF;
END;
/

CREATE OR REPLACE TRIGGER trg_generate_review_id
BEFORE INSERT ON REVIEW
FOR EACH ROW
BEGIN
    IF :NEW.review_id IS NULL THEN
        :NEW.review_id := 'R' || LPAD(seq_review_id.NEXTVAL, 9, '0');
    END IF;

    IF :NEW.approval_status IS NULL THEN
        :NEW.approval_status := 'YES';
    END IF;
END;
/


CREATE OR REPLACE TRIGGER trg_generate_trader_payout_id
BEFORE INSERT ON TRADER_PAYOUT
FOR EACH ROW
BEGIN
    IF :NEW.payout_id IS NULL THEN
        :NEW.payout_id := 'TPAY' || LPAD(seq_trader_payout_id.NEXTVAL, 8, '0');
    END IF;
END;
/

CREATE OR REPLACE TRIGGER trg_generate_payout_item_id
BEFORE INSERT ON TRADER_PAYOUT_ITEM
FOR EACH ROW
BEGIN
    IF :NEW.payout_item_id IS NULL THEN
        :NEW.payout_item_id := 'TPI' || LPAD(seq_trader_payout_item_id.NEXTVAL, 8, '0');
    END IF;
END;
/

CREATE INDEX idx_trader_payout_trader ON TRADER_PAYOUT(trader_id);
CREATE INDEX idx_trader_payout_paid_date ON TRADER_PAYOUT(paid_date);
CREATE INDEX idx_tpi_payout ON TRADER_PAYOUT_ITEM(payout_id);
CREATE INDEX idx_tpi_trader ON TRADER_PAYOUT_ITEM(trader_id);
CREATE INDEX idx_tpi_order ON TRADER_PAYOUT_ITEM(order_id);


INSERT INTO PICKUP_SLOT (allowed_day, start_hour, end_hour, max_capacity)
VALUES ('WEDNESDAY', 10, 13, 20);

INSERT INTO PICKUP_SLOT (allowed_day, start_hour, end_hour, max_capacity)
VALUES ('WEDNESDAY', 13, 16, 20);

INSERT INTO PICKUP_SLOT (allowed_day, start_hour, end_hour, max_capacity)
VALUES ('WEDNESDAY', 16, 19, 20);

INSERT INTO PICKUP_SLOT (allowed_day, start_hour, end_hour, max_capacity)
VALUES ('THURSDAY', 10, 13, 20);

INSERT INTO PICKUP_SLOT (allowed_day, start_hour, end_hour, max_capacity)
VALUES ('THURSDAY', 13, 16, 20);

INSERT INTO PICKUP_SLOT (allowed_day, start_hour, end_hour, max_capacity)
VALUES ('THURSDAY', 16, 19, 20);

INSERT INTO PICKUP_SLOT (allowed_day, start_hour, end_hour, max_capacity)
VALUES ('FRIDAY', 10, 13, 20);

INSERT INTO PICKUP_SLOT (allowed_day, start_hour, end_hour, max_capacity)
VALUES ('FRIDAY', 13, 16, 20);

INSERT INTO PICKUP_SLOT (allowed_day, start_hour, end_hour, max_capacity)
VALUES ('FRIDAY', 16, 19, 20);

COMMIT;