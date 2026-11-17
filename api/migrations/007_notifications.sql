-- Novedades para el colaborador - OCA So Cafe Bar
-- Guarda hasta cuando el colaborador ya vio sus novedades (aprobaciones, rechazos y
-- anulaciones). Las novedades en si NO se guardan: se derivan de las tablas existentes
-- (reviewed_at / voided_at), asi que no se duplica nada.
--
-- ADITIVA e idempotente: solo CREATE TABLE IF NOT EXISTS, no toca ninguna tabla existente
-- (funciona igual en MySQL 8 y MariaDB). Se puede importar por phpMyAdmin.
-- Rollback: DROP TABLE user_notification_state;

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS user_notification_state (
    user_id INT UNSIGNED NOT NULL,
    seen_at DATETIME     NOT NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_notif_state_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
