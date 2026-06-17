-- SimplyRunFaster Database Schema
-- Milestone 1 + Migration 002 (Archetype Engine)
-- MariaDB 5.3+ / MySQL 5.5+ compatible
-- Character set: utf8 (utf8 not available on MariaDB 5.3)

SET NAMES utf8;
SET time_zone = '+00:00';

-- ============================================================
-- USERS & AUTH
-- ============================================================

CREATE TABLE IF NOT EXISTS `users` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`             VARCHAR(255) NOT NULL,
    `password_hash`     VARCHAR(255) NOT NULL,
    `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'force a password change on next login',
    `active`            TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'deactivated accounts (0) cannot log in',
    `role`              ENUM('athlete','coach','assistant_coach','admin') NOT NULL DEFAULT 'athlete',
    `managed_by`        INT DEFAULT NULL COMMENT 'head coach user_id for assistant coaches; NULL otherwise',
    `name`              VARCHAR(150) NOT NULL,
    `theme_preference`  ENUM('light','dark','system') NOT NULL DEFAULT 'system',
    `timezone`          VARCHAR(64) NOT NULL DEFAULT 'America/New_York' COMMENT 'IANA tz id; DB stays UTC, converted in PHP',
    `phone_number`      VARCHAR(20) DEFAULT NULL COMMENT 'E.164 format',
    `phone_verified`    TINYINT(1) NOT NULL DEFAULT 0,
    `signup_source`     ENUM('invite','organic','ad_campaign','other') NOT NULL DEFAULT 'organic',
    `invite_code`       VARCHAR(64) DEFAULT NULL,
    `ad_campaign_id`    VARCHAR(255) DEFAULT NULL COMMENT 'UTM campaign tag',
    `ad_source`         VARCHAR(100) DEFAULT NULL COMMENT 'UTM source e.g. instagram',
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `idx_users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ============================================================
-- INVITE LINKS
-- ============================================================

CREATE TABLE IF NOT EXISTS `invite_links` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`              VARCHAR(64) NOT NULL,
    `created_by`        INT UNSIGNED NOT NULL COMMENT 'user_id of coach or admin',
    `assigned_coach_id` INT UNSIGNED NOT NULL COMMENT 'coach pre-assigned to athlete',
    `coupon_code`       VARCHAR(100) DEFAULT NULL COMMENT 'Stripe coupon to apply at signup',
    `expires_at`        DATETIME NOT NULL,
    `used_at`           DATETIME DEFAULT NULL,
    `used_by`           INT UNSIGNED DEFAULT NULL COMMENT 'athlete user_id',
    `max_uses`          INT NOT NULL DEFAULT 1,
    `use_count`         INT NOT NULL DEFAULT 0,
    `notes`             VARCHAR(500) DEFAULT NULL COMMENT 'coach-facing label',
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_invite_code` (`code`),
    KEY `idx_invite_created_by` (`created_by`),
    KEY `idx_invite_coach` (`assigned_coach_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ============================================================
-- COACH ASSIGNMENTS (athlete -> head coach + optional assistant coach)
-- Authoritative for the permission model; athletes.coach_id is kept in sync
-- with coach_assignments.coach_id by the app so existing reads keep working.
-- ============================================================

