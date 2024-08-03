<?php
// Although optimized, the process is still data-intensive 
ini_set('memory_limit', '500M');

/**
 * Get a database connection.
 *
 * @return PgSql\Connection The PostgreSQL database connection.
 * @throws Exception If unable to connect to the database.
 */
function getDbConnection(): PgSql\Connection
{
  $host = getenv('DB_HOST');
  $dbname = getenv('DB_NAME');
  $user = getenv('DB_USER');
  $password = getenv('DB_PASS');

  $connection_string = "host=$host dbname=$dbname user=$user password=$password";
  $dbConnection = pg_connect($connection_string);

  if (!$dbConnection) {
    throw new Exception("Unable to connect to the database.");
  }

  return $dbConnection;
}

/**
 * Get commissions for the given owner ID.
 *
 * @param int $owner_id The ID of the shops owner.
 * @return Generator<array<string, Generator<string>>> A generator yielding shop name and a generator for its CSV rows.
 * @throws Exception If database connection fails or query errors occur.
 */
function getCommissions(int $owner_id): Generator
{
  $dbConnection = getDbConnection();

  foreach (getShops($dbConnection, $owner_id) as $shop) {
    $shop_id = $shop['id'];
    $shop_name = $shop['name'];

    yield [
      'shop_name' => $shop_name,
      'commissions' => generateFromattedCsvRows(
        getShopCommissions($dbConnection, $shop_id)
      )
    ];
  }

  pg_close($dbConnection);
}

/**
 * Get all shop owners' ids.
 *
 * @return string[] An array of ids.
 * @throws Exception If query errors occur.
 */
function getShopOwners(): array
{
  $dbConnection = getDbConnection();

  $shops_query = <<<EOF
    SELECT id FROM shops_owner;
    EOF;

  $result = pg_query($dbConnection, $shops_query);

  if (!$result) {
    throw new Exception("Error in query: " . pg_last_error($dbConnection));
  }

  $owner_ids = [];
  while ($row = pg_fetch_assoc($result)) {
    $owner_ids[] = $row["id"];
  }

  pg_close($dbConnection);

  return $owner_ids;
}


/**
 * Get shops for the given owner ID.
 *
 * @param PgSql\Connection $dbConnection The database connection.
 * @param int $owner_id The ID of the owner.
 * @return array{0: string, 1: string} An array of a shop's [id, name].
 * @throws Exception If query errors occur.
 */
function getShops(PgSql\Connection $dbConnection, int $owner_id): array
{
  // Get shop IDs and names for the given owner
  $shops_query = <<<EOF
    SELECT
        shop.id AS id,
        shop.name AS name
    FROM shop
        LEFT JOIN shops_owner own ON shop.owned_by = own.id
    WHERE
        own.id = $1
    EOF;

  $result = pg_query_params($dbConnection, $shops_query, [$owner_id]);

  if (!$result) {
    throw new Exception("Error in query: " . pg_last_error($dbConnection));
  }

  $shops = [];
  while ($row = pg_fetch_assoc($result)) {
    $shops[] = $row;
  }

  return $shops;
}

/**
 * Create a temporary view of a shop's commissions.
 *
 * @throws Exception If query errors occur.
 */
