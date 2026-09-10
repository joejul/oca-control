-- Solicitudes de vacaciones - OCA So Cafe Bar
-- Idempotente en lo posible (CREATE TABLE IF NOT EXISTS).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS vacation_requests (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    request_date  DATE         NOT NULL,
    description   TEXT         NULL,
    status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by   INT UNSIGNED NULL,
    reviewed_at   DATETIME     NULL,
    review_note   TEXT         NULL,
    voided_at     DATETIME     NULL,
    voided_by     INT UNSIGNED NULL,
    void_reason   TEXT         NULL,
    PRIMARY KEY (id),
    KEY idx_vacation_user (user_id),
    KEY idx_vacation_status (status),
    KEY idx_vacation_date (request_date),
    CONSTRAINT fk_vacation_user     FOREIGN KEY (user_id)     REFERENCES users (id),
    CONSTRAINT fk_vacation_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id),
    CONSTRAINT fk_vacation_voider   FOREIGN KEY (voided_by)   REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