CREATE TABLE IF NOT EXISTS `coach_assignments` (
    `id`                 INT AUTO_INCREMENT PRIMARY KEY,
    `athlete_id`         INT NOT NULL,
    `coach_id`           INT NOT NULL,
    `assistant_coach_id` INT NULL,
    `assigned_at`        DATETIME NOT NULL,
    `assigned_by`        INT NOT NULL,
    UNIQUE KEY `unique_athlete` (`athlete_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- ============================================================
-- PLAN REGENERATION REQUESTS (assistant coach -> head coach)
-- ============================================================

CREATE TABLE IF NOT EXISTS `plan_regeneration_requests` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `athlete_id`   INT NOT NULL,
    `requested_by` INT NOT NULL,
    `requested_at` DATETIME NOT NULL,
    `status`       ENUM('pending','approved','dismissed') NOT NULL DEFAULT 'pending',
    `actioned_by`  INT NULL,
    `actioned_at`  DATETIME NULL,
    `notes`        TEXT NULL,
    KEY `idx_prr_athlete` (`athlete_id`),
    KEY `idx_prr_status` (`status`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- ============================================================
-- ATHLETES
-- ============================================================

CREATE TABLE IF NOT EXISTS `athletes` (
    `id`                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`                   INT UNSIGNED NOT NULL,
    `coach_id`                  INT UNSIGNED DEFAULT NULL COMMENT 'assigned coach user_id',
    `onboarding_completed_at`   DATETIME DEFAULT NULL COMMENT 'triggers first plan build',
    `status`                    ENUM('active','paused','churned') NOT NULL DEFAULT 'active',
    -- Billing (Stripe integration — Milestone 8)
    `stripe_customer_id`        VARCHAR(100) DEFAULT NULL,
    `stripe_subscription_id`    VARCHAR(100) DEFAULT NULL,
    `billing_status`            ENUM('active','trialing','comped','past_due','cancelled','paused') NOT NULL DEFAULT 'trialing',
    `billing_notes`             TEXT DEFAULT NULL COMMENT 'Coach-facing note',
    `trial_ends_at`             DATETIME DEFAULT NULL,
    `comp_reason`               ENUM('founding_athlete','coach_relationship','promotional','referral','other') DEFAULT NULL,
    `created_at`                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_athletes_user` (`user_id`),
    KEY `idx_athletes_coach` (`coach_id`),
    KEY `idx_athletes_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ============================================================
-- ATHLETE PROFILES (onboarding data — engine reads this)
-- ============================================================

CREATE TABLE IF NOT EXISTS `athlete_profiles` (
    `athlete_id`                INT UNSIGNED NOT NULL,
    `plan_type`                 ENUM('race_cycle','development_plan','maintenance_plan','recovery_block','return_to_running') DEFAULT NULL,
    -- Goal
    `goal_race_date`            DATE DEFAULT NULL,
    `goal_race_distance`        VARCHAR(20) DEFAULT NULL COMMENT '5K, 10K, HM, marathon, ultra',
    `goal_finish_time`          VARCHAR(20) DEFAULT NULL COMMENT 'optional time goal',
    `ultra_surface`             ENUM('road','trail') DEFAULT NULL COMMENT 'trail vs road, ultra goal distances only',
    `is_hyrox`                  TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Hyrox UI facade; engine runs mile logic underneath',
    `hyrox_ever`                TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Latches to 1 once Hyrox is ever selected; keeps the Hyrox pill visible',
    -- Current fitness (all time-on-feet based per engine spec)
    `current_weekly_minutes`    INT DEFAULT NULL COMMENT 'current weekly time on feet in minutes',
    `longest_recent_run_mins`   INT DEFAULT NULL COMMENT 'longest recent run in minutes',
    `months_at_current_volume`  INT DEFAULT NULL,
    `most_recent_race_distance` VARCHAR(20) DEFAULT NULL,
    `most_recent_race_time`     INT DEFAULT NULL COMMENT 'seconds',
    `most_recent_race_date`     DATE DEFAULT NULL,
    -- Experience
    `years_running`             FLOAT DEFAULT NULL,
    `peak_weekly_minutes`       INT DEFAULT NULL COMMENT 'highest-ever weekly time on feet',
    `experience_level`          ENUM('beginner','intermediate','advanced') DEFAULT NULL,
    `injury_history`            TEXT DEFAULT NULL,
    -- Availability / scheduling
    `training_days_per_week`    INT DEFAULT NULL,
    `must_off_days`             LONGTEXT DEFAULT NULL COMMENT 'JSON array of day numbers 0=Sun',
    `scheduling_preference`     ENUM('fixed','flex') DEFAULT 'flex',
    `long_run_day`              TINYINT DEFAULT NULL COMMENT '0=Sun..6=Sat',
    `primary_workout_day`       TINYINT DEFAULT NULL,
    `track_access`              ENUM('yes','no','road_reps_ok') DEFAULT 'road_reps_ok',
    -- Watch
    `watch_platform`            ENUM('garmin','polar','apple','wahoo','none') NOT NULL DEFAULT 'none',
    `watch_connected`           TINYINT(1) NOT NULL DEFAULT 0,
    -- Zones (populated after race result or coach entry)
    `hr_zones`                  LONGTEXT DEFAULT NULL,
    `pace_zones`                LONGTEXT DEFAULT NULL,
    `typical_easy_pace_min`     INT DEFAULT NULL COMMENT 'Faster end of typical easy-day pace, seconds per mile',
    `typical_easy_pace_max`     INT DEFAULT NULL COMMENT 'Slower end of typical easy-day pace, seconds per mile',
    `pace_zones_source`         ENUM('race_result','easy_pace_estimate','manual') DEFAULT NULL COMMENT 'How pace_zones was derived',
    `pace_zones_visible`        TINYINT(1) NOT NULL DEFAULT 1,
    `pace_zones_hidden_reason`  TEXT DEFAULT NULL COMMENT 'internal coach note',
    -- Peak volume ceiling (engine uses this — never exceeds)
    `peak_volume_ceiling_mins`  INT DEFAULT NULL COMMENT 'max weekly minutes; engine-derived, coach-adjustable',
    -- Terrain access and classification (engine reads these)
    `track_field_background`    TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Has track or field athletics background; auto-grants plyometric_clearance',
    `hill_access`               TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Has access to hilly terrain; required for hill archetypes',
    `base_classification`       ENUM('well_trained','workable','insufficient') DEFAULT NULL COMMENT 'Cached engine classification (recomputed on profile update or coach override)',
    -- Coach clearances
    `plyometric_clearance`      TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Cleared for plyometric/bounding workouts; auto-set if track_field_background=1',
    `medical_clearance_confirmed` TINYINT(1) NOT NULL DEFAULT 0,
    `medical_clearance_at`      DATETIME DEFAULT NULL,
    -- Return-to-running
    `return_time_off_band`      ENUM('1_2_weeks','2_6_weeks','6_16_weeks','4_12_months','12_plus_months') DEFAULT NULL,
    -- Cross-training equipment
    `cross_training_bike`       ENUM('none','stationary','road_gravel') NOT NULL DEFAULT 'none',
    `cross_training_elliptical` ENUM('none','gym','home') NOT NULL DEFAULT 'none',
    `cross_training_pool`       TINYINT(1) NOT NULL DEFAULT 0,
    `cross_training_other`      TEXT DEFAULT NULL,
    -- Preferences
    `units`                     ENUM('miles','km') NOT NULL DEFAULT 'miles',
    `updated_at`                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`athlete_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ============================================================
-- PERSONAL BESTS
-- ============================================================

CREATE TABLE IF NOT EXISTS `personal_bests` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `athlete_id`        INT UNSIGNED NOT NULL,
    `distance`          ENUM('5K','10K','15K','half','marathon','ultra','mile','other') NOT NULL,
    `distance_override` FLOAT DEFAULT NULL COMMENT 'for other distances',
    `time_seconds`      INT NOT NULL COMMENT 'finish time in seconds',
    `source`            ENUM('system','manual') NOT NULL DEFAULT 'manual',
    `race_id`           INT UNSIGNED DEFAULT NULL,
    `race_date`         DATE DEFAULT NULL,
    `notes`             TEXT DEFAULT NULL,
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pb_athlete_distance` (`athlete_id`, `distance`),
    KEY `idx_pb_athlete` (`athlete_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ============================================================
-- RACES
-- ============================================================

CREATE TABLE IF NOT EXISTS `races` (
    `id`                            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `athlete_id`                    INT UNSIGNED NOT NULL,
    `added_by`                      INT UNSIGNED NOT NULL,
    `added_by_role`                 ENUM('athlete','coach','assistant_coach','admin') NOT NULL,
    `race_name`                     VARCHAR(255) NOT NULL,
    `race_distance`                 ENUM('5K','10K','15K','half','marathon','ultra','other','50k','50_miler','100k','100_miler') NOT NULL,
    `distance_override`             FLOAT DEFAULT NULL COMMENT 'miles, when distance=other',
    `distance_override_unit`        ENUM('miles','km') DEFAULT NULL COMMENT 'unit the athlete entered for an "other" distance',
    `race_date`                     DATE NOT NULL,
    `is_goal_race`                  TINYINT(1) NOT NULL DEFAULT 0,
    `result_time`                   INT DEFAULT NULL COMMENT 'finish time in seconds',
    `result_synced_from_watch`      TINYINT(1) NOT NULL DEFAULT 0,
    `result_notes`                  TEXT DEFAULT NULL COMMENT 'athlete free-text notes logged with the result',
    `recalibration_proposed`        TINYINT(1) NOT NULL DEFAULT 0,
    `recalibration_approved`        TINYINT(1) DEFAULT NULL,
    `recalibration_approved_by`     INT UNSIGNED DEFAULT NULL,
    `recalibration_approved_at`     DATETIME DEFAULT NULL,
    `proposed_pace_zones`           LONGTEXT DEFAULT NULL,
    `notes`                         TEXT DEFAULT NULL,
    `created_at`                    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                    DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_races_athlete` (`athlete_id`),
    KEY `idx_races_date` (`race_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ============================================================
-- WATCH CONNECTIONS
-- ============================================================

CREATE TABLE IF NOT EXISTS `watch_connections` (
    `athlete_id`        INT UNSIGNED NOT NULL,
    `platform`          ENUM('garmin','polar','apple','wahoo') NOT NULL,
    `access_token`      TEXT NOT NULL COMMENT 'encrypted',
    `refresh_token`     TEXT DEFAULT NULL COMMENT 'encrypted',
    `token_expires_at`  DATETIME DEFAULT NULL,
    `last_synced_at`    DATETIME DEFAULT NULL,
    `sync_status`       ENUM('active','error','disconnected') NOT NULL DEFAULT 'active',
    `error_message`     TEXT DEFAULT NULL,
    PRIMARY KEY (`athlete_id`, `platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ============================================================
-- WORKOUT LIBRARY
-- ============================================================

CREATE TABLE IF NOT EXISTS `workout_library` (
    `id`                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `library_code`              VARCHAR(10) DEFAULT NULL COMMENT 'e.g. WL-001',
    `name`                      VARCHAR(255) NOT NULL,
    `athlete_facing_name`       VARCHAR(255) DEFAULT NULL,
    `workout_type`              ENUM('easy','long','tempo','interval','hill','fartlek','race_pace','recovery','rest','cross_train','speed','plyometric') NOT NULL,
    `phase_tags`                LONGTEXT DEFAULT NULL COMMENT 'array: base, build, peak, taper',
    `distance_tags`             LONGTEXT DEFAULT NULL COMMENT 'array: 5K, 10K, half, marathon',
    `prescription_type`         ENUM('time','distance','count') NOT NULL DEFAULT 'time',
    `track_required`            ENUM('yes','no','preferred') NOT NULL DEFAULT 'no',
    `secondary_stimulus`        TINYINT(1) NOT NULL DEFAULT 0,
    `long_run_embedded`         TINYINT(1) NOT NULL DEFAULT 0,
    `coach_clearance_required`  TINYINT(1) NOT NULL DEFAULT 0,
    `min_base_classification`   ENUM('workable','well_trained') NOT NULL DEFAULT 'workable',
    `intensity_factor`          FLOAT NOT NULL DEFAULT 0.5 COMMENT 'for training stress calculation',
    `structure`                 LONGTEXT DEFAULT NULL COMMENT 'warmup, main set, cooldown with targets',
    `description`               TEXT COMMENT 'athlete-facing instructions',
    `engine_notes`              TEXT DEFAULT NULL COMMENT 'internal engine usage notes',
    `tags`                      LONGTEXT DEFAULT NULL,
    `created_by`                INT UNSIGNED DEFAULT NULL COMMENT 'coach user_id, null = system',
    `created_at`                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_wl_type` (`workout_type`),
    KEY `idx_wl_code` (`library_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ============================================================
-- TRAINING PLANS
-- ============================================================

CREATE TABLE IF NOT EXISTS `training_plans` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `athlete_id`            INT UNSIGNED NOT NULL,
    `plan_type`             ENUM('race_cycle','development_plan','maintenance_plan','recovery_block','return_to_running') NOT NULL DEFAULT 'race_cycle',
    `rtr_current_stage`     INT DEFAULT NULL COMMENT 'return_to_running run/walk stage 1-10; NULL for other plan types',
    `status`                ENUM('pending_approval','active','archived','abandoned') NOT NULL DEFAULT 'pending_approval',
    `approved_by`           INT UNSIGNED DEFAULT NULL COMMENT 'coach user_id',
    `approved_at`           DATETIME DEFAULT NULL,
    `plan_start_date`       DATE DEFAULT NULL,
    `plan_end_date`         DATE DEFAULT NULL,
    `goal_race_date`        DATE DEFAULT NULL COMMENT 'snapshot at generation time',
    `generated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `generation_trigger`    ENUM('onboarding','block_end','coach_manual','engine_rebuild') NOT NULL DEFAULT 'onboarding',
    `notes`                 TEXT DEFAULT NULL COMMENT 'coach notes on this plan',
    PRIMARY KEY (`id`),
    KEY `idx_plans_athlete` (`athlete_id`),
    KEY `idx_plans_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ============================================================
-- PLANNED WORKOUTS
-- ============================================================

CREATE TABLE IF NOT EXISTS `planned_workouts` (
    `id`                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `plan_id`                   INT UNSIGNED NOT NULL,
    `athlete_id`                INT UNSIGNED NOT NULL COMMENT 'denormalized for query speed',
    `scheduled_date`            DATE NOT NULL,
    `workout_type`              ENUM('easy','long','tempo','interval','hill','fartlek','race_pace','recovery','rest','cross_train','speed','plyometric') NOT NULL,
    `archetype_code`            VARCHAR(60) DEFAULT NULL COMMENT 'References workout_archetypes.code',
    `archetype_variant`         VARCHAR(60) DEFAULT NULL COMMENT 'Variant code selected at generation time',
    `archetype_params`          LONGTEXT    DEFAULT NULL COMMENT 'JSON: resolved parameters (rep_count, duration, effort, etc.)',
    `description`               TEXT COMMENT 'human-readable instructions',
    `target_distance`           FLOAT DEFAULT NULL COMMENT 'miles or km',
    `target_duration`           INT DEFAULT NULL COMMENT 'minutes',
    `target_pace_min`           FLOAT DEFAULT NULL COMMENT 'min/mile lower bound',
    `target_pace_max`           FLOAT DEFAULT NULL COMMENT 'min/mile upper bound',
    `target_hr_zone`            TINYINT DEFAULT NULL COMMENT '1-5',
    `intensity_load`            FLOAT DEFAULT NULL COMMENT 'training stress score',
    -- Coach lock
    `coach_locked`              TINYINT(1) NOT NULL DEFAULT 0,
    `coach_edited_by`           INT UNSIGNED DEFAULT NULL,
    `coach_edited_at`           DATETIME DEFAULT NULL,
    -- Athlete visibility / watch
    `visible_to_athlete`        TINYINT(1) NOT NULL DEFAULT 0,
    `pushed_to_watch`           TINYINT(1) NOT NULL DEFAULT 0,
    `pushed_at`                 DATETIME DEFAULT NULL,
    -- Athlete day-swap fields (Section 12)
    `original_scheduled_date`   DATE DEFAULT NULL,
    `athlete_moved`             TINYINT(1) NOT NULL DEFAULT 0,
    `athlete_moved_at`          DATETIME DEFAULT NULL,
    `must_off_override`         TINYINT(1) NOT NULL DEFAULT 0,
    `notes`                     TEXT DEFAULT NULL COMMENT 'coach-facing notes',
    -- Coach soft-delete (migration_012)
    `cancelled`                 TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'coach soft-deleted; day renders as rest',
    `cancelled_at`              DATETIME DEFAULT NULL,
    `cancelled_by`              INT DEFAULT NULL COMMENT 'user_id of the coach who cancelled it',
    `added_by_role`             VARCHAR(32) DEFAULT NULL COMMENT "'assistant_coach' when added by an assistant coach (coach-only AC badge)",
    PRIMARY KEY (`id`),
    KEY `idx_pw_plan` (`plan_id`),
    KEY `idx_pw_athlete_date` (`athlete_id`, `scheduled_date`),
    KEY `idx_pw_visible` (`athlete_id`, `visible_to_athlete`),
    KEY `idx_pw_archetype` (`archetype_code`),
    KEY `idx_pw_cancelled` (`plan_id`, `cancelled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ============================================================
-- COMPLETED WORKOUTS
-- ============================================================

CREATE TABLE IF NOT EXISTS `completed_workouts` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `athlete_id`            INT UNSIGNED NOT NULL,
    `planned_workout_id`    INT UNSIGNED DEFAULT NULL COMMENT 'matched by date',
    `source`                ENUM('garmin','polar','apple','wahoo','manual') NOT NULL DEFAULT 'manual',
    `external_activity_id`  VARCHAR(255) DEFAULT NULL COMMENT 'platform own ID',
    `activity_date`         DATE NOT NULL,
    `workout_type`          VARCHAR(50) DEFAULT NULL,
    `actual_distance`       FLOAT DEFAULT NULL,
    `actual_duration`       INT DEFAULT NULL COMMENT 'minutes',
    `avg_pace`              FLOAT DEFAULT NULL COMMENT 'min/mile',
    `avg_hr`                INT DEFAULT NULL COMMENT 'bpm',
    `max_hr`                INT DEFAULT NULL COMMENT 'bpm',
    `hr_zones_breakdown`    LONGTEXT DEFAULT NULL COMMENT 'time in each zone',
    `elevation_gain`        FLOAT DEFAULT NULL,
    `power_avg`             INT DEFAULT NULL COMMENT 'watts',
    -- Manual logging fields
    `completion_status`     ENUM('full','partial','no') DEFAULT NULL,
    `rpe`                   TINYINT DEFAULT NULL COMMENT '1-10 (internal); athlete sees Easy/Moderate/Hard/VeryHard',
    `rpe_discomfort`        TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'return-to-running pain flag',
    `effort_descriptor`     ENUM('easy','moderate','hard','very_hard','discomfort') DEFAULT NULL,
    -- Raw data for future use
    `raw_data`              LONGTEXT DEFAULT NULL COMMENT 'full platform payload',
    `compliance_score`      FLOAT DEFAULT NULL COMMENT '0-1 vs planned workout',
    `synced_at`             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_cw_athlete_date` (`athlete_id`, `activity_date`),
    KEY `idx_cw_planned` (`planned_workout_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ============================================================
-- TRAINING LOAD (ATL/CTL/TSB — computed daily)
-- ============================================================

CREATE TABLE IF NOT EXISTS `training_load` (
    `athlete_id`    INT UNSIGNED NOT NULL,
    `date`          DATE NOT NULL,
    `atl`           FLOAT NOT NULL DEFAULT 0 COMMENT 'acute training load (7-day)',
    `ctl`           FLOAT NOT NULL DEFAULT 0 COMMENT 'chronic training load (42-day)',
    `tsb`           FLOAT NOT NULL DEFAULT 0 COMMENT 'training stress balance (CTL - ATL)',
    `daily_stress`  FLOAT NOT NULL DEFAULT 0 COMMENT 'training stress score for this day',
    `computed_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`athlete_id`, `date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ============================================================
-- ENGINE FLAGS
-- ============================================================

CREATE TABLE IF NOT EXISTS `engine_flags` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `athlete_id`    INT UNSIGNED NOT NULL,
    `flag_type`     ENUM(
                        'missed_workouts','hr_elevated','load_spike','compliance_low',
                        'plan_rebuild_needed','compliance_trend','compliance_pattern',
                        'excessive_fatigue','fitness_decline','taper_concern',
                        'insufficient_base','return_to_running_discomfort',
                        'limited_development_opportunity','long_run_day_conflict',
                        'display_generation_incomplete',
                        'profile_updated','pace_zones_missing',
                        'schedule_day_ramp','ultra_surface_reminder',
                        'race_added','goal_race_changed','pace_recalibration',
                        'hyrox_supplement_reminder','assistant_pace_zone_edit'
                    ) NOT NULL,
    `severity`      ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
    `flag_date`     DATE NOT NULL,
    `details`       LONGTEXT DEFAULT NULL COMMENT 'machine-readable context',
    `message`       TEXT NOT NULL COMMENT 'human-readable summary for coach',
    `status`        ENUM('open','dismissed','acted_on') NOT NULL DEFAULT 'open',
    `reviewed_by`   INT UNSIGNED DEFAULT NULL,
    `reviewed_at`   DATETIME DEFAULT NULL,
    `dismiss_reason` TEXT DEFAULT NULL COMMENT 'required for critical flags',
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_flags_athlete` (`athlete_id`),
    KEY `idx_flags_status` (`status`, `severity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ============================================================
-- PLAN APPROVAL QUEUE
-- ============================================================

CREATE TABLE IF NOT EXISTS `plan_approval_queue` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `plan_id`           INT UNSIGNED NOT NULL,
    `athlete_id`        INT UNSIGNED NOT NULL,
    `requested_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `request_reason`    ENUM('onboarding','block_end','coach_manual','engine_rebuild') NOT NULL,
    `status`            ENUM('pending','approved','rejected','modified_and_approved') NOT NULL DEFAULT 'pending',
    `reviewed_by`       INT UNSIGNED DEFAULT NULL,
    `reviewed_at`       DATETIME DEFAULT NULL,
    `coach_notes`       TEXT DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_paq_status` (`status`),
    KEY `idx_paq_athlete` (`athlete_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ============================================================
-- PLAN TEMPLATES (Section 30)
-- ============================================================

CREATE TABLE IF NOT EXISTS `plan_templates` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `created_by`            INT UNSIGNED NOT NULL COMMENT 'coach user_id',
    `platform_wide`         TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'admin-promoted',
    `name`                  VARCHAR(255) NOT NULL,
    `plan_type`             ENUM('race_cycle','development_plan','maintenance_plan','recovery_block','return_to_running') NOT NULL,
    `target_distance`       ENUM('5K','10K','15K','half','marathon','ultra') DEFAULT NULL,
    `cycle_length_weeks`    INT NOT NULL,
    `phase_proportions`     LONGTEXT DEFAULT NULL COMMENT 'base/build/peak/taper percentages',
    `week_structures`       LONGTEXT DEFAULT NULL COMMENT 'array of week templates',
    `notes`                 TEXT DEFAULT NULL COMMENT 'coach-facing usage notes',
    `use_count`             INT NOT NULL DEFAULT 0,
    `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_pt_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ============================================================
-- MESSAGES (Section 13)
-- ============================================================

CREATE TABLE IF NOT EXISTS `messages` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `athlete_id`            INT UNSIGNED NOT NULL COMMENT 'always scoped to an athlete',
    `sender_id`             INT UNSIGNED NOT NULL,
    `sender_role`           ENUM('athlete','coach','assistant_coach') NOT NULL,
    `body`                  TEXT NOT NULL,
    `sent_at`               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `read_at`               DATETIME DEFAULT NULL,
    `push_sent`             TINYINT(1) NOT NULL DEFAULT 0,
    `message_type`          ENUM('message','session_note','session_note_reply') NOT NULL DEFAULT 'message',
    `completed_workout_id`  INT UNSIGNED DEFAULT NULL COMMENT 'session thread origin',
    `planned_workout_id`    INT UNSIGNED DEFAULT NULL COMMENT 'session card link to a planned workout (available pre-completion)',
    `thread_id`             INT UNSIGNED DEFAULT NULL COMMENT 'self-referencing for session threads',
    PRIMARY KEY (`id`),
    KEY `idx_msg_athlete` (`athlete_id`, `sent_at`),
    KEY `idx_msg_unread` (`athlete_id`, `read_at`),
    KEY `idx_msg_planned` (`planned_workout_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ============================================================
-- SESSION NOTES (Section 13)
-- ============================================================

CREATE TABLE IF NOT EXISTS `session_notes` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `completed_workout_id`  INT UNSIGNED DEFAULT NULL COMMENT 'set once the workout is completed; NULL for pre-completion notes',
    `planned_workout_id`    INT UNSIGNED DEFAULT NULL COMMENT 'thread link to a planned workout (available pre-completion)',
    `athlete_id`            INT UNSIGNED NOT NULL,
    `author_id`             INT UNSIGNED NOT NULL,
    `author_role`           ENUM('athlete','coach','assistant_coach') NOT NULL,
    `body`                  TEXT NOT NULL,
    `soft_limit_chars`      INT NOT NULL DEFAULT 500,
    `hard_limit_chars`      INT NOT NULL DEFAULT 1000,
    `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sn_workout` (`completed_workout_id`),
    KEY `idx_sn_planned` (`planned_workout_id`),
    KEY `idx_sn_athlete` (`athlete_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ============================================================
-- NOTIFICATION PREFERENCES (Section 28)
-- ============================================================

CREATE TABLE IF NOT EXISTS `notification_preferences` (
    `user_id`           INT UNSIGNED NOT NULL,
    `notification_type` VARCHAR(60) NOT NULL,
    `enabled`           TINYINT(1) NOT NULL DEFAULT 1,
    `channel_push`      TINYINT(1) NOT NULL DEFAULT 1,
    `channel_email`     TINYINT(1) NOT NULL DEFAULT 0,
    `channel_sms`       TINYINT(1) NOT NULL DEFAULT 0,
    `quiet_hours_start` TIME NOT NULL DEFAULT '22:00:00',
    `quiet_hours_end`   TIME NOT NULL DEFAULT '07:00:00',
    `preferred_time`    TIME DEFAULT NULL COMMENT 'for scheduled notifications',
    `preferred_day`     TINYINT DEFAULT NULL COMMENT '0-6 for weekly notifications',
    `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `notification_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ============================================================
-- PUSH NOTIFICATION SUBSCRIPTIONS (Web Push API)
-- ============================================================

CREATE TABLE IF NOT EXISTS `push_subscriptions` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED NOT NULL,
    `endpoint`      TEXT NOT NULL,
    `p256dh`        TEXT NOT NULL COMMENT 'client public key',
    `auth`          TEXT NOT NULL COMMENT 'auth secret',
    `user_agent`    VARCHAR(255) DEFAULT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_used_at`  DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_push_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ============================================================
-- PHONE VERIFICATION (SMS flow)
-- ============================================================

CREATE TABLE IF NOT EXISTS `phone_verifications` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED NOT NULL,
    `phone_number`  VARCHAR(20) NOT NULL,
    `code`          VARCHAR(10) NOT NULL,
    `expires_at`    DATETIME NOT NULL,
    `verified_at`   DATETIME DEFAULT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pv_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ============================================================
-- PASSWORD RESET TOKENS
-- ============================================================

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `token`      VARCHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at`    DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_reset_token` (`token`),
    KEY `idx_reset_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ============================================================
-- WORKOUT ARCHETYPES (Migration 002)
-- ============================================================

CREATE TABLE IF NOT EXISTS `workout_archetypes` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`               VARCHAR(60)  NOT NULL COMMENT 'stable slug, e.g. continuous_easy',
    `version`            TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `status`             ENUM('active','inactive','draft') NOT NULL DEFAULT 'active',

    -- Core identity
    `name`               VARCHAR(255) NOT NULL,
    `workout_type`       ENUM(
                             'easy','long','tempo','interval','hill','fartlek',
                             'race_pace','recovery','rest','cross_train',
                             'speed','plyometric'
                         ) NOT NULL,
    `mapped_templates`   LONGTEXT DEFAULT NULL COMMENT 'JSON array of WL-xxx codes',
    `description`        TEXT DEFAULT NULL,

    -- Engine selection (all stored as JSON objects)
    `selection`          LONGTEXT NOT NULL COMMENT 'JSON: slot_types, phases, plan_types, goal_distances, min_classification, track_requirement, coach_clearance_required, requires, excludes',
    `weights`            LONGTEXT NOT NULL COMMENT 'JSON: phase/goal_distance/classification/plan_type weight maps (0-10)',
    `generation`         LONGTEXT NOT NULL COMMENT 'JSON: prescription_model, duration_source, progression_model, recovery_model, intensity_factor',

    -- Template definition (JSON objects)
    `variants`           LONGTEXT DEFAULT NULL COMMENT 'JSON array of {code, name, ...} variant objects',
    `parameters`         LONGTEXT DEFAULT NULL COMMENT 'JSON: parameter definitions with workable/well_trained ranges',
    `structure_template` LONGTEXT DEFAULT NULL COMMENT 'JSON: segment structure template with {{token}} placeholders',
    `display`            LONGTEXT DEFAULT NULL COMMENT 'JSON: lead_with, title_template, summary_template, description_template',
    `instance_signature` LONGTEXT DEFAULT NULL COMMENT 'JSON: field list used to identify a unique generated instance',
    `coach_notes`        LONGTEXT DEFAULT NULL COMMENT 'JSON: intended_use string, special_rules array',

    -- Ownership / visibility
    `created_by`         INT UNSIGNED DEFAULT NULL COMMENT 'coach user_id; NULL = system archetype',
    `platform_wide`      TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'system archetypes always platform-wide; coach-created default 0',
    `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME  DEFAULT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY  `uq_archetype_code`       (`code`),
    KEY         `idx_archetype_status`    (`status`),
    KEY         `idx_archetype_type`      (`workout_type`),
    KEY         `idx_archetype_created_by`(`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- After fresh install, seed archetypes with:
--   php scripts/seed_archetypes.php

-- ============================================================
-- SEED: WORKOUT LIBRARY (23 initial templates)
-- ============================================================

INSERT INTO `workout_library`
    (`library_code`, `name`, `athlete_facing_name`, `workout_type`, `phase_tags`, `distance_tags`,
     `prescription_type`, `track_required`, `secondary_stimulus`, `long_run_embedded`,
     `coach_clearance_required`, `min_base_classification`, `intensity_factor`,
     `structure`, `description`)
VALUES

('WL-001', 'Easy Run', 'Easy run', 'easy',
 '["base","build","peak","taper"]', '["5K","10K","half","marathon"]',
 'time', 'no', 0, 0, 0, 'workable', 0.5,
 '{"main": "Full duration at easy effort, HR zone 1-2"}',
 'An easy, conversational effort. You should be able to speak in full sentences throughout. Don\'t watch your pace — run by feel and keep it relaxed.'),

('WL-002', 'Easy Run with Strides', 'Easy run with strides', 'easy',
 '["base","build","peak","taper"]', '["5K","10K","half","marathon"]',
 'time', 'no', 1, 0, 0, 'workable', 0.55,
 '{"main": "Easy effort for main portion", "strides": "4-6 x 20-25 sec at 85-90% effort", "recovery": "60-90 sec walk/jog between strides"}',
 'An easy run finishing with strides. Run the main portion at a comfortable, conversational effort. In the final 10 minutes, find a flat stretch and run 4–6 strides of 20–25 seconds each.'),

('WL-003', 'Pure Aerobic Long Run', 'Long run', 'long',
 '["base","build","peak","taper"]', '["5K","10K","half","marathon"]',
 'time', 'no', 0, 0, 0, 'workable', 0.6,
 '{"main": "Full duration at easy effort, HR zone 1-2"}',
 'A long easy effort. The goal is time on your feet at a comfortable, sustainable pace. This should feel genuinely easy — not moderate, not controlled hard, actually easy.'),

('WL-004', 'Progression Long Run', 'Progression long run', 'long',
 '["base","build"]', '["10K","half","marathon"]',
 'time', 'no', 1, 1, 0, 'workable', 0.7,
 '{"first_third": "easy effort HR zone 1-2", "middle_third": "aerobic effort HR zone 2-3", "final_third": "honest aerobic/threshold effort HR zone 3"}',
 'A long run that builds in effort over time. Start at a genuinely easy pace and let the effort increase naturally as you go.'),

('WL-005', 'Long Run with Goal Pace Segments', 'Long run with goal pace', 'long',
 '["build","peak"]', '["half","marathon"]',
 'time', 'no', 1, 1, 0, 'workable', 0.85,
 '{"opening": "20-30% of total at easy effort", "segments": "1-3 goal pace segments, 20-40% of total", "closing": "remaining at easy effort"}',
 'A long run with segments at your goal race pace built in. The pace segments should feel honest but sustainable — not a struggle, not effortless.'),

('WL-006', 'Long Run with Cutdown Finish', 'Long run — cutdown finish', 'long',
 '["build","peak"]', '["10K","half","marathon"]',
 'time', 'no', 1, 1, 0, 'workable', 0.85,
 '{"opening": "65-70% of total at easy effort", "cutdown": "30-35% with pace descending every 5-10 minutes"}',
 'A long run that finishes faster than it starts. Run the majority at easy effort, then in the final 20–30 minutes begin picking up the pace progressively.'),

('WL-007', 'Descending Time Fartlek', 'Descending fartlek', 'fartlek',
 '["base","build"]', '["5K","10K","half","marathon"]',
 'time', 'no', 1, 0, 0, 'workable', 0.7,
 '{"warmup": "15-20 min easy", "round1": "90s hard / 3min easy / 60s hard / 2min easy / 30s hard / 1min easy", "round2": "repeat", "cooldown": "10-15 min easy"}',
 'A fartlek session with two rounds of descending effort bursts. The hard efforts aren\'t sprints — run them at a pace you could sustain for several minutes if you had to.'),

('WL-008', 'Track Intervals: 1000m Repeats', '1000m repeats', 'interval',
 '["build","peak"]', '["5K","10K","half"]',
 'distance', 'preferred', 0, 0, 0, 'workable', 1.0,
 '{"warmup": "15-20 min easy + 4x20s strides", "main": "5-8 x 1000m at 5K effort", "recovery": "2:30-3:00 min easy jog", "cooldown": "10-15 min easy"}',
 '1000m repeats at your 5K effort. Each repeat should feel like a hard, controlled effort — the kind of pace you could race at for about 20 minutes.'),

('WL-009', 'Track Intervals: Mixed Distance', 'Mixed track session', 'interval',
 '["build","peak"]', '["5K","10K"]',
 'distance', 'preferred', 0, 0, 0, 'workable', 1.0,
 '{"warmup": "15-20 min easy + 4x20s strides", "part1": "4x1000m at 5K effort / 3min recovery", "part2": "4x200m at mile effort / 2min recovery", "cooldown": "10-15 min easy"}',
 'A two-part track session combining longer repeats with short speed work. The first part builds your ability to sustain race pace; the second develops your top-end speed.'),

('WL-010', 'Track Session: 1600m + 300m', '1600m repeats + 300m', 'interval',
 '["build","peak"]', '["5K","10K","half"]',
 'distance', 'preferred', 0, 0, 0, 'workable', 1.0,
 '{"warmup": "15-20 min easy + 4x20s strides", "part1": "4x1600m at mile/5K effort / 2:30 recovery", "part2": "2x300m at 800m effort / 2min recovery", "cooldown": "10-15 min easy"}',
 'A two-part session: longer mile-effort repeats followed by short, fast 300m efforts. The mile repeats should feel like a sustained hard effort — controlled but honest.'),

('WL-011', 'Sustained Hill Circuit', 'Hill circuits', 'hill',
 '["build","peak"]', '["5K","10K","half","marathon"]',
 'time', 'no', 1, 0, 0, 'workable', 0.7,
 '{"warmup": "15-20 min easy", "main": "3-6 hill circuits (climb + jog descent)", "climb_effort": "10K effort approx", "cooldown": "10-15 min easy"}',
 'A hill circuit session — repeated climbs of a substantial hill with jog descents as recovery. Find a hill that takes 2–4 minutes to climb at a hard but sustainable effort.'),

('WL-012', 'Hill Sprint Session', 'Hill sprints', 'hill',
 '["base","build"]', '["5K","10K","half","marathon"]',
 'time', 'no', 1, 0, 0, 'workable', 0.7,
 '{"warmup": "20 min easy", "main": "8-12 x 10-15 sec hill sprints, walk-back recovery", "cooldown": "10 min easy"}',
 'Short, steep hill sprints — one of the best things you can do for your running regardless of your goal distance. Sprint up hard — close to all-out — focusing on driving your knees and pumping your arms.'),

('WL-013', 'Hill Bounding and Skipping Circuits', 'Hill bounding circuits', 'hill',
 '["base","build"]', '["5K","10K","half","marathon"]',
 'count', 'no', 1, 0, 1, 'workable', 0.75,
 '{"warmup": "20 min easy including dynamic drills", "main": "6 circuits — 3 bounds + 3 skips with jog descent recovery", "cooldown": "10-15 min easy"}',
 'Hill circuits combining bounding and skipping — plyometric work that builds explosive power and running economy. Your coach will walk you through the form before your first session.'),

('WL-014', 'Tempo Intervals', 'Tempo intervals', 'tempo',
 '["build","peak"]', '["10K","half","marathon"]',
 'time', 'no', 0, 0, 0, 'workable', 0.8,
 '{"warmup": "15-20 min easy", "main": "3-5 x 8-12 min at tempo effort / 3-4 min easy jog recovery", "cooldown": "10-15 min easy"}',
 'Sustained tempo efforts with recovery between. Tempo pace is "comfortably hard" — a pace you could hold for about an hour in a race.'),

('WL-015', 'Steady State Progression Run', 'Steady state run', 'tempo',
 '["base","build"]', '["5K","10K","half"]',
 'time', 'no', 0, 0, 0, 'workable', 0.75,
 '{"opening": "aerobic effort HR zone 2", "middle": "building through zone 2-3", "final": "threshold effort HR zone 3-4"}',
 'A continuous run that builds in effort from aerobic to threshold. Start at a comfortable aerobic pace and let the effort increase gradually over the run.'),

('WL-016', 'Long Run with Fartlek Pickups', 'Long run with pickups', 'long',
 '["base","build"]', '["half","marathon"]',
 'time', 'no', 1, 1, 0, 'workable', 0.75,
 '{"main": "Full long run at easy effort", "pickups": "8-12 x 60 sec pickups every 8-10 min at 10K effort"}',
 'A long run with short pickups scattered throughout. Run the majority at easy effort, but every 8–10 minutes insert a 60-second pickup at a noticeably faster pace.'),

('WL-017', 'Pre-Race Activation Run', 'Pre-race shakeout', 'easy',
 '["taper"]', '["5K","10K","half","marathon"]',
 'time', 'no', 1, 0, 0, 'workable', 0.5,
 '{"easy_run": "20-30 min easy", "strides": "4-6 x 20 sec at 85-90% effort / 60-90 sec walk recovery"}',
 'An easy shakeout run the day before your race with strides to wake up your legs. Keep the easy portion genuinely easy — this run has one job: make you feel loose and ready without tiring you out.'),

('WL-018', 'Recovery Run', 'Recovery run', 'recovery',
 '["build","peak","taper"]', '["5K","10K","half","marathon"]',
 'time', 'no', 0, 0, 0, 'workable', 0.3,
 '{"main": "20-30 min at recovery effort, HR zone 1"}',
 'An easy, short run at a genuinely relaxed effort. This run exists to promote recovery, not to add fitness. Run slower than you think you need to.'),

('WL-019', 'Beginner Stride Session', 'Stride session', 'interval',
 '["base"]', '["5K","10K"]',
 'count', 'no', 0, 0, 0, 'workable', 0.55,
 '{"warmup": "15 min easy jog", "main": "10 x 20 sec sprints at near-maximum effort / walk-back recovery", "cooldown": "10 min easy jog"}',
 'A structured speed session designed to introduce fast running. After a comfortable warmup jog, you\'ll run 10 short sprints of 20 seconds each with full walk-back recovery between them.'),

('WL-020', 'Descending Speed Fartlek', 'Speed fartlek', 'fartlek',
 '["build","peak"]', '["5K","10K","half","marathon"]',
 'time', 'no', 0, 0, 0, 'workable', 0.7,
 '{"warmup": "15-20 min easy", "rounds": "4 rounds of: 1min hard / 1min easy / 30sec harder / 1min easy / 15sec near-sprint / 1min easy", "between_rounds": "2-3 min easy jog", "cooldown": "10-15 min easy"}',
 'A fartlek session with four rounds of descending, sharp efforts. The efforts get shorter and faster within each round — the 15-second efforts should feel genuinely fast, close to your top speed.'),

('WL-021', 'High Volume Tempo Intervals (20×2/1)', '20×2/1 intervals', 'interval',
 '["build","peak"]', '["half","marathon"]',
 'time', 'no', 0, 0, 0, 'well_trained', 1.0,
 '{"warmup": "15-20 min easy", "main": "20 x 2 min hard / 1 min easy jog", "cooldown": "10-15 min easy"}',
 'Twenty rounds of 2 minutes hard followed by 1 minute easy. This is a high-volume quality session — the cumulative load is the point.'),

('WL-022', '⅛ Mile Road Repeats', '⅛ mile repeats', 'interval',
 '["base","build","peak"]', '["5K","10K","half","marathon"]',
 'count', 'no', 1, 0, 0, 'workable', 0.8,
 '{"warmup": "20 min easy", "main": "20-25 x 1/8 mile (~200m) at near-sprint effort / ~90 sec easy jog recovery", "cooldown": "10-15 min easy"}',
 'Short, fast ⅛ mile (200m) repeats on the road with easy jog recovery. Each repeat should be run at close to your top controlled speed — fast and powerful but not falling apart.'),

('WL-023', 'Quarters and Eighths', 'Quarters and eighths', 'interval',
 '["build","peak"]', '["5K","10K","half"]',
 'distance', 'no', 0, 0, 0, 'workable', 1.0,
 '{"warmup": "15-20 min easy + 4x20s strides", "main": "10-16 x 400m at near-sprint effort / 1/8 mile easy jog recovery", "cooldown": "10-15 min easy"}',
 'Alternating 400m efforts and ⅛ mile recovery jogs. The 400m efforts should be run at close to your best controlled speed for that distance. This session develops your ability to sustain speed under fatigue.');
