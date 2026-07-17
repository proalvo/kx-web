-- =====================================================================
-- KX-Web — Public results website for Kayak Cross competitions
-- MariaDB >= 10.5, utf8mb4
-- Companion to KX-Results (local kx-server). Data is pushed here via
-- the KX-Web Sync API and stored denormalized so it survives deletion
-- of the local kx-server.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Schema migrations bookkeeping
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_migration (
  version      INT UNSIGNED NOT NULL PRIMARY KEY,
  applied_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Multi-tenancy: organization -> user (membership with role)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS organization (
  org_id         CHAR(36)     NOT NULL PRIMARY KEY,        -- UUID
  name           VARCHAR(200) NOT NULL,
  country        CHAR(3)      NOT NULL,                    -- ISO 3166-1 alpha-3, e.g. FIN
  contact_email  VARCHAR(254) NOT NULL,
  status         ENUM('pending','active','suspended') NOT NULL DEFAULT 'pending',
  org_key_hash   VARCHAR(255) NOT NULL DEFAULT '',        -- provisioning key (bcrypt), lets kx-server create competitions
  org_key_hint   CHAR(4)      NOT NULL DEFAULT '',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_org_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_user (                      -- "user" is reserved-ish; avoid quoting
  user_id        CHAR(36)     NOT NULL PRIMARY KEY,
  email          VARCHAR(254) NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,                    -- PHP password_hash()
  name           VARCHAR(200) NOT NULL,
  is_site_admin  TINYINT(1)   NOT NULL DEFAULT 0,
  status         ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at  DATETIME NULL,
  UNIQUE KEY uq_user_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS org_user (
  org_id   CHAR(36) NOT NULL,
  user_id  CHAR(36) NOT NULL,
  role     ENUM('org_admin','editor') NOT NULL DEFAULT 'editor',
  PRIMARY KEY (org_id, user_id),
  KEY idx_org_user_user (user_id),
  CONSTRAINT fk_org_user_org  FOREIGN KEY (org_id)  REFERENCES organization (org_id) ON DELETE CASCADE,
  CONSTRAINT fk_org_user_user FOREIGN KEY (user_id) REFERENCES app_user (user_id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Competition (owned by an organization)
-- api_key is stored hashed; the plaintext key is shown once at creation
-- and entered into kx-server's competition settings.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS competition (
  competition_id  CHAR(36)     NOT NULL PRIMARY KEY,       -- UUID (same as kx-server's)
  org_id          CHAR(36)     NOT NULL,
  slug            VARCHAR(80)  NOT NULL,                   -- URL: /competition/{slug}
  name            VARCHAR(200) NOT NULL,
  country         CHAR(3)      NOT NULL,
  location        VARCHAR(200) NOT NULL DEFAULT '',
  start_date      DATE         NOT NULL,
  end_date        DATE         NOT NULL,
  time_zone       VARCHAR(64)  NOT NULL DEFAULT 'Europe/Helsinki',
  comp_type       ENUM('Domestic','International','Mixed') NOT NULL DEFAULT 'Domestic',
  status          ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  api_key_hash    VARCHAR(255) NOT NULL,
  api_key_hint    CHAR(4)      NOT NULL DEFAULT '',        -- last 4 chars, for admin UI
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_competition_slug (slug),
  KEY idx_competition_org (org_id),
  KEY idx_competition_status_dates (status, start_date),
  CONSTRAINT fk_competition_org FOREIGN KEY (org_id) REFERENCES organization (org_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Event within a competition (e.g. K1M, K1W)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS event (
  event_id        CHAR(36)     NOT NULL PRIMARY KEY,       -- UUID (kx-server's event_id)
  competition_id  CHAR(36)     NOT NULL,
  event_code      VARCHAR(20)  NOT NULL,                   -- e.g. K1M
  event_name      VARCHAR(200) NOT NULL,                   -- e.g. Kayak Cross Men
  gates           TINYINT UNSIGNED NOT NULL DEFAULT 4,     -- 1..8
  sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_event_code (competition_id, event_code),
  KEY idx_event_competition (competition_id),
  CONSTRAINT fk_event_competition FOREIGN KEY (competition_id)
    REFERENCES competition (competition_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Phase of an event (time trial ... final, official result)
-- status drives the public UI:
--   startlist -> show start list
--   live      -> spectators poll JSON endpoint
--   official  -> frozen final results
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS phase (
  phase_id      CHAR(36) NOT NULL PRIMARY KEY,             -- UUID
  event_id      CHAR(36) NOT NULL,
  phase         ENUM('TIME_TRIAL','QUALIFICATION','REPECHAGE','QUARTER_FINAL',
                     'SEMI_FINAL','FINAL','OFFICIAL_RESULT') NOT NULL,
  status        ENUM('hidden','startlist','live','official') NOT NULL DEFAULT 'hidden',
  published_at  DATETIME NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_phase (event_id, phase),
  CONSTRAINT fk_phase_event FOREIGN KEY (event_id) REFERENCES event (event_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Denormalized entry row: everything needed to render a start list or
-- result table without joins to athlete master data.
-- grp/slot_no come from the start list; rank/score/penalties from results.
-- Gate columns: NULL = no data yet, 0 = clean, >0 = penalty seconds/code
-- as computed by kx-server (the site only displays, never re-ranks).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS phase_entry (
  entry_id    CHAR(36)     NOT NULL PRIMARY KEY,           -- UUID
  phase_id    CHAR(36)     NOT NULL,
  grp         TINYINT UNSIGNED NOT NULL DEFAULT 1,         -- "group" is reserved
  slot_no     TINYINT UNSIGNED NOT NULL,
  bib         VARCHAR(20)  NULL,          -- text: colour bibs, leading zeros
  rank        SMALLINT UNSIGNED NULL,
  first_name  VARCHAR(100) NOT NULL,
  last_name   VARCHAR(100) NOT NULL,
  club        VARCHAR(200) NOT NULL DEFAULT '',
  country     CHAR(3)      NOT NULL DEFAULT '',
  icf_id      VARCHAR(40)  NULL,                           -- for future athlete pages
  nf_id       VARCHAR(40)  NULL,
  score       DECIMAL(8,2) NULL,                           -- time or computed score
  dns         TINYINT(1)   NOT NULL DEFAULT 0,
  dnf         TINYINT(1)   NOT NULL DEFAULT 0,
  dsq         TINYINT(1)   NOT NULL DEFAULT 0,
  ral         TINYINT(1)   NOT NULL DEFAULT 0,
  gate1 TINYINT UNSIGNED NULL,
  gate2 TINYINT UNSIGNED NULL,
  gate3 TINYINT UNSIGNED NULL,
  gate4 TINYINT UNSIGNED NULL,
  gate5 TINYINT UNSIGNED NULL,
  gate6 TINYINT UNSIGNED NULL,
  gate7 TINYINT UNSIGNED NULL,
  gate8 TINYINT UNSIGNED NULL,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_entry_position (phase_id, grp, slot_no),
  KEY idx_entry_phase_rank (phase_id, grp, rank),
  CONSTRAINT fk_entry_phase FOREIGN KEY (phase_id) REFERENCES phase (phase_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Sync audit log (also used by kx-server to skip unchanged pushes)
-- Purged by cron, e.g. rows older than 90 days.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sync_log (
  sync_id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,  -- internal only
  competition_id  CHAR(36)     NOT NULL,
  endpoint        VARCHAR(80)  NOT NULL,                   -- e.g. /api/v1/phase
  payload_hash    CHAR(64)     NOT NULL,                   -- SHA-256 of raw body
  ip              VARCHAR(45)  NOT NULL DEFAULT '',        -- IPv4/IPv6
  result          ENUM('ok','error','unchanged') NOT NULL,
  message         VARCHAR(500) NOT NULL DEFAULT '',
  received_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sync_comp_time (competition_id, received_at),
  CONSTRAINT fk_sync_competition FOREIGN KEY (competition_id)
    REFERENCES competition (competition_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Login throttling / API rate limiting (simple fixed-window counters)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rate_limit (
  bucket       VARCHAR(120) NOT NULL,                      -- e.g. 'login:1.2.3.4' or 'api:{competition_id}'
  window_start DATETIME     NOT NULL,
  hits         INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

INSERT IGNORE INTO schema_migration (version) VALUES (1), (2), (3);
