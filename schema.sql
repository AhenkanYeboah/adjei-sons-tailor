-- =========================================================
-- BESPOKE TAILORING PLATFORM — DATABASE SCHEMA
-- Engine: MySQL 8 / MariaDB 10.5+
-- Charset: utf8mb4 (supports GH₵, emoji in WhatsApp logs, etc.)
-- =========================================================

CREATE DATABASE IF NOT EXISTS bespoke_tailor
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE bespoke_tailor;

-- ---------------------------------------------------------
-- 1. CLIENTS & STAFF
-- ---------------------------------------------------------

CREATE TABLE clients (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name           VARCHAR(150)        NOT NULL,
    email               VARCHAR(150)        UNIQUE,
    phone               VARCHAR(20)         NOT NULL UNIQUE, -- WhatsApp number, e.g. +233...
    password_hash       VARCHAR(255)        NOT NULL,
    country             VARCHAR(80)         DEFAULT 'Ghana',
    timezone            VARCHAR(50)         DEFAULT 'Africa/Accra',
    preferred_currency  CHAR(3)             DEFAULT 'GHS', -- ISO 4217
    referred_by_client_id INT UNSIGNED      NULL,
    created_at          DATETIME            DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME            DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (referred_by_client_id) REFERENCES clients(id) ON DELETE SET NULL,
    INDEX idx_clients_phone (phone)
) ENGINE=InnoDB;

