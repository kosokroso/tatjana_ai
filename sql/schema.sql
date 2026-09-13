-- ============================================================
-- Baza za AI asistenta — Kreativni Splet
-- ============================================================
-- Uporaba v phpMyAdmin:
--   1. Levo izberi bazo. Na shared hostingu jo ustvaris v cPanelu, ne tukaj.
--   2. Zavihek "SQL" -> prilepi to datoteko -> Izvedi.
--   3. Skripta je idempotentna: lahko jo pozenes veckrat.
--
-- POZOR: skripta tabele najprej POBRISE. Ce so v bazi ze prava
-- povprasevanja strank, jih prej izvozi.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS business_hours;
DROP TABLE IF EXISTS inquiries;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- products — katalog storitev
--
-- price_per_unit sme biti NULL: pri storitvah, ki nimajo objavljene
-- cene, asistent pove "cena po dogovoru" namesto da bi si jo izmislil.
--
-- price_from pomeni izhodiscno ceno ("od 399 EUR"). Brez te oznake bi
-- asistent stranki povedal izhodiscno ceno kot koncno.
-- ------------------------------------------------------------
CREATE TABLE products (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name            VARCHAR(160)   NOT NULL,
  category        VARCHAR(40)    NOT NULL COMMENT 'spletne-strani | trzenje | oblikovanje | vzdrzevanje',
  unit            VARCHAR(20)    NOT NULL COMMENT 'paket | mesec | projekt | ura',
  price_per_unit  DECIMAL(10,2)  NULL COMMENT 'NULL = cena po dogovoru',
  price_from      TINYINT(1)     NOT NULL DEFAULT 0 COMMENT '1 = izhodiscna cena, koncna je odvisna od obsega',
  stock_quantity  DECIMAL(10,2)  NOT NULL DEFAULT 1 COMMENT 'pri storitvah 1 = na voljo, 0 = trenutno ne sprejemamo',
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
-- orders — projekti strank
-- Ena vrstica = en projekt. Stranka prek asistenta preveri, kako
-- napreduje, sele ko pove stevilko projekta IN svoj telefon/e-posto.
-- ------------------------------------------------------------
CREATE TABLE orders (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id    INT UNSIGNED NOT NULL,
  product_id     INT UNSIGNED NOT NULL,
  quantity       DECIMAL(10,2) NOT NULL DEFAULT 1,
  order_date     DATE          NOT NULL,
  delivery_date  DATE          NULL COMMENT 'predviden zakljucek; NULL dokler ni dogovorjen',
  status         ENUM('pending','scheduled','delivered','cancelled') NOT NULL DEFAULT 'pending',
  note           VARCHAR(300)  NULL,
  PRIMARY KEY (id),
  KEY idx_orders_customer (customer_id),
  KEY idx_orders_status (status),
  CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_orders_product  FOREIGN KEY (product_id)  REFERENCES products(id)
) ENGINE=InnoDB AUTO_INCREMENT=10001 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- inquiries — povprasevanja, ki jih zbere asistent
--
-- ime, telefon IN e-posta so obvezni: brez e-poste podjetje ne more
-- poslati ponudbe, brez telefona pa ne more poklicati nazaj.
-- ------------------------------------------------------------
CREATE TABLE inquiries (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at  DATETIME     NOT NULL,
  name        VARCHAR(120) NOT NULL,
  phone       VARCHAR(40)  NOT NULL,
  email       VARCHAR(160) NOT NULL,
  product     VARCHAR(160) NULL,
  quantity    VARCHAR(60)  NULL,
  note        VARCHAR(500) NULL,
  source      VARCHAR(20)  NOT NULL DEFAULT 'chat' COMMENT 'chat | voice',
  status      ENUM('new','handled','discarded') NOT NULL DEFAULT 'new',
  PRIMARY KEY (id),
  KEY idx_inquiries_status (status),
  KEY idx_inquiries_created (created_at)
) ENGINE=InnoDB AUTO_INCREMENT=500 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- business_hours
-- day_of_week: 1 = ponedeljek ... 7 = nedelja (ISO-8601)
--
-- POZOR: spodnje vrednosti so PRIVZETE, ne preverjene. Popravi jih na
-- pravi delovni cas, preden asistent zazivi na strani — stranki jih
-- bo povedal kot dejstvo.
-- ------------------------------------------------------------
CREATE TABLE business_hours (
  day_of_week  TINYINT UNSIGNED NOT NULL,
  opens_at     TIME         NULL,
  closes_at    TIME         NULL,
  closed       TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- KATALOG STORITEV
-- Cene so povzete po kreativnisplet.si. Kjer cena ni objavljena,
-- je NULL — asistent v tem primeru pove "cena po dogovoru".
-- ============================================================

INSERT INTO products (id, name, category, unit, price_per_unit, price_from, stock_quantity, description) VALUES
(1,  'Enostavna spletna stran',              'spletne-strani', 'paket',   399.00, 1, 1, 'Enostranska predstavitvena stran ali stran z eno podstranjo. Vkljucuje osnovno SEO optimizacijo, kontaktni obrazec, SSL certifikat in prilagoditev mobilnim napravam. Izdelava priblizno dva tedna.'),
(2,  'Napredna spletna stran',               'spletne-strani', 'paket',   899.00, 1, 1, 'Neomejeno stevilo podstrani, veckjezicnost, blog in Google Analytics 4. Primerno za podjetja, ki zelijo redno objavljati vsebine.'),
(3,  'Spletna trgovina Shopify',             'spletne-strani', 'paket',  1100.00, 1, 1, 'Postavitev trgovine Shopify z urejanjem izdelkov, placilnimi sistemi, SEO za spletne trgovine in postavitvijo akcij.'),
(4,  'Vzdrzevanje spletne strani',           'vzdrzevanje',    'mesec',    30.00, 1, 1, 'Od 30 do 120 EUR na mesec glede na obseg. Posodobitve, varnost, varnostne kopije in spremembe vsebine.'),
(5,  'Meta oglasi (Facebook in Instagram)',  'trzenje',        'projekt',   NULL, 0, 1, 'Priprava in vodenje ciljanih oglasnih kampanj na Facebooku in Instagramu. Cena je odvisna od obsega in oglasnega proracuna.'),
(6,  'SEO optimizacija',                     'trzenje',        'projekt',   NULL, 0, 1, 'Tehnicna in vsebinska optimizacija za iskalnike. Obseg dolocimo po pregledu obstojece strani.'),
(7,  'Vodenje druzbenih omrezij',            'trzenje',        'mesec',     NULL, 0, 1, 'Strategija, priprava vsebin in vodenje profilov na TikToku, Facebooku in Instagramu.'),
(8,  'Logotip in celostna graficna podoba',  'oblikovanje',    'projekt',   NULL, 0, 1, 'Oblikovanje logotipa in celostne graficne podobe znamke.'),
(9,  'Fotografiranje',                       'oblikovanje',    'projekt',   NULL, 0, 1, 'Profesionalno fotografiranje izdelkov, prostorov in ekipe za uporabo na spletni strani in druzbenih omrezjih.'),
(10, 'Video produkcija',                     'oblikovanje',    'projekt',   NULL, 0, 1, 'Snemanje in montaza video vsebin za splet in druzbena omrezja.');

-- ============================================================
-- TESTNI PODATKI
-- Spodnje stranke in projekti so IZMISLJENI, namenjeni preizkusu
-- poizvedbe po stanju projekta. Pred zagonom na pravi strani jih
-- pobrisi ali zamenjaj s pravimi:
--     DELETE FROM orders; DELETE FROM customers;
-- ============================================================

INSERT INTO customers (id, name, phone, email, address, created_date) VALUES
(1, 'Testna stranka Ena',  '+38641234567', 'test1@example.com', 'Testni naslov 1', '2026-05-04'),
(2, 'Testna stranka Dve',  '+38631876543', 'test2@example.com', 'Testni naslov 2', '2026-07-18');

INSERT INTO orders (id, customer_id, product_id, quantity, order_date, delivery_date, status, note) VALUES
(10001, 1, 2, 1, '2026-08-10', '2026-09-20', 'scheduled', 'Napredna stran, ceka se gradivo stranke.'),
(10002, 2, 3, 1, '2026-08-28', NULL,         'pending',   'Shopify trgovina, termin se ni dogovorjen.'),
(10003, 1, 4, 1, '2026-06-01', '2026-06-05', 'delivered', 'Mesecno vzdrzevanje, aktivno.');

INSERT INTO business_hours (day_of_week, opens_at, closes_at, closed) VALUES
(1, '09:00:00', '17:00:00', 0),
(2, '09:00:00', '17:00:00', 0),
(3, '09:00:00', '17:00:00', 0),
(4, '09:00:00', '17:00:00', 0),
(5, '09:00:00', '17:00:00', 0),
(6, NULL,       NULL,       1),
(7, NULL,       NULL,       1);
