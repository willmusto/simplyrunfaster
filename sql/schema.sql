-- SimplyRunFaster Database Schema
-- Regenerated from the LIVE production schema on 2026-06-23 (structure only).
-- Engine, tables, and columns reflect production EXACTLY. Do not hand-edit — regenerate
-- with scripts/dump_schema.php (or mysqldump --no-data) when the live schema changes.
-- NOTE: production is MyISAM throughout (no FKs, no transactions); see _specs/db_debt_audit.md.

SET NAMES utf8;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `account_deletions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `deleted_at` datetime NOT NULL,
  `reason` varchar(64) COLLATE utf8_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_acctdel_user` (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `athletes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `coach_id` int(10) unsigned DEFAULT NULL COMMENT 'assigned coach user_id',
  `onboarding_completed_at` datetime DEFAULT NULL COMMENT 'triggers first plan build',
  `status` enum('active','paused','churned') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'active',
  `stripe_customer_id` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `stripe_subscription_id` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `billing_status` enum('active','trialing','comped','past_due','cancelled','paused') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'trialing',
  `billing_notes` text COLLATE utf8_unicode_ci COMMENT 'Coach-facing note',
  `trial_ends_at` datetime DEFAULT NULL,
  `comp_reason` enum('founding_athlete','coach_relationship','promotional','referral','other') COLLATE utf8_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_athletes_user` (`user_id`),
  KEY `idx_athletes_coach` (`coach_id`),
  KEY `idx_athletes_status` (`status`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `athlete_behavior_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `athlete_id` int(11) NOT NULL,
  `logged_at` datetime NOT NULL,
  `metric_type` enum('rpe_vs_target','completion_rate','easy_pace_drift','response_time','engagement_score') NOT NULL,
  `metric_value` float NOT NULL,
  `metric_context` longtext,
  `plan_week` int(11) DEFAULT NULL,
  `phase` varchar(16) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_abl_athlete_metric` (`athlete_id`,`metric_type`,`logged_at`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `athlete_profiles` (
  `athlete_id` int(10) unsigned NOT NULL,
  `plan_type` enum('race_cycle','development_plan','maintenance_plan','recovery_block','return_to_running') COLLATE utf8_unicode_ci DEFAULT NULL,
  `goal_race_date` date DEFAULT NULL,
  `goal_race_distance` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT '5K, 10K, HM, marathon, ultra',
  `goal_finish_time` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'optional time goal',
  `ultra_surface` enum('road','trail') COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'trail vs road, ultra goal distances only',
  `is_hyrox` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Hyrox UI facade; engine runs mile logic underneath',
  `hyrox_ever` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Latches to 1 once Hyrox is ever selected; keeps the Hyrox pill visible',
  `current_weekly_minutes` int(11) DEFAULT NULL COMMENT 'current weekly time on feet in minutes',
  `longest_recent_run_mins` int(11) DEFAULT NULL COMMENT 'longest recent run in minutes',
  `months_at_current_volume` int(11) DEFAULT NULL,
  `most_recent_race_distance` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL,
  `most_recent_race_time` int(11) DEFAULT NULL COMMENT 'seconds',
  `most_recent_race_date` date DEFAULT NULL,
  `years_running` float DEFAULT NULL,
  `peak_weekly_minutes` int(11) DEFAULT NULL COMMENT 'highest-ever weekly time on feet',
  `experience_level` enum('beginner','intermediate','advanced') COLLATE utf8_unicode_ci DEFAULT NULL,
  `injury_history` text COLLATE utf8_unicode_ci,
  `training_days_per_week` int(11) DEFAULT NULL,
  `must_off_days` longtext COLLATE utf8_unicode_ci COMMENT 'JSON array of day numbers 0=Sun',
  `scheduling_preference` enum('fixed','flex') COLLATE utf8_unicode_ci DEFAULT 'flex',
  `long_run_day` tinyint(4) DEFAULT NULL COMMENT '0=Sun..6=Sat',
  `primary_workout_day` tinyint(4) DEFAULT NULL,
  `track_access` enum('yes','no','road_reps_ok') COLLATE utf8_unicode_ci DEFAULT 'road_reps_ok',
  `track_field_background` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Has track or field athletics background; auto-grants plyometric_clearance',
  `hill_access` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Has access to hilly terrain; required for sustained_hill_repeats and plyometric_hill_circuits',
  `base_classification` enum('well_trained','workable','insufficient') COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Cached engine classification (recomputed on profile update or coach override)',
  `watch_platform` enum('garmin','polar','apple','wahoo','none') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'none',
  `watch_connected` tinyint(1) NOT NULL DEFAULT '0',
  `hr_zones` longtext COLLATE utf8_unicode_ci,
  `pace_zones` longtext COLLATE utf8_unicode_ci,
  `typical_easy_pace_min` int(11) DEFAULT NULL COMMENT 'Faster end of typical easy-day pace, seconds per mile',
  `typical_easy_pace_max` int(11) DEFAULT NULL COMMENT 'Slower end of typical easy-day pace, seconds per mile',
  `pace_zones_source` enum('race_result','easy_pace_estimate','manual') COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'How pace_zones was derived',
  `pace_zones_visible` tinyint(1) NOT NULL DEFAULT '1',
  `pace_zones_hidden_reason` text COLLATE utf8_unicode_ci COMMENT 'internal coach note',
  `peak_volume_ceiling_mins` int(11) DEFAULT NULL COMMENT 'max weekly minutes; engine-derived, coach-adjustable',
  `plyometric_clearance` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Cleared for plyometric/bounding workouts; auto-set if track_field_background=1',
  `medical_clearance_confirmed` tinyint(1) NOT NULL DEFAULT '0',
  `medical_clearance_at` datetime DEFAULT NULL,
  `return_time_off_band` enum('1_2_weeks','2_6_weeks','6_16_weeks','4_12_months','12_plus_months') COLLATE utf8_unicode_ci DEFAULT NULL,
  `cross_training_bike` enum('none','stationary','road_gravel') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'none',
  `cross_training_elliptical` enum('none','gym','home') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'none',
  `cross_training_pool` tinyint(1) NOT NULL DEFAULT '0',
  `cross_training_other` text COLLATE utf8_unicode_ci,
  `units` enum('miles','km') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'miles',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`athlete_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `athlete_response_profiles` (
  `athlete_id` int(11) NOT NULL,
  `computed_at` datetime NOT NULL,
  `weeks_of_data` int(11) NOT NULL DEFAULT '0',
  `metrics_json` longtext,
  PRIMARY KEY (`athlete_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `coaching_decisions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `status` enum('active','inactive','proposed','proposed_by_assistant') NOT NULL DEFAULT 'proposed',
  `title` varchar(255) NOT NULL,
  `reason` text NOT NULL,
  `trigger_json` longtext NOT NULL,
  `action_json` longtext NOT NULL,
  `scope_distances` longtext,
  `scope_phases` longtext,
  `scope_plan_types` longtext,
  `times_fired` int(11) NOT NULL DEFAULT '0',
  `last_fired_at` datetime DEFAULT NULL,
  `source` enum('manual','proposed_from_adjustment') NOT NULL DEFAULT 'manual',
  `proposed_from_count` int(11) DEFAULT NULL COMMENT 'how many adjustments triggered this proposal',
  `proposed_at` datetime DEFAULT NULL COMMENT 'when the pattern proposer generated this',
  `shared` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Phase 4: head coach shares this active rule across the whole roster',
  `rationale` text COMMENT 'Phase 4: the "why", surfaced in the coaching philosophy export',
  PRIMARY KEY (`id`),
  KEY `idx_cd_creator` (`created_by`,`status`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `coaching_intelligence_flags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `athlete_id` int(11) NOT NULL,
  `coach_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  `flag_type` enum('rpe_trending_high','rpe_trending_low','compliance_dropping','compliance_streak','engagement_dropping','adaptation_ahead_of_schedule','dropout_risk','plan_adjustment_recommended','predicted_fatigue','predicted_dropout','injury_risk_pattern','adaptation_ahead') NOT NULL,
  `severity` enum('info','warning','opportunity') NOT NULL,
  `title` varchar(255) NOT NULL,
  `detail` text NOT NULL,
  `suggested_action` text,
  `suggested_adjustment` longtext,
  `status` enum('open','actioned','dismissed','superseded') NOT NULL DEFAULT 'open',
  `actioned_at` datetime DEFAULT NULL,
  `dismissed_at` datetime DEFAULT NULL,
  `confidence` enum('low','medium','high') DEFAULT NULL COMMENT 'Phase 3 predictive confidence tier; NULL for non-predictive flags',
  `prediction_horizon_days` int(11) DEFAULT NULL COMMENT 'Phase 3: how many days ahead the prediction looks',
  `predicted_for_date` date DEFAULT NULL COMMENT 'Phase 3: target date the prediction is about',
  PRIMARY KEY (`id`),
  KEY `idx_cif_coach_status` (`coach_id`,`status`),
  KEY `idx_cif_athlete_type` (`athlete_id`,`flag_type`,`created_at`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `coach_adjustments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `planned_workout_id` int(11) NOT NULL,
  `athlete_id` int(11) NOT NULL,
  `coach_id` int(11) NOT NULL,
  `adjusted_at` datetime NOT NULL,
  `flagged_for_review` tinyint(1) NOT NULL DEFAULT '0',
  `change_type` enum('archetype_substitution','duration_change','day_swap','workout_removed','workout_added','instructions_edited','pace_zone_edit') NOT NULL,
  `before_archetype_code` varchar(64) DEFAULT NULL,
  `before_workout_type` varchar(32) DEFAULT NULL,
  `before_duration_mins` int(11) DEFAULT NULL,
  `before_scheduled_date` date DEFAULT NULL,
  `before_instructions` longtext,
  `after_archetype_code` varchar(64) DEFAULT NULL,
  `after_workout_type` varchar(32) DEFAULT NULL,
  `after_duration_mins` int(11) DEFAULT NULL,
  `after_scheduled_date` date DEFAULT NULL,
  `after_instructions` longtext,
  `ctx_goal_distance` varchar(32) DEFAULT NULL,
  `ctx_phase` varchar(16) DEFAULT NULL,
  `ctx_week_number` int(11) DEFAULT NULL,
  `ctx_classification` varchar(16) DEFAULT NULL,
  `ctx_weekly_mins` int(11) DEFAULT NULL,
  `ctx_plan_week` int(11) DEFAULT NULL,
  `reason_tag` enum('athlete_fatigue','schedule_conflict','insufficient_recovery','wrong_phase','athlete_preference','injury_concern','weather_conditions','race_preparation','coach_preference','other') DEFAULT NULL,
  `reason_notes` text,
  `coaching_decision_id` int(11) DEFAULT NULL,
  `proposed_decision_id` int(11) DEFAULT NULL COMMENT 'set when this adjustment contributed to a proposal',
  PRIMARY KEY (`id`),
  KEY `idx_ca_coach` (`coach_id`),
  KEY `idx_ca_athlete` (`athlete_id`),
  KEY `idx_ca_flagged` (`flagged_for_review`,`coaching_decision_id`),
  KEY `idx_ca_workout` (`planned_workout_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `coach_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `athlete_id` int(11) NOT NULL,
  `coach_id` int(11) NOT NULL,
  `assistant_coach_id` int(11) DEFAULT NULL,
  `assigned_at` datetime NOT NULL,
  `assigned_by` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_athlete` (`athlete_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `coach_roster_insights` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `coach_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  `insight_type` enum('compliance_cluster','engagement_cluster','upcoming_races','adjustment_pattern','streak_cluster','workload_spike') NOT NULL,
  `title` varchar(255) NOT NULL,
  `detail` text NOT NULL,
  `athlete_ids` longtext NOT NULL,
  `severity` enum('info','warning','opportunity') NOT NULL DEFAULT 'info',
  `status` enum('open','dismissed') NOT NULL DEFAULT 'open',
  `dismissed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cri_coach_status` (`coach_id`,`status`),
  KEY `idx_cri_type_created` (`insight_type`,`created_at`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `completed_workouts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `athlete_id` int(10) unsigned NOT NULL,
  `planned_workout_id` int(10) unsigned DEFAULT NULL COMMENT 'matched by date',
  `source` enum('garmin','polar','apple','wahoo','manual','intervals') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'manual',
  `source_device` varchar(32) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'garmin/coros/polar/suunto when known from the Intervals.icu payload',
  `external_activity_id` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'platform own ID',
  `activity_date` date NOT NULL,
  `workout_type` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `actual_distance` float DEFAULT NULL,
  `actual_duration` int(11) DEFAULT NULL COMMENT 'minutes',
  `avg_pace` float DEFAULT NULL COMMENT 'min/mile',
  `avg_hr` int(11) DEFAULT NULL COMMENT 'bpm',
  `max_hr` int(11) DEFAULT NULL COMMENT 'bpm',
  `hr_zones_breakdown` longtext COLLATE utf8_unicode_ci COMMENT 'time in each zone',
  `elevation_gain` float DEFAULT NULL,
  `power_avg` int(11) DEFAULT NULL COMMENT 'watts',
  `completion_status` enum('full','partial','no') COLLATE utf8_unicode_ci DEFAULT NULL,
  `rpe` tinyint(4) DEFAULT NULL COMMENT '1-10 (internal); athlete sees Easy/Moderate/Hard/VeryHard',
  `rpe_discomfort` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'return-to-running pain flag',
  `effort_descriptor` enum('easy','moderate','hard','very_hard','discomfort') COLLATE utf8_unicode_ci DEFAULT NULL,
  `raw_data` longtext COLLATE utf8_unicode_ci COMMENT 'full platform payload',
  `compliance_score` float DEFAULT NULL COMMENT '0-1 vs planned workout',
  `synced_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_source_external` (`source`,`external_activity_id`(190)),
  KEY `idx_cw_athlete_date` (`athlete_id`,`activity_date`),
  KEY `idx_cw_planned` (`planned_workout_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `device_notify_preferences` (
  `user_id` int(11) NOT NULL,
  `brand` varchar(32) NOT NULL,
  `notify` tinyint(1) NOT NULL DEFAULT '0',
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`user_id`,`brand`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `engine_flags` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `athlete_id` int(10) unsigned NOT NULL,
  `flag_type` enum('missed_workouts','hr_elevated','load_spike','compliance_low','plan_rebuild_needed','compliance_trend','compliance_pattern','excessive_fatigue','fitness_decline','taper_concern','insufficient_base','return_to_running_discomfort','limited_development_opportunity','long_run_day_conflict','display_generation_incomplete','profile_updated','pace_zones_missing','schedule_day_ramp','ultra_surface_reminder','race_added','goal_race_changed','pace_recalibration','hyrox_supplement_reminder','assistant_pace_zone_edit','unmatched_activity') COLLATE utf8_unicode_ci NOT NULL,
  `severity` enum('info','warning','critical') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'info',
  `flag_date` date NOT NULL,
  `details` longtext COLLATE utf8_unicode_ci COMMENT 'machine-readable context',
  `message` text COLLATE utf8_unicode_ci NOT NULL COMMENT 'human-readable summary for coach',
  `status` enum('open','dismissed','acted_on') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'open',
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `dismiss_reason` text COLLATE utf8_unicode_ci COMMENT 'required for critical flags',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_flags_athlete` (`athlete_id`),
  KEY `idx_flags_status` (`status`,`severity`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `intervals_connections` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `intervals_athlete_id` varchar(32) COLLATE utf8_unicode_ci NOT NULL,
  `access_token_enc` text COLLATE utf8_unicode_ci NOT NULL COMMENT 'OAuth access token, encrypted at rest',
  `scope` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `connected_at` datetime NOT NULL,
  `last_synced_at` datetime DEFAULT NULL,
  `sync_status` enum('ok','error','pending') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'pending',
  `last_error` text COLLATE utf8_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_intervals_user` (`user_id`),
  KEY `idx_intervals_athlete` (`intervals_athlete_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `intervals_push_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `planned_workout_id` int(10) unsigned NOT NULL,
  `intervals_event_id` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
  `pushed_at` datetime NOT NULL,
  `status` enum('success','failed','skipped') COLLATE utf8_unicode_ci NOT NULL,
  `error_message` text COLLATE utf8_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `idx_push_workout` (`planned_workout_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `intervals_webhook_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_type` varchar(64) COLLATE utf8_unicode_ci NOT NULL,
  `athlete_id` varchar(32) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Intervals.icu athlete id from payload',
  `payload` longtext COLLATE utf8_unicode_ci NOT NULL,
  `received_at` datetime NOT NULL,
  `status` enum('received','processed','skipped','failed') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'received',
  `error_message` text COLLATE utf8_unicode_ci,
  `processed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_webhook_athlete` (`athlete_id`),
  KEY `idx_webhook_status` (`status`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `invite_links` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) COLLATE utf8_unicode_ci NOT NULL,
  `created_by` int(10) unsigned NOT NULL COMMENT 'user_id of coach or admin',
  `assigned_coach_id` int(10) unsigned NOT NULL COMMENT 'coach pre-assigned to athlete',
  `coupon_code` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Stripe coupon to apply at signup',
  `discount_percent` tinyint(4) DEFAULT NULL,
  `discount_duration` varchar(16) COLLATE utf8_unicode_ci DEFAULT NULL,
  `stripe_coupon_id` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
  `billing_interval` enum('monthly','annual') COLLATE utf8_unicode_ci DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `used_by` int(10) unsigned DEFAULT NULL COMMENT 'athlete user_id',
  `deactivated_at` datetime DEFAULT NULL COMMENT 'set when a coach manually deactivates the link',
  `max_uses` int(11) NOT NULL DEFAULT '1',
  `use_count` int(11) NOT NULL DEFAULT '0',
  `notes` varchar(500) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'coach-facing label',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invite_code` (`code`),
  KEY `idx_invite_created_by` (`created_by`),
  KEY `idx_invite_coach` (`assigned_coach_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `athlete_id` int(10) unsigned NOT NULL COMMENT 'always scoped to an athlete',
  `sender_id` int(10) unsigned NOT NULL,
  `sender_role` enum('athlete','coach','assistant_coach') COLLATE utf8_unicode_ci NOT NULL,
  `body` text COLLATE utf8_unicode_ci NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `read_at` datetime DEFAULT NULL,
  `push_sent` tinyint(1) NOT NULL DEFAULT '0',
  `message_type` enum('message','session_note','session_note_reply') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'message',
  `completed_workout_id` int(10) unsigned DEFAULT NULL COMMENT 'session thread origin',
  `planned_workout_id` int(10) unsigned DEFAULT NULL COMMENT 'session card link to a planned workout (available pre-completion)',
  `thread_id` int(10) unsigned DEFAULT NULL COMMENT 'self-referencing for session threads',
  `reply_count` int(11) NOT NULL DEFAULT '0' COMMENT 'session card: comments after the first (re-float counter)',
  PRIMARY KEY (`id`),
  KEY `idx_msg_athlete` (`athlete_id`,`sent_at`),
  KEY `idx_msg_unread` (`athlete_id`,`read_at`),
  KEY `idx_msg_planned` (`planned_workout_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `notification_preferences` (
  `user_id` int(10) unsigned NOT NULL,
  `notification_type` varchar(60) COLLATE utf8_unicode_ci NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `channel_push` tinyint(1) NOT NULL DEFAULT '1',
  `channel_email` tinyint(1) NOT NULL DEFAULT '0',
  `channel_sms` tinyint(1) NOT NULL DEFAULT '0',
  `quiet_hours_start` time NOT NULL DEFAULT '22:00:00',
  `quiet_hours_end` time NOT NULL DEFAULT '07:00:00',
  `preferred_time` time DEFAULT NULL COMMENT 'for scheduled notifications',
  `preferred_day` tinyint(4) DEFAULT NULL COMMENT '0-6 for weekly notifications',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`,`notification_type`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token` varchar(64) COLLATE utf8_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reset_token` (`token`),
  KEY `idx_reset_user` (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `personal_bests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `athlete_id` int(10) unsigned NOT NULL,
  `distance` enum('5K','10K','15K','half','marathon','ultra','mile','other') COLLATE utf8_unicode_ci NOT NULL,
  `distance_override` float DEFAULT NULL COMMENT 'for other distances',
  `time_seconds` int(11) NOT NULL COMMENT 'finish time in seconds',
  `source` enum('system','manual') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'manual',
  `race_id` int(10) unsigned DEFAULT NULL,
  `race_date` date DEFAULT NULL,
  `notes` text COLLATE utf8_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pb_athlete_distance` (`athlete_id`,`distance`),
  KEY `idx_pb_athlete` (`athlete_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `phone_verifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `phone_number` varchar(20) COLLATE utf8_unicode_ci NOT NULL,
  `code` varchar(10) COLLATE utf8_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pv_user` (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `planned_workouts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `plan_id` int(10) unsigned NOT NULL,
  `athlete_id` int(10) unsigned NOT NULL COMMENT 'denormalized for query speed',
  `scheduled_date` date NOT NULL,
  `workout_type` enum('easy','long','tempo','interval','hill','fartlek','race_pace','recovery','rest','cross_train','speed','plyometric','race') COLLATE utf8_unicode_ci DEFAULT NULL,
  `archetype_code` varchar(60) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'References workout_archetypes.code',
  `archetype_variant` varchar(60) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Variant code selected at generation time',
  `archetype_params` longtext COLLATE utf8_unicode_ci COMMENT 'JSON: resolved parameters (rep_count, duration, effort, etc.)',
  `workout_archetype_id` int(10) unsigned DEFAULT NULL COMMENT 'FK to workout_archetypes.id (snapshot; row may still exist if archetype is updated)',
  `archetype_version_snapshot` tinyint(3) unsigned DEFAULT NULL COMMENT 'Version of archetype at generation time for audit trail',
  `instance_signature` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Computed key used for anti-repeat detection (code|variant|params hash)',
  `structure` longtext COLLATE utf8_unicode_ci COMMENT 'JSON: resolved segment structure rendered from archetype structure_template',
  `display_title` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Athlete-facing workout title, generated once at plan creation',
  `display_summary` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Athlete-facing one-line summary (duration, distance range, rep count, etc.)',
  `athlete_instructions` text COLLATE utf8_unicode_ci COMMENT 'Athlete-facing workout description generated from archetype display.description_template',
  `description` text COLLATE utf8_unicode_ci COMMENT 'human-readable instructions',
  `target_distance` float DEFAULT NULL COMMENT 'miles or km',
  `target_duration` int(11) DEFAULT NULL COMMENT 'minutes',
  `target_pace_min` float DEFAULT NULL COMMENT 'min/mile lower bound',
  `target_pace_max` float DEFAULT NULL COMMENT 'min/mile upper bound',
  `target_hr_zone` tinyint(4) DEFAULT NULL COMMENT '1-5',
  `intensity_load` float DEFAULT NULL COMMENT 'training stress score',
  `coach_locked` tinyint(1) NOT NULL DEFAULT '0',
  `coach_edited_by` int(10) unsigned DEFAULT NULL,
  `coach_edited_at` datetime DEFAULT NULL,
  `visible_to_athlete` tinyint(1) NOT NULL DEFAULT '0',
  `pushed_to_watch` tinyint(1) NOT NULL DEFAULT '0',
  `pushed_at` datetime DEFAULT NULL,
  `intervals_event_id` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Intervals.icu calendar event id (srf_{id} upsert)',
  `original_scheduled_date` date DEFAULT NULL,
  `athlete_moved` tinyint(1) NOT NULL DEFAULT '0',
  `athlete_moved_at` datetime DEFAULT NULL,
  `must_off_override` tinyint(1) NOT NULL DEFAULT '0',
  `notes` text COLLATE utf8_unicode_ci COMMENT 'coach-facing notes',
  `cancelled` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'coach soft-deleted; day renders as rest',
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL COMMENT 'user_id of the coach who cancelled it',
  `added_by_role` varchar(32) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT '''assistant_coach'' when added by an assistant coach (coach-only AC badge)',
  `carried_over_from_plan_id` int(11) DEFAULT NULL COMMENT 'set when this row was carried into a regenerated plan from the prior plan',
  `carried_over_at` datetime DEFAULT NULL COMMENT 'when the row was carried over during a regen',
  PRIMARY KEY (`id`),
  KEY `idx_pw_plan` (`plan_id`),
  KEY `idx_pw_athlete_date` (`athlete_id`,`scheduled_date`),
  KEY `idx_pw_visible` (`athlete_id`,`visible_to_athlete`),
  KEY `idx_pw_archetype` (`archetype_code`),
  KEY `idx_pw_sig_athlete_date` (`athlete_id`,`instance_signature`,`scheduled_date`),
  KEY `idx_pw_cancelled` (`plan_id`,`cancelled`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `plan_approval_queue` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `plan_id` int(10) unsigned NOT NULL,
  `athlete_id` int(10) unsigned NOT NULL,
  `requested_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `request_reason` enum('onboarding','block_end','coach_manual','engine_rebuild') COLLATE utf8_unicode_ci NOT NULL,
  `status` enum('pending','approved','rejected','modified_and_approved') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'pending',
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `coach_notes` text COLLATE utf8_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `idx_paq_status` (`status`),
  KEY `idx_paq_athlete` (`athlete_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `plan_regeneration_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `athlete_id` int(11) NOT NULL,
  `requested_by` int(11) NOT NULL,
  `requested_at` datetime NOT NULL,
  `status` enum('pending','approved','dismissed') NOT NULL DEFAULT 'pending',
  `actioned_by` int(11) DEFAULT NULL,
  `actioned_at` datetime DEFAULT NULL,
  `notes` text,
  PRIMARY KEY (`id`),
  KEY `idx_prr_athlete` (`athlete_id`),
  KEY `idx_prr_status` (`status`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `plan_templates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `created_by` int(10) unsigned NOT NULL COMMENT 'coach user_id',
  `platform_wide` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'admin-promoted',
  `name` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `plan_type` enum('race_cycle','development_plan','maintenance_plan','recovery_block','return_to_running') COLLATE utf8_unicode_ci NOT NULL,
  `target_distance` enum('5K','10K','15K','half','marathon','ultra') COLLATE utf8_unicode_ci DEFAULT NULL,
  `cycle_length_weeks` int(11) NOT NULL,
  `phase_proportions` longtext COLLATE utf8_unicode_ci COMMENT 'base/build/peak/taper percentages',
  `week_structures` longtext COLLATE utf8_unicode_ci COMMENT 'array of week templates',
  `notes` text COLLATE utf8_unicode_ci COMMENT 'coach-facing usage notes',
  `use_count` int(11) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pt_created_by` (`created_by`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `push_subscriptions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `endpoint` text COLLATE utf8_unicode_ci NOT NULL,
  `p256dh` text COLLATE utf8_unicode_ci NOT NULL COMMENT 'client public key',
  `auth` text COLLATE utf8_unicode_ci NOT NULL COMMENT 'auth secret',
  `user_agent` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_push_user` (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `races` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `athlete_id` int(10) unsigned NOT NULL,
  `added_by` int(10) unsigned NOT NULL,
  `added_by_role` enum('athlete','coach','assistant_coach','admin') COLLATE utf8_unicode_ci NOT NULL,
  `race_name` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `race_distance` enum('5K','10K','15K','half','marathon','ultra','other','50k','50_miler','100k','100_miler') COLLATE utf8_unicode_ci NOT NULL,
  `distance_override` float DEFAULT NULL COMMENT 'miles, when distance=other',
  `distance_override_unit` enum('miles','km') COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'unit the athlete entered for an "other" distance',
  `race_date` date NOT NULL,
  `is_goal_race` tinyint(1) NOT NULL DEFAULT '0',
  `result_time` int(11) DEFAULT NULL COMMENT 'finish time in seconds',
  `result_synced_from_watch` tinyint(1) NOT NULL DEFAULT '0',
  `result_notes` text COLLATE utf8_unicode_ci COMMENT 'athlete free-text notes logged with the result',
  `recalibration_proposed` tinyint(1) NOT NULL DEFAULT '0',
  `recalibration_approved` tinyint(1) DEFAULT NULL,
  `recalibration_approved_by` int(10) unsigned DEFAULT NULL,
  `recalibration_approved_at` datetime DEFAULT NULL,
  `proposed_pace_zones` longtext COLLATE utf8_unicode_ci,
  `notes` text COLLATE utf8_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_races_athlete` (`athlete_id`),
  KEY `idx_races_date` (`race_date`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `scheduled_messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `athlete_id` int(10) unsigned NOT NULL,
  `sender_id` int(10) unsigned NOT NULL COMMENT 'coach user_id',
  `body` text COLLATE utf8_unicode_ci NOT NULL,
  `send_after` datetime NOT NULL,
  `sent` tinyint(1) NOT NULL DEFAULT '0',
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sm_pending` (`sent`,`send_after`),
  KEY `idx_sm_athlete` (`athlete_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `session_notes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `completed_workout_id` int(10) unsigned DEFAULT NULL COMMENT 'set once the workout is completed; NULL for pre-completion notes',
  `planned_workout_id` int(10) unsigned DEFAULT NULL COMMENT 'thread link to a planned workout (available pre-completion)',
  `athlete_id` int(10) unsigned NOT NULL,
  `author_id` int(10) unsigned NOT NULL,
  `author_role` enum('athlete','coach','assistant_coach') COLLATE utf8_unicode_ci NOT NULL,
  `body` text COLLATE utf8_unicode_ci NOT NULL,
  `soft_limit_chars` int(11) NOT NULL DEFAULT '500',
  `hard_limit_chars` int(11) NOT NULL DEFAULT '1000',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sn_workout` (`completed_workout_id`),
  KEY `idx_sn_athlete` (`athlete_id`),
  KEY `idx_sn_planned` (`planned_workout_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `stripe_webhook_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_id` varchar(64) COLLATE utf8_unicode_ci NOT NULL,
  `event_type` varchar(80) COLLATE utf8_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8_unicode_ci,
  `received_at` datetime NOT NULL,
  `processed` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_swl_event` (`event_id`),
  KEY `idx_swl_type` (`event_type`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `training_load` (
  `athlete_id` int(10) unsigned NOT NULL,
  `date` date NOT NULL,
  `atl` float NOT NULL DEFAULT '0' COMMENT 'acute training load (7-day)',
  `ctl` float NOT NULL DEFAULT '0' COMMENT 'chronic training load (42-day)',
  `tsb` float NOT NULL DEFAULT '0' COMMENT 'training stress balance (CTL - ATL)',
  `daily_stress` float NOT NULL DEFAULT '0' COMMENT 'training stress score for this day',
  `computed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`athlete_id`,`date`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `training_plans` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `athlete_id` int(10) unsigned NOT NULL,
  `plan_type` enum('race_cycle','development_plan','maintenance_plan','recovery_block','return_to_running') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'race_cycle',
  `rtr_current_stage` int(11) DEFAULT NULL COMMENT 'return_to_running run/walk stage 1-10; NULL for other plan types',
  `status` enum('pending_approval','active','archived','abandoned') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'pending_approval',
  `approved_by` int(10) unsigned DEFAULT NULL COMMENT 'coach user_id',
  `approved_at` datetime DEFAULT NULL,
  `plan_start_date` date DEFAULT NULL,
  `plan_end_date` date DEFAULT NULL,
  `goal_race_date` date DEFAULT NULL COMMENT 'snapshot at generation time',
  `generated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `generation_trigger` enum('onboarding','block_end','coach_manual','engine_rebuild') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'onboarding',
  `notes` text COLLATE utf8_unicode_ci COMMENT 'coach notes on this plan',
  `coach_generation_notes` longtext COLLATE utf8_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `idx_plans_athlete` (`athlete_id`),
  KEY `idx_plans_status` (`status`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'force a password change on next login',
  `role` enum('athlete','coach','assistant_coach','admin') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'athlete',
  `managed_by` int(11) DEFAULT NULL COMMENT 'head coach user_id for assistant coaches; NULL otherwise',
  `name` varchar(150) COLLATE utf8_unicode_ci NOT NULL,
  `theme_preference` enum('light','dark','system') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'system',
  `timezone` varchar(64) COLLATE utf8_unicode_ci NOT NULL DEFAULT 'America/New_York',
  `phone_number` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'E.164 format',
  `phone_verified` tinyint(1) NOT NULL DEFAULT '0',
  `signup_source` enum('invite','organic','ad_campaign','other') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'organic',
  `invite_code` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
  `consent_age` tinyint(1) NOT NULL DEFAULT '0' COMMENT '18+/parental consent confirmed at onboarding',
  `consent_privacy` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Privacy Policy agreed at onboarding',
  `consent_tos` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Terms of Service agreed at onboarding',
  `consent_tos_at` datetime DEFAULT NULL COMMENT 'when ToS consent was recorded',
  `consent_given_at` datetime DEFAULT NULL COMMENT 'when both consents were recorded',
  `deleted_at` datetime DEFAULT NULL COMMENT 'set when the account is anonymized by the retention cron',
  `stripe_customer_id` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
  `subscription_status` enum('none','trialing','active','past_due','canceled','comped') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'none',
  `subscription_end_date` date DEFAULT NULL,
  `billing_interval` enum('monthly','annual') COLLATE utf8_unicode_ci DEFAULT NULL,
  `grace_period_ends` date DEFAULT NULL,
  `ad_campaign_id` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'UTM campaign tag',
  `ad_source` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'UTM source e.g. instagram',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `active` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'deactivated accounts (0) cannot log in',
  `last_login_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role` (`role`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `watch_connections` (
  `athlete_id` int(10) unsigned NOT NULL,
  `platform` enum('garmin','polar','apple','wahoo') COLLATE utf8_unicode_ci NOT NULL,
  `access_token` text COLLATE utf8_unicode_ci NOT NULL COMMENT 'encrypted',
  `refresh_token` text COLLATE utf8_unicode_ci COMMENT 'encrypted',
  `token_expires_at` datetime DEFAULT NULL,
  `last_synced_at` datetime DEFAULT NULL,
  `sync_status` enum('active','error','disconnected') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'active',
  `error_message` text COLLATE utf8_unicode_ci,
  PRIMARY KEY (`athlete_id`,`platform`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `weekly_review_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `coach_id` int(11) NOT NULL,
  `week_start` date NOT NULL,
  `completed_at` datetime DEFAULT NULL,
  `items_reviewed` int(11) NOT NULL DEFAULT '0',
  `decisions_added` int(11) NOT NULL DEFAULT '0',
  `flags_actioned` int(11) NOT NULL DEFAULT '0',
  `flags_dismissed` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_coach_week` (`coach_id`,`week_start`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `workout_archetypes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(60) COLLATE utf8_unicode_ci NOT NULL COMMENT 'stable slug, e.g. continuous_easy',
  `version` tinyint(3) unsigned NOT NULL DEFAULT '1',
  `status` enum('active','inactive','draft') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'active',
  `name` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `workout_type` enum('easy','long','tempo','interval','hill','fartlek','race_pace','recovery','rest','cross_train','speed','plyometric') COLLATE utf8_unicode_ci NOT NULL,
  `mapped_templates` longtext COLLATE utf8_unicode_ci COMMENT 'JSON array of WL-xxx codes',
  `description` text COLLATE utf8_unicode_ci,
  `selection` longtext COLLATE utf8_unicode_ci NOT NULL COMMENT 'JSON: slot_types, phases, plan_types, goal_distances, min_classification, track_requirement, coach_clearance_required, requires, excludes',
  `weights` longtext COLLATE utf8_unicode_ci NOT NULL COMMENT 'JSON: phase/goal_distance/classification/plan_type weight maps (0-10)',
  `generation` longtext COLLATE utf8_unicode_ci NOT NULL COMMENT 'JSON: prescription_model, duration_source, progression_model, recovery_model, intensity_factor',
  `variants` longtext COLLATE utf8_unicode_ci COMMENT 'JSON array of {code, name, ...} variant objects',
  `parameters` longtext COLLATE utf8_unicode_ci COMMENT 'JSON: parameter definitions with workable/well_trained ranges',
  `structure_template` longtext COLLATE utf8_unicode_ci COMMENT 'JSON: segment structure template with {{token}} placeholders',
  `display` longtext COLLATE utf8_unicode_ci COMMENT 'JSON: lead_with, title_template, summary_template, description_template',
  `instance_signature` longtext COLLATE utf8_unicode_ci COMMENT 'JSON: field list used to identify a unique generated instance',
  `coach_notes` longtext COLLATE utf8_unicode_ci COMMENT 'JSON: intended_use string, special_rules array',
  `created_by` int(10) unsigned DEFAULT NULL COMMENT 'coach user_id; NULL = system archetype',
  `platform_wide` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'system archetypes always platform-wide; coach-created default 0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_archetype_code` (`code`),
  KEY `idx_archetype_status` (`status`),
  KEY `idx_archetype_type` (`workout_type`),
  KEY `idx_archetype_created_by` (`created_by`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `workout_library` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `library_code` varchar(10) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'e.g. WL-001',
  `name` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `athlete_facing_name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `workout_type` enum('easy','long','tempo','interval','hill','fartlek','race_pace','recovery','rest','cross_train','speed','plyometric') COLLATE utf8_unicode_ci NOT NULL,
  `phase_tags` longtext COLLATE utf8_unicode_ci COMMENT 'array: base, build, peak, taper',
  `distance_tags` longtext COLLATE utf8_unicode_ci COMMENT 'array: 5K, 10K, half, marathon',
  `prescription_type` enum('time','distance','count') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'time',
  `track_required` enum('yes','no','preferred') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'no',
  `secondary_stimulus` tinyint(1) NOT NULL DEFAULT '0',
  `long_run_embedded` tinyint(1) NOT NULL DEFAULT '0',
  `coach_clearance_required` tinyint(1) NOT NULL DEFAULT '0',
  `min_base_classification` enum('workable','well_trained') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'workable',
  `intensity_factor` float NOT NULL DEFAULT '0.5' COMMENT 'for training stress calculation',
  `structure` longtext COLLATE utf8_unicode_ci COMMENT 'warmup, main set, cooldown with targets',
  `description` text COLLATE utf8_unicode_ci COMMENT 'athlete-facing instructions',
  `engine_notes` text COLLATE utf8_unicode_ci COMMENT 'internal engine usage notes',
  `tags` longtext COLLATE utf8_unicode_ci,
  `created_by` int(10) unsigned DEFAULT NULL COMMENT 'coach user_id, null = system',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wl_type` (`workout_type`),
  KEY `idx_wl_code` (`library_code`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;


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
