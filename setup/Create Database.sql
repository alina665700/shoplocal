
CREATE TABLE "USER" (
    user_id        VARCHAR2(10) PRIMARY KEY,
    first_name     VARCHAR2(100) NOT NULL,
    last_name      VARCHAR2(100) NOT NULL,
    email_address  VARCHAR2(255) NOT NULL UNIQUE,
    ph_number      NUMBER(15) NOT NULL,
    password_hash  VARCHAR2(255) NOT NULL,
    user_role      VARCHAR2(20) DEFAULT 'CUSTOMER' NOT NULL
        CONSTRAINT chk_user_role CHECK (user_role IN ('CUSTOMER','TRADER','ADMIN')),
    active_status  VARCHAR2(20) DEFAULT 'ACTIVE' NOT NULL
        CONSTRAINT chk_user_active_status CHECK (active_status IN ('ACTIVE','SUSPEND')),
    email_verified NUMBER(1) DEFAULT 0 NOT NULL,
    email_token    VARCHAR2(100),
    date_created   DATE DEFAULT SYSDATE
);

CREATE TABLE CUSTOMER (
    user_id VARCHAR2(10) PRIMARY KEY REFERENCES "USER"(user_id)
);

CREATE TABLE ADMIN (
    user_id VARCHAR2(10) PRIMARY KEY REFERENCES "USER"(user_id)
);

CREATE TABLE TRADER (
    user_id         VARCHAR2(10) PRIMARY KEY REFERENCES "USER"(user_id),
    admin_id        VARCHAR2(10) NULL REFERENCES ADMIN(user_id),
    pan_number      VARCHAR2(20) UNIQUE,
    verified_status VARCHAR2(20) DEFAULT 'PENDING'
        CONSTRAINT chk_trader_verified_status CHECK (verified_status IN ('PENDING','VERIFIED','REJECTED'))
);

CREATE TABLE CATEGORY (
    category_id    VARCHAR2(10) PRIMARY KEY,
    category_name  VARCHAR2(100) NOT NULL UNIQUE,
    category_image VARCHAR2(255),
    description    VARCHAR2(500)
);

CREATE TABLE SHOP (
    shop_id         VARCHAR2(10) PRIMARY KEY,
    trader_id       VARCHAR2(10) NOT NULL REFERENCES TRADER(user_id),
    shop_name       VARCHAR2(200) NOT NULL UNIQUE,
    location        VARCHAR2(300),
    approval_status VARCHAR2(20) DEFAULT 'PENDING'
        CONSTRAINT chk_shop_approval_status CHECK (approval_status IN ('PENDING','APPROVED','SUSPENDED'))
);

CREATE TABLE PRODUCT (
    product_id              VARCHAR2(10) PRIMARY KEY,
    product_image           VARCHAR2(255),
    shop_id                 VARCHAR2(10) NOT NULL REFERENCES SHOP(shop_id),
    category_id VARCHAR2(10) DEFAULT 'CAT0000000' NULL REFERENCES CATEGORY(category_id),
    admin_id                VARCHAR2(10) NULL REFERENCES ADMIN(user_id),
    product_name            VARCHAR2(200) NOT NULL,
    description             CLOB,
    item_price              NUMBER(10,2) NOT NULL CONSTRAINT chk_product_price CHECK (item_price >= 0),
    quantity_per_item       NUMBER(10) DEFAULT 1,
    stock_available         NUMBER(10) DEFAULT 0 CONSTRAINT chk_product_stock CHECK (stock_available >= 0),
    min_order               NUMBER(5) DEFAULT 1,
    max_order               NUMBER(5) DEFAULT 100,
    allergy_info            VARCHAR2(500),
    is_active               NUMBER(1) DEFAULT 1 NOT NULL,
    admin_approval_status   VARCHAR2(20) DEFAULT 'PENDING' NOT NULL,
    CONSTRAINT chk_product_min_max CHECK (min_order <= max_order),
    CONSTRAINT chk_product_is_active CHECK (is_active IN (0, 1)),
    CONSTRAINT chk_product_admin_approval CHECK (admin_approval_status IN ('PENDING', 'APPROVED', 'REJECTED'))
);

CREATE TABLE DISCOUNT (
    discount_id         VARCHAR2(10) PRIMARY KEY,
    product_id          VARCHAR2(10) NOT NULL REFERENCES PRODUCT(product_id),
    trader_id           VARCHAR2(10) NOT NULL REFERENCES TRADER(user_id),
    discount_percentage NUMBER(5,2) CONSTRAINT chk_discount_percentage CHECK (discount_percentage BETWEEN 0 AND 100),
    start_date          DATE NOT NULL,
    end_date            DATE NOT NULL,
    CONSTRAINT chk_discount_dates CHECK (end_date > start_date)
);

