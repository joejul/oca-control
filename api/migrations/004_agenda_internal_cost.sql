-- Costo interno del evento para OCASO (no se muestra en el Resumen de Julian).
SET NAMES utf8mb4;

ALTER TABLE events
    ADD COLUMN internal_cost DECIMAL(10,2) NULL AFTER price;
