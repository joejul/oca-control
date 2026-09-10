-- Control de horas extra y consumos - OCA So Cafe Bar
-- Esquema inicial. Idempotente en lo posible (CREATE TABLE IF NOT EXISTS).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username        VARCHAR(40)  NOT NULL,
    display_name    VARCHAR(80)  NOT NULL,
    role            ENUM('admin','collaborator') NOT NULL DEFAULT 'collaborator',
    credential_hash VARCHAR(255) NOT NULL,
    active          TINYINT(1)   NOT NULL DEFAULT 1,
    failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until    DATETIME     NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS auth_tokens (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NOT NULL,
    token_hash   CHAR(64)     NOT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at   DATETIME     NOT NULL,
    last_used_at DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_tokens_hash (token_hash),
    KEY idx_auth_tokens_user (user_id),
    CONSTRAINT fk_auth_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS closures (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    label        VARCHAR(120) NOT NULL,
    start_date   DATE         NOT NULL,
    end_date     DATE         NOT NULL,
    generated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    generated_by INT UNSIGNED NOT NULL,
    totals_json  JSON         NULL,
    PRIMARY KEY (id),
    KEY idx_closures_generated_by (generated_by),
    CONSTRAINT fk_closures_user FOREIGN KEY (generated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS overtime_entries (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    work_date   DATE         NOT NULL,
    hours       DECIMAL(4,2) NOT NULL,
    note        TEXT         NULL,
    status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME     NULL,
    review_note TEXT         NULL,
    closure_id  INT UNSIGNED NULL,
    voided_at   DATETIME     NULL,
    voided_by   INT UNSIGNED NULL,
    void_reason TEXT         NULL,
    PRIMARY KEY (id),
    KEY idx_overtime_user (user_id),
    KEY idx_overtime_status (status),
    KEY idx_overtime_closure (closure_id),
    KEY idx_overtime_work_date (work_date),
    CONSTRAINT fk_overtime_user     FOREIGN KEY (user_id)     REFERENCES users (id),
    CONSTRAINT fk_overtime_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id),
    CONSTRAINT fk_overtime_voider   FOREIGN KEY (voided_by)   REFERENCES users (id),
    CONSTRAINT fk_overtime_closure  FOREIGN KEY (closure_id)  REFERENCES closures (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS consumption_entries (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    consumer_id   INT UNSIGNED  NOT NULL,
    registered_by INT UNSIGNED  NOT NULL,
    amount        DECIMAL(10,2) NOT NULL,
    detail        TEXT          NULL,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closure_id    INT UNSIGNED  NULL,
    voided_at     DATETIME      NULL,
    voided_by     INT UNSIGNED  NULL,
    void_reason   TEXT          NULL,
    PRIMARY KEY (id),
    KEY idx_consumption_consumer (consumer_id),
    KEY idx_consumption_registered_by (registered_by),
    KEY idx_consumption_closure (closure_id),
    KEY idx_consumption_created_at (created_at),
    CONSTRAINT fk_consumption_consumer   FOREIGN KEY (consumer_id)   REFERENCES users (id),
    CONSTRAINT fk_consumption_registrant FOREIGN KEY (registered_by) REFERENCES users (id),
    CONSTRAINT fk_consumption_voider     FOREIGN KEY (voided_by)     REFERENCES users (id),
    CONSTRAINT fk_consumption_closure    FOREIGN KEY (closure_id)    REFERENCES closures (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_id     INT UNSIGNED    NULL,
    action       VARCHAR(60)     NOT NULL,
    entity_type  VARCHAR(40)     NULL,
    entity_id    INT UNSIGNED    NULL,
    details_json JSON            NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_actor (actor_id),
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
