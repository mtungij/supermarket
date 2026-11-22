-- Creates the dedicated test database used by phpunit (see phpunit.xml).
CREATE DATABASE IF NOT EXISTS supermarket_saas_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON supermarket_saas_test.* TO 'supermarket'@'%';
FLUSH PRIVILEGES;
