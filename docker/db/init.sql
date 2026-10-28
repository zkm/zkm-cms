-- zkm: the CMS. zkm_tests: PHPUnit. wp_import: a WordPress database loaded from a backup for `php spark wp:import`.
CREATE DATABASE IF NOT EXISTS zkm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS zkm_tests CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS wp_import CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL ON zkm.* TO 'zkm'@'%';
GRANT ALL ON wp_import.* TO 'zkm'@'%';
GRANT ALL ON zkm_tests.* TO 'zkm'@'%';
