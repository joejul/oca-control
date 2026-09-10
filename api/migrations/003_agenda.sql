-- Agenda de programacion y contenido - OCA So Cafe Bar
-- Idempotente en lo posible (CREATE TABLE IF NOT EXISTS).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS events (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    month       CHAR(7)      NOT NULL,
    date        DATE         NULL,
    type        VARCHAR(40)  NOT NULL,
    theme       VARCHAR(160) NULL,
    schedule    VARCHAR(80)  NULL,
    cover       VARCHAR(80)  NULL,
    promo       TEXT         NULL,
    artists     VARCHAR(160) NULL,
    price       VARCHAR(40)  NULL,
    art_status  VARCHAR(30)  NOT NULL DEFAULT 'en_plan',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_events_month (month),
    KEY idx_events_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
