CREATE TABLE IF NOT EXISTS PREFIX_mobbex_subscription (
    `product_id` INT(11) NOT NULL PRIMARY KEY,
    `uid` TEXT NOT NULL,
    `type` TEXT NOT NULL,
    `state` INT(11) NOT NULL,
    `interval` TEXT NOT NULL,
    `name` TEXT NOT NULL,
    `description` TEXT NOT NULL,
    `total` DECIMAL(18,2) NOT NULL,
    `limit` INT(11) NOT NULL,
    `free_trial` INT(11) NOT NULL,
    `signup_fee` DECIMAL(18,2) NOT NULL
);
/
CREATE TABLE IF NOT EXISTS PREFIX_mobbex_subscriber (
    `order_id` INT(11) NOT NULL PRIMARY KEY,
    `uid` TEXT NOT NULL,
    `subscription_uid` TEXT NOT NULL,
    `state` INT(11) NOT NULL,
    `test` TINYINT NOT NULL,
    `name` TEXT NOT NULL,
    `email` TEXT NOT NULL,
    `phone` TEXT NOT NULL,
    `identification` TEXT NOT NULL,
    `customer_id` INT(11) NOT NULL,
    `source_url` TEXT NOT NULL,
    `control_url` TEXT NOT NULL,
    `register_data` TEXT NOT NULL,
    `start_date` DATETIME NOT NULL,
    `last_execution` DATETIME NOT NULL,
    `next_execution` DATETIME NOT NULL
);
/
CREATE TABLE IF NOT EXISTS PREFIX_mobbex_execution (
    `uid` VARCHAR(255) NOT NULL PRIMARY KEY,
    `order_id` INT(11) NOT NULL,
    `subscription_uid` TEXT NOT NULL,
    `subscriber_uid` TEXT NOT NULL,
    `status` INT(11) NOT NULL,
    `total` DECIMAL(18,2) NOT NULL,
    `date` DATETIME NOT NULL,
    `data` TEXT NOT NULL
);