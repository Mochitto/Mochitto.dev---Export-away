BEGIN;

-- Insert data into shops_owner
INSERT INTO shops_owner (code)
SELECT generate_series(1, 3);

-- Insert data into shop
INSERT INTO shop (owned_by, name, code)
SELECT
    1,
    'Shop ' || gs.num AS name,
    gs.num AS code
FROM
    generate_series(1, 2) AS gs(num);

-- Insert data into shop_details
INSERT INTO shop_details (shop_id, email, address)
SELECT
    id,
    'shop' || id || '@example.com',
    'Address ' || id
FROM shop;

-- Insert data into product
INSERT INTO product (price, name)
SELECT
    ROUND((random() * 100)::numeric, 2) AS price,
    'Product ' || gs.num AS name
FROM
    generate_series(1, 500000) AS gs(num);

-- Insert data into customer
INSERT INTO customer (name, surname)
SELECT
    'Customer ' || gs.num AS name,
    'Surname ' || gs.num AS surname
FROM
    generate_series(1, 5) AS gs(num);

-- Insert data into commission
INSERT INTO commission (shop_id, product_id, quantity)
SELECT
    shop.id,
    product.id,
    (FLOOR(random() * 10) + 1)
FROM
    (SELECT id FROM shop) as shop
    CROSS JOIN (SELECT id FROM product) as product
  ;

INSERT INTO commissions_customers (commission_id, customer_id)
SELECT
    commission.id AS commission_id,
    customer.id AS customer_id
FROM
    (SELECT id FROM commission) AS commission,
    (SELECT id FROM customer) AS customer
;

-- Insert data into review
INSERT INTO review (type, content, commissions_customers_id)
SELECT
    CASE WHEN r.num THEN 'RATING' ELSE 'DESCRIPTION' END AS type,
    CASE WHEN r.num THEN '7' ELSE 'DESCRIPTION' END AS content,
    cc.id AS commissions_customers_id
FROM
    commissions_customers cc
    CROSS JOIN (
      select RANDOM() > 0.5 as num
    ) as r;

COMMIT;