CREATE TABLE staff (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name           VARCHAR(150)        NOT NULL,
    role                ENUM('owner','tailor','measurer','admin','support') NOT NULL DEFAULT 'tailor',
    email               VARCHAR(150)        UNIQUE,
    phone               VARCHAR(20),
    whatsapp_number     VARCHAR(20),
    password_hash       VARCHAR(255)        NOT NULL,
    is_active           TINYINT(1)          DEFAULT 1,
    created_at          DATETIME            DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 2. MEASUREMENTS
-- (method matters — flags whether it's video-call-verified,
--  self-reported, or future AI-estimated, so trust level is
--  always visible on an order)
-- ---------------------------------------------------------

CREATE TABLE measurements (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id           INT UNSIGNED        NOT NULL,
    chest_cm            DECIMAL(5,2),
    waist_cm            DECIMAL(5,2),
    hip_cm              DECIMAL(5,2),
    shoulder_cm         DECIMAL(5,2),
    sleeve_cm           DECIMAL(5,2),
    inseam_cm           DECIMAL(5,2),
    neck_cm             DECIMAL(5,2),
    height_cm           DECIMAL(5,2),
    extra_notes         TEXT,               -- e.g. "left shoulder slightly lower"
    method               ENUM('video_call','in_person','self_reported','ai_estimated') NOT NULL DEFAULT 'video_call',
    recorded_by_staff_id INT UNSIGNED       NULL,   -- who took the measurement (audit trail)
    booking_id          INT UNSIGNED        NULL,   -- links back to the call it was taken on
    client_confirmed    TINYINT(1)          DEFAULT 0, -- did client reply "confirmed" to the WhatsApp summary?
    recorded_at         DATETIME            DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by_staff_id) REFERENCES staff(id) ON DELETE SET NULL,
    INDEX idx_measurements_client (client_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 3. STYLE CONFIGURATOR: fabrics, styles, options
-- ---------------------------------------------------------

CREATE TABLE fabrics (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(150)        NOT NULL,
    description         TEXT,
    image_url           VARCHAR(255),
    video_url           VARCHAR(255),       -- drape video
    price_modifier      DECIMAL(8,2)        DEFAULT 0.00, -- added to base price
    stock_status        ENUM('in_stock','low_stock','out_of_stock') DEFAULT 'in_stock',
    free_swatch_eligible TINYINT(1)         DEFAULT 1,
    created_at          DATETIME            DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE style_options (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category            ENUM('cut','lining','buttons','monogram','collar','cuff') NOT NULL,
    name                VARCHAR(100)        NOT NULL,
    price_modifier      DECIMAL(8,2)        DEFAULT 0.00,
    image_url           VARCHAR(255),
    is_active           TINYINT(1)          DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE garment_types (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(100)        NOT NULL,     -- e.g. "Two-Piece Suit", "Kaftan"
    base_price          DECIMAL(10,2)       NOT NULL,
    base_production_days INT UNSIGNED       NOT NULL DEFAULT 14, -- baseline before queue adjustment
    is_active           TINYINT(1)          DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE swatch_requests (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id           INT UNSIGNED        NOT NULL,
    fabric_ids          JSON                NOT NULL,     -- up to 4 fabric ids
    shipping_address    TEXT                NOT NULL,
    status              ENUM('requested','shipped','delivered') DEFAULT 'requested',
    requested_at        DATETIME            DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 4. PRODUCTION QUEUE & EXPRESS SLOTS
-- (drives the live wait-time estimator + express toggle;
--  single source of truth used by both homepage widget and checkout)
-- ---------------------------------------------------------

CREATE TABLE production_settings (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    days_added_per_n_orders INT UNSIGNED    NOT NULL DEFAULT 2,  -- e.g. +2 days per 5 open orders
    orders_per_increment    INT UNSIGNED    NOT NULL DEFAULT 5,
    min_wait_days           INT UNSIGNED    NOT NULL DEFAULT 7,
    max_wait_days           INT UNSIGNED    NOT NULL DEFAULT 45,
    updated_at              DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
-- (Single-row config table — app reads the one row. Keeps tuning out of code.)

CREATE TABLE express_slots (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    week_start_date     DATE                NOT NULL,
    tier                ENUM('express_5day','rush_48hr') NOT NULL,
    slots_total         INT UNSIGNED        NOT NULL,
    slots_used          INT UNSIGNED        NOT NULL DEFAULT 0,
    UNIQUE KEY uq_week_tier (week_start_date, tier)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 5. ORDERS
-- ---------------------------------------------------------

CREATE TABLE orders (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id           INT UNSIGNED        NOT NULL,
    garment_type_id     INT UNSIGNED        NOT NULL,
    fabric_id           INT UNSIGNED        NOT NULL,
    measurement_id      INT UNSIGNED        NOT NULL,
    style_config        JSON                NOT NULL,     -- chosen style_option ids, monogram text, etc.
    order_tier          ENUM('standard','express_5day','rush_48hr') NOT NULL DEFAULT 'standard',
    status              ENUM('received','measuring','cutting','sewing','fitting','ready_for_pickup','completed','cancelled')
                                             NOT NULL DEFAULT 'received',
    total_price         DECIMAL(10,2)       NOT NULL,
    deposit_amount      DECIMAL(10,2)       NOT NULL,
    balance_amount      DECIMAL(10,2)       NOT NULL,
    deposit_paid        TINYINT(1)          DEFAULT 0,
    balance_paid        TINYINT(1)          DEFAULT 0,
    promised_pickup_date DATE               NOT NULL,      -- calculated at order time, locked in
    actual_pickup_date  DATE                NULL,
    assigned_staff_id   INT UNSIGNED        NULL,
    created_at          DATETIME            DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME            DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (garment_type_id) REFERENCES garment_types(id),
    FOREIGN KEY (fabric_id) REFERENCES fabrics(id),
    FOREIGN KEY (measurement_id) REFERENCES measurements(id),
    FOREIGN KEY (assigned_staff_id) REFERENCES staff(id) ON DELETE SET NULL,
    INDEX idx_orders_client (client_id),
    INDEX idx_orders_status (status)
) ENGINE=InnoDB;

-- Every status change logged — this feeds BOTH the client tracking
-- dashboard and the WhatsApp notification trigger, off one source of truth.
CREATE TABLE order_status_log (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id            INT UNSIGNED        NOT NULL,
    status              VARCHAR(30)         NOT NULL,
    changed_by_staff_id INT UNSIGNED        NULL,
    whatsapp_sent       TINYINT(1)          DEFAULT 0,
    changed_at          DATETIME            DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by_staff_id) REFERENCES staff(id) ON DELETE SET NULL,
    INDEX idx_status_log_order (order_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 6. PAYMENTS
-- ---------------------------------------------------------

CREATE TABLE payments (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id            INT UNSIGNED        NOT NULL,
    payment_type        ENUM('deposit','balance','full') NOT NULL,
    amount              DECIMAL(10,2)       NOT NULL,
    currency             CHAR(3)            NOT NULL DEFAULT 'GHS',
    method              ENUM('paystack','hubtel','stripe','bank_transfer','cash') NOT NULL,
    transaction_ref     VARCHAR(150)        UNIQUE,
    status              ENUM('pending','successful','failed','refunded') DEFAULT 'pending',
    paid_at             DATETIME            NULL,
    created_at          DATETIME            DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    INDEX idx_payments_order (order_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 7. BOOKINGS (video measurement calls, fittings, consultations)
-- ---------------------------------------------------------

CREATE TABLE bookings (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id           INT UNSIGNED        NOT NULL,
    staff_id            INT UNSIGNED        NULL,
    booking_type        ENUM('video_measurement','consultation','in_person_fitting') NOT NULL,
    slot_datetime_utc   DATETIME            NOT NULL,      -- always store UTC, render in client.timezone
    duration_minutes    INT UNSIGNED        DEFAULT 20,
    status              ENUM('scheduled','completed','no_show','cancelled') DEFAULT 'scheduled',
    google_calendar_event_id VARCHAR(150)   NULL,
    reminder_sent       TINYINT(1)          DEFAULT 0,
    call_recording_url  VARCHAR(255)        NULL,          -- if consented
    created_at          DATETIME            DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE SET NULL,
    INDEX idx_bookings_client (client_id),
    INDEX idx_bookings_slot (slot_datetime_utc)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 8. WHATSAPP NOTIFICATION LOG
-- (auditable — lets you debug "client says they didn't get a message")
-- ---------------------------------------------------------

CREATE TABLE whatsapp_notifications (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id           INT UNSIGNED        NOT NULL,
    related_order_id    INT UNSIGNED        NULL,
    related_booking_id  INT UNSIGNED        NULL,
    message_type        VARCHAR(50)         NOT NULL,      -- e.g. 'status_update','booking_reminder','measurement_summary'
    message_body        TEXT                NOT NULL,
    status              ENUM('queued','sent','failed') DEFAULT 'queued',
    sent_at             DATETIME            NULL,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (related_order_id) REFERENCES orders(id) ON DELETE SET NULL,
    FOREIGN KEY (related_booking_id) REFERENCES bookings(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 9. REFERRALS & MARKETING
-- ---------------------------------------------------------

CREATE TABLE referrals (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    referrer_client_id  INT UNSIGNED        NOT NULL,
    referred_client_id  INT UNSIGNED        NOT NULL UNIQUE,
    discount_percent    DECIMAL(5,2)        DEFAULT 10.00,
    referrer_reward_applied TINYINT(1)      DEFAULT 0,
    referred_reward_applied TINYINT(1)      DEFAULT 0,
    created_at          DATETIME            DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (referrer_client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (referred_client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE style_quiz_leads (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email               VARCHAR(150)        NOT NULL,
    quiz_answers        JSON                NOT NULL,
    recommended_fabric_ids JSON             NULL,
    discount_code       VARCHAR(30)         NULL,
    converted_to_client_id INT UNSIGNED     NULL,
    created_at          DATETIME            DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (converted_to_client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 10. GALLERY & CONTENT
-- ---------------------------------------------------------

CREATE TABLE gallery_items (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    image_url           VARCHAR(255)        NOT NULL,
    occasion_category   ENUM('wedding','corporate','graduation','traditional','casual') NOT NULL,
    client_story         TEXT,
    client_name_display  VARCHAR(100),       -- optional, client-approved display name
    is_featured          TINYINT(1)          DEFAULT 0,
    created_at           DATETIME            DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE blog_posts (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title               VARCHAR(200)        NOT NULL,
    slug                VARCHAR(220)        NOT NULL UNIQUE,
    body                LONGTEXT            NOT NULL,
    cover_image_url     VARCHAR(255),
    author_staff_id     INT UNSIGNED        NULL,
    published_at        DATETIME            NULL,
    created_at          DATETIME            DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (author_staff_id) REFERENCES staff(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 11. SIZE GUARANTEE / ALTERATIONS
-- ---------------------------------------------------------

CREATE TABLE alteration_requests (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id            INT UNSIGNED        NOT NULL,
    issue_description   TEXT                NOT NULL,
    is_within_guarantee_window TINYINT(1)   DEFAULT 1,  -- computed at creation vs order.actual_pickup_date + 30 days
    status              ENUM('requested','scheduled','completed') DEFAULT 'requested',
    requested_at        DATETIME            DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =========================================================
-- SEED: default production settings row (app expects exactly one row)
-- =========================================================
INSERT INTO production_settings (days_added_per_n_orders, orders_per_increment, min_wait_days, max_wait_days)
VALUES (2, 5, 7, 45);
