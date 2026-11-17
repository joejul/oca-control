-- Salidas anticipadas y reposicion de horas - OCA So Cafe Bar
--
-- Migracion SOLO ADITIVA: crea dos tablas nuevas y no modifica ninguna tabla
-- existente, asi que es segura sobre una base con datos. Idempotente
-- (CREATE TABLE IF NOT EXISTS) y se puede importar por phpMyAdmin.
--
-- Rollback (si hiciera falta):
--   DROP TABLE makeup_hours;
--   DROP TABLE early_leaves;
--
-- Saldo por reponer = SUM(early_leaves.minutes_owed, no anuladas)
--                   - SUM(makeup_hours.minutes, aprobadas y no anuladas)
-- Estas tablas NO se archivan con los cierres: la deuda arrastra de un cierre
-- al siguiente hasta que se reponga.

SET NAMES utf8mb4;

-- Registro de salida anticipada (no requiere aprobacion).
CREATE TABLE IF NOT EXISTS early_leaves (
    id              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED      NOT NULL,
    leave_date      DATE              NOT NULL,
    scheduled_end   TIME              NOT NULL,   -- hora oficial de salida
    actual_end      TIME              NOT NULL,   -- hora real de salida
    minutes_owed    SMALLINT UNSIGNED NOT NULL,   -- scheduled_end - actual_end (calculado en el servidor)
    note            TEXT              NOT NULL,
    created_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    voided_at       DATETIME          NULL,
    voided_by       INT UNSIGNED      NULL,
    void_reason     TEXT              NULL,
    PRIMARY KEY (id),
    KEY idx_early_leaves_user (user_id),
    KEY idx_early_leaves_date (leave_date),
    CONSTRAINT fk_early_leaves_user   FOREIGN KEY (user_id)   REFERENCES users (id),
    CONSTRAINT fk_early_leaves_voider FOREIGN KEY (voided_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reposicion de horas (la aprueba el admin, igual que las horas extra).
CREATE TABLE IF NOT EXISTS makeup_hours (
    id            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED      NOT NULL,
    makeup_date   DATE              NOT NULL,
    minutes       SMALLINT UNSIGNED NOT NULL,
    note          TEXT              NULL,
    status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by   INT UNSIGNED      NULL,
    reviewed_at   DATETIME          NULL,
    review_note   TEXT              NULL,
    voided_at     DATETIME          NULL,
    voided_by     INT UNSIGNED      NULL,
    void_reason   TEXT              NULL,
    PRIMARY KEY (id),
    KEY idx_makeup_user (user_id),
    KEY idx_makeup_status (status),
    KEY idx_makeup_date (makeup_date),
    CONSTRAINT fk_makeup_user     FOREIGN KEY (user_id)     REFERENCES users (id),
    CONSTRAINT fk_makeup_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id),
    CONSTRAINT fk_makeup_voider   FOREIGN KEY (voided_by)   REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
