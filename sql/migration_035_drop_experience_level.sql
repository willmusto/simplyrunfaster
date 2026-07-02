-- Migration 035: drop athlete_profiles.experience_level (dead field).
-- Nothing reads it: the engine derives classification from volume/history
-- (never from this enum), and the onboarding question + coach profile display
-- were removed in the same change.
--
-- MyISAM note: DROP COLUMN rebuilds the table; athlete_profiles is tiny
-- (single-digit rows), so unlike the held drops in migration 034 this is
-- risk-free. Run via scripts/run_migration_035.php (idempotent), AFTER
-- deploying the code that no longer references the column.

ALTER TABLE `athlete_profiles` DROP COLUMN `experience_level`;
