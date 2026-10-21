-- Additive delivery kind; events carry job IDs only, never code/results/hidden tests.
ALTER TABLE realtime_outbox MODIFY kind ENUM('channel','dm','group','code_lab') NOT NULL;