CREATE TABLE VOUCHER (
    voucher_id       VARCHAR2(10) PRIMARY KEY,
    voucher_code     VARCHAR2(50) NOT NULL UNIQUE,
    discount_type    VARCHAR2(20) CONSTRAINT chk_voucher_discount_type CHECK (discount_type IN ('PERCENTAGE','FIXED')),
    discount_value   NUMBER(10,2) NOT NULL,
    min_order_amount NUMBER(10,2) DEFAULT 0,
    start_date       DATE NOT NULL,
    end_date         DATE NOT NULL,
    usage_limit      NUMBER(10) DEFAULT 1,
    used_count       NUMBER(10) DEFAULT 0,
    status           VARCHAR2(20) DEFAULT 'ACTIVE'
        CONSTRAINT chk_voucher_status CHECK (status IN ('ACTIVE','INACTIVE','EXPIRED')),
    CONSTRAINT chk_voucher_dates CHECK (end_date > start_date),
    CONSTRAINT chk_voucher_usage CHECK (used_count <= usage_limit)
);

CREATE TABLE PICKUP_SLOT (
    slot_id      VARCHAR2(10) PRIMARY KEY,
    allowed_day  VARCHAR2(10) NOT NULL
        CONSTRAINT chk_pickup_allowed_day CHECK (allowed_day IN ('WEDNESDAY','THURSDAY','FRIDAY')),
    start_hour   NUMBER(2) NOT NULL
        CONSTRAINT chk_pickup_start_hour CHECK (start_hour IN (10,13,16)),
    end_hour     NUMBER(2) NOT NULL
        CONSTRAINT chk_pickup_end_hour CHECK (end_hour IN (13,16,19)),
    max_capacity NUMBER(5) DEFAULT 20 NOT NULL,
    CONSTRAINT chk_pickup_capacity CHECK (max_capacity > 0),
    CONSTRAINT chk_pickup_time CHECK (
        (start_hour = 10 AND end_hour = 13) OR
        (start_hour = 13 AND end_hour = 16) OR
        (start_hour = 16 AND end_hour = 19)
    )
);

CREATE TABLE ORDERS (
    order_id         VARCHAR2(10) PRIMARY KEY,
    customer_id      VARCHAR2(10) NOT NULL REFERENCES CUSTOMER(user_id),
    slot_id          VARCHAR2(10) NOT NULL REFERENCES PICKUP_SLOT(slot_id),
    voucher_id       VARCHAR2(10) NULL REFERENCES VOUCHER(voucher_id),
    pickup_date      DATE NOT NULL,
    order_date       DATE DEFAULT SYSDATE,
    discount_applied NUMBER(10,2) DEFAULT 0,
    total_amount     NUMBER(10,2) NOT NULL,
    order_status VARCHAR2(20) DEFAULT 'CONFIRMED'
        CONSTRAINT chk_order_status CHECK (order_status IN ('CONFIRMED','READY','COLLECTED','CANCELLED')),
    CONSTRAINT chk_order_total CHECK (total_amount >= 0),
    CONSTRAINT chk_order_discount CHECK (discount_applied >= 0),
    CONSTRAINT chk_order_pickup_date CHECK (TRUNC(pickup_date) >= TRUNC(order_date) + 1)
);

CREATE TABLE ORDER_ITEM (
    order_id      VARCHAR2(10) NOT NULL REFERENCES ORDERS(order_id),
    product_id    VARCHAR2(10) NOT NULL REFERENCES PRODUCT(product_id),
    trader_id     VARCHAR2(10) NOT NULL REFERENCES TRADER(user_id),
    quantity      NUMBER(10) NOT NULL CONSTRAINT chk_order_item_qty CHECK (quantity > 0),
    locked_price  NUMBER(10,2) NOT NULL CONSTRAINT chk_order_item_price CHECK (locked_price >= 0),

    item_status   VARCHAR2(20) DEFAULT 'PENDING' NOT NULL
        CONSTRAINT chk_order_item_status CHECK (
            item_status IN ('PENDING','READY','COLLECTED','CANCELLED')
        ),

    PRIMARY KEY (order_id, product_id)
);

CREATE TABLE PAYMENT (
    payment_id     VARCHAR2(10) PRIMARY KEY,
    order_id       VARCHAR2(10) NOT NULL UNIQUE REFERENCES ORDERS(order_id),
    customer_id    VARCHAR2(10) NOT NULL REFERENCES CUSTOMER(user_id),
    amount_paid    NUMBER(10,2) NOT NULL,
    payment_method VARCHAR2(50) DEFAULT 'PAYPAL' NOT NULL
        CONSTRAINT chk_payment_method CHECK (payment_method = 'PAYPAL'),
    payment_status VARCHAR2(20) DEFAULT 'COMPLETED'
        CONSTRAINT chk_payment_status CHECK (payment_status IN ('COMPLETED','FAILED','REFUNDED')),
    payment_date   DATE DEFAULT SYSDATE,
    CONSTRAINT chk_payment_amount CHECK (amount_paid >= 0)
);


