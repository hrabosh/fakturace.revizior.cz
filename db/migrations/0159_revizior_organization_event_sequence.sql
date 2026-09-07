-- ReviziOR: sekvence událostí na vazbě organizace.
--
-- Outbox už umí jiné agregáty než doklad (`invoice_link_id` je NULL-able,
-- `aggregate_type` volný). Chyběl jen monotónní čítač pro organizaci: bez něj
-- by ReviziOR nepoznal pořadí událostí o onboardingu a starší stav by mohl
-- přepsat novější.

SET NAMES utf8mb4;

ALTER TABLE revizior_organization_links
  ADD COLUMN IF NOT EXISTS event_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Monotónní čítač událostí o organizaci pro outbox (R5).';
