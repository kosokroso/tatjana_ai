-- ============================================================
-- Kurivo Gorica Voice AI — testna baza
-- ============================================================
-- Uporaba v phpMyAdmin:
--   1. Levo izberi bazo (npr. kurivo_voice_test). Na shared hostingu
--      bazo ustvariš v cPanelu, ne tukaj.
--   2. Zavihek "SQL" -> prilepi to datoteko -> Izvedi.
--   3. Skripta je idempotentna: lahko jo poženeš večkrat.
--
-- Če imaš pravice za ustvarjanje baz (lokalni XAMPP), odkomentiraj:
-- CREATE DATABASE IF NOT EXISTS kurivo_voice_test
--   CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE kurivo_voice_test;
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS business_hours;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- products
-- ------------------------------------------------------------
CREATE TABLE products (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name            VARCHAR(160)   NOT NULL,
  category        VARCHAR(40)    NOT NULL COMMENT 'drva | peleti | briketi',
  unit            VARCHAR(20)    NOT NULL COMMENT 'kubik | vreča | paleta | tona | paket | zaboj',
  price_per_unit  DECIMAL(10,2)  NOT NULL,
  stock_quantity  DECIMAL(10,2)  NOT NULL DEFAULT 0,
  description     VARCHAR(400)   NULL,
  active          TINYINT(1)     NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_products_category (category),
  KEY idx_products_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- customers
-- ------------------------------------------------------------
CREATE TABLE customers (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(160) NOT NULL,
  phone         VARCHAR(40)  NOT NULL COMMENT 'E.164 zapis: +38641234567',
  email         VARCHAR(160) NULL,
  address       VARCHAR(240) NULL,
  created_date  DATE         NOT NULL,
  PRIMARY KEY (id),
  KEY idx_customers_phone (phone),
  KEY idx_customers_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- orders
-- Ena vrstica = eno naročilo z enim izdelkom (MVP).
-- Tool vrne "items" kot polje z enim elementom, da se oblika
-- odgovora ne spremeni, če kasneje dodaš order_items tabelo.
-- ------------------------------------------------------------
CREATE TABLE orders (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id    INT UNSIGNED NOT NULL,
  product_id     INT UNSIGNED NOT NULL,
  quantity       DECIMAL(10,2) NOT NULL,
  order_date     DATE          NOT NULL,
  delivery_date  DATE          NULL COMMENT 'NULL dokler ni dogovorjen termin',
  status         ENUM('pending','scheduled','delivered','cancelled') NOT NULL DEFAULT 'pending',
  note           VARCHAR(300)  NULL,
  PRIMARY KEY (id),
  KEY idx_orders_customer (customer_id),
  KEY idx_orders_status (status),
  CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_orders_product  FOREIGN KEY (product_id)  REFERENCES products(id)
) ENGINE=InnoDB AUTO_INCREMENT=10001 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- business_hours
-- day_of_week: 1 = ponedeljek ... 7 = nedelja (ISO-8601)
-- ------------------------------------------------------------
CREATE TABLE business_hours (
  day_of_week  TINYINT UNSIGNED NOT NULL,
  opens_at     TIME         NULL,
  closes_at    TIME         NULL,
  closed       TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TESTNI PODATKI
-- ============================================================

INSERT INTO products (id, name, category, unit, price_per_unit, stock_quantity, description) VALUES
(1,  'Bukova drva, suha, 25 cm',              'drva',    'kubik',  110.00,  24.00, 'Sušena bukev, vlaga pod 20 %. Primerna za kamine in peči na drva.'),
(2,  'Bukova drva, suha, 33 cm',              'drva',    'kubik',  105.00,  18.00, 'Sušena bukev, standardna dolžina za centralne peči.'),
(3,  'Bukova drva, suha, 50 cm',              'drva',    'kubik',   98.00,  12.00, 'Sušena bukev, polena 50 cm za večje kotle.'),
(4,  'Bukova drva, sveža, 33 cm',             'drva',    'kubik',   78.00,  30.00, 'Sveže razžagana bukev. Priporočeno sušenje 12–18 mesecev.'),
(5,  'Hrastova drva, suha, 25 cm',            'drva',    'kubik',  118.00,   9.00, 'Hrast, visoka kurilna vrednost, dolgo tli.'),
(6,  'Gabrova drva, suha, 33 cm',             'drva',    'kubik',  115.00,   6.00, 'Gaber, najvišja kurilna vrednost med domačimi vrstami.'),
(7,  'Mešana trda drva, suha, 33 cm',         'drva',    'kubik',   92.00,  20.00, 'Mešanica bukve, gabra in hrasta. Najbolj ugodna izbira.'),
(8,  'Mešana drva, 25 cm, paleta 1,8 m3',     'drva',    'paleta', 185.00,   8.00, 'Zložena paleta 1,8 kubika, ovita in pripravljena za viličar.'),
(9,  'Brezova drva, suha, 25 cm',             'drva',    'kubik',  108.00,   5.00, 'Breza, prijeten vonj, primerna za odprte kamine.'),
(10, 'Smrekova drva, suha, 33 cm',            'drva',    'kubik',   68.00,  15.00, 'Mehak les, hitro zagori. Primerno za podkurjanje.'),
(11, 'Bukova drva v vreči, 25 cm, 30 l',      'drva',    'vreča',    6.50, 120.00, 'Vreča 30 litrov, priročno za manjše količine.'),
(12, 'Kaminska drva, bukev, 20 cm, zaboj',    'drva',    'zaboj',  125.00,   7.00, 'Zaboj 1 kubik, enakomerno cepljena polena 20 cm.'),
(13, 'Peleti A1 smreka, vreča 15 kg',         'peleti',  'vreča',    5.90, 340.00, 'Certificirani ENplus A1, 100 % smreka, pepel pod 0,7 %.'),
(14, 'Peleti A1 smreka, paleta 975 kg',       'peleti',  'paleta', 365.00,  14.00, 'Paleta 65 vreč po 15 kg. Najbolj prodajan izdelek.'),
(15, 'Peleti A1 bukev, vreča 15 kg',          'peleti',  'vreča',    6.20, 180.00, 'Bukovi peleti, višja gostota, daljši čas gorenja.'),
(16, 'Peleti A1 bukev, paleta 990 kg',        'peleti',  'paleta', 385.00,   6.00, 'Paleta 66 vreč po 15 kg, bukovi peleti ENplus A1.'),
(17, 'Peleti A2, vreča 15 kg',                'peleti',  'vreča',    5.20,  95.00, 'Razred A2, nekoliko več pepela, ugodnejša cena.'),
(18, 'Peleti razsuti (vpih), 1 tona',         'peleti',  'tona',   340.00,  25.00, 'Dostava s cisterno in vpih v zalogovnik. Minimalno 3 tone.'),
(19, 'Lesni briketi bukev, paket 10 kg',      'briketi', 'paket',    4.80, 210.00, 'Stisnjeno bukovo žaganje brez veziv.'),
(20, 'Lesni briketi, paleta 960 kg',          'briketi', 'paleta', 330.00,   9.00, 'Paleta 96 paketov po 10 kg.');

INSERT INTO customers (id, name, phone, email, address, created_date) VALUES
(1, 'Janez Novak',            '+38641234567', 'janez.novak@gmail.com',   'Prvomajska ulica 12, 5000 Nova Gorica',          '2023-10-14'),
(2, 'Marija Kos',             '+38631876543', 'marija.kos@siol.net',     'Vipavska cesta 45, 5270 Ajdovščina',             '2024-02-03'),
(3, 'Gostilna Pri Lipi d.o.o.','+38653021122', 'info@prilipi.si',        'Trg Edvarda Kardelja 3, 5000 Nova Gorica',       '2022-06-21'),
(4, 'Ana Furlan',             '+38640111222', 'ana.furlan@gmail.com',    'Ulica Gradnikove brigade 7, 5000 Nova Gorica',   '2026-08-29'),
(5, 'Peter Vodopivec',        '+38651998877', 'p.vodopivec@outlook.com', 'Solkanska cesta 18, 5250 Solkan',                '2026-09-02');

-- Današnji datum ob pripravi podatkov: 2026-09-11
INSERT INTO orders (id, customer_id, product_id, quantity, order_date, delivery_date, status, note) VALUES
(10001, 1, 1,  6.00, '2026-06-12', '2026-06-20', 'delivered', 'Dostava na dvorišče, stranka je bila doma.'),
(10002, 1, 14, 2.00, '2026-07-30', '2026-08-05', 'delivered', 'Dve paleti peletov, razloženo pod nadstrešek.'),
(10003, 2, 7,  4.00, '2026-05-18', '2026-05-27', 'delivered', NULL),
(10004, 3, 18, 5.00, '2026-08-01', '2026-08-08', 'delivered', 'Vpih v zalogovnik, gostilna Pri Lipi.'),
(10005, 1, 2,  8.00, '2026-09-02', '2026-09-18', 'scheduled', 'Dostava dopoldne, stranka želi klic pol ure prej.'),
(10006, 2, 13, 40.00,'2026-09-05', '2026-09-15', 'scheduled', '40 vreč peletov, dostava do garaže.'),
(10007, 3, 16, 3.00, '2026-09-07', '2026-09-22', 'scheduled', 'Tri palete bukovih peletov za sezono.'),
(10008, 5, 8,  1.00, '2026-09-08', '2026-09-16', 'scheduled', 'Prva dostava, dovoz je ozek — manjši kamion.'),
(10009, 4, 11, 10.00,'2026-09-09', NULL,         'pending',   'Čaka potrditev termina.'),
(10010, 2, 19, 20.00,'2026-09-10', NULL,         'pending',   'Stranka se še odloča med briketi in peleti.'),
(10011, 5, 5,  3.00, '2026-09-10', NULL,         'pending',   'Hrastova drva — preveriti zalogo pred potrditvijo.'),
(10012, 4, 13, 15.00,'2026-08-20', '2026-08-26', 'cancelled', 'Stranka je preklicala, kupila drugje.');

INSERT INTO business_hours (day_of_week, opens_at, closes_at, closed) VALUES
(1, '08:00:00', '18:00:00', 0),
(2, '08:00:00', '18:00:00', 0),
(3, '08:00:00', '18:00:00', 0),
(4, '08:00:00', '18:00:00', 0),
(5, '08:00:00', '18:00:00', 0),
(6, '08:00:00', '14:00:00', 0),
(7, NULL,       NULL,       1);
