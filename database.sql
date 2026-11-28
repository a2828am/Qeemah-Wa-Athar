-- ============================================================================
--  Qeemah wa Athar  |  قيمة وأثر
--  Complete MySQL schema — 38 InnoDB tables, utf8mb4, full seed data
--  MySQL 8.0+  /  MariaDB 10.5+
--
--  Test accounts (password for ALL: Password123! )
--    admin@qeemah.test      system administrator
--    ahmed@qeemah.test      individual user (product owner / requester)
--    sara@qeemah.test       individual user
--    store@qeemah.test      local store
--    rep@albir.test         charity representative (جمعية البر)
--    member@albir.test      charity member (limited permissions)
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

CREATE DATABASE IF NOT EXISTS qeemah_wa_athar
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE qeemah_wa_athar;

-- ══════════════════════════════════════════════════════════════════════════
--  SECTION 1 — AUTH, RBAC & SECURITY  (11 tables)
-- ══════════════════════════════════════════════════════════════════════════

-- 1 ────────────────────────────────────────────────────────────── user_types
CREATE TABLE IF NOT EXISTS user_types (
  id           TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code         VARCHAR(32)  NOT NULL,
  name_ar      VARCHAR(80)  NOT NULL,
  description  VARCHAR(255) DEFAULT NULL,
  icon         VARCHAR(40)  NOT NULL DEFAULT 'user',
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2 ──────────────────────────────────────────────────────────────────── users
CREATE TABLE IF NOT EXISTS users (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_type_id      TINYINT UNSIGNED NOT NULL,
  full_name         VARCHAR(120) NOT NULL,
  email             VARCHAR(160) NOT NULL,
  phone             VARCHAR(24)  DEFAULT NULL,
  password_hash     VARCHAR(255) NOT NULL,
  city              VARCHAR(60)  DEFAULT NULL,
  avatar            VARCHAR(255) DEFAULT NULL,
  bio               TEXT         DEFAULT NULL,
  status            ENUM('active','pending','suspended','deleted') NOT NULL DEFAULT 'active',
  email_verified_at DATETIME     DEFAULT NULL,
  phone_verified_at DATETIME     DEFAULT NULL,
  last_login_at     DATETIME     DEFAULT NULL,
  login_count       INT UNSIGNED NOT NULL DEFAULT 0,
  notify_messages   TINYINT(1)   NOT NULL DEFAULT 1,
  notify_requests   TINYINT(1)   NOT NULL DEFAULT 1,
  rating_avg        DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  rating_count      INT UNSIGNED NOT NULL DEFAULT 0,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_type   (user_type_id),
  KEY idx_users_status (status),
  KEY idx_users_city   (city),
  CONSTRAINT fk_users_type FOREIGN KEY (user_type_id)
    REFERENCES user_types (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3 ──────────────────────────────────────────────────────────────────── roles
CREATE TABLE IF NOT EXISTS roles (
  id           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code         VARCHAR(48) NOT NULL,
  name_ar      VARCHAR(80) NOT NULL,
  scope        ENUM('system','association') NOT NULL DEFAULT 'system',
  is_system    TINYINT(1) NOT NULL DEFAULT 0,
  created_at   DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4 ────────────────────────────────────────────────────────────── permissions
CREATE TABLE IF NOT EXISTS permissions (
  id        SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code      VARCHAR(64) NOT NULL,
  name_ar   VARCHAR(120) NOT NULL,
  module    VARCHAR(40) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_code (code),
  KEY idx_permissions_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5 ───────────────────────────────────────────────────────── role_permissions
CREATE TABLE IF NOT EXISTS role_permissions (
  role_id       SMALLINT UNSIGNED NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  KEY idx_rp_permission (permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id)
    REFERENCES roles (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id)
    REFERENCES permissions (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6 ──────────────────────────────────────────────────────────── user_roles
CREATE TABLE IF NOT EXISTS user_roles (
  user_id     INT UNSIGNED      NOT NULL,
  role_id     SMALLINT UNSIGNED NOT NULL,
  granted_by  INT UNSIGNED      DEFAULT NULL,
  granted_at  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, role_id),
  KEY idx_ur_role (role_id),
  CONSTRAINT fk_ur_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_ur_role FOREIGN KEY (role_id)
    REFERENCES roles (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_ur_granted_by FOREIGN KEY (granted_by)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7 ─────────────────────────────────────────────────────── verification_codes
CREATE TABLE IF NOT EXISTS verification_codes (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  purpose    ENUM('email','phone','password_reset','login') NOT NULL,
  code_hash  VARCHAR(255) NOT NULL,
  channel    ENUM('email','sms','in_app') NOT NULL DEFAULT 'email',
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME DEFAULT NULL,
  attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_vc_user_purpose (user_id, purpose),
  KEY idx_vc_expires (expires_at),
  CONSTRAINT fk_vc_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8 ─────────────────────────────────────────────────────────── user_sessions
CREATE TABLE IF NOT EXISTS user_sessions (
  id             CHAR(64)      NOT NULL,
  user_id        INT UNSIGNED  NOT NULL,
  ip_address     VARBINARY(16) DEFAULT NULL,
  user_agent     VARCHAR(255)  DEFAULT NULL,
  csrf_token     CHAR(64)      NOT NULL,
  last_activity  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at     DATETIME      DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_sessions_user (user_id),
  KEY idx_sessions_activity (last_activity),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9 ─────────────────────────────────────────────────────────── login_requests
CREATE TABLE IF NOT EXISTS login_requests (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED DEFAULT NULL,
  identifier   VARCHAR(160) NOT NULL,
  kind         ENUM('password','code','assoc_approval') NOT NULL DEFAULT 'password',
  status       ENUM('pending','approved','denied','expired') NOT NULL DEFAULT 'pending',
  token_hash   CHAR(64) DEFAULT NULL,
  ip_address   VARBINARY(16) DEFAULT NULL,
  user_agent   VARCHAR(255) DEFAULT NULL,
  expires_at   DATETIME DEFAULT NULL,
  resolved_by  INT UNSIGNED DEFAULT NULL,
  resolved_at  DATETIME DEFAULT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_lr_user (user_id),
  KEY idx_lr_status (status),
  CONSTRAINT fk_lr_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_lr_resolved FOREIGN KEY (resolved_by)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10 ────────────────────────────────────────────────────── login_logout_logs
CREATE TABLE IF NOT EXISTS login_logout_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED DEFAULT NULL,
  event       ENUM('login','logout','failed_login','session_expired','locked') NOT NULL,
  session_id  CHAR(64) DEFAULT NULL,
  ip_address  VARBINARY(16) DEFAULT NULL,
  user_agent  VARCHAR(255) DEFAULT NULL,
  detail      VARCHAR(255) DEFAULT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_lll_user (user_id),
  KEY idx_lll_event (event),
  KEY idx_lll_created (created_at),
  CONSTRAINT fk_lll_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11 ──────────────────────────────────────────────────────── user_activities
CREATE TABLE IF NOT EXISTS user_activities (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  action      VARCHAR(64) NOT NULL,
  entity_type VARCHAR(40) DEFAULT NULL,
  entity_id   INT UNSIGNED DEFAULT NULL,
  meta        JSON DEFAULT NULL,
  ip_address  VARBINARY(16) DEFAULT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ua_user (user_id),
  KEY idx_ua_action (action),
  KEY idx_ua_entity (entity_type, entity_id),
  CONSTRAINT fk_ua_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════════════════════════════════════════
--  SECTION 2 — MARKETPLACE & PRODUCTS  (6 tables)
-- ══════════════════════════════════════════════════════════════════════════

-- 12 ────────────────────────────────────────────────────────────── categories
CREATE TABLE IF NOT EXISTS categories (
  id           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_id    SMALLINT UNSIGNED DEFAULT NULL,
  code         VARCHAR(40) NOT NULL,
  name_ar      VARCHAR(80) NOT NULL,
  icon         VARCHAR(40) NOT NULL DEFAULT 'package',
  sort_order   SMALLINT NOT NULL DEFAULT 0,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_code (code),
  KEY idx_categories_parent (parent_id),
  CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id)
    REFERENCES categories (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13 ──────────────────────────────────────────────────────────────── products
CREATE TABLE IF NOT EXISTS products (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_id       INT UNSIGNED NOT NULL,
  category_id    SMALLINT UNSIGNED NOT NULL,
  association_id INT UNSIGNED DEFAULT NULL,
  title          VARCHAR(160) NOT NULL,
  slug           VARCHAR(190) NOT NULL,
  description    TEXT NOT NULL,
  transaction_type ENUM('sell','swap','donate','free') NOT NULL,
  condition_type ENUM('new','like_new','good','fair','used') NOT NULL DEFAULT 'good',
  status         ENUM('draft','available','reserved','completed','archived','removed') NOT NULL DEFAULT 'available',
  price          DECIMAL(12,2) DEFAULT NULL,
  currency       CHAR(3) NOT NULL DEFAULT 'YER',
  swap_wanted    VARCHAR(255) DEFAULT NULL,
  city           VARCHAR(60) NOT NULL,
  address_hint   VARCHAR(255) DEFAULT NULL,
  donation_scope ENUM('general','targeted') DEFAULT NULL,
  target_association_id INT UNSIGNED DEFAULT NULL,
  views_count    INT UNSIGNED NOT NULL DEFAULT 0,
  favorites_count INT UNSIGNED NOT NULL DEFAULT 0,
  requests_count INT UNSIGNED NOT NULL DEFAULT 0,
  published_at   DATETIME DEFAULT NULL,
  completed_at   DATETIME DEFAULT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_slug (slug),
  KEY idx_products_owner (owner_id),
  KEY idx_products_category (category_id),
  KEY idx_products_type (transaction_type),
  KEY idx_products_status (status),
  KEY idx_products_city (city),
  KEY idx_products_created (created_at),
  KEY idx_products_search (title, description(120)),
  KEY idx_products_target_assoc (target_association_id),
  FULLTEXT KEY ft_products (title, description),
  CONSTRAINT fk_products_owner FOREIGN KEY (owner_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_products_category FOREIGN KEY (category_id)
    REFERENCES categories (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_products_assoc FOREIGN KEY (association_id)
    REFERENCES associations (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_products_target_assoc FOREIGN KEY (target_association_id)
    REFERENCES associations (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_products_sell_price CHECK (
    (transaction_type <> 'sell') OR (price IS NOT NULL AND price >= 0)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14 ───────────────────────────────────────────────────────── product_images
CREATE TABLE IF NOT EXISTS product_images (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id  INT UNSIGNED NOT NULL,
  file_path   VARCHAR(255) NOT NULL,
  alt_text    VARCHAR(160) DEFAULT NULL,
  sort_order  TINYINT NOT NULL DEFAULT 0,
  is_primary  TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pi_product (product_id, sort_order),
  CONSTRAINT fk_pi_product FOREIGN KEY (product_id)
    REFERENCES products (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15 ────────────────────────────────────────────────────────────────── offers
CREATE TABLE IF NOT EXISTS offers (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id     INT UNSIGNED NOT NULL,
  user_id        INT UNSIGNED NOT NULL,
  request_id     BIGINT UNSIGNED DEFAULT NULL,
  offer_type     ENUM('price','swap') NOT NULL,
  amount         DECIMAL(12,2) DEFAULT NULL,
  swap_product_id INT UNSIGNED DEFAULT NULL,
  message        VARCHAR(500) DEFAULT NULL,
  status         ENUM('pending','accepted','rejected','withdrawn') NOT NULL DEFAULT 'pending',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_offers_product (product_id),
  KEY idx_offers_user (user_id),
  KEY idx_offers_status (status),
  CONSTRAINT fk_offers_product FOREIGN KEY (product_id)
    REFERENCES products (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_offers_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_offers_request FOREIGN KEY (request_id)
    REFERENCES user_requests (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_offers_swap FOREIGN KEY (swap_product_id)
    REFERENCES products (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16 ───────────────────────────────────────────────────── favorite_products
CREATE TABLE IF NOT EXISTS favorite_products (
  user_id    INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, product_id),
  KEY idx_fav_product (product_id),
  CONSTRAINT fk_fav_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_fav_product FOREIGN KEY (product_id)
    REFERENCES products (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17 ───────────────────────────────────────────────────────── search_history
CREATE TABLE IF NOT EXISTS search_history (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED DEFAULT NULL,
  session_ref CHAR(64) DEFAULT NULL,
  term        VARCHAR(160) DEFAULT NULL,
  category_id SMALLINT UNSIGNED DEFAULT NULL,
  city        VARCHAR(60) DEFAULT NULL,
  transaction_type ENUM('sell','swap','donate','free') DEFAULT NULL,
  results_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sh_user (user_id, created_at),
  KEY idx_sh_term (term),
  KEY idx_sh_category (category_id),
  CONSTRAINT fk_sh_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_sh_category FOREIGN KEY (category_id)
    REFERENCES categories (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════════════════════════════════════════
--  SECTION 3 — REQUESTS & TRANSACTIONS  (2 tables)
-- ══════════════════════════════════════════════════════════════════════════

-- 18 ────────────────────────────────────────────────────────── user_requests
CREATE TABLE IF NOT EXISTS user_requests (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id     INT UNSIGNED NOT NULL,
  requester_id   INT UNSIGNED NOT NULL,
  owner_id       INT UNSIGNED NOT NULL,
  request_type   ENUM('sell','swap','donate','free') NOT NULL,
  association_id INT UNSIGNED DEFAULT NULL,
  -- Lifecycle: awaiting_owner → accepted → negotiating → agreed → delivery_pending
  --            → delivered, plus rejected / cancelled / disputed.
  status         ENUM('awaiting_owner','accepted','negotiating','agreed','delivery_pending',
                      'delivered','rejected','cancelled','disputed')
                 NOT NULL DEFAULT 'awaiting_owner',
  agreed_at      DATETIME DEFAULT NULL,
  delivered_at   DATETIME DEFAULT NULL,
  message        VARCHAR(700) DEFAULT NULL,
  offered_product_id INT UNSIGNED DEFAULT NULL,
  offered_amount DECIMAL(12,2) DEFAULT NULL,
  contact_phone  VARCHAR(24) DEFAULT NULL,
  handled_by     INT UNSIGNED DEFAULT NULL,
  handled_at     DATETIME DEFAULT NULL,
  completed_at   DATETIME DEFAULT NULL,
  cancelled_reason VARCHAR(255) DEFAULT NULL,
  reject_reason  VARCHAR(255) DEFAULT NULL,
  dispute_reason VARCHAR(255) DEFAULT NULL,
  conversation_id BIGINT UNSIGNED DEFAULT NULL,
  agreement_summary VARCHAR(500) DEFAULT NULL,
  agreement_place  VARCHAR(120) DEFAULT NULL,
  agreement_when   VARCHAR(120) DEFAULT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_req_product (product_id),
  KEY idx_req_requester (requester_id),
  KEY idx_req_owner (owner_id),
  KEY idx_req_status (status),
  KEY idx_req_created (created_at),
  KEY idx_req_conversation (conversation_id),
  KEY idx_req_association (association_id),
  CONSTRAINT fk_req_product FOREIGN KEY (product_id)
    REFERENCES products (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_req_requester FOREIGN KEY (requester_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_req_owner FOREIGN KEY (owner_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_req_offered FOREIGN KEY (offered_product_id)
    REFERENCES products (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_req_handler FOREIGN KEY (handled_by)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_req_assoc FOREIGN KEY (association_id)
    REFERENCES associations (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19 ─────────────────────────────────────────────────────── exchange_requests
CREATE TABLE IF NOT EXISTS exchange_requests (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id         BIGINT UNSIGNED NOT NULL,
  offered_product_id INT UNSIGNED NOT NULL,
  wanted_product_id  INT UNSIGNED NOT NULL,
  value_gap          DECIMAL(12,2) DEFAULT NULL,
  owner_accepted     TINYINT(1) NOT NULL DEFAULT 0,
  requester_accepted TINYINT(1) NOT NULL DEFAULT 0,
  meetup_city        VARCHAR(60) DEFAULT NULL,
  meetup_note        VARCHAR(255) DEFAULT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_exchange_request (request_id),
  KEY idx_ex_wanted (wanted_product_id),
  KEY idx_ex_offered (offered_product_id),
  CONSTRAINT fk_ex_request FOREIGN KEY (request_id)
    REFERENCES user_requests (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_ex_offered FOREIGN KEY (offered_product_id)
    REFERENCES products (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_ex_wanted FOREIGN KEY (wanted_product_id)
    REFERENCES products (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════════════════════════════════════════
--  SECTION 4 — CHARITIES, CAMPAIGNS & BENEFICIARIES  (7 tables)
-- ══════════════════════════════════════════════════════════════════════════

-- 20 ─────────────────────────────────────────────────────────── associations
CREATE TABLE IF NOT EXISTS associations (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_user_id    INT UNSIGNED NOT NULL,
  name             VARCHAR(160) NOT NULL,
  slug             VARCHAR(190) NOT NULL,
  license_number   VARCHAR(80) DEFAULT NULL,
  description      TEXT DEFAULT NULL,
  logo_path        VARCHAR(255) DEFAULT NULL,
  city             VARCHAR(60) NOT NULL,
  phone            VARCHAR(24) DEFAULT NULL,
  email            VARCHAR(160) DEFAULT NULL,
  website          VARCHAR(190) DEFAULT NULL,
  needs_summary    VARCHAR(255) DEFAULT NULL,
  status           ENUM('pending','verified','suspended','rejected') NOT NULL DEFAULT 'pending',
  verified_at      DATETIME DEFAULT NULL,
  verified_by      INT UNSIGNED DEFAULT NULL,
  donations_received INT UNSIGNED NOT NULL DEFAULT 0,
  rating_avg       DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_assoc_slug (slug),
  KEY idx_assoc_status (status),
  KEY idx_assoc_city (city),
  CONSTRAINT fk_assoc_owner FOREIGN KEY (owner_user_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_assoc_verifier FOREIGN KEY (verified_by)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 21 ─────────────────────────────────────────────────── association_members
CREATE TABLE IF NOT EXISTS association_members (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  association_id  INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  role            ENUM('representative','manager','coordinator','viewer') NOT NULL DEFAULT 'viewer',
  can_campaigns   TINYINT(1) NOT NULL DEFAULT 0,
  can_donations   TINYINT(1) NOT NULL DEFAULT 0,
  can_beneficiaries TINYINT(1) NOT NULL DEFAULT 0,
  can_members     TINYINT(1) NOT NULL DEFAULT 0,
  status          ENUM('invited','active','suspended','removed') NOT NULL DEFAULT 'active',
  invited_by      INT UNSIGNED DEFAULT NULL,
  joined_at       DATETIME DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_assoc_member (association_id, user_id),
  KEY idx_am_user (user_id),
  CONSTRAINT fk_am_assoc FOREIGN KEY (association_id)
    REFERENCES associations (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_am_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_am_inviter FOREIGN KEY (invited_by)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 21b ─────────────────────────────────── association_join_requests
-- An individual asks to be recognised as representing an association. The
-- association's representative accepts (which creates the association_members
-- row and grants the role) or rejects. Kept separate from association_members
-- so a pending ask never grants any access.
CREATE TABLE IF NOT EXISTS association_join_requests (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  association_id  INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  message         VARCHAR(500) DEFAULT NULL,
  requested_role  ENUM('manager','coordinator','viewer') NOT NULL DEFAULT 'coordinator',
  status          ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  resolved_by     INT UNSIGNED DEFAULT NULL,
  resolved_at     DATETIME DEFAULT NULL,
  decision_note   VARCHAR(255) DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ajr_assoc (association_id, status),
  KEY idx_ajr_user (user_id),
  CONSTRAINT fk_ajr_assoc FOREIGN KEY (association_id)
    REFERENCES associations (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_ajr_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_ajr_resolver FOREIGN KEY (resolved_by)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 22 ────────────────────────────────────────────────────────────── donations
CREATE TABLE IF NOT EXISTS donations (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id       INT UNSIGNED DEFAULT NULL,
  donor_id         INT UNSIGNED NOT NULL,
  association_id   INT UNSIGNED DEFAULT NULL,
  request_id       BIGINT UNSIGNED DEFAULT NULL,
  campaign_id      BIGINT UNSIGNED DEFAULT NULL,
  donation_scope   ENUM('general','targeted') NOT NULL DEFAULT 'general',
  kind             ENUM('in_kind','cash') NOT NULL DEFAULT 'in_kind',
  title            VARCHAR(160) NOT NULL,
  quantity         VARCHAR(80) DEFAULT NULL,
  estimated_value  DECIMAL(12,2) DEFAULT NULL,
  status           ENUM('offered','claimed','received','distributed','cancelled') NOT NULL DEFAULT 'offered',
  claimed_at       DATETIME DEFAULT NULL,
  received_at      DATETIME DEFAULT NULL,
  distributed_at   DATETIME DEFAULT NULL,
  note             VARCHAR(500) DEFAULT NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_don_assoc (association_id),
  KEY idx_don_donor (donor_id),
  KEY idx_don_status (status),
  KEY idx_don_scope (donation_scope),
  KEY idx_don_campaign (campaign_id),
  CONSTRAINT fk_don_product FOREIGN KEY (product_id)
    REFERENCES products (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_don_donor FOREIGN KEY (donor_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_don_assoc FOREIGN KEY (association_id)
    REFERENCES associations (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_don_request FOREIGN KEY (request_id)
    REFERENCES user_requests (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_don_campaign FOREIGN KEY (campaign_id)
    REFERENCES donation_campaigns (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 23 ───────────────────────────────────────────────────── donation_campaigns
CREATE TABLE IF NOT EXISTS donation_campaigns (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  association_id  INT UNSIGNED NOT NULL,
  created_by      INT UNSIGNED NOT NULL,
  title           VARCHAR(160) NOT NULL,
  slug            VARCHAR(190) NOT NULL,
  description     TEXT NOT NULL,
  goal_summary    VARCHAR(255) DEFAULT NULL,
  target_items    INT UNSIGNED NOT NULL DEFAULT 0,
  received_items  INT UNSIGNED NOT NULL DEFAULT 0,
  target_amount   DECIMAL(12,2) DEFAULT NULL,
  status          ENUM('draft','active','paused','completed','cancelled') NOT NULL DEFAULT 'active',
  starts_at       DATE DEFAULT NULL,
  ends_at         DATE DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_campaign_slug (slug),
  KEY idx_campaign_assoc (association_id),
  KEY idx_campaign_status (status),
  CONSTRAINT fk_campaign_assoc FOREIGN KEY (association_id)
    REFERENCES associations (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_campaign_creator FOREIGN KEY (created_by)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 24 ──────────────────────────────────────────────── donation_campaign_items
CREATE TABLE IF NOT EXISTS donation_campaign_items (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id    BIGINT UNSIGNED NOT NULL,
  item_name      VARCHAR(140) NOT NULL,
  category_id    SMALLINT UNSIGNED DEFAULT NULL,
  quantity_needed INT UNSIGNED NOT NULL DEFAULT 1,
  quantity_received INT UNSIGNED NOT NULL DEFAULT 0,
  priority       ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  note           VARCHAR(255) DEFAULT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_dci_campaign (campaign_id),
  KEY idx_dci_category (category_id),
  CONSTRAINT fk_dci_campaign FOREIGN KEY (campaign_id)
    REFERENCES donation_campaigns (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_dci_category FOREIGN KEY (category_id)
    REFERENCES categories (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 25 ────────────────────────────────────────── donation_campaign_participants
CREATE TABLE IF NOT EXISTS donation_campaign_participants (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id  BIGINT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  donation_id  BIGINT UNSIGNED DEFAULT NULL,
  role         ENUM('donor','volunteer') NOT NULL DEFAULT 'donor',
  items_count  INT UNSIGNED NOT NULL DEFAULT 0,
  joined_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dcp_unique (campaign_id, user_id, donation_id),
  KEY idx_dcp_user (user_id),
  CONSTRAINT fk_dcp_campaign FOREIGN KEY (campaign_id)
    REFERENCES donation_campaigns (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_dcp_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_dcp_donation FOREIGN KEY (donation_id)
    REFERENCES donations (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 26 ──────────────────────────────────────────────────── donation_receipts
CREATE TABLE IF NOT EXISTS donation_receipts (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  receipt_no      VARCHAR(40) NOT NULL,
  donation_id     BIGINT UNSIGNED NOT NULL,
  association_id  INT UNSIGNED NOT NULL,
  issued_by       INT UNSIGNED NOT NULL,
  beneficiary_ref VARCHAR(60) DEFAULT NULL,
  items_summary   VARCHAR(255) NOT NULL,
  quantity        VARCHAR(80) DEFAULT NULL,
  issued_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  signature_note  VARCHAR(255) DEFAULT NULL,
  pdf_path        VARCHAR(255) DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_receipt_no (receipt_no),
  KEY idx_receipt_donation (donation_id),
  KEY idx_receipt_assoc (association_id),
  CONSTRAINT fk_receipt_donation FOREIGN KEY (donation_id)
    REFERENCES donations (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_receipt_assoc FOREIGN KEY (association_id)
    REFERENCES associations (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_receipt_issuer FOREIGN KEY (issued_by)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 27 ──────────────────────────────────────────────────────── beneficiaries
CREATE TABLE IF NOT EXISTS beneficiaries (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  association_id  INT UNSIGNED NOT NULL,
  code            VARCHAR(40) NOT NULL,
  full_name       VARCHAR(140) NOT NULL,
  national_id     VARCHAR(40) DEFAULT NULL,
  phone           VARCHAR(24) DEFAULT NULL,
  city            VARCHAR(60) DEFAULT NULL,
  household_size  TINYINT UNSIGNED NOT NULL DEFAULT 1,
  needs_summary   VARCHAR(255) DEFAULT NULL,
  status          ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
  created_by      INT UNSIGNED NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_beneficiary_code (association_id, code),
  KEY idx_benef_assoc (association_id),
  KEY idx_benef_status (status),
  CONSTRAINT fk_benef_assoc FOREIGN KEY (association_id)
    REFERENCES associations (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_benef_creator FOREIGN KEY (created_by)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 28 ───────────────────────────────────────────────────── distribution_logs
CREATE TABLE IF NOT EXISTS distribution_logs (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  association_id  INT UNSIGNED NOT NULL,
  beneficiary_id  BIGINT UNSIGNED NOT NULL,
  donation_id     BIGINT UNSIGNED DEFAULT NULL,
  receipt_id      BIGINT UNSIGNED DEFAULT NULL,
  items_summary   VARCHAR(255) NOT NULL,
  quantity        VARCHAR(80) DEFAULT NULL,
  distributed_on  DATE NOT NULL,
  handled_by      INT UNSIGNED NOT NULL,
  note            VARCHAR(255) DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_dist_assoc (association_id),
  KEY idx_dist_benef (beneficiary_id),
  KEY idx_dist_date (distributed_on),
  CONSTRAINT fk_dist_assoc FOREIGN KEY (association_id)
    REFERENCES associations (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_dist_benef FOREIGN KEY (beneficiary_id)
    REFERENCES beneficiaries (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_dist_donation FOREIGN KEY (donation_id)
    REFERENCES donations (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_dist_receipt FOREIGN KEY (receipt_id)
    REFERENCES donation_receipts (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_dist_handler FOREIGN KEY (handled_by)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════════════════════════════════════════
--  SECTION 5 — MESSAGING & NOTIFICATIONS  (3 tables)
-- ══════════════════════════════════════════════════════════════════════════

-- 29 ─────────────────────────────────────────────────────── conversation_reads
CREATE TABLE IF NOT EXISTS conversation_reads (
  conversation_id BIGINT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  last_read_message_id BIGINT UNSIGNED DEFAULT NULL,
  read_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (conversation_id, user_id),
  KEY idx_cr_user (user_id),
  CONSTRAINT fk_cr_conversation FOREIGN KEY (conversation_id)
    REFERENCES conversations (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_cr_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 30 ────────────────────────────────────────────────────────── conversations
CREATE TABLE IF NOT EXISTS conversations (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id   INT UNSIGNED DEFAULT NULL,
  request_id   BIGINT UNSIGNED DEFAULT NULL,
  donation_id  BIGINT UNSIGNED DEFAULT NULL,
  user_a_id    INT UNSIGNED NOT NULL,
  user_b_id    INT UNSIGNED NOT NULL,
  subject      VARCHAR(160) DEFAULT NULL,
  status       ENUM('open','archived','blocked') NOT NULL DEFAULT 'open',
  last_message_at DATETIME DEFAULT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_conv_pair_product (user_a_id, user_b_id, product_id),
  KEY idx_conv_pair_request (user_a_id, user_b_id, request_id),
  KEY idx_conv_a (user_a_id, last_message_at),
  KEY idx_conv_b (user_b_id, last_message_at),
  KEY idx_conv_product (product_id),
  KEY idx_conv_request (request_id),
  CONSTRAINT fk_conv_product FOREIGN KEY (product_id)
    REFERENCES products (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_conv_request FOREIGN KEY (request_id)
    REFERENCES user_requests (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_conv_donation FOREIGN KEY (donation_id)
    REFERENCES donations (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_conv_a FOREIGN KEY (user_a_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_conv_b FOREIGN KEY (user_b_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 31 ─────────────────────────────────────────────────────────────── messages
CREATE TABLE IF NOT EXISTS messages (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id BIGINT UNSIGNED NOT NULL,
  sender_id       INT UNSIGNED NOT NULL,
  body            TEXT NOT NULL,
  kind            ENUM('text','system','offer','image') NOT NULL DEFAULT 'text',
  read_at         DATETIME DEFAULT NULL,
  delivered_at    DATETIME DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_msg_conv (conversation_id, id),
  KEY idx_msg_sender (sender_id),
  KEY idx_msg_unread (conversation_id, read_at),
  CONSTRAINT fk_msg_conv FOREIGN KEY (conversation_id)
    REFERENCES conversations (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 32 ────────────────────────────────────────────────────────── notifications
-- ─────────────────────────────────────────────── request_status_history
-- Append-only audit of every request state change: who, when, why.
CREATE TABLE IF NOT EXISTS request_status_history (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id   BIGINT UNSIGNED NOT NULL,
  from_status  VARCHAR(32) DEFAULT NULL,
  to_status    VARCHAR(32) NOT NULL,
  actor_id     INT UNSIGNED DEFAULT NULL,
  reason       VARCHAR(255) DEFAULT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rsh_request (request_id, id),
  KEY idx_rsh_status (to_status),
  KEY idx_rsh_actor (actor_id),
  CONSTRAINT fk_rsh_request FOREIGN KEY (request_id)
    REFERENCES user_requests (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_rsh_actor FOREIGN KEY (actor_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────── request_agreements
-- One row per party per request: both confirmations make the deal "agreed".
CREATE TABLE IF NOT EXISTS request_agreements (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id   BIGINT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  role         ENUM('requester','owner','association_rep') NOT NULL,
  summary      VARCHAR(500) DEFAULT NULL,
  place        VARCHAR(120) DEFAULT NULL,
  when_note    VARCHAR(120) DEFAULT NULL,
  confirmed_at DATETIME DEFAULT NULL,
  revoked_at   DATETIME DEFAULT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_agreement_request_user (request_id, user_id),
  KEY idx_agreement_request (request_id),
  CONSTRAINT fk_agreement_request FOREIGN KEY (request_id)
    REFERENCES user_requests (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_agreement_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────── request_deliveries
-- Two-sided hand-over confirmation; a single party can never complete it.
CREATE TABLE IF NOT EXISTS request_deliveries (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id     BIGINT UNSIGNED NOT NULL,
  user_id        INT UNSIGNED NOT NULL,
  role           ENUM('requester','owner','association_rep') NOT NULL,
  confirmed_at   DATETIME DEFAULT NULL,
  note           VARCHAR(255) DEFAULT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_delivery_request_user (request_id, user_id),
  KEY idx_delivery_request (request_id),
  CONSTRAINT fk_delivery_request FOREIGN KEY (request_id)
    REFERENCES user_requests (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_delivery_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────── user_addresses
-- Saved delivery addresses owned by a user (never shown to other users).
CREATE TABLE IF NOT EXISTS user_addresses (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  label       VARCHAR(60) NOT NULL DEFAULT 'العنوان الرئيسي',
  city        VARCHAR(60) NOT NULL,
  district    VARCHAR(120) DEFAULT NULL,
  street      VARCHAR(190) DEFAULT NULL,
  details     VARCHAR(255) DEFAULT NULL,
  phone       VARCHAR(24) DEFAULT NULL,
  is_default  TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_addr_user (user_id, is_default),
  CONSTRAINT fk_addr_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────── assistant_threads
-- Optional chat assistant: only what the user typed. Flagged for retention.
CREATE TABLE IF NOT EXISTS assistant_threads (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED DEFAULT NULL,
  session_key   VARCHAR(64) NOT NULL,
  role          ENUM('user','assistant','system') NOT NULL,
  body          TEXT NOT NULL,
  engine        VARCHAR(24) DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_assist_session (session_key, id),
  KEY idx_assist_user (user_id, created_at),
  CONSTRAINT fk_assist_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  type        VARCHAR(48) NOT NULL,
  title       VARCHAR(160) NOT NULL,
  body        VARCHAR(500) DEFAULT NULL,
  link_url    VARCHAR(255) DEFAULT NULL,
  icon        VARCHAR(40) NOT NULL DEFAULT 'bell',
  is_read     TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at     DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_notif_user (user_id, is_read),
  KEY idx_notif_created (created_at),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════════════════════════════════════════
--  SECTION 6 — TRUST, MODERATION & ADMIN  (6 tables)
-- ══════════════════════════════════════════════════════════════════════════

-- 33 ──────────────────────────────────────────────────────────────── ratings
CREATE TABLE IF NOT EXISTS ratings (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id    BIGINT UNSIGNED DEFAULT NULL,
  rater_id      INT UNSIGNED NOT NULL,
  ratee_id      INT UNSIGNED NOT NULL,
  product_id    INT UNSIGNED DEFAULT NULL,
  score         TINYINT UNSIGNED NOT NULL,
  comment       VARCHAR(500) DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rating_once (request_id, rater_id),
  KEY idx_rating_ratee (ratee_id),
  KEY idx_rating_product (product_id),
  CONSTRAINT fk_rating_request FOREIGN KEY (request_id)
    REFERENCES user_requests (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_rating_rater FOREIGN KEY (rater_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_rating_ratee FOREIGN KEY (ratee_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_rating_product FOREIGN KEY (product_id)
    REFERENCES products (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_rating_score CHECK (score BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 34 ────────────────────────────────────────────────────────────── complaints
CREATE TABLE IF NOT EXISTS complaints (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  complainant_id INT UNSIGNED NOT NULL,
  against_user_id INT UNSIGNED DEFAULT NULL,
  association_id INT UNSIGNED DEFAULT NULL,
  subject       VARCHAR(160) NOT NULL,
  body          TEXT NOT NULL,
  status        ENUM('open','investigating','resolved','dismissed') NOT NULL DEFAULT 'open',
  resolution    VARCHAR(500) DEFAULT NULL,
  handled_by    INT UNSIGNED DEFAULT NULL,
  handled_at    DATETIME DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_compl_status (status),
  KEY idx_compl_against (against_user_id),
  CONSTRAINT fk_compl_complainant FOREIGN KEY (complainant_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_compl_against FOREIGN KEY (against_user_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_compl_assoc FOREIGN KEY (association_id)
    REFERENCES associations (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_compl_handler FOREIGN KEY (handled_by)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 35 ──────────────────────────────────────────────────────────────── reports
CREATE TABLE IF NOT EXISTS reports (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reporter_id   INT UNSIGNED NOT NULL,
  entity_type   ENUM('user','product','association','request','message','campaign') NOT NULL,
  entity_id     INT UNSIGNED NOT NULL,
  reason        ENUM('spam','fraud','inappropriate','counterfeit','harassment','other') NOT NULL,
  details       TEXT DEFAULT NULL,
  status        ENUM('open','reviewing','actioned','dismissed') NOT NULL DEFAULT 'open',
  action_note   VARCHAR(500) DEFAULT NULL,
  reviewed_by   INT UNSIGNED DEFAULT NULL,
  reviewed_at   DATETIME DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_report_entity (entity_type, entity_id),
  KEY idx_report_status (status),
  CONSTRAINT fk_report_reporter FOREIGN KEY (reporter_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_report_reviewer FOREIGN KEY (reviewed_by)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 36 ───────────────────────────────────────────────────────── product_reports
CREATE TABLE IF NOT EXISTS product_reports (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id    INT UNSIGNED NOT NULL,
  reporter_id   INT UNSIGNED NOT NULL,
  report_id     BIGINT UNSIGNED DEFAULT NULL,
  reason_code   VARCHAR(48) NOT NULL,
  note          VARCHAR(500) DEFAULT NULL,
  status        ENUM('open','reviewing','actioned','dismissed') NOT NULL DEFAULT 'open',
  reviewed_by   INT UNSIGNED DEFAULT NULL,
  reviewed_at   DATETIME DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_product_report_once (product_id, reporter_id),
  KEY idx_pr_status (status),
  CONSTRAINT fk_pr_product FOREIGN KEY (product_id)
    REFERENCES products (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_pr_reporter FOREIGN KEY (reporter_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_pr_report FOREIGN KEY (report_id)
    REFERENCES reports (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_pr_reviewer FOREIGN KEY (reviewed_by)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 37 ─────────────────────────────────────────────────────── admin_audit_logs
CREATE TABLE IF NOT EXISTS admin_audit_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id    INT UNSIGNED DEFAULT NULL,
  action      VARCHAR(80) NOT NULL,
  entity_type VARCHAR(40) DEFAULT NULL,
  entity_id   INT UNSIGNED DEFAULT NULL,
  before_json JSON DEFAULT NULL,
  after_json  JSON DEFAULT NULL,
  ip_address  VARBINARY(16) DEFAULT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_admin (admin_id),
  KEY idx_audit_action (action),
  KEY idx_audit_entity (entity_type, entity_id),
  KEY idx_audit_created (created_at),
  CONSTRAINT fk_audit_admin FOREIGN KEY (admin_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 38 ─────────────────────────────────────────────────────── system_settings
CREATE TABLE IF NOT EXISTS system_settings (
  setting_key   VARCHAR(64) NOT NULL,
  setting_value TEXT DEFAULT NULL,
  value_type    ENUM('string','int','bool','json') NOT NULL DEFAULT 'string',
  group_name    VARCHAR(40) NOT NULL DEFAULT 'general',
  label_ar      VARCHAR(120) NOT NULL,
  updated_by    INT UNSIGNED DEFAULT NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key),
  KEY idx_settings_group (group_name),
  CONSTRAINT fk_settings_user FOREIGN KEY (updated_by)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
--  SEED DATA
-- ============================================================================

INSERT INTO user_types (code, name_ar, description, icon) VALUES
('individual','فرد','مستخدم فرد يبيع أو يتبادل أو يتبرّع','user'),
('store','متجر محلي','متجر أو نشاط تجاري صغير','store'),
('association','جمعية خيرية','جمعية أو مؤسسة خيرية موثّقة','landmark'),
('admin','مسؤول النظام','إدارة المنصة والرقابة','shield')
ON DUPLICATE KEY UPDATE code=code;

INSERT INTO roles (code, name_ar, scope, is_system) VALUES
('admin','مسؤول النظام','system',1),
('moderator','مشرف محتوى','system',1),
('user','مستخدم','system',1),
('store','متجر','system',1),
('association_representative','ممثل جمعية','association',1),
('association_manager','مدير جمعية','association',1),
('association_coordinator','منسّق جمعية','association',1),
('association_viewer','مشاهد جمعية','association',1)
ON DUPLICATE KEY UPDATE code=code;

INSERT INTO permissions (code, name_ar, module) VALUES
('product.create','إنشاء منتج','products'),
('product.update_own','تعديل منتجاتي','products'),
('product.delete_own','حذف منتجاتي','products'),
('product.moderate','إشراف على المنتجات','products'),
('request.create','إنشاء طلب','requests'),
('request.handle_own','معالجة الطلبات الواردة','requests'),
('request.moderate','إشراف على الطلبات','requests'),
('favorite.manage','إدارة المفضلة','favorites'),
('message.send','إرسال رسائل','messages'),
('rating.create','إضافة تقييم','ratings'),
('report.create','إبلاغ عن محتوى','moderation'),
('association.manage','إدارة الجمعية','charity'),
('campaign.manage','إدارة الحملات','charity'),
('donation.claim','استلام التبرعات','charity'),
('donation.receipt','إصدار إيصالات','charity'),
('beneficiary.manage','إدارة المستفيدين','charity'),
('member.manage','إدارة الأعضاء','charity'),
('admin.users','إدارة المستخدمين','admin'),
('admin.associations','إدارة الجمعيات','admin'),
('admin.products','إدارة المنتجات','admin'),
('admin.reports','إدارة البلاغات','admin'),
('admin.audit','سجل التدقيق','admin'),
('admin.settings','إعدادات النظام','admin')
ON DUPLICATE KEY UPDATE code=code;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.code='admin'
ON DUPLICATE KEY UPDATE role_id=role_id;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.code='moderator' AND p.code IN
('product.moderate','request.moderate','report.create','admin.reports','admin.products')
ON DUPLICATE KEY UPDATE role_id=role_id;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.code IN ('user','store') AND p.code IN
('product.create','product.update_own','product.delete_own','request.create',
 'request.handle_own','favorite.manage','message.send','rating.create','report.create')
ON DUPLICATE KEY UPDATE role_id=role_id;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.code IN ('association_representative','association_manager') AND p.code IN
('product.create','product.update_own','request.create','request.handle_own','favorite.manage',
 'message.send','rating.create','report.create','association.manage','campaign.manage',
 'donation.claim','donation.receipt','beneficiary.manage','member.manage')
ON DUPLICATE KEY UPDATE role_id=role_id;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.code='association_coordinator' AND p.code IN
('request.handle_own','message.send','donation.claim','beneficiary.manage')
ON DUPLICATE KEY UPDATE role_id=role_id;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.code='association_viewer' AND p.code IN ('message.send')
ON DUPLICATE KEY UPDATE role_id=role_id;

-- ── users (password for all: Password123! ) ──
SET @H = '$2y$10$SfpFsgppWm2YMF8.7.zuJ./VwD1O/MXSFTsYCzLnsSV3x.5cl356S';
INSERT INTO users (id, user_type_id, full_name, email, phone, password_hash, city, bio, status, email_verified_at, rating_avg, rating_count) VALUES
(1, 4, 'مسؤول المنصة',   'admin@qeemah.test',  '770000001', @H, 'صنعاء', 'إدارة منصة قيمة وأثر', 'active', NOW(), 0, 0),
(2, 1, 'أحمد العريفي',   'ahmed@qeemah.test',  '771234567', @H, 'صنعاء', 'أشارك ما لا أحتاجه وأبحث عمّا ينفع', 'active', NOW(), 4.80, 32),
(3, 1, 'سارة الحداد',    'sara@qeemah.test',   '772345678', @H, 'عدن',  'مهتمة بإعادة التدوير والأثاث المستعمل', 'active', NOW(), 4.60, 18),
(4, 2, 'متجر الأخضر',    'store@qeemah.test',  '773456789', @H, 'تعز',  'متجر محلي يبيع المنتجات المستعملة بحالة جيدة', 'active', NOW(), 4.30, 9),
(5, 3, 'جمعية البر الخيرية', 'rep@albir.test',  '774567890', @H, 'صنعاء', 'ممثل جمعية البر الخيرية', 'active', NOW(), 4.90, 41),
(6, 3, 'منسّق جمعية البر', 'member@albir.test', '775678901', @H, 'صنعاء', 'منسّق ميداني في جمعية البر', 'active', NOW(), 4.50, 7),
(7, 3, 'مؤسسة التكافل',   'rep@takaful.test',  '776789012', @H, 'عدن',  'ممثل مؤسسة التكافل الاجتماعي', 'active', NOW(), 4.70, 23),
(8, 1, 'خالد الشامي',    'khaled@qeemah.test', '777890123', @H, 'إب',   'أبيع وأتبادل الأجهزة الإلكترونية', 'active', NOW(), 4.20, 11)
ON DUPLICATE KEY UPDATE id=id;

INSERT INTO user_roles (user_id, role_id, granted_by) VALUES
(1,1,1),(5,5,1),(6,7,1),(7,5,1),(2,3,1),(3,3,1),(4,4,1),(8,3,1)
ON DUPLICATE KEY UPDATE user_id=user_id;

INSERT INTO categories (id, parent_id, code, name_ar, icon, sort_order) VALUES
(1,NULL,'electronics','إلكترونيات','smartphone',1),
(2,NULL,'clothing','ملابس','shirt',2),
(3,NULL,'furniture','أثاث','armchair',3),
(4,NULL,'books','كتب','book-open',4),
(5,NULL,'kids','أطفال','baby',5),
(6,NULL,'home','منزلي','house',6),
(7,NULL,'other','أخرى','package',7),
(8,1,'phones','هواتف','smartphone',1),
(9,1,'computers','حاسوب','laptop',2),
(10,2,'winter','ملابس شتوية','shirt',1)
ON DUPLICATE KEY UPDATE id=id;

INSERT INTO associations (id, owner_user_id, name, slug, license_number, description, city, phone, email, needs_summary, status, verified_at, verified_by, donations_received, rating_avg) VALUES
(1, 5, 'جمعية البر الخيرية', 'albir',  'LIC-1023', 'جمعية خيرية تعمل على دعم الأسر المتعففة وتوفير الاحتياجات الأساسية من ملابس وأثاث وأجهزة.', 'صنعاء', '01-234567', 'info@albir.test', 'ملابس شتوية · حقائب مدرسية · أثاث', 'verified', NOW(), 1, 128, 4.90),
(2, 7, 'مؤسسة التكافل الاجتماعي', 'takaful', 'LIC-2044', 'مؤسسة تنموية تستهدف الأسر الأشد احتياجاً عبر برامج التمكين وتوزيع التبرعات العينية.', 'عدن', '02-345678', 'info@takaful.test', 'بطانيات · مطبوعات · أجهزة للطلاب', 'verified', NOW(), 1, 96, 4.70),
(3, 5, 'مبادرة يد العون', 'yad-al-awn', 'LIC-3312', 'مبادرة تطوعية تجمع الكتب والأدوات المدرسية وتوزّعها على الطلاب.', 'إب', '03-456789', 'info@yadawn.test', 'كتب مدرسية · أدوات مكتبية', 'verified', NOW(), 1, 54, 4.60),
(4, 7, 'جمعية الأمل للأيتام', 'alamal', 'LIC-4471', 'جمعية ترعى الأيتام وتوفر لهم الملابس والحقائب المدرسية.', 'تعز', '04-567890', 'info@alamal.test', 'ملابس أطفال · حقائب · أدوات', 'pending', NULL, NULL, 0, 0.00)
ON DUPLICATE KEY UPDATE id=id;

INSERT INTO association_members (association_id, user_id, role, can_campaigns, can_donations, can_beneficiaries, can_members, status, invited_by, joined_at) VALUES
(1, 5, 'representative', 1,1,1,1, 'active', 1, NOW()),
(1, 6, 'coordinator',    0,1,1,0, 'active', 5, NOW()),
(2, 7, 'representative', 1,1,1,1, 'active', 1, NOW()),
(3, 5, 'representative', 1,1,1,1, 'active', 1, NOW())
ON DUPLICATE KEY UPDATE association_id=association_id;

INSERT INTO products (id, owner_id, category_id, association_id, title, slug, description, transaction_type, condition_type, status, price, swap_wanted, city, views_count, favorites_count, requests_count, published_at, donation_scope, target_association_id) VALUES
(1, 2, 1, NULL, 'هاتف Samsung Galaxy A54 — 128GB', 'samsung-galaxy-a54-128gb', 'هاتف ذكي بحالة جيدة جداً، مستعمل لأربعة أشهر فقط. يعمل بكفاءة عالية، مع العلبة والشاحن الأصلي.', 'sell', 'like_new', 'available', 85000, NULL, 'صنعاء', 342, 3, 4, NOW(), NULL, NULL),
(2, 4, 1, NULL, 'سماعات Sony WH-1000XM4 بلوتوث', 'sony-wh1000xm4', 'سماعات بلوتوث لاسلكية مع خاصية عزل الضوضاء. حالة ممتازة مع الحقيبة الأصلية.', 'swap', 'good', 'available', NULL, 'هاتف أو ساعة ذكية', 'عدن', 512, 5, 3, NOW(), NULL, NULL),
(3, 3, 2, 1, 'جاكيت شتوي رجالي — مقاس L', 'winter-jacket-l', 'جاكيت شتوي رجالي مقاس L، جديد لم يُستخدم، لونه رمادي. تبرّع موجّه لجمعية البر الخيرية.', 'donate', 'new', 'available', NULL, NULL, 'تعز', 198, 2, 2, NOW(), 'targeted', 1),
(4, 2, 3, NULL, 'كنبة ثلاثية المقاعد — قماش رمادي', 'three-seat-sofa-gray', 'كنبة ثلاثية المقاعد بقماش رمادي بحالة جيدة، تصلح لغرفة جلوس عائلية.', 'sell', 'good', 'available', 120000, NULL, 'صنعاء', 275, 1, 1, NOW(), NULL, NULL),
(5, 4, 4, NULL, 'مجموعة كتب مدرسية — الصف الثالث', 'school-books-grade3', 'مجموعة متكاملة من الكتب المدرسية للصف الثالث الابتدائي بحالة ممتازة.', 'free', 'good', 'available', NULL, NULL, 'عدن', 143, 2, 1, NOW(), NULL, NULL),
(6, 8, 1, NULL, 'لابتوب HP EliteBook 840 G5', 'hp-elitebook-840-g5', 'لابتوب احترافي، معالج i5، ذاكرة 8GB، قرص SSD 256GB. بحالة ممتازة.', 'sell', 'good', 'available', 180000, NULL, 'صنعاء', 401, 1, 2, NOW(), NULL, NULL),
(7, 3, 3, NULL, 'طاولة مكتب خشبية — 120 سم', 'wooden-desk-120', 'طاولة مكتب خشبية بلون طبيعي، أبعاد 120×60 سم، بحالة جيدة.', 'swap', 'good', 'available', NULL, 'كرسي مكتب أو رفوف', 'عدن', 167, 1, 1, NOW(), NULL, NULL),
(8, 1, 5, 1, 'ملابس أطفال — مقاسات مختلفة', 'kids-clothes-mixed', 'مجموعة ملابس أطفال نظيفة بمقاسات من 2 إلى 5 سنوات. تبرّع عام معروض على الجمعيات الموثّقة؛ أول جمعية تقبله تحصل عليه.', 'donate', 'good', 'available', NULL, NULL, 'صنعاء', 221, 0, 0, NOW(), 'general', NULL),
(9, 4, 2, NULL, 'حذاء Nike رياضي — مقاس 42', 'nike-shoes-42', 'حذاء رياضي أصلي مقاس 42، استُخدم مرتين فقط.', 'free', 'like_new', 'available', NULL, NULL, 'تعز', 189, 2, 0, NOW(), NULL, NULL)
ON DUPLICATE KEY UPDATE id=id;


-- product photos shipped with the project (real photographs, never icons)
INSERT INTO product_images (product_id, file_path, alt_text, sort_order, is_primary) VALUES
(1, 'storage/uploads/products/p1.jpg', 'هاتف Samsung Galaxy A54 — 128GB', 0, 1),
(2, 'storage/uploads/products/p2.jpg', 'سماعات Sony WH-1000XM4 بلوتوث', 1, 0),
(3, 'storage/uploads/products/p3.jpg', 'جاكيت شتوي رجالي — مقاس L', 2, 0),
(4, 'storage/uploads/products/p4.jpg', 'كنبة ثلاثية المقاعد — قماش رمادي', 3, 0),
(5, 'storage/uploads/products/p5.jpg', 'مجموعة كتب مدرسية — الصف الثالث', 4, 0),
(6, 'storage/uploads/products/p6.jpg', 'لابتوب HP EliteBook 840 G5', 5, 0),
(7, 'storage/uploads/products/p7.jpg', 'طاولة مكتب خشبية — 120 سم', 6, 0),
(8, 'storage/uploads/products/p8.jpg', 'ملابس أطفال — مقاسات مختلفة', 7, 0),
(9, 'storage/uploads/products/p9.jpg', 'حذاء Nike رياضي — مقاس 42', 8, 0)
ON DUPLICATE KEY UPDATE product_id=product_id;

-- donations tied to products (in-kind)
INSERT INTO donations (product_id, donor_id, association_id, donation_scope, kind, title, quantity, status, note, received_at) VALUES
(3, 3, 1, 'targeted', 'in_kind', 'جاكيت شتوي رجالي — مقاس L', 'قطعة واحدة', 'received', 'تبرّع موجّه لجمعية البر', NOW()),
(8, 2, 1, 'targeted', 'in_kind', 'ملابس أطفال — مقاسات مختلفة', 'كيس كامل', 'offered', 'متاح للاستلام', NULL),
(NULL, 2, NULL, 'general', 'in_kind', 'حقيبة مدرسية جديدة', '20 حقيبة', 'offered', 'تبرّع عام مفتوح للجمعيات المؤهلة', NULL),
(NULL, 4, NULL, 'general', 'in_kind', 'بطانيات شتوية', '15 بطانية', 'offered', NULL, NULL)
ON DUPLICATE KEY UPDATE product_id=product_id;

INSERT INTO donation_campaigns (id, association_id, created_by, title, slug, description, goal_summary, target_items, received_items, status, starts_at, ends_at) VALUES
(1, 1, 5, 'شتاء دافئ 2026', 'winter-warm-2026', 'حملة لجمع الملابس الشتوية والبطانيات للأسر المتعففة في صنعاء قبل حلول فصل الشتاء.', 'ملابس شتوية وبطانيات', 200, 64, 'active', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 60 DAY)),
(2, 1, 5, 'حقيبة لكل طالب', 'bag-for-every-student', 'حملة لتوفير الحقائب والقرطاسية لطلاب المدارس في الأحياء الفقيرة.', 'حقائب مدرسية وقرطاسية', 150, 41, 'active', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 45 DAY)),
(3, 2, 7, 'أجهزة للطلاب', 'devices-for-students', 'جمع أجهزة لوحية وحواسيب مستعملة بحالة جيدة لطلاب الجامعات.', 'أجهزة لوحية', 60, 12, 'active', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 90 DAY))
ON DUPLICATE KEY UPDATE id=id;

INSERT INTO donation_campaign_items (campaign_id, item_name, category_id, quantity_needed, quantity_received, priority) VALUES
(1, 'جاكيت شتوي', 2, 80, 26, 'high'),
(1, 'بطانيات', 6, 120, 38, 'urgent'),
(2, 'حقائب مدرسية', 4, 150, 41, 'high'),
(3, 'أجهزة لوحية', 1, 60, 12, 'normal')
ON DUPLICATE KEY UPDATE campaign_id=campaign_id;

INSERT INTO beneficiaries (association_id, code, full_name, phone, city, household_size, needs_summary, created_by, status) VALUES
(1,'B-1001','أسرة أبو بكر','771111111','صنعاء',6,'ملابس شتوية وأغطية','5','active'),
(1,'B-1002','أسرة الحاج سالم','772222222','صنعاء',4,'حقائب مدرسية','5','active'),
(2,'B-2001','أسرة العمري','773333333','عدن',7,'بطانيات وحصائر','7','active')
ON DUPLICATE KEY UPDATE association_id=association_id;

INSERT INTO donation_receipts (receipt_no, donation_id, association_id, issued_by, beneficiary_ref, items_summary, quantity, signature_note) VALUES
('RCP-2026-0001', 1, 1, 5, 'B-1001', 'جاكيت شتوي رجالي', 'قطعة واحدة', 'استلمت الأسرة التبرّع بالكامل')
ON DUPLICATE KEY UPDATE receipt_no=receipt_no;

INSERT INTO distribution_logs (association_id, beneficiary_id, donation_id, receipt_id, items_summary, quantity, distributed_on, handled_by, note) VALUES
(1, 1, 1, 1, 'جاكيت شتوي رجالي', 'قطعة واحدة', CURDATE(), 6, 'تم التوزيع ميدانياً')
ON DUPLICATE KEY UPDATE association_id=association_id;

-- requests covering every lifecycle status
-- Demo requests: one row per lifecycle branch so every screen has real data.
INSERT INTO user_requests (id, product_id, requester_id, owner_id, request_type, status, message,
                           contact_phone, conversation_id, offered_product_id, reject_reason,
                           agreement_summary, agreement_place, agreement_when, created_at) VALUES
(1, 1, 3, 2, 'sell',   'awaiting_owner',   'هل السعر قابل للتفاوض؟',             '772345678', NULL, NULL, NULL, NULL, NULL, NULL, DATE_SUB(NOW(), INTERVAL 5 HOUR)),
(2, 2, 2, 4, 'swap',   'negotiating',      'أقترح مبادلة بساعة ذكية',            '771234567', 2,    9,    NULL, NULL, NULL, NULL, DATE_SUB(NOW(), INTERVAL 26 HOUR)),
(3, 4, 8, 2, 'sell',   'accepted',         'أرغب في شراء الكنبة، جاهز للاستلام',  '777890123', 1,    NULL, NULL, NULL, NULL, NULL, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(4, 5, 2, 4, 'free',   'delivery_pending', 'أحتاج الكتب لأبنائي',                '771234567', NULL, NULL, NULL,
    'استلام 12 كتاباً مدرسياً مجاناً', 'مدرسة الأمل — بوابة الزاوية', 'الخميس 4 عصراً', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(5, 3, 8, 3, 'donate', 'delivered',        'يسعدني استلام الجاكيت لجمعية البر',   '777890123', NULL, NULL, NULL,
    'تسليم جاكيت شتوي مقاس L لجمعية البر', 'مقر جمعية البر', 'الأحد 10 صباحاً', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(6, 6, 2, 8, 'sell',   'rejected',         'هل يمكن التقسيط؟',                   '771234567', NULL, NULL, 'البائع لا يوفّر التقسيط', NULL, NULL, NULL, DATE_SUB(NOW(), INTERVAL 4 DAY)),
(7, 7, 2, 3, 'swap',   'cancelled',        'أظن المقاس غير مناسب',               '771234567', NULL, NULL, NULL, NULL, NULL, NULL, DATE_SUB(NOW(), INTERVAL 8 DAY)),
(8, 9, 2, 4, 'free',   'awaiting_owner',   'أحتاج حذاء رياضياً مقاس 42 لابني',    '771234567', NULL, NULL, NULL, NULL, NULL, NULL, DATE_SUB(NOW(), INTERVAL 9 HOUR)),
(9, 7, 8, 3, 'swap',   'agreed',           'أبادل الطاولة بطاولة أصغر',          '777890123', NULL, 6,    NULL,
    'مبادلة الطاولة بطاولة أصغر مناسبة للمكتب', 'حي الصفا', 'السبت صباحاً', DATE_SUB(NOW(), INTERVAL 30 HOUR))
ON DUPLICATE KEY UPDATE id=id;

INSERT INTO exchange_requests (request_id, offered_product_id, wanted_product_id, value_gap, owner_accepted, requester_accepted, meetup_city, meetup_note) VALUES
(2, 6, 2, 95000, 0, 1, 'عدن', 'مقابلة في وسط المدينة')
ON DUPLICATE KEY UPDATE request_id=request_id;

INSERT INTO offers (product_id, user_id, request_id, offer_type, amount, swap_product_id, message, status) VALUES
(1, 3, 1, 'price', 80000, NULL, 'أعرض 80,000 ريال', 'pending'),
(2, 2, 2, 'swap', NULL, 6, 'مبادلة باللابتوب', 'pending')
ON DUPLICATE KEY UPDATE product_id=product_id;

INSERT INTO favorite_products (user_id, product_id) VALUES (2,1),(2,2),(2,4),(2,7),(3,1),(3,3),(8,6)
ON DUPLICATE KEY UPDATE user_id=user_id;

INSERT INTO search_history (user_id, term, category_id, city, transaction_type, results_count) VALUES
(2,'هاتف',1,'صنعاء','sell',2),(2,'كنبة',3,NULL,'sell',1),(2,'كتب',4,'عدن','free',1),
(3,'جاكيت',2,NULL,'donate',1),(8,'لابتوب',1,'صنعاء','sell',1)
ON DUPLICATE KEY UPDATE user_id=user_id;

-- Only requests that were accepted have a thread: conversations open after approval.
INSERT INTO conversations (id, product_id, request_id, user_a_id, user_b_id, subject, status, last_message_at) VALUES
(1, 4, 3, 2, 8, 'كنبة ثلاثية المقاعد — طلب #3', 'open', NOW()),
(2, 2, 2, 4, 2, 'سماعات Sony WH-1000XM4 — طلب #2', 'open', NOW())
ON DUPLICATE KEY UPDATE id=id;

-- Thread for request #3 (accepted, waiting for the two parties to agree).
INSERT INTO messages (conversation_id, sender_id, body, kind, read_at, delivered_at, created_at) VALUES
(1, 8, 'السلام عليكم، شكراً على قبول الطلب. متى يناسبكم التسليم؟', 'text', NOW(), NOW(), DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(1, 2, 'وعليكم السلام، يناسبني غداً بعد العصر في شارع حدة.', 'text', NOW(), NOW(), DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(1, 8, 'ممتاز، اتفقنا مبدئياً على 115,000 ر.ي.', 'text', NULL, NOW(), DATE_SUB(NOW(), INTERVAL 40 MINUTE)),
-- Thread for request #2 (swap under negotiation).
(2, 2, 'أقترح مبادلة بساعة ذكية مقابل السماعات، ما رأيك؟', 'text', NULL, NOW(), DATE_SUB(NOW(), INTERVAL 30 MINUTE))
ON DUPLICATE KEY UPDATE conversation_id=conversation_id;

INSERT INTO conversation_reads (conversation_id, user_id, last_read_message_id, read_at) VALUES
(1, 8, 2, NOW()),(1, 2, 2, NOW())
ON DUPLICATE KEY UPDATE conversation_id=conversation_id;

-- ── Request lifecycle demo data ────────────────────────────────────────────
-- Every state change is append-only; the UI reads its timeline from here.
INSERT INTO request_status_history (request_id, from_status, to_status, actor_id, reason, created_at) VALUES
(1, NULL,            'awaiting_owner', 3, 'إرسال الطلب',            DATE_SUB(NOW(), INTERVAL 5 HOUR)),
(2, NULL,            'awaiting_owner', 2, 'إرسال الطلب',            DATE_SUB(NOW(), INTERVAL 26 HOUR)),
(2, 'awaiting_owner','accepted',       4, 'قبول الطلب وفتح محادثة', DATE_SUB(NOW(), INTERVAL 25 HOUR)),
(2, 'accepted',      'negotiating',    2, 'بدء الاتفاق داخل المحادثة', DATE_SUB(NOW(), INTERVAL 24 HOUR)),
(3, NULL,            'awaiting_owner', 8, 'إرسال الطلب',            DATE_SUB(NOW(), INTERVAL 3 DAY)),
(3, 'awaiting_owner','accepted',       2, 'قبول الطلب وفتح محادثة', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(4, NULL,            'awaiting_owner', 2, 'إرسال الطلب',            DATE_SUB(NOW(), INTERVAL 2 DAY)),
(4, 'awaiting_owner','accepted',       4, 'قبول الطلب وفتح محادثة', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(4, 'accepted',      'negotiating',    2, 'بدء الاتفاق',            DATE_SUB(NOW(), INTERVAL 47 HOUR)),
(4, 'negotiating',   'agreed',         2, 'تأكيد الطرفين على الاتفاق', DATE_SUB(NOW(), INTERVAL 40 HOUR)),
(4, 'agreed',        'delivery_pending', 4, 'بدء تسليم المنتج',    DATE_SUB(NOW(), INTERVAL 20 HOUR)),
(5, NULL,            'awaiting_owner', 8, 'إرسال الطلب',            DATE_SUB(NOW(), INTERVAL 6 DAY)),
(5, 'awaiting_owner','accepted',       3, 'قبول الطلب وفتح محادثة', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(5, 'accepted',      'negotiating',    8, 'بدء الاتفاق',            DATE_SUB(NOW(), INTERVAL 6 DAY)),
(5, 'negotiating',   'agreed',         3, 'تأكيد الطرفين على الاتفاق', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(5, 'agreed',        'delivery_pending', 3, 'بدء تسليم المنتج',    DATE_SUB(NOW(), INTERVAL 5 DAY)),
(5, 'delivery_pending', 'delivered',   8, 'تأكيد الاستلام من الطرفين', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(6, NULL,            'awaiting_owner', 2, 'إرسال الطلب',            DATE_SUB(NOW(), INTERVAL 4 DAY)),
(6, 'awaiting_owner','rejected',       8, 'البائع لا يوفّر التقسيط', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(7, NULL,            'awaiting_owner', 2, 'إرسال الطلب',            DATE_SUB(NOW(), INTERVAL 8 DAY)),
(7, 'awaiting_owner','cancelled',      2, 'إلغاء الطالب — المقاس غير مناسب', DATE_SUB(NOW(), INTERVAL 7 DAY)),
(8, NULL,            'awaiting_owner', 2, 'إرسال الطلب',            DATE_SUB(NOW(), INTERVAL 9 HOUR)),
(9, NULL,            'awaiting_owner', 8, 'إرسال الطلب',            DATE_SUB(NOW(), INTERVAL 30 HOUR)),
(9, 'awaiting_owner','accepted',       3, 'قبول الطلب وفتح محادثة', DATE_SUB(NOW(), INTERVAL 29 HOUR)),
(9, 'accepted',      'negotiating',    8, 'بدء الاتفاق',            DATE_SUB(NOW(), INTERVAL 28 HOUR)),
(9, 'negotiating',   'agreed',         8, 'تأكيد الطرفين على الاتفاق', DATE_SUB(NOW(), INTERVAL 26 HOUR))
ON DUPLICATE KEY UPDATE request_id=request_id;

INSERT INTO request_agreements (request_id, user_id, role, summary, place, when_note, confirmed_at, created_at) VALUES
(4, 2, 'requester', 'استلام 12 كتاباً مدرسياً مجاناً', 'مدرسة الأمل — بوابة الزاوية', 'الخميس 4 عصراً', DATE_SUB(NOW(), INTERVAL 40 HOUR), DATE_SUB(NOW(), INTERVAL 45 HOUR)),
(4, 4, 'owner',     'تسليم 12 كتاباً مدرسياً مجاناً',  'مدرسة الأمل — بوابة الزاوية', 'الخميس 4 عصراً', DATE_SUB(NOW(), INTERVAL 40 HOUR), DATE_SUB(NOW(), INTERVAL 45 HOUR)),
(5, 8, 'requester', 'استلام جاكيت شتوي مقاس L', 'مقر جمعية البر', 'الأحد 10 صباحاً', DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY)),
(5, 3, 'owner',     'تسليم جاكيت شتوي مقاس L',  'مقر جمعية البر', 'الأحد 10 صباحاً', DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY)),
(9, 8, 'requester', 'مبادلة الطاولة بطاولة أصغر مناسبة للمكتب', 'حي الصفا', 'السبت صباحاً', DATE_SUB(NOW(), INTERVAL 26 HOUR), DATE_SUB(NOW(), INTERVAL 27 HOUR)),
(9, 3, 'owner',     'مبادلة الطاولة بطاولة أصغر مناسبة للمكتب', 'حي الصفا', 'السبت صباحاً', DATE_SUB(NOW(), INTERVAL 26 HOUR), DATE_SUB(NOW(), INTERVAL 27 HOUR))
ON DUPLICATE KEY UPDATE request_id=request_id;

INSERT INTO request_deliveries (request_id, user_id, role, confirmed_at, note) VALUES
(5, 3, 'owner',     DATE_SUB(NOW(), INTERVAL 4 DAY), 'تم التسليم بحالة جيدة'),
(5, 8, 'requester', DATE_SUB(NOW(), INTERVAL 4 DAY), 'استلمت المنتج وهو مطابق')
ON DUPLICATE KEY UPDATE request_id=request_id;

-- Two saved addresses for the demo buyer.
INSERT INTO user_addresses (user_id, label, city, district, street, details, phone, is_default) VALUES
(8, 'المنزل',  'إب', 'حي الصفا', 'شارع المستشفى', 'بجوار مسجد النور — الدور الثاني', '777890123', 1),
(8, 'العمل',   'إب', 'وسط المدينة', 'شارع جمال', NULL, '777890123', 0)
ON DUPLICATE KEY UPDATE user_id=user_id;

INSERT INTO notifications (user_id, type, title, body, link_url, icon, is_read) VALUES
(2,'request_new','طلب جديد على منتجك','سارة الحداد أرسلت طلباً على «هاتف Samsung Galaxy A54»','my_requests.php','tag',0),
(2,'message_new','رسالة جديدة','لديك رسالة جديدة من سارة الحداد','chat.php?c=1','message-circle',0),
(3,'request_accepted','تم قبول طلبك','تم قبول طلبك على «كنبة ثلاثية المقاعد»','my_requests.php','check-circle',1),
(5,'donation_new','تبرّع موجّه لجمعيتكم','وصل تبرّع موجّه: جاكيت شتوي رجالي','charity/donations_manage.php','hand-heart',0)
ON DUPLICATE KEY UPDATE user_id=user_id;

INSERT INTO ratings (request_id, rater_id, ratee_id, product_id, score, comment) VALUES
(5, 3, 8, 3, 5, 'تعامل راقٍ وتسليم في الوقت'),
(5, 8, 3, 3, 4, 'منتج مطابق للوصف')
ON DUPLICATE KEY UPDATE request_id=request_id;

INSERT INTO reports (reporter_id, entity_type, entity_id, reason, details, status) VALUES
(2,'product',6,'spam','السعر غير واقعي','open'),
(3,'user',8,'harassment','رسائل غير لائقة','reviewing')
ON DUPLICATE KEY UPDATE reporter_id=reporter_id;

INSERT INTO product_reports (product_id, reporter_id, report_id, reason_code, note, status) VALUES
(6, 2, 1, 'spam', 'السعر غير واقعي', 'open')
ON DUPLICATE KEY UPDATE product_id=product_id;

INSERT INTO complaints (complainant_id, against_user_id, subject, body, status) VALUES
(3, 8, 'تأخر في التسليم', 'المستخدم لم يستجب لمدة أسبوع بعد قبول الطلب.', 'investigating')
ON DUPLICATE KEY UPDATE complainant_id=complainant_id;

INSERT INTO admin_audit_logs (admin_id, action, entity_type, entity_id, after_json) VALUES
(1,'association.verified','association',1,'{"status":"verified"}'),
(1,'user.role_granted','user',5,'{"role":"association_representative"}')
ON DUPLICATE KEY UPDATE admin_id=admin_id;

INSERT INTO system_settings (setting_key, setting_value, value_type, group_name, label_ar) VALUES
('site_name','قيمة وأثر','string','general','اسم الموقع'),
('site_tagline','كل منتج له قيمة… وكل مشاركة لها أثر','string','general','الشعار النصي'),
('default_city','صنعاء','string','general','المدينة الافتراضية'),
('currency','YER','string','general','العملة'),
('items_per_page','12','int','general','عدد العناصر في الصفحة'),
('allow_registration','1','bool','auth','السماح بالتسجيل'),
('require_email_verification','0','bool','auth','اشتراط توثيق البريد'),
('association_auto_verify','0','bool','auth','توثيق الجمعيات تلقائياً'),
('chat_poll_interval_ms','4000','int','chat','تحديث المحادثة (مللي ثانية)'),
('notif_poll_interval_ms','15000','int','chat','تحديث الإشعارات (مللي ثانية)'),
('max_upload_mb','5','int','uploads','أقصى حجم للصورة'),
('allowed_image_types','["image/jpeg","image/png","image/webp"]','json','uploads','أنواع الصور المسموحة'),
('ai_enabled','1','bool','ai','تفعيل المساعد الذكي'),
('ai_price_min_samples','3','int','ai','أقل عدد عينات لتسعير ذكي'),
('ai_chat_enabled','0','bool','ai','المحادثة الذكية (تحتاج مفتاحاً في البيئة)'),
('ai_retention_days','7','int','ai','مدة الاحتفاظ بأسئلة المساعد (أيام)'),
('ai_last_ok_at','','string','ai','آخر ردّ ناجح من النموذج (يُكتب آلياً)'),
('ai_last_error_at','','string','ai','آخر فشل اتصال بالنموذج (يُكتب آلياً)'),
('ai_last_latency_ms','0','int','ai','زمن آخر ردّ من النموذج (مللي ثانية)')
ON DUPLICATE KEY UPDATE setting_key=setting_key;

INSERT INTO verification_codes (user_id, purpose, code_hash, channel, expires_at) VALUES
(2,'password_reset', SHA2('123456', 256), 'email', DATE_ADD(NOW(), INTERVAL 1 HOUR))
ON DUPLICATE KEY UPDATE user_id=user_id;

INSERT INTO login_logout_logs (user_id, event, ip_address, user_agent, detail) VALUES
(2,'login', INET6_ATON('127.0.0.1'), 'Seed', 'تسجيل دخول تجريبي'),
(5,'login', INET6_ATON('127.0.0.1'), 'Seed', 'تسجيل دخول تجريبي')
ON DUPLICATE KEY UPDATE user_id=user_id;

INSERT INTO user_activities (user_id, action, entity_type, entity_id) VALUES
(2,'product.created','product',1),(2,'request.received','request',1),(3,'request.created','request',1)
ON DUPLICATE KEY UPDATE user_id=user_id;

-- ============================================================================
--  Useful admin view: platform health
-- ============================================================================
CREATE OR REPLACE VIEW v_platform_stats AS
SELECT
  (SELECT COUNT(*) FROM users WHERE status='active')                AS active_users,
  (SELECT COUNT(*) FROM products WHERE status='available')          AS available_products,
  (SELECT COUNT(*) FROM products WHERE status='reserved')           AS reserved_products,
  (SELECT COUNT(*) FROM products WHERE status='completed')          AS completed_products,
  (SELECT COUNT(*) FROM user_requests WHERE status='new')           AS new_requests,
  (SELECT COUNT(*) FROM user_requests WHERE status='completed')     AS completed_requests,
  (SELECT COUNT(*) FROM associations WHERE status='verified')       AS verified_associations,
  (SELECT COUNT(*) FROM donations WHERE status IN ('offered','claimed')) AS open_donations,
  (SELECT COUNT(*) FROM reports WHERE status='open')                AS open_reports;
