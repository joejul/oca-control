-- Simplifica el estado de arte a 2 valores: pending / completed.
SET NAMES utf8mb4;

UPDATE events SET art_status = 'pending' WHERE art_status IN ('en_plan', 'planning', 'pending_art');
UPDATE events SET art_status = 'completed' WHERE art_status IN ('art_ready', 'scheduled', 'published');

ALTER TABLE events
    MODIFY COLUMN art_status VARCHAR(20) NOT NULL DEFAULT 'pending';
