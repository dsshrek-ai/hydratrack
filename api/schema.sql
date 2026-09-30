-- HydraTrack -- schema for MyDataWorld
-- Run in phpMyAdmin's SQL tab against the MyDataWorld database, AFTER My Apps
-- Hub's own api/schema.sql (this uses the shared users/sessions/apps/
-- app_access/app_usage_log tables).
--
-- Tables are prefixed hydra_ so they can't collide with another app's. Every
-- user-owned row carries user_id (= the shared users.id login).
--
-- Source of truth vs. cache:
--   hydra_water_logs            authoritative consumption events
--   hydra_daily_summary         one row per user per local day; target is a
--                               snapshot of the goals that applied that day
--   hydra_daily_goal_progress   per-goal snapshot + progress for each day
--   hydra_user_stats            cached streaks/totals, rebuilt from the above
-- Past days' targets are never rewritten when a goal or container changes.

-- ---------- PLATFORM TABLES (shared) -- idempotent for a standalone run ----------

CREATE TABLE IF NOT EXISTS users (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  username       VARCHAR(100) NOT NULL UNIQUE,
  password_hash  VARCHAR(255) NULL,
  display_name   VARCHAR(100) NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  token       CHAR(64) PRIMARY KEY,
  user_id     INT NOT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at  TIMESTAMP NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- HYDRATRACK ----------

-- Per-user app settings (spec 10.1). The login itself is the shared users row.
-- start_date = the first local day HydraTrack tracked for this user; daily
-- summary rows run from here to today.
CREATE TABLE IF NOT EXISTS hydra_user_settings (
  user_id                INT PRIMARY KEY,
  preferred_volume_unit  VARCHAR(10) NOT NULL DEFAULT 'oz',
  time_zone              VARCHAR(100) NOT NULL DEFAULT 'America/Denver',
  start_date             DATE NOT NULL,
  created_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hydra_containers (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT NOT NULL,
  container_name  VARCHAR(100) NOT NULL,
  volume_ounces   DECIMAL(8,2) NOT NULL,
  icon_key        VARCHAR(50) NULL,
  sort_order      INT NOT NULL DEFAULT 0,
  is_quick_log    TINYINT(1) NOT NULL DEFAULT 1,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_hydra_containers_user (user_id, is_active, sort_order),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Container goal: container_id + quantity, target_ounces = quantity x volume.
-- Direct-ounce goal: container_id and quantity NULL, target_ounces as entered.
CREATE TABLE IF NOT EXISTS hydra_goals (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  user_id        INT NOT NULL,
  goal_name      VARCHAR(150) NULL,
  container_id   INT NULL,
  quantity       DECIMAL(8,2) NULL,
  target_ounces  DECIMAL(8,2) NOT NULL,
  start_date     DATE NOT NULL,
  end_date       DATE NULL,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  sort_order     INT NOT NULL DEFAULT 0,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_hydra_goals_user (user_id, is_active, sort_order),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (container_id) REFERENCES hydra_containers(id)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1 = Monday ... 7 = Sunday. "All Days" = seven rows; there is no special value.
CREATE TABLE IF NOT EXISTS hydra_goal_weekdays (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  goal_id         INT NOT NULL,
  weekday_number  TINYINT NOT NULL,
  weekday_name    VARCHAR(10) NOT NULL,
  UNIQUE KEY ux_hydra_goal_weekday (goal_id, weekday_number),
  FOREIGN KEY (goal_id) REFERENCES hydra_goals(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- logged_at is UTC; log_date / weekday_number are the user's local calendar day.
-- entry_type: QuickLog | Manual (room for future types).
CREATE TABLE IF NOT EXISTS hydra_water_logs (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT NOT NULL,
  logged_at       DATETIME NOT NULL,
  log_date        DATE NOT NULL,
  weekday_number  TINYINT NOT NULL,
  container_id    INT NULL,
  goal_id         INT NULL,
  ounces          DECIMAL(8,2) NOT NULL,
  entry_type      VARCHAR(20) NOT NULL,
  notes           VARCHAR(500) NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_hydra_logs_user_date (user_id, log_date),
  KEY ix_hydra_logs_user_at (user_id, logged_at),
  KEY ix_hydra_logs_container (container_id),
  KEY ix_hydra_logs_goal (goal_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (container_id) REFERENCES hydra_containers(id),
  FOREIGN KEY (goal_id) REFERENCES hydra_goals(id)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- goal_met is only ever true on a day that had a target (target_ounces > 0).
CREATE TABLE IF NOT EXISTS hydra_daily_summary (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  user_id           INT NOT NULL,
  summary_date      DATE NOT NULL,
  weekday_number    TINYINT NOT NULL,
  target_ounces     DECIMAL(8,2) NOT NULL DEFAULT 0,
  consumed_ounces   DECIMAL(8,2) NOT NULL DEFAULT 0,
  percent_complete  DECIMAL(7,2) NOT NULL DEFAULT 0,
  goal_met          TINYINT(1) NOT NULL DEFAULT 0,
  entry_made        TINYINT(1) NOT NULL DEFAULT 0,
  first_log_at      DATETIME NULL,
  last_log_at       DATETIME NULL,
  updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY ux_hydra_summary_user_date (user_id, summary_date),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- container_id is a snapshot of the goal's container on that day (NULL for a
-- direct-ounce goal), so editing a goal later doesn't rewrite old progress.
CREATE TABLE IF NOT EXISTS hydra_daily_goal_progress (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  user_id             INT NOT NULL,
  progress_date       DATE NOT NULL,
  weekday_number      TINYINT NOT NULL,
  goal_id             INT NOT NULL,
  container_id        INT NULL,
  sort_order          INT NOT NULL DEFAULT 0,
  target_quantity     DECIMAL(8,2) NULL,
  completed_quantity  DECIMAL(8,2) NOT NULL DEFAULT 0,
  target_ounces       DECIMAL(8,2) NOT NULL,
  logged_ounces       DECIMAL(8,2) NOT NULL DEFAULT 0,
  goal_completed      TINYINT(1) NOT NULL DEFAULT 0,
  updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY ux_hydra_progress (user_id, progress_date, goal_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (goal_id) REFERENCES hydra_goals(id)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hydra_user_stats (
  user_id                 INT PRIMARY KEY,
  current_logging_streak  INT NOT NULL DEFAULT 0,
  longest_logging_streak  INT NOT NULL DEFAULT 0,
  current_goal_streak     INT NOT NULL DEFAULT 0,
  longest_goal_streak     INT NOT NULL DEFAULT 0,
  total_ounces_logged     DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_days_logged       INT NOT NULL DEFAULT 0,
  total_goals_met         INT NOT NULL DEFAULT 0,
  last_calculated_at      DATETIME NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- BOOTSTRAP (run once, after you've signed up through My Apps Hub):
--
-- 1) Register the app with the Hub -- run the "NEW APP: HydraTrack" block
--    appended to my-apps-hub/api/schema.sql.
--
-- 2) Grant yourself the app (or use the Hub's admin.html):
--      INSERT INTO app_access (user_id, app_id)
--      SELECT u.id, a.id FROM users u, apps a
--      WHERE u.username = 'you@example.com' AND a.app_key = 'hydratrack';
-- ============================================================
