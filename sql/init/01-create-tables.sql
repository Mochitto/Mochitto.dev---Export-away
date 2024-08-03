CREATE TABLE shops_owner (
  id SERIAL PRIMARY KEY,
  code INT UNIQUE NOT NULL
);

CREATE TABLE shop (
  id SERIAL PRIMARY KEY,
  owned_by INT NOT NULL REFERENCES shops_owner(id), 
  name VARCHAR(100) NOT NULL, 
  code INT UNIQUE NOT NULL
);

CREATE TABLE shop_details (
  id SERIAL PRIMARY KEY,
  shop_id INT UNIQUE NOT NULL REFERENCES shop(id),
  email VARCHAR(100),
  address VARCHAR(100)
);

CREATE TABLE product (
  id SERIAL PRIMARY KEY,
  price DECIMAL(10,2) NOT NULL,
  name VARCHAR(100) NOT NULL
);

CREATE TABLE customer (
  id SERIAL PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  surname VARCHAR(100) NOT NULL
);

CREATE TABLE commission (
  id SERIAL PRIMARY KEY,
  shop_id INT NOT NULL REFERENCES shop(id),
  product_id INT NOT NULL REFERENCES product(id),
  quantity INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE commissions_customers (
  id SERIAL PRIMARY KEY,
  commission_id INT NOT NULL REFERENCES commission(id), 
  customer_id INT NOT NULL REFERENCES customer(id),
  UNIQUE (commission_id, customer_id)
);

CREATE TABLE review (
  id SERIAL PRIMARY KEY,
  type VARCHAR(100) NOT NULL CHECK (type IN ('RATING', 'DESCRIPTION')),
  content TEXT NOT NULL,
  commissions_customers_id INT UNIQUE NOT NULL REFERENCES commissions_customers(id),
  written_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