function createTempView(PgSql\Connection $dbConnection, int $shop_id): void
{
  $create_view_query = <<<EOF
    CREATE TEMP VIEW temp_shop_commissions AS
    SELECT 
        TO_CHAR(com.created_at, 'DD/MM/YYYY') AS commission_time,
        TRIM(shop.code || ' - ' || shop.name) AS shop_code_name,
        COALESCE(shop_details.email, '') AS shop_email,
        COALESCE(shop_details.address, '') AS shop_address,
        ROUND((com.quantity * prod.price), 2) AS earnings,
        com.quantity AS quantity,
        prod.name AS product_name,
        COALESCE(customers.number, 0) AS customers_number,
        COALESCE(customers.ids, '') AS customers_ids,
        ARRAY_TO_JSON(ARRAY_AGG(
            (
                CASE
                    review.type
                    WHEN 'RATING' THEN  
                        review.content || ' stars out of 10'
                    WHEN 'DESCRIPTION' THEN
                        review.content
                    ELSE 'INVALID REVIEW TYPE'
                END
            )
            ORDER BY
                commissions_customers.customer_id
        )) AS reviews
    FROM commission com
        LEFT JOIN shop ON com.shop_id = shop.id
        LEFT JOIN shop_details ON shop_details.shop_id = shop.id
        LEFT JOIN (
            SELECT 
                COUNT(DISTINCT customer_id) AS number,
                STRING_AGG(DISTINCT customer_id::text, ', ') AS ids,
                commission_id
            FROM 
                commissions_customers
            GROUP BY
                commission_id
        ) customers ON com.id = customers.commission_id
        LEFT JOIN product prod ON com.product_id = prod.id
        LEFT JOIN commissions_customers ON com.id = commissions_customers.commission_id
        LEFT JOIN review ON review.commissions_customers_id = commissions_customers.id
    WHERE
        shop.id = ($shop_id)
    GROUP BY
        com.created_at,
        shop.code,
        shop.name,
        shop_details.email,
        shop_details.address,
        com.quantity,
        prod.name,
        customers.number,
        customers.ids,
        com.quantity * prod.price;
    EOF;

  $result = pg_query($dbConnection, $create_view_query);

  if (!$result) {
    throw new Exception("Error creating temporary view: " . pg_last_error($dbConnection));
  }
}

/**
 * Drops the temporary view.
 *
 * @throws Exception If query errors occur.
 */
function dropTempView(PgSql\Connection $dbConnection): void
{
  $drop_view_query = 'DROP VIEW IF EXISTS temp_shop_commissions;';

  $result = pg_query($dbConnection, $drop_view_query);

  if (!$result) {
    throw new Exception("Error dropping temporary view: " . pg_last_error($dbConnection));
  }
}

/**
 * Get shop commissions for the given shop ID.
 *
 * @return Generator<array> a generator producing commissions in bulk.
 * @throws Exception If query errors occur.
 */
function getShopCommissions(PgSql\Connection $dbConnection, int $shop_id): Generator
{
  createTempView($dbConnection, $shop_id);

  $limit = 100000;
  $offset = 0;

  while (true) {
    $shop_query = <<<EOF
        SELECT 
            commission_time,
            shop_code_name,
            shop_email,
            shop_address,
            earnings,
            quantity,
            product_name,
            customers_number,
            customers_ids,
            reviews
        FROM temp_shop_commissions
        ORDER BY commission_time
        LIMIT $limit OFFSET $offset;
        EOF;

    $result = pg_query($dbConnection, $shop_query);

    if (!$result) {
      throw new Exception("Error in query: " . pg_last_error($dbConnection));
    }

    // Fetch results
    $commissions = [];
    while ($row = pg_fetch_assoc($result)) {
      $commissions[] = $row;
    }

    if (empty($commissions)) {
      break;
    }

    yield $commissions;

    $offset += $limit;
  }

  dropTempView($dbConnection);
}

/**
 * Generates formatted CSV rows from commissions.
 *
 * @param Generator<array> $commissions An array of commissions data.
 * @return Generator<array<string>> A generator yielding CSV rows as string arrays.
 */
function generateFromattedCsvRows(Generator $bulkCommissions): Generator
{
  yield [
    'Commission Time',
    'Shop Code Name',
    'Shop Email',
    'Shop Address',
    'Earnings',
    'Quantity',
    'Product Name',
    'Customers Number',
    'Customers IDs',
    'Reviews'
  ];

  foreach ($bulkCommissions as $commissions) {
    foreach ($commissions as $commission) {
      $reviews_str = implode("\nEOF\n", json_decode($commission['reviews'], true));
      yield [
        $commission['commission_time'],
        $commission['shop_code_name'],
        $commission['shop_email'],
        $commission['shop_address'],
        $commission['earnings'],
        $commission['quantity'],
        $commission['product_name'],
        $commission['customers_number'],
        $commission['customers_ids'],
        $reviews_str
      ];
    }
  }
}
