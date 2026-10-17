-- ------------------------------------------------------------
-- Delovni cas: odprto 24 ur, vse dni v tednu
--
-- Tatjana sprejme klic ob vsaki uri tudi brez tega SQL-a: ai/call-guard.php
-- delovnega casa ne preverja. Ta tabela doloca troje drugega:
--   1. kaj Tatjana POVE, ko jo kdo vprasa po delovnem casu;
--   2. ali sme prevezati na sodelavca (ai/agent-config.php posilja
--      transfer_phone samo, kadar je open_now resnicen);
--   3. katere termine ponudi (DirectMySQLAdapter::findFreeSlots).
--
-- Posledica, ki jo je treba poznati: po tem SQL-u je podjetje odprto tudi
-- ponoci, zato bo Tatjana ob treh zjutraj poskusila prevezati na TRANSFER_PHONE
-- in ponujala termine sredi noci. Ce to ni zeleno, nastavi namesto 00:00-23:59
-- pravi cas, ko nekdo res dvigne telefon.
--
-- Zagon: phpMyAdmin, baza kreati02_wp_iuikg, zavihek SQL.
-- ------------------------------------------------------------

SET NAMES utf8mb4;

-- day_of_week po ISO-8601: 1 = ponedeljek ... 7 = nedelja.
-- INSERT ... ON DUPLICATE KEY UPDATE zato, da deluje tudi, ce katera vrstica
-- v tabeli manjka; navaden UPDATE bi jo tiho preskocil.
INSERT INTO ai_business_hours (day_of_week, opens_at, closes_at, closed) VALUES
(1, '00:00:00', '23:59:59', 0),
(2, '00:00:00', '23:59:59', 0),
(3, '00:00:00', '23:59:59', 0),
(4, '00:00:00', '23:59:59', 0),
(5, '00:00:00', '23:59:59', 0),
(6, '00:00:00', '23:59:59', 0),
(7, '00:00:00', '23:59:59', 0)
ON DUPLICATE KEY UPDATE
  opens_at  = VALUES(opens_at),
  closes_at = VALUES(closes_at),
  closed    = VALUES(closed);

-- Preveri izid:
-- SELECT * FROM ai_business_hours ORDER BY day_of_week;
