-- ============================================================
-- Struktura baze za AI asistenta
-- ============================================================
-- Samo tabele, brez podatkov. Katalog storitev se vnese prek
-- skrbniške strani (/admin/storitve.php), ne z urejanjem te datoteke.
--
-- Uporaba:
--   Samodejno: setup.php to zažene sam ob postavitvi.
--   Rocno:     phpMyAdmin -> izberi bazo -> zavihek SQL -> prilepi -> Izvedi.
--
-- Predpona ai_ locuje tabele asistenta od WordPressovih, kadar si delita bazo.
-- Ce v config.php spremenis DB_PREFIX, preimenuj tudi tabele tukaj.
--
-- POZOR: skripta tabele najprej POBRISE. Ce so v bazi ze prava
-- povprasevanja strank, jih prej izvozi.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS ai_orders;
DROP TABLE IF EXISTS ai_products;
DROP TABLE IF EXISTS ai_customers;
DROP TABLE IF EXISTS ai_business_hours;
DROP TABLE IF EXISTS ai_inquiries;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- ai_products — katalog storitev oziroma izdelkov
--
-- price_per_unit sme biti NULL: pri storitvah brez objavljene cene
-- asistent pove "cena po dogovoru" namesto da bi si jo izmislil.
--
-- price_from pomeni izhodiscno ceno ("od 399 EUR"). Brez te oznake bi
-- asistent stranki povedal izhodiscno ceno kot koncno.
-- ------------------------------------------------------------
CREATE TABLE ai_products (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name            VARCHAR(160)   NOT NULL,
  category        VARCHAR(40)    NOT NULL COMMENT 'mora se ujemati s kategorijami v ai/tool-definitions.json',
  unit            VARCHAR(20)    NOT NULL COMMENT 'paket | mesec | projekt | ura',
  price_per_unit  DECIMAL(10,2)  NULL COMMENT 'NULL = cena po dogovoru',
  price_from      TINYINT(1)     NOT NULL DEFAULT 0 COMMENT '1 = izhodiscna cena',
  stock_quantity  DECIMAL(10,2)  NOT NULL DEFAULT 1 COMMENT 'pri storitvah 1 = na voljo',
  description     VARCHAR(400)   NULL,
  active          TINYINT(1)     NOT NULL DEFAULT 1 COMMENT '0 = asistent je ne omenja',
  PRIMARY KEY (id),
  KEY idx_products_category (category),
  KEY idx_products_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- ai_customers — obstojece stranke, kadar naj asistent zna
-- odgovoriti na vprasanje o stanju narocila ali projekta
-- ------------------------------------------------------------
CREATE TABLE ai_customers (
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
-- ai_orders — narocila oziroma projekti
-- Stranka prek asistenta preveri stanje sele, ko pove stevilko
-- IN telefon ali e-posto, s katero je bilo narocilo oddano.
-- ------------------------------------------------------------
CREATE TABLE ai_orders (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id    INT UNSIGNED NOT NULL,
  product_id     INT UNSIGNED NOT NULL,
  quantity       DECIMAL(10,2) NOT NULL DEFAULT 1,
  order_date     DATE          NOT NULL,
  delivery_date  DATE          NULL COMMENT 'NULL dokler ni dogovorjen termin',
  status         ENUM('pending','scheduled','delivered','cancelled') NOT NULL DEFAULT 'pending',
  note           VARCHAR(300)  NULL,
  PRIMARY KEY (id),
  KEY idx_orders_customer (customer_id),
  KEY idx_orders_status (status),
  CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES ai_customers(id),
  CONSTRAINT fk_orders_product  FOREIGN KEY (product_id)  REFERENCES ai_products(id)
) ENGINE=InnoDB AUTO_INCREMENT=10001 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- ai_inquiries — povprasevanja, ki jih zbere asistent
-- Ime, telefon IN e-posta so obvezni: brez e-poste podjetje ne
-- more poslati ponudbe, brez telefona ne more poklicati nazaj.
-- ------------------------------------------------------------
CREATE TABLE ai_inquiries (
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
-- ai_business_hours
-- day_of_week: 1 = ponedeljek ... 7 = nedelja (ISO-8601)
--
-- Privzeto pon-pet 9-17. Popravi na pravi delovni cas stranke,
-- preden asistent zazivi - stranki ga pove kot dejstvo.
-- ------------------------------------------------------------
CREATE TABLE ai_business_hours (
  day_of_week  TINYINT UNSIGNED NOT NULL,
  opens_at     TIME         NULL,
  closes_at    TIME         NULL,
  closed       TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ai_business_hours (day_of_week, opens_at, closes_at, closed) VALUES
(1, '09:00:00', '17:00:00', 0),
(2, '09:00:00', '17:00:00', 0),
(3, '09:00:00', '17:00:00', 0),
(4, '09:00:00', '17:00:00', 0),
(5, '09:00:00', '17:00:00', 0),
(6, NULL,       NULL,       1),
(7, NULL,       NULL,       1);


-- ------------------------------------------------------------
-- ai_knowledge — odgovori na vprasanja, ki niso o ceni
--
-- Katalog pove, kaj podjetje prodaja in po cem. Vprasanja kot "ali delate
-- tudi za drustva", "kaj potrebujete od nas za zacetek" ali "kako poteka
-- prevzem strani" pa doslej niso imela vira, zato je asistent odgovoril
-- splosno ali pa priznal, da ne ve.
--
-- Vsebino ureja podjetje samo v admin/znanje.php. Nic od tega ne sme biti v
-- kodi: pri naslednji stranki so vprasanja druga.
--
-- keywords obstaja, ker vprasanje po telefonu redko vsebuje iste besede kot
-- zapisan odgovor. Stranka vprasa "a je to enkratni znesek", v odgovoru pa
-- pise "placilo v dveh obrokih".
-- ------------------------------------------------------------
CREATE TABLE ai_knowledge (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  question   VARCHAR(300)  NOT NULL COMMENT 'vprasanje, kot ga postavi stranka',
  answer     VARCHAR(1200) NOT NULL COMMENT 'odgovor s podjetjevimi besedami',
  keywords   VARCHAR(300)  NULL COMMENT 'sopomenke, locene z vejico',
  active     TINYINT(1)    NOT NULL DEFAULT 1 COMMENT '0 = asistent tega ne uporabi',
  updated_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_knowledge_active (active),
  FULLTEXT KEY ft_knowledge (question, answer, keywords)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- ai_appointments — dogovorjeni termini
--
-- Prosti termini se NE hranijo v tabeli. Izpeljejo se iz ai_business_hours,
-- dolzine termina in ze zasedenih vrstic tukaj. Tabela prostih terminov bi
-- pomenila, da jo mora nekdo vzdrzevati vnaprej - in prvi teden, ko tega ne
-- stori, asistent ponudi termin, ki ga ni.
--
-- starts_at je v casovnem pasu iz TIMEZONE, ne v UTC. Vse drugo v tej bazi je
-- lokalno; mesanje obojega je vir napak, ki se pokazejo sele ob menjavi ure.
-- ------------------------------------------------------------
CREATE TABLE ai_appointments (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  starts_at    DATETIME     NOT NULL,
  duration_min SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  name         VARCHAR(160) NOT NULL,
  phone        VARCHAR(40)  NOT NULL,
  email        VARCHAR(160) NULL,
  note         VARCHAR(600) NULL COMMENT 'kaj zeli stranka na sestanku',
  status       VARCHAR(16)  NOT NULL DEFAULT 'booked' COMMENT 'booked | cancelled',
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_appointments_start (starts_at, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