CREATE TABLE TRADER_PAYOUT (
    payout_id       VARCHAR2(20) PRIMARY KEY,
    trader_id       VARCHAR2(10) NOT NULL REFERENCES TRADER(user_id),
    period_start    DATE NOT NULL,
    period_end      DATE NOT NULL,
    item_rows       NUMBER(10) DEFAULT 0 NOT NULL,
    item_units      NUMBER(10) DEFAULT 0 NOT NULL,
    gross_amount    NUMBER(10,2) DEFAULT 0 NOT NULL,
    platform_fee    NUMBER(10,2) DEFAULT 0 NOT NULL,
    payout_amount   NUMBER(10,2) DEFAULT 0 NOT NULL,
    paid_date       DATE DEFAULT SYSDATE NOT NULL,
    paid_by         VARCHAR2(10) NULL REFERENCES ADMIN(user_id),
    note            VARCHAR2(500),
    CONSTRAINT chk_trader_payout_amounts CHECK (gross_amount >= 0 AND platform_fee >= 0 AND payout_amount >= 0),
    CONSTRAINT chk_trader_payout_counts CHECK (item_rows >= 0 AND item_units >= 0),
    CONSTRAINT chk_trader_payout_dates CHECK (period_end >= period_start)
);

CREATE TABLE TRADER_PAYOUT_ITEM (
    payout_item_id  VARCHAR2(20) PRIMARY KEY,
    payout_id       VARCHAR2(20) NOT NULL REFERENCES TRADER_PAYOUT(payout_id) ON DELETE CASCADE,
    order_id        VARCHAR2(10) NOT NULL REFERENCES ORDERS(order_id),
    product_id      VARCHAR2(10) NOT NULL REFERENCES PRODUCT(product_id),
    trader_id       VARCHAR2(10) NOT NULL REFERENCES TRADER(user_id),
    quantity        NUMBER(10) NOT NULL,
    locked_price    NUMBER(10,2) NOT NULL,
    line_total      NUMBER(10,2) NOT NULL,
    gross_amount    NUMBER(10,2) DEFAULT 0 NOT NULL,
    platform_fee    NUMBER(10,2) DEFAULT 0 NOT NULL,
    payout_amount   NUMBER(10,2) DEFAULT 0 NOT NULL,
    CONSTRAINT uq_trader_payout_item UNIQUE (order_id, product_id, trader_id),
    CONSTRAINT chk_trader_payout_item_amounts CHECK (quantity > 0 AND locked_price >= 0 AND line_total >= 0 AND gross_amount >= 0 AND platform_fee >= 0 AND payout_amount >= 0)
);

CREATE TABLE CART (
    cart_id      VARCHAR2(10) PRIMARY KEY,
    customer_id  VARCHAR2(10) NOT NULL UNIQUE REFERENCES CUSTOMER(user_id),
    created_time TIMESTAMP DEFAULT SYSTIMESTAMP
);

CREATE TABLE CART_ITEM (
    cart_id      VARCHAR2(10) NOT NULL REFERENCES CART(cart_id),
    product_id   VARCHAR2(10) NOT NULL REFERENCES PRODUCT(product_id),
    quantity     NUMBER(10) NOT NULL CONSTRAINT chk_cart_item_qty CHECK (quantity > 0),
    PRIMARY KEY (cart_id, product_id)
);

CREATE TABLE WISHLIST (
    wishlist_id  VARCHAR2(10) PRIMARY KEY,
    customer_id  VARCHAR2(10) NOT NULL REFERENCES CUSTOMER(user_id),
    created_date DATE DEFAULT SYSDATE
);

CREATE TABLE WISHLIST_ITEM (
    wishlist_id  VARCHAR2(10) NOT NULL REFERENCES WISHLIST(wishlist_id),
    product_id   VARCHAR2(10) NOT NULL REFERENCES PRODUCT(product_id),
    PRIMARY KEY (wishlist_id, product_id)
);

CREATE TABLE REVIEW (
    review_id       VARCHAR2(10) PRIMARY KEY,
    product_id      VARCHAR2(10) NOT NULL REFERENCES PRODUCT(product_id),
    customer_id     VARCHAR2(10) NOT NULL REFERENCES CUSTOMER(user_id),
    rating          NUMBER(1) NOT NULL
        CONSTRAINT chk_review_rating CHECK (rating BETWEEN 1 AND 5),
    review_text     CLOB,
    approval_status VARCHAR2(3) DEFAULT 'YES' NOT NULL
        CONSTRAINT chk_review_approval_status CHECK (approval_status IN ('YES','NO')),
    date_posted     DATE DEFAULT SYSDATE,
    reported_by     VARCHAR2(10) NULL REFERENCES TRADER(user_id),
    report_reason   VARCHAR2(500),
    reported_date   DATE
);

COMMIT;