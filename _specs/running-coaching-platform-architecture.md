# Running Coaching Platform — System Architecture & Project Brief
*Version 1.0 — Greenfield*

---

## 1. Product Overview

A scalable running coaching platform that algorithmically generates and manages individualized training plans for tens to hundreds of athletes, with human coach oversight. The engine — not AI — generates training. Coaches can override anything. Athletes see only a rolling window of their plan; coaches see everything.

**Business model:** $25–50/month per athlete. Minimal human intervention required, but coach tools are first-class.

---

## 2. Core Design Principles

1. **The engine generates, coaches approve.** Full plan rebuilds require coach sign-off. The engine can make minor rolling adjustments autonomously within defined guardrails.
2. **Actual training is a first-class input.** What the athlete *did* matters as much as what they were *told to do*. Watch data flows back into the engine on every cycle when available.
3. **Athletes see a window; coaches see everything.** Athletes have a rolling 7–10 day view. The full 4–12 week macro plan is visible only to coaches.
4. **Coach edits are sticky.** The engine does not overwrite workouts that a coach has manually edited. Locked workouts are routed around, not replaced.
5. **Universal guardrails for v1.** Safety rules apply equally to all athletes regardless of experience. Profile-influenced guardrails are a v2 feature.
6. **Iterative build.** Architecture should support incremental delivery. Ship Garmin integration first; layer in Polar, then Apple Watch.
7. **Watch integration is optional.** Athletes without a smartwatch can use the platform fully — manual workout logging and RPE self-report are first-class inputs. Watch sync is a value-add, not a requirement.
8. **Progressive Web App.** SimplyRunFaster is built as a PWA — a fully responsive website that is also installable on mobile home screens with push notification support. One codebase, no App Store dependency.
9. **In-app messaging.** Coaches and athletes communicate within the platform. All coaching communication is tied to the athlete's profile and training data. Asynchronous by design — coaches respond when available.

---

## 3. High-Level Architecture

```
┌─────────────────────────────────────────────────────┐
│                    Web Application                   │
│         (PHP + MySQL on NearlyFreeSpeech.net)        │
│                                                      │
│  ┌──────────────┐        ┌───────────────────────┐  │
│  │ Athlete Portal│        │    Coach Dashboard     │  │
│  │ - Rolling     │        │ - Full macro plan view │  │
│  │   7–10 day    │        │ - Plan approval queue  │  │
│  │   plan view   │        │ - Workout override UI  │  │
│  │ - Workout log │        │ - Athlete roster       │  │
│  │ - Onboarding  │        │ - Flag/alert review    │  │
│  └──────┬───────┘        └──────────┬────────────┘  │
│         │                           │                │
│         └───────────┬───────────────┘                │
│                     │                                │
│              ┌──────▼──────┐                         │
│              │  MySQL DB    │                         │
│              │ (core store) │                         │
│              └──────┬──────┘                         │
│                     │                                │
│         ┌───────────▼────────────┐                   │
│         │    Training Engine     │                    │
│         │  (PHP cron job /       │                    │
│         │   scheduled process)   │                    │
│         │                        │                    │
│         │  - Plan generator      │                    │
│         │  - Rolling adjuster    │                    │
│         │  - Guardrail enforcer  │                    │
│         │  - Compliance scorer   │                    │
│         │  - Flag/alert system   │                    │
│         └───────────┬────────────┘                   │
└─────────────────────┼───────────────────────────────┘
                      │
          ┌───────────▼────────────┐
          │   Watch Sync Layer      │
          │  (PHP + REST API calls) │
          │                         │
          │  Phase 1: Garmin        │
          │  Phase 2: Polar         │
          │  Phase 3: Apple/Health  │
          │  Phase 4: Wahoo         │
          └─────────────────────────┘
```

### Watch Sync is Bidirectional

- **Push:** Structured workouts → watch (pace targets, HR zones, intervals, duration)
- **Pull:** Completed workout data → database → engine (GPS, HR, pace, power, RPE if available)

---

## 4. Database Schema

### `athletes`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| user_id | INT FK | links to users table |
| coach_id | INT FK | assigned coach |
| onboarding_completed_at | DATETIME | triggers first plan build |
| status | ENUM | active, paused, churned |
| created_at | DATETIME | |

### `users`
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| email | VARCHAR | unique |
| password_hash | VARCHAR | |
| role | ENUM | athlete, coach, assistant_coach, admin |
| name | VARCHAR | |
| theme_preference | ENUM | light, dark, system — default: system |
| timezone | VARCHAR(64) | IANA tz id — default: America/New_York (migration_008). See §5.5 timezone model |
| phone_number | VARCHAR | nullable — E.164 format, used for SMS notifications |
| phone_verified | BOOL | default false — verified via SMS code before SMS notifications activate |
| signup_source | ENUM | invite, organic, ad_campaign, other |
| invite_code | VARCHAR | nullable — the invite code used at signup |
| ad_campaign_id | VARCHAR | nullable — UTM campaign tag for ad-driven signups |
| ad_source | VARCHAR | nullable — UTM source (e.g. instagram, facebook) |
| consent_age | BOOL | default false — 18+/parental consent confirmed at onboarding (migration_013) |
| consent_privacy | BOOL | default false — Privacy Policy agreed at onboarding (migration_013) |
| consent_given_at | DATETIME | nullable — when both consents were recorded (migration_013) |
| consent_tos | BOOL | default false — Terms of Service agreed at onboarding (migration_020); see §25 |
| consent_tos_at | DATETIME | nullable — when ToS consent was recorded (migration_020) |
| deleted_at | DATETIME | nullable — set when the account is anonymized by the 90-day retention cron (migration_013); see §25 |
| created_at | DATETIME | |

*Stripe/subscription columns (`stripe_customer_id`, `subscription_status`, `subscription_end_date`, `billing_interval`, `grace_period_ends`) also live on `users` — see §29. migration_013 grandfathers all users existing at migration time as consented (they predate the policy).*

### `athlete_profiles`
Populated by onboarding form(s). The engine reads this at plan generation time.

| Column | Type | Notes |
|---|---|---|
| athlete_id | INT FK | |
| goal_race_date | DATE | primary target race |
| goal_race_distance | VARCHAR | 5K, 10K, 15K, Half Marathon, Marathon, mile, and the ultra keys 50k / 50_miler / 100k / 100_miler. A Hyrox goal is stored as `mile` with `is_hyrox=1`. See engine spec §9b (ultra) and §9c (mile/Hyrox). |
| goal_finish_time | VARCHAR | optional time goal |
| ultra_surface | ENUM | road, trail (nullable) — populated only for ultra goal distances (migration_018); drives trail-vs-road archetype weighting + long-run cues |
| is_hyrox | BOOL | default false — Hyrox UI facade flag (migration_021); engine runs mile logic underneath, display shows "Hyrox" |
| hyrox_ever | BOOL | default false — latches to 1 the first time Hyrox is ever selected and never resets (migration_023). Keeps the Hyrox goal-distance pill visible in Training Settings after the athlete switches away to another goal. |
| current_weekly_mileage | FLOAT | self-reported at onboarding |
| training_days_per_week | INT | availability |
| long_run_day | TINYINT | preferred day of week (0=Sun); used when scheduling_preference='fixed' |
| primary_workout_day | TINYINT | preferred primary quality day (0=Sun); used when scheduling_preference='fixed' |
| must_off_days | LONGTEXT | JSON array of day-of-week ints (0=Sun) that can never be training days. Widened from VARCHAR(20) in migration_004. |
| scheduling_preference | ENUM | fixed, flex (default flex) |
| track_access | ENUM | yes, no, road_reps_ok (default road_reps_ok) |
| peak_weekly_minutes | INT | highest-ever weekly time on feet (serves the "highest weekly volume" field — no separate column was added) |
| peak_volume_ceiling_mins | INT | coach-set weekly ceiling the engine never exceeds |
| experience_level | ENUM | beginner, intermediate, advanced |
| base_classification | ENUM | well_trained, workable, insufficient (nullable). Engine-computed cache, **written on every plan generation** (`PlanGenerator::generate`) from goal distance + current volume; not an onboarding input. See engine spec §5. |
| injury_history | TEXT | free text or structured JSON |
| hr_zones | JSON | if available from watch data |
| pace_zones | JSON | derived via McMillan/Riegel math; see §26 provenance and engine spec §2. Shape: seconds/mile per zone. |
| typical_easy_pace_min | INT | faster end of typical easy-day pace (seconds/mile); basis for the easy-pace pace-zone estimate |
| typical_easy_pace_max | INT | slower end of typical easy-day pace (seconds/mile) |
| pace_zones_source | ENUM | race_result, easy_pace_estimate, manual (nullable). See §26. |
| watch_platform | ENUM | garmin, polar, apple, wahoo, none |
| watch_connected | BOOL | default false |
| pace_zones_visible | BOOL | default true — coach/admin can hide from athlete UI |
| pace_zones_hidden_reason | TEXT | nullable — internal coach note explaining why zones are hidden |
| cross_training_bike | ENUM | none, stationary, road_gravel |
| cross_training_elliptical | ENUM | none, gym, home |
| cross_training_pool | BOOL | default false |
| cross_training_other | TEXT | nullable — free text |
| updated_at | DATETIME | bumped on every profile save |

> **Note:** `sql/schema.sql` is the canonical column list (this table is a high-level overview). `current_weekly_mileage` above is historical naming — the actual column is `current_weekly_minutes` (all volume is time-on-feet in minutes). `workout_day_preference` was specified but **never implemented** and does not exist in production; it is superseded by `long_run_day` + `primary_workout_day`. See §31 for the editing UI.

### `training_plans`
One plan per athlete at a time. Previous plans are archived, not deleted.

| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| athlete_id | INT FK | |
| status | ENUM | pending_approval, active, archived, abandoned |
| approved_by | INT FK | coach user_id |
| approved_at | DATETIME | |
| plan_start_date | DATE | |
| plan_end_date | DATE | |
| goal_race_date | DATE | snapshot at generation time |
| generated_at | DATETIME | |
| generation_trigger | ENUM | onboarding, block_end, coach_manual, engine_rebuild |
| notes | TEXT | coach notes on this plan |

**`training_plans.status` value semantics:** `pending_approval` means the plan has been generated and is queued for coach review. Once a coach activates it, status moves to `active`. There is no separate `approved` value on this table. This is distinct from `plan_approval_queue.status`, which records the coach's review decision (`pending`, `approved`, `rejected`, `modified_and_approved`) as an audit event. The two tables serve different purposes: `plan_approval_queue` captures the workflow history; `training_plans.status` reflects the plan's current operational state. Code that needs to identify "plans an athlete is currently executing or has recently had reviewed" should query `training_plans.status IN ('pending_approval', 'active')`.

### `planned_workouts`
The full macro plan. One row per day with a workout.

| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| plan_id | INT FK | |
| athlete_id | INT FK | denormalized for query speed |
| scheduled_date | DATE | |
| workout_type | ENUM | easy, long, tempo, interval, race_pace, recovery, rest, cross_train |
| archetype_code | VARCHAR | nullable; stable code slug identifying the archetype (e.g. `tempo_intervals`, `sustained_hill_repeats`) |
| archetype_variant | VARCHAR | nullable; variant code within archetype |
| archetype_params | JSON | nullable; resolved parameter values at generation time |
| workout_archetype_id | INT FK | nullable; foreign key to workout_archetypes |
| archetype_version_snapshot | TINYINT | nullable; archetype version at generation time |
| instance_signature | VARCHAR | nullable; pipe-delimited fingerprint of key resolved parameters — used to enforce anti-repeat hard-block window |
| structure | JSON | nullable; fully rendered workout structure |
| display_title | VARCHAR | nullable; engine-generated title (e.g. "6 × 90 sec Hill Repeats") — non-editable, regenerated on plan rebuild |
| display_summary | VARCHAR | nullable; engine-generated subtitle (e.g. "30 min · 2.5–3 miles") — non-editable |
| athlete_instructions | TEXT | nullable; engine-generated instructional paragraph; the field coaches edit to annotate or replace the description |
| description | TEXT | legacy field; active for return_to_running and recovery_block workouts; superseded by athlete_instructions for archetype workouts |
| target_distance | FLOAT | miles or km |
| target_duration | INT | minutes |
| target_pace_min | FLOAT | min/mile lower bound |
| target_pace_max | FLOAT | min/mile upper bound |
| target_hr_zone | TINYINT | 1–5 |
| intensity_load | FLOAT | pre-computed at generation time as `target_duration × effective_intensity_factor`, where effective_intensity_factor is `resolved_variant.intensity_factor` if the selected variant specifies one, otherwise `archetype.generation.intensity_factor`; read by TrainingLoad to derive intensity_factor for ATL/CTL/TSB computation |
| coach_locked | BOOL | engine will not overwrite if true |
| coach_edited_by | INT FK | nullable |
| coach_edited_at | DATETIME | nullable |
| visible_to_athlete | BOOL | true when within rolling window |
| pushed_to_watch | BOOL | |
| pushed_at | DATETIME | nullable |
| notes | TEXT | coach-facing notes |

### `completed_workouts`
Actual training data pulled from watch or manually logged.

| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| athlete_id | INT FK | |
| planned_workout_id | INT FK | nullable; matched by date |
| source | ENUM | garmin, polar, apple, wahoo, manual |
| external_activity_id | VARCHAR | platform's own ID |
| activity_date | DATE | |
| workout_type | VARCHAR | as classified by platform |
| actual_distance | FLOAT | |
| actual_duration | INT | minutes |
| avg_pace | FLOAT | min/mile |
| avg_hr | INT | bpm |
| max_hr | INT | bpm |
| hr_zones_breakdown | JSON | time in each zone |
| elevation_gain | FLOAT | |
| power_avg | INT | watts, if available |
| rpe | TINYINT | 1–10, if self-reported |
| raw_data | JSON | full platform payload for future use |
| compliance_score | FLOAT | calculated; 0–1 vs planned workout |
| synced_at | DATETIME | |

### `training_load`
Computed daily. Drives the engine's fatigue/fitness model (ATL/CTL).

| Column | Type | Notes |
|---|---|---|
| athlete_id | INT FK | |
| date | DATE | |
| atl | FLOAT | acute training load (7-day) |
| ctl | FLOAT | chronic training load (42-day) |
| tsb | FLOAT | training stress balance (CTL - ATL) |
| computed_at | DATETIME | |
| PRIMARY KEY | (athlete_id, date) | |

### `engine_flags`
The engine raises flags; coaches review and dismiss them.

| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| athlete_id | INT FK | |
| flag_type | ENUM | missed_workouts, hr_elevated, load_spike, compliance_low, plan_rebuild_needed, compliance_trend, compliance_pattern, excessive_fatigue, fitness_decline, taper_concern, insufficient_base, return_to_running_discomfort, limited_development_opportunity, long_run_day_conflict, display_generation_incomplete, profile_updated, pace_zones_missing |
| severity | ENUM | info, warning, critical |
| flag_date | DATE | |
| details | JSON | machine-readable context |
| message | TEXT | human-readable summary for coach — canonical column name is `message` |
| status | ENUM | open, dismissed, acted_on |
| reviewed_by | INT FK | nullable |
| reviewed_at | DATETIME | nullable |

**Flag deduplication:** Before inserting any engine flag, the engine checks for an existing open flag of the same `flag_type` for the same athlete and skips the insert if one is found. This applies to all engine-raised flags at generation and evaluation time — a given flag type will appear at most once in the open state per athlete. **Exceptions (inserted without deduplication):**
- **`display_generation_incomplete`** — each plan generation raises its own flag independently, because display completeness is plan-specific. See engine spec §18.8.
- **`profile_updated`** — each profile save is a distinct event the coach should see individually; never merged into an existing open flag. See §31 (Profile Editing).

### `plan_approval_queue`
Holds generated plans pending coach review before activation.

| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| plan_id | INT FK | |
| athlete_id | INT FK | |
| requested_at | DATETIME | |
| request_reason | ENUM | onboarding, block_end, coach_manual, engine_rebuild |
| status | ENUM | pending, approved, rejected, modified_and_approved |
| reviewed_by | INT FK | nullable |
| reviewed_at | DATETIME | nullable |
| coach_notes | TEXT | |

### `workout_library`
Legacy template table seeded from initial workout library (WL-001 through WL-023, documented in the Workout Library document). **This table is no longer joined by AthleteController, CoachController, or TrainingLoad.php for archetype-generated workouts.** It remains in the database for historical reference and as a seed source for initial content. The archetype engine (workout_archetypes table + ArchetypeSelector) is the active workout prescription layer. Archetype/variant swapping UI was removed in Milestone 3.5 and will be redesigned in a future milestone. *(June 2026)* The coach **Library page (`/app/coach/library`) no longer reads `workout_library` at all** — it is now a read-only browser over the active `workout_archetypes` (see §8, "Workout Library — archetype browser"); the old `workout_library` template list + "Add template" creation UI were removed.

| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| name | VARCHAR | e.g. "6x800 at 5K pace" |
| workout_type | ENUM | matches planned_workouts |
| phase | ENUM | base, build, peak, taper |
| distance_target | VARCHAR | |
| structure | JSON | intervals, rest periods, warmup/cooldown |
| description | TEXT | |
| tags | JSON | e.g. ["track", "vo2max", "tuesday"] |
| created_by | INT FK | coach user_id |

### `invite_links`
Coach or admin-generated invite links for athlete onboarding.

| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| code | VARCHAR | unique random token |
| created_by | INT FK | user_id of coach or admin who generated it |
| assigned_coach_id | INT FK | coach pre-assigned to athlete who uses this link |
| coupon_code | VARCHAR | nullable — Stripe coupon to apply at signup |
| expires_at | DATETIME | default 7 days from creation, configurable |
| used_at | DATETIME | nullable — set when athlete completes signup |
| used_by | INT FK | nullable — athlete user_id |
| max_uses | INT | default 1, configurable for batch invites |
| use_count | INT | default 0 |
| notes | VARCHAR | coach-facing label e.g. "for Sarah from track club" |
| deactivated_at | DATETIME | nullable — set when a coach manually disables the link (migration 016) |

*Deactivation (shipped — June 2026):* each active link in the Invite Athletes panel has a **Deactivate** button (used/expired/inactive links show a muted status label only). `POST /app/coach/invites/deactivate` → `CoachController::deactivateInvite()` (owner-scoped, CSRF, idempotent: sets `deactivated_at` where still NULL; not gated on `use_count`, so a partially-used multi-use link can still be killed). `AuthController::getValidInvite()` filters `deactivated_at IS NULL`, so a deactivated link can no longer onboard anyone and the invite landing page shows "This invite link is no longer active."

### `personal_bests`
Tracks current PB per distance. Auto-updated when a new race result beats the existing PB. Manual entries accepted at onboarding and thereafter.

| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| athlete_id | INT FK | |
| distance | ENUM | 5K, 10K, 15K, half, marathon, ultra, mile, other |
| distance_override | FLOAT | nullable — for other distances |
| time_seconds | INT | finish time in seconds |
| source | ENUM | system, manual — system = verified from race result |
| race_id | INT FK | nullable — links to races table if system-verified |
| race_date | DATE | |
| notes | TEXT | nullable — e.g. "Boston 2019" |
| created_at | DATETIME | |
| PRIMARY KEY candidate | (athlete_id, distance) | engine keeps only the fastest per distance |

### `races`
Tune-up and goal races added by coach or athlete.

| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| athlete_id | INT FK | |
| added_by | INT FK | user_id of coach or athlete who added it |
| added_by_role | ENUM | athlete, coach, assistant_coach, admin (assistant_coach added migration_025) |
| race_name | VARCHAR | free text |
| race_distance | ENUM | 5K, 10K, 15K, half, marathon, ultra, other + the ultra keys 50k / 50_miler / 100k / 100_miler (migration_019) |
| distance_override | FLOAT | nullable — miles, used when distance = other |
| distance_override_unit | ENUM | miles, km (nullable) — the unit the athlete entered for an "other" distance (migration_019) |
| race_date | DATE | |
| is_goal_race | BOOL | default false. Marking a goal race syncs `athlete_profiles.goal_race_date`/`goal_race_distance`. The goal race is also represented as an `is_goal_race=1` row here; migration_025 backfilled one such row for existing athletes who had a profile goal but no `races` row (race_name 'Goal Race'; distance label mapped to the ENUM, unmappable mile/Hyrox stored as 'other'). |
| result_time | INT | nullable — finish time in seconds, set after race |
| result_synced_from_watch | BOOL | default false |
| result_notes | TEXT | nullable — athlete's free-text notes logged with the result (migration_019; distinct from coach `notes`) |
| recalibration_proposed | BOOL | default false |
| recalibration_approved | BOOL | nullable |
| recalibration_approved_by | INT FK | nullable |
| recalibration_approved_at | DATETIME | nullable |
| proposed_pace_zones | JSON | nullable — engine-computed zones from result |
| notes | TEXT | nullable — coach notes on this race |
| created_at | DATETIME | |
| updated_at | DATETIME | nullable — touched on result logging / recalibration (migration_019) |

The race-management feature (athlete + coach entry, terracotta calendar pills, conflict
warnings, pre/post-race engine adjustments, post-race result + pace recalibration) is
implemented as described in **§26**.

### `watch_connections`
OAuth tokens and sync state per platform per athlete.

| Column | Type | Notes |
|---|---|---|
| athlete_id | INT FK | |
| platform | ENUM | garmin, polar, apple, wahoo |
| access_token | TEXT | encrypted |
| refresh_token | TEXT | encrypted |
| token_expires_at | DATETIME | |
| last_synced_at | DATETIME | |
| sync_status | ENUM | active, error, disconnected |
| error_message | TEXT | nullable |

### `device_notify_preferences` *(implemented — migration 011)*
Per-athlete opt-in to be notified when a wearable brand's integration ships (see Section 6). A row's presence with `notify = 1` means the athlete wants a heads-up; disabling the Settings toggle deletes the row. MariaDB-safe (utf8 / InnoDB).

| Column | Type | Notes |
|---|---|---|
| user_id | INT FK → users(id) | |
| brand | VARCHAR(32) | garmin, coros, polar, suunto |
| notify | TINYINT(1) | default 0; 1 when opted in |
| updated_at | DATETIME | |
| PRIMARY KEY | (user_id, brand) | |

### `account_deletions` *(implemented — migration_013)*
Audit log written by the 90-day data-retention cron (`scripts/cron_delete_expired_accounts.php`, see §25). One row per anonymized account. MariaDB-safe (utf8 / InnoDB).

| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| user_id | INT | the anonymized user (the `users` row is kept, not hard-deleted) |
| deleted_at | DATETIME | when anonymization ran |
| reason | VARCHAR(64) | `90_day_post_cancellation` or `90_day_incomplete_onboarding` |

---

## 5. The Training Engine

### 5.1 Plan Generation (Macro — 4–12 Weeks)

**Triggers:**
- Athlete completes onboarding (automatic, first plan)
- End of a training block (engine-initiated, requires approval)
- Coach manually requests rebuild
- Engine determines current plan is unrecoverable (raises `plan_rebuild_needed` flag → coach approves)

**Process:**
1. Read `athlete_profiles` (goals, availability, current fitness)
2. Read `training_load` history (CTL/ATL trends)
3. Read recent `completed_workouts` (compliance, actual fitness)
4. Determine training phase and block structure (base → build → peak → taper)
5. For each plan slot, select an archetype via `ArchetypeSelector` (eligibility rules + weighted random pick), resolve parameters to concrete values, and render athlete-facing display text
6. Write `planned_workouts` rows with archetype metadata, rendered display fields, and `intensity_load = target_duration × archetype.intensity_factor` (pre-computed at generation time)
7. Validate weekly totals against guardrails
8. Insert record into `plan_approval_queue` with status `pending`
9. Notify assigned coach

**All generated plans sit in `pending` status until a coach approves them.** Athletes see nothing until approval.

### 5.2 Rolling Adjustment (Post-Workout, Autonomous)

Runs after each completed workout is synced. Operates only within the visible 7–10 day window and only within guardrail limits. Does not touch `coach_locked` workouts.

**Permitted autonomous adjustments:**
- Swap tomorrow's easy run → rest day (if compliance_score < threshold or ATL spike detected)
- Reduce tomorrow's target distance by up to 15%
- Downgrade workout intensity (e.g., tempo → easy) if HR data suggests excess fatigue
- Shift a non-locked workout forward 1 day within the window

**Not permitted autonomously (raises flag for coach review):**
- Removing a quality session entirely
- Adding volume
- Moving goal race date
- Any change to a `coach_locked` workout
- Any change outside the 10-day window

### 5.3 Weekly Re-evaluation (Sunday Night Batch Job)

Runs once per week for each active athlete.

1. Score the week: compliance, load absorbed, deviation from plan
2. Recompute ATL/CTL/TSB
3. Evaluate whether macro plan trajectory is still valid
4. If yes: minor adjustments to upcoming weeks (within guardrails), no approval needed
5. If no: raise `plan_rebuild_needed` flag, notify coach

### 5.4 Universal Guardrails (v1)

These are hard rules the engine cannot violate autonomously:

| Rule | Limit |
|---|---|
| Weekly volume increase | Max 10% week-over-week |
| Weekly volume decrease (cutback) | Max 20% (planned cutback weeks excepted) |
| Quality sessions per week | Max 2 (tempo, interval, race_pace) |
| Long run as % of weekly volume | Max 35% |
| Consecutive hard days | Max 2 before a mandatory easy/rest day |
| Minimum rest days per week | 1 |
| Taper minimum duration | 2 weeks before goal race |
| Max plan length | 20 weeks |
| Min plan length | 4 weeks |

Any adjustment that would violate a guardrail is blocked. The engine either finds an alternative adjustment or raises a flag for coach review.

### 5.5 Timezone Model

The server, database, and all PHP date math run in **UTC**. Every stored `DATETIME`/`TIMESTAMP` (message `sent_at`, flag `created_at`, `coach_edited_at`, …) is a UTC instant; calendar `DATE` columns (`scheduled_date`, `plan_start_date`, `activity_date`, …) are stored as the athlete's local calendar date. There is **no per-row timezone column** and no schema migration of stored values — conversion happens at read/write time in PHP.

- **Per-user preference.** `users.timezone` holds an IANA identifier (default `America/New_York`, migration_008). Athletes set their own on the regular Settings page (`/app/settings`); a coach can override an athlete's on the athlete edit page (COACH CONTROLS); a coach sets their own display timezone on coach Settings.
- **Shared helper.** `src/Timezone.php` is the single conversion point — `forUser()/zone()`, `toLocal()`/`format()` for displaying UTC instants, `today()`/`tomorrow()`/`dateInZone()` for local calendar dates, and `selectOptions()`/`label()` for the curated ~25-zone dropdown (offsets computed live so DST is reflected at render). Invalid/unknown stored zones fall back to the default silently. Controllers and views call the helper rather than constructing `DateTime` inline.
- **Display.** UTC instants are converted to the **viewing user's** timezone (athlete pages → athlete; coach pages → coach). When a coach views an athlete's plan, dates are anchored on the **athlete's** timezone (the plan is the athlete's), with a small label shown when the athlete's zone differs from the coach's. Calendar `DATE` columns are rendered as-is (a date is a date — never tz-shifted).
- **Generation day boundaries.** `plan_start_date` ("tomorrow") is computed in the **athlete's** timezone, not the server's UTC — a UTC midnight may be a different calendar day locally. The daily rolling-window cron (hour 5 UTC) groups active-plan athletes by timezone and opens each group's window against that zone's local *today* + `ATHLETE_WINDOW_DAYS`.

---

## 6. Watch Integration (Optional — Athlete Value-Add)

**Watch integration is entirely optional for athletes.** Athletes without a watch can log workouts manually and self-report RPE. The engine functions fully with manual data. Watch sync is a value-add that improves data quality and athlete convenience, not a platform requirement.

### Strava Integration — Status: Pending API Approval

Strava's API would provide a universal pull layer — any watch platform that syncs to Strava (Garmin, Polar, COROS, Suunto, Apple Watch, phone-based) would feed into SimplyRunFaster automatically. This would significantly broaden compatibility without requiring direct integrations with every platform.

**However:** Strava's production API approval process is notoriously slow and opaque. Approval is not guaranteed and may take months or never arrive. SimplyRunFaster should apply for Strava API access immediately upon beta launch but must not be architecturally dependent on it.

**Application strategy:** Submit Strava API application at beta launch with real usage data from initial cohort. If approved before general availability, Strava becomes the primary pull integration. If not approved, direct watch integrations cover the majority of the target athlete demographic.

**Architecture decision:** Build direct watch integrations as the primary data layer. Strava is a parallel application in progress, not a milestone dependency.

### Connected Devices — "Notify me when available" *(implemented — June 2026)*

The athlete Settings page surfaces a **Connected Devices** section. Its primary row is **Intervals.icu** (connect / disconnect / "Sync workouts now" — see §6). The four direct-integration brands (Garmin, COROS, Polar, Suunto) appear below it as a build-order roadmap, each with a "Coming soon" badge (no per-brand toggle).

- **When connected to Intervals.icu**, the brand rows are hidden entirely — the athlete already syncs every supported watch through Intervals.icu, so per-brand rows would only confuse.
- **When not connected**, the brand rows show, followed by a single opt-in: a *"Notify me →"* link that reveals one checkbox — *"Notify me when direct watch integrations launch."*
- The opt-in persists as a **single `device_notify_preferences` row** with `brand = 'all'` (one preference covering every brand), saved via AJAX `POST /app/settings/devices/notify` `{ brand: 'all', enabled }` → `AthleteController::saveDeviceNotifyPreference()` (athlete auth + CSRF; the brand allowlist includes `all`). Pre-populated on load from `loadDeviceNotifyPrefs()`. Interest capture only — when a direct integration ships, the stored opt-ins seed the availability announcement. *(Earlier per-brand toggles were replaced by this single opt-in; legacy per-brand rows are harmless and no longer surfaced.)*

### Phase 1: Intervals.icu (Watch Integration Layer) *(implemented & deployed)*

Rather than integrate each watch brand's API directly first, **Intervals.icu is the Phase 1 watch integration layer.** Athletes connect their watch to a free Intervals.icu account, then connect Intervals.icu to SimplyRunFaster via OAuth — one integration covering **Garmin, COROS, Polar, Suunto, Wahoo, Amazfit, Apple Watch, and Huawei**. We push structured workouts to the athlete's Intervals.icu calendar (which syncs to the watch) and pull completed runs back. Direct per-brand integrations are **Phase 2+** (below), pursued only if a brand needs richer fidelity than Intervals.icu provides.

**OAuth & tokens.** OAuth 2.0, scope `ACTIVITY:READ,CALENDAR:WRITE` (`IntervalsService::getAuthUrl()` / `exchangeCode()`). Access tokens are stored **encrypted at rest** — AES-256-GCM via `src/Crypto.php`, keyed by `APP_ENCRYPTION_KEY` (64-hex / 32-byte key, set only in `config/config.local.php`) — in `intervals_connections`. Intervals.icu OAuth returns no refresh token and tokens don't expire per current docs. Athletes connect / disconnect (and re-sync) from Settings → Connected Devices; connect/callback are CSRF-protected.

**Schema (`migration_014`).** `intervals_connections` (one row per user, encrypted token), `intervals_push_log`, `intervals_webhook_log`; plus `planned_workouts.intervals_event_id`, `completed_workouts.source_device`, the `'intervals'` value on the `completed_workouts.source` enum, and a `(source, external_activity_id)` uniqueness key. NOTE: the production DB is **MyISAM throughout**, so these tables are created **MyISAM with plain indexes and no foreign keys** — an InnoDB child → MyISAM parent FK fails to create, and the rest of the schema is FK-free anyway.

**Push (plan → watch).** `planned_workouts.structure` JSON → Intervals.icu native workout text (`generateWorkoutText()`), upserted as a calendar `WORKOUT` event keyed by stable `external_id = srf_{planned_workout_id}` (re-pushing moves/updates the same event — never duplicates). Text rendering:
- **Effort language, not zone labels** — `easy` / `moderate effort` / `tempo effort` / `interval effort` / `speed effort` (no Z1–Z6). Easy running is time-on-feet only (§3); hills are effort-based and carry cue text only (§18.5).
- **Pace-range citations on quality steps only** (§18.9), drawn from the athlete's *visible* `pace_zones`, e.g. `- 11m tempo effort (7:33–7:54/mi)`; omitted when zones are hidden/empty. Tempo → 10K–half band; intervals/speed → nearest of 5K/mile/800/400 ±5s; mixed → mile–5K; fartlek → 5K–10K.
- Rep distances render in **miles** (`0.37mi`); durations as `15m` / `90s → 1m30s`; warmup **strides are folded into the Warmup section** as an inline `Nx` block.
- Triggers: a workout entering the visible window (`cron_update_visibility.php`), plan approval (`approvePlan` → `pushNewlyVisible`), and coach add / reschedule / remove. When a plan is **archived** (regeneration or rejection) its events are bulk-deleted via `deleteEventsForPlan()` (`PlanGenerator::archivePreviousPlans()` and `CoachController::rejectPlan()`).

**Pull (watch → log).** Webhook `POST /webhook/intervals` (shared-secret verified, every event logged to `intervals_webhook_log`) on `ACTIVITY_UPLOADED` / `ACTIVITY_ANALYZED`, plus a 30-day backfill on connect. Run activities map to `completed_workouts` (`source='intervals'`), idempotent on the `(source, external_activity_id)` key, then the post-completion pipeline runs (compliance, training-load recompute, RPE prompt, return-to-running progression). Unmatched activities insert as unplanned and raise an `unmatched_activity` info flag.

**Manual re-sync.** `IntervalsService::repushAllVisible($userId, $db)` re-pushes every visible, non-cancelled workout in the active plan (upsert, each logged to `intervals_push_log`). Exposed two ways: coaches via `POST /app/integrations/intervals/repush` (their own athletes); athletes via a **"Sync workouts now"** button in Settings → `POST /app/integrations/intervals/sync-athlete`.

**Routes / config.** `GET /app/integrations/intervals/connect` · `GET …/callback` · `POST …/disconnect` · `POST …/repush` · `POST …/sync-athlete` · public `GET …/guide` (setup walkthrough, `views/static/intervals_setup.php`). Secrets `INTERVALS_CLIENT_ID` / `INTERVALS_CLIENT_SECRET` / `INTERVALS_WEBHOOK_SECRET` / `INTERVALS_REDIRECT_URI` are empty placeholders in `config/config.php`; real values live only in `config/config.local.php`.

### Phase 2+: Garmin (direct)

**APIs:**
- Garmin Health API — push structured workouts, pull completed activity data
- OAuth 2.0 for athlete authorization

**Push format:** Garmin accepts structured workouts with steps (warmup, intervals, cooldown), pace/HR targets per step. Map `planned_workouts.structure` JSON to Garmin's workout schema.

**Pull format:** Webhook or polling for completed activities. Map to `completed_workouts`.

**Sync schedule:** Pull runs every 2 hours. Push happens when a workout enters the athlete's visible window (T-1 day by default) or immediately on approval if within window.

**Market rationale:** Garmin has the largest share of serious recreational runners by a significant margin. Building Garmin first covers the majority of the target demographic independently of Strava approval.

### Phase 2: Polar
Polar Accesslink API. Similar push/pull model. Add after Garmin loop is validated end-to-end.

### Phase 3: COROS
COROS API is developer-friendly with no significant approval friction. Growing rapidly in the serious runner market. Add after Polar — covers meaningful additional market segment without Strava dependency.

### Phase 4: Apple Watch / HealthKit
Requires a companion iOS app or HealthKit integration via a third-party bridge. Most complex — defer to Phase 4. Primarily pull-only without a native app. Evaluate demand before building.

### Phase 5: Strava (if approved)
If Strava API approval is granted, add as a universal pull layer. Athletes connect Strava instead of or in addition to direct watch integration. Does not replace direct integrations — Strava cannot push structured workouts to watches.

### Phase 6: Wahoo
ELEMNT ecosystem. Running support is limited — evaluate demand before committing to build.

---

## 7. Athlete-Facing Rolling Window

- Athlete UI shows workouts for the next 7–10 days only.
- `planned_workouts.visible_to_athlete` is set to `true` by a daily cron job as dates enter the window.
- Watch push is triggered at the same time (or slightly ahead).
- Completed workouts display alongside planned workouts in the log view (what was planned vs. what was done).
- Athletes cannot edit planned workouts. They can log RPE, notes, or manually log a workout. Manual logging is a fully supported first-class flow, not a fallback.

**Today dashboard — "Your Stats" (2026-06-16):** The athlete Today view (`views/athlete/today.php`, rendered by `AthleteController::today()`) "Your Stats" section shows **last-30-days running stats** rather than the engine load model (the former Fitness/CTL, Fatigue/ATL, Form/TSB cards were removed, along with the dashboard-only `training_load` query). Three cards over completed running workouts in the last 30 days (`completed_workouts`, `workout_type` filtered to running types, `activity_date` within window): **Days Run** (`COUNT(DISTINCT activity_date)`), **Time Running** (`SUM(actual_duration)` shown `H:MM`), and **Volume** (`SUM(actual_distance)`, shown `mi`, or `km` when `athlete_profiles.units = 'km'`, 1 decimal). All three show "—" when there are no running workouts in the window. CTL/ATL/TSB remain computed and stored (`training_load`) and are still used by the coach view and progress page — only the athlete dashboard surface changed.

**Today dashboard — "This Week" accordion (2026-06-17):** Each "This Week" row is a tappable, **CSS-only** accordion (hidden checkbox + `<label>`, no JS). Collapsed shows the existing day/badge/duration plus a chevron; expanded reveals a recessed card with `display_title`, `display_summary` (muted), `athlete_instructions`, and a "Log this workout →" link on today's row only, with a 200ms `max-height` ease and a rotating chevron. Self-contained in `today.php` (inline `<style>`); the data is already present because `getVisibleWorkouts()` selects `pw.*`.

---

## 8. Coach Dashboard

Coaches see:
- Full macro plan (all weeks, all workouts)
- Inline edit controls on any workout (sets `coach_locked = true`)
- Plan approval queue (approve / reject / modify)
- Engine flags queue (dismiss / act on)
- Athlete roster with compliance sparklines and load trends
- Ability to manually trigger plan rebuild

Coach accounts are tagged `role = coach` in `users`. A coach can be assigned to multiple athletes. An athlete has one assigned coach.

**Plan week display — partial first/last weeks:** The coach dashboard and approval calendar group workouts into Mon–Sun calendar weeks. When a plan starts on a day other than Monday (the default: plans start on Sunday, the long-run day), the first UI week contains only the workouts falling within that Mon–Sun window — typically just Sunday's long run. The last UI week is similarly partial if the plan ends before Saturday. This is expected display behavior, not a generation artifact: the underlying plan generator computes volume per internal code-week (7-day blocks from plan start), but the UI groups by calendar week. A plan starting Sunday June 14 will show UI week 1 as a single long run (60 min) and UI week 2 as the first full 3-day training week.

**Implementation status (2026-06-14):** The coach individual-athlete view renders the full macro plan for the current active or pending-approval plan underneath the existing workout card list. It groups rows into Mon–Sun UI weeks, shows blank outside-plan cells for partial first/last weeks, labels phase and cutback status, displays workout type/duration/coach lock/compliance dots, and expands day cells read-only to show title, duration, summary, and athlete instructions. Full macro-plan inline editing remains deferred beyond the existing simple edit endpoint; that endpoint sets `coach_locked = true` when a coach saves an override. The Screen 3 right-context sidebar (pace zones, PBs, load, flags, billing, quick actions) remains future work.

**Mobile responsiveness (2026-06-16):** The coach individual-athlete view (`views/coach/athlete_view.php`) is now fully responsive at ≤768px — the desktop sidebar+main grid collapses to a single full-width column with no horizontal overflow. The macro-plan calendar switches from the wide horizontally-scrolling 7-column grid to stacked full-width day rows (date left, workout badge/duration right; rest days muted); profile rows stack label-above-value; the message preview wraps; alert cards stack their header above the message; and action/dismiss buttons and workout tap targets meet a 44px minimum. Desktop layout (≥1024px) is unchanged. All changes are scoped to the view's own `<style>` block plus minimal HTML restructuring — no other pages or shared CSS affected.

**Plan management — drag-reschedule, add, remove (2026-06-16):** The macro plan on the coach athlete view is now editable. The coach's arrangement is authoritative — none of these re-run or re-optimize the engine.

- **Drag-to-reschedule.** Workout bubbles are draggable; dropping on another in-plan day persists via `POST /app/coach/athlete/:id/workout/reschedule` → `CoachController::rescheduleWorkout()` (coach-owns-athlete auth + CSRF). The handler validates the destination is a real date inside the plan window, then: warns (non-blocking) if it's a **must-off** day (`{error:'must_off'}`, client re-POSTs with `force:true` on confirm); reports a **conflict** if the day is occupied (`{error:'conflict', existing_workout}`, client offers a **swap**, sent as `swap_with` and applied as an atomic two-row date exchange in a transaction); otherwise `UPDATE scheduled_date`. The calendar DOM updates in place; any server error reverts the drag. (HTML5 drag is desktop; the stacked mobile list is tap-only.)
- **Add workout.** Empty/rest day cells (coach view only) show a "+ Add workout" button opening a modal with two paths via `POST /app/coach/athlete/:id/workout/add` → `addWorkout()` (supports a `preview` flag). **Archetype picker:** filter by category (Easy/Long/Quality/Recovery) → pick archetype → variant + duration → live preview → add. Instances are resolved and rendered through the engine via the new `PlanGenerator::composeManualWorkout()` (and `PlanGenerator::manualArchetypeLibrary()` powers the picker), so all snapshot/display columns match generated workouts. **Free-form:** title, workout type (badge color), duration, athlete instructions, coach-only notes (stored in `planned_workouts.notes`), `archetype_code = NULL`. Both paths insert with `coach_locked = 1` and `visible_to_athlete = 1`.
- **Remove workout.** A muted "Remove workout" action in the detail popout soft-deletes via `POST /app/coach/athlete/:id/workout/remove` → `removeWorkout()`: sets `cancelled = 1`, `cancelled_at`, `cancelled_by` (never a hard delete, preserving the training log). The day renders as rest with a faint coach-only "Removed" marker. **migration_012** adds `cancelled`/`cancelled_at`/`cancelled_by` to `planned_workouts`; **all** consumers now filter `cancelled = 0 OR cancelled IS NULL` — `getPlanWorkouts`, the athlete Today/Plan visible-workout and completed-match queries, `TrainingLoad`'s planned-workout join, `cron_notifications`, and `cron_update_visibility`.
- **Edit workout (2026-06-18).** An "Edit workout" action in the detail popout (`#mwd`) — shown only for a **future, uncompleted, non-cancelled** workout — opens a three-mode modal (`#ewd`, mirroring the add modal) that edits the existing row via the (extended) `POST /app/coach/workouts/:id/edit` → `CoachController::editPlannedWorkout()`. One path, three modes (`mode` = surface | archetype | freeform), kept backward-compatible with the weekly-calendar's form-encoded surface edit:
  - **Surface tweak** — duration / type badge / title / instructions / coach notes; `archetype_code` and `instance_signature` unchanged.
  - **Archetype swap** — filter→archetype→variant→duration→live preview→save, re-resolved through `PlanGenerator::composeManualWorkout()`, overwriting the archetype snapshot + all display columns + `instance_signature` (a `preview` flag returns the render with no write).
  - **Free-form** — title / type / duration / instructions / notes; `archetype_code = NULL`, `instance_signature = NULL`.
  Every save sets `coach_locked = 1` + `coach_edited_by`/`coach_edited_at`, **recomputes `intensity_load`** (surface = the row's preserved factor × new duration; archetype = the variant/archetype intensity factor × duration via `composeManualWorkout`; free-form = the type's load factor × duration, identical to the free-form add), keeps display text consistent with the new duration (archetype regenerates all display; surface regenerates the one-line summary; free-form leaves it NULL → derived at render), and best-effort re-pushes to Intervals.icu (upsert by `srf_{id}`; a push failure can never fail the edit). It **never** calls `PlanGenerator::generate` — one row only. **Permissions:** head coach/admin get all three modes; assistant coaches get surface + archetype only (free-form denied), and assistant edits tag `added_by_role='assistant_coach'`. Allowing a coach-authored title on a locked row is a deliberate departure from the engine's "title/summary engine-owned" rule.

Coach dashboard also includes:
- In-app messaging thread per athlete (see Section 14)
- Push notification controls (see Section 15)

**Mobile macro-plan lead-in (2026-06-17 fix):** On the coach athlete view (`views/coach/athlete_view.php`) at ≤768px, the stacked macro-plan list no longer leaves a full-height empty gap for the calendar-padding days before the plan's first workday (e.g. Mon–Wed when the plan starts Thursday). Those out-of-plan cells carry `.macro-day-outside`, which the mobile breakpoint hides — but an equal-specificity `.macro-day { display:flex }` rule directly below it was winning on source order and re-showing them. The hide rule is now the compound selector `.macro-day.macro-day-outside { display:none }` so it wins regardless of order. Desktop grid unchanged (outside days still render as blank cells).

### Workout Library — archetype browser *(2026-06-17)*

The coach **Library page (`/app/coach/library`, `CoachController::library()`) is a read-only browser over the active `workout_archetypes`** — it no longer touches the legacy `workout_library` table, and the old "+ Add template" creation flow (`libraryAddTemplate` + its `POST /app/coach/library` route) was removed. Archetypes are managed via the seeder, never through this UI.

- **List:** dynamic count ("N workout archetypes available"); a repurposed filter bar — search (name/code/description), **Type** (Easy/Long/Quality/Recovery, mapped from `selection.slot_types`), **Phase** (Base/Build/Peak/Taper, from `selection.phases`), and **Distance** (5K/10K/Half/Marathon/Ultra/Mile, matched against `selection.goal_distances` with **Ultra→marathon** and **Mile→5K** since archetypes key on the selector distance). Cards show name, code slug, workout-type badge (shared `pill_class`/`pill_label`), phase + distance tags, intensity factor, prescription type, description (with Show more), variant count, and a "System archetype" label.
- **Detail drawer** (right on desktop, bottom sheet ≤768px): variants, per-classification parameter ranges, generation metadata (intensity factor / prescription / recovery model / min classification), plan-type eligibility, full `display.description_template`, and a "Preview workout" button.
- **Preview:** `GET /app/coach/library/preview?archetype_id&classification&duration&goal_distance&variant` → `CoachController::libraryPreview()` (JSON, **no DB writes**) runs the archetype through `PlanGenerator::previewArchetype()` — the same resolve→render pipeline as `composeManualWorkout()` but against a throwaway context (explicit classification + selector distance, effort-only/no pace citations). Returns `display_title`, `display_summary`, `athlete_instructions`, `generated_parameters`, and `structure` so coaches can see exactly what an athlete would be shown for any configuration.

### Unified Messages tab *(2026-06-17)*

A coach **Messages tab (`/app/coach/messages`, `CoachController::unifiedMessages()`)** consolidates every athlete thread into one inbox, alongside the existing per-athlete thread (`/app/coach/athlete/:id/messages`, still linked from the athlete detail page). Nav: a "Messages" item in the sidebar **and** the mobile bottom nav with a live unread badge.

- **Inbox list** (left panel desktop / full-width mobile): all active athletes, sorted by most-recent message (athletes with none sort last, alphabetical), with avatar (teal when unread), name (bold when unread), 60-char preview ("You: " prefix for coach-sent), a smart timestamp, and an unread dot. Search filters by name. Built by `getMessageThreads()`; the **timestamp** (`messageListTimeLabel()`) is formatted in the **athlete's** timezone (`users.timezone` via `Timezone`): today → time ("2:34 PM"), yesterday → "Yesterday", within the past week → weekday ("Tuesday"), older → date ("Jun 9").
- **Thread (right panel desktop / `/app/coach/messages/:id` full-page on mobile):** reuses the existing `views/coach/messages.php` component (now with an optional `$backUrl`), so session-note cards, polling, and the compose/send endpoint are shared — no duplication. Desktop loads the thread without a page reload via `GET /app/coach/messages/:id/panel` (fragment), then re-binds `window.SRF.initMessaging()` (exposed from `app.js` with teardown so swapping threads never leaks pollers). Opening a thread marks it read (`loadThreadForPanel()`), clears the row's unread indicator, and decrements the badge.
- **Polling:** the nav badge polls `GET /app/coach/messages/unread-count` every 10s on all coach pages (`window.SRF.setNavMsgBadge`); the inbox list refreshes every 10s via `GET /app/coach/messages/threads` (re-sort + preview/timestamp/unread update, preserving search + active selection); the active thread keeps the existing 10s message poll.
- **Mobile bottom nav (2026-06-17):** **Alerts was removed from the coach bottom nav** to make room for Messages (it remains in the desktop sidebar). Bottom nav is now: Home / Athletes / Messages / Approvals / Settings.

---

## 9. Onboarding Flow

Triggered on first login. Completion sets `onboarding_completed_at` and queues plan generation.

**Form sections (can be split across multiple screens):**

1. **Goal** — target race, distance, date, time goal (time optional). **Goal race date is required when a race-cycle goal is selected** (inline client validation "Please enter your goal race date to continue." + server check in `saveStep1`); it stays optional for development / maintenance / return-to-running. This mirrors the engine's hard guard — `generateRaceCycle` refuses to build without `goal_race_date` and raises a critical `missing_goal_race_date` flag (engine spec §6). Distance pills are ordered shortest-first with Hyrox first when shown: Hyrox · Mile / 1500m · 5K · 10K · 15K · Half Marathon · Marathon · 50K · 50 Mile · 100K · 100 Mile (ultra labels drop the "Ultra" suffix). Selecting an ultra shows a required trail/road question (`ultra_surface`); selecting Hyrox stores `goal=mile` + `is_hyrox=1` (and latches `hyrox_ever=1`) and shows a functional-fitness supplement note on the next screen. In **Training Settings** the same pill set is used, and the Hyrox pill is shown only once the athlete has ever selected it (`is_hyrox=1 OR hyrox_ever=1`), so it persists after switching to another goal. See engine spec §9b/§9c.
2. **Current fitness** — current weekly mileage, longest recent long run, most recent race result (optional)
3. **Availability** — days per week, preferred long run day, any days unavailable
4. **History** — years running, injury history, highest-ever weekly mileage
5. **Watch setup** — platform selection, OAuth connect (optional — clearly skippable, no friction if declined)
6. **Preferences** — units (miles/km), notifications, dark/light mode, **plus three required consent checkboxes**: (a) "I am 18 years of age or older, or I have obtained parental or guardian consent…", (b) "I have read and agree to the [Privacy Policy](/app/privacy).", and (c) "I have read and agree to the [Terms of Service](/app/terms), including the assumption of risk and liability waiver in Section 5." (migration_020, 2026-06-17). All three must be checked; `OnboardingController::saveStep6()` validates them server-side (the client `required` attribute is convenience only) and refuses to finalize onboarding if any is missing. On success it records `consent_age = 1`, `consent_privacy = 1`, `consent_given_at = NOW()`, `consent_tos = 1`, `consent_tos_at = NOW()` on the `users` row. See §25.

Data writes to `athlete_profiles`. Coach is notified. Plan generation is queued but requires coach approval before athlete sees anything.

---

## 10. Hosting & Infrastructure (NearlyFreeSpeech.net)

**What runs on NFSN:**
- PHP web application (athlete portal + coach dashboard)
- MySQL database
- Cron jobs: weekly engine batch, daily window-visibility update, watch sync polling

**NFSN cron limitations:** NFSN supports cron via their custom daemon setup. Jobs should be lightweight and stateless. Heavy plan generation jobs should be time-bounded and resumable (process one athlete per invocation if needed, queue-driven).

**External dependencies (all REST/API, called from PHP):**
- Garmin Health API
- Polar Accesslink API
- Payment processor (Stripe recommended)
- Transactional email — **Resend** (implemented; `src/Mailer.php`). Replaces the earlier Postmark/SendGrid placeholder.
- SMS (Twilio — for SMS notification channel and phone number verification) — *deferred; schema accommodates it (`channel_sms`) but it is not wired*

**No persistent background process required.** All engine logic is cron-triggered batch processing.

---


## 11. Progressive Web App (PWA)

SimplyRunFaster is built as a PWA. This means it is simultaneously:
- A fully functional responsive website accessible in any browser
- An installable app on mobile home screens (iOS and Android) without App Store distribution
- A push notification sender via the Web Push API

### Technical Requirements for PWA
- Service worker (handles offline caching and push notification delivery)
- Web app manifest (defines app name, icon, colors, display mode)
- Served over HTTPS (required — NFSN supports this)

### What PWA Enables
- **Mobile install:** Athletes and coaches tap "Add to Home Screen" — the app installs with the SimplyRunFaster logo, launches full-screen, indistinguishable from a native app
- **Push notifications:** Delivered to installed PWA on mobile and desktop Chrome/Edge even when the browser is not open
- **Offline support:** Current week's training plan is cached and readable without a connection. Write actions (RPE logging, manual workout entry) queue and sync when connection returns
- **No App Store dependency:** No Apple/Google review process, no 30% subscription tax, no native app maintenance

### PWA Display Modes
- Installed on mobile: standalone (full screen, no browser chrome)
- In browser: normal responsive website
- Both experiences are fully functional

### Push Notification Events
*✅ Web Push is implemented (June 2026) — see Section 28 for the dispatcher, channels, preferences, and the full event wiring. The table below is the event catalog; delivery now flows through `Notifications::send()`.*

| Event | Recipient | Message |
|---|---|---|
| Workout pushed to watch | Athlete | "Your [workout name] is on your watch" |
| Plan approved by coach | Athlete | "Your training plan is ready" |
| New message from coach | Athlete | Coach message preview |
| New message from athlete | Coach | Athlete name + message preview |
| Engine flag raised | Coach | "Attention needed: [athlete name]" |
| Plan pending approval | Coach | "[Athlete name]'s plan is ready for review" |
| RPE not logged (24hr after quality session) | Athlete | "How did [workout name] feel?" |

### Cache-Busting Strategy and Service Worker Lifecycle

**Static asset versioning:** `app.css` and `app.js` are referenced in every HTML template with a `filemtime`-based query string: `app.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/app.css') ?>`, computed per-request in PHP. The URL changes automatically whenever the file's mtime changes on deploy, making `Cache-Control: max-age=31536000, immutable` correct — the cache entry is bound to a specific file version, not to the bare path. No manual version bumping or content-hashing build pipeline is required.

**Service worker HTTP headers:** `sw.js` is served with `Cache-Control: no-cache, no-store, must-revalidate` via a `<FilesMatch "sw\.js$">` block in `.htaccess`, overriding the `max-age=31536000, immutable` that `.htaccess` applies to all other `.js` files. The app entry HTML pages (PHP-served routes) include `no-store, no-cache, must-revalidate` via PHP headers. This ensures browsers always fetch the latest SW script and HTML shell on each navigation, regardless of browser HTTP cache state.

**Service worker lifecycle:** `sw.js` calls `self.skipWaiting()` in the install event so a newly installed SW activates on the next launch. The activate event deletes all caches whose name differs from `CACHE_NAME`. It deliberately does **not** call `self.clients.claim()`: claiming would swap the controller of an already-open, authenticated page the instant a deploy lands and — paired with the cache flush — was producing stale, logged-out navigations on the open client. Without claim, open pages keep their current controller until the next natural navigation/relaunch, so an active session is never interrupted; `skipWaiting()` still hands control to the new SW on the next launch. A `{type: 'SKIP_WAITING'}` message listener allows DevTools force-activation during testing. *(Removing `clients.claim()` was part of the 2026-06-16 PWA session-loss fix — see "Session persistence across SW updates" below.)*

**PRECACHE list:** The SW pre-caches only auth-independent resources: `/app/offline` and `/manifest.json`. Personalized pages (`/app`, `/app/plan`) are **not** pre-cached — fetching them at install time stores whatever the install request's session/role produced (a redirect, a 403, or a logged-out page), which then gets re-served as a stale "logged out" artifact after a deploy. They are cached lazily on a real visit by `networkFirst` instead. `app.css` and `app.js` are also **not** pre-cached: SW cache matching does not ignore query strings, so the un-versioned path would never match the versioned URL. They are cached lazily by the `cacheFirst` handler on first request — when the versioned URL produces a cache miss, the file is fetched and stored under the versioned key. A new deploy changes the `?v=` value, producing a fresh cache miss and fetch automatically.

**Install-time HTTP cache bypass:** PRECACHE items are fetched with `{cache: 'reload'}` mode during install, bypassing the browser's HTTP cache. Without this, a browser that has `app.css` cached with `max-age=31536000, immutable` would return the stale cached bytes to the SW during install — meaning a CACHE_NAME bump would create a new SW cache containing old CSS, producing no visible change for the user.

**Session persistence across SW updates (2026-06-16 fix):** PWA users (notably coaches/admins) were being bounced to the login screen on every deploy. The session cookie itself was fine (30-day `lifetime`, `secure`, `httponly`, `SameSite=Lax` at the time — later raised to `None`, see the 2026-06-17 fix below); the cause was the SW caching `/app/login` — which is both the PWA `start_url` and the role-dispatch route. A logged-out first visit cached the login page under `/app/login`; after a `CACHE_NAME` bump flushed caches, the iOS standalone PWA's cold-relaunch to `start_url` could be served the stale logged-out page even though the cookie was still valid. Fixes: (1) auth/dispatch pages (`/app/login`, `/app/register`, `/app/logout`, `/app/forgot-password`, `/app/reset-password`, `/app/invite/*`) are now **network-only** — never read from or written to the cache, so they always reflect live session state; (2) `networkFirst` only caches **non-redirected** `200` responses, so a followed `302` auth/role redirect is never stored under the requested URL; (3) `clients.claim()` was removed (above). Server-side, `Auth::applyCookieParams()` also pins `session.gc_maxlifetime` to `SESSION_LIFETIME` (30 days) so idle server-side session files aren't garbage-collected before the cookie expires.

**CACHE_NAME convention:** `srf-YYYYMMDD`. Bumped on every deploy by running `sed -i "s/srf-[0-9][0-9]*/srf-$(date +%Y%m%d)/" sw.js` in the project root. The activate event uses string equality (`key !== CACHE_NAME`) to identify stale caches — any change to the string, regardless of direction, invalidates the old cache. The pattern requires **at least one digit** (`[0-9][0-9]*`) so it matches only the date-based name and never the unrelated `srf-notification` push tag in `sw.js` (a bare `srf-[0-9]*` would corrupt that tag to `srf-YYYYMMDDnotification`). **Same-day re-deploys:** the bare date name means a second deploy on the same day is a no-op for asset busting (network-first views/PHP still serve fresh; cache-first CSS/JS are `?v=<filemtime>`-versioned, so they bust regardless); for repeated same-day cache flushes append a counter suffix (`srf-2026061702`, `…03`, …).

**HTTPS, session cookie & CSRF (2026-06-17 fix):**
- **Force HTTPS** in `.htaccess`. NearlyFreeSpeech terminates TLS at an upstream proxy, so the backend Apache always sees `%{HTTPS}=off` even for HTTPS requests — the real scheme arrives in `X-Forwarded-Proto`. The redirect fires only when **both** `X-Forwarded-Proto != https` **and** `%{HTTPS} off`, so it never loops on NFSN (proxied) or on a directly-TLS server. (A bare `RewriteCond %{HTTPS} off` rule would redirect-loop forever on NFSN.)
- **Session cookie** (`Auth::applyCookieParams()`, set before `session_start()`): `path=/` (site-wide), `secure`, `httponly`, **`SameSite=None`**, 30-day `lifetime`. `secure` relies on the HTTPS redirect above (mobile Safari refuses to store a `Secure` cookie over plain HTTP). `SameSite` was raised from `Lax` to `None` as the final lever in the iOS Chrome login-loop fix below (`None` requires `Secure`, which is already set). No `domain` is set, so the cookie is **host-only** (`simplyrunfaster.com`, no leading dot) — correct for iOS browsers. CSRF protection is unaffected by `None` because every POST is independently verified (`Auth::verifyCsrf`, or the invite-bound token below).
- **CSRF mismatch** no longer shows a white screen. `Auth::verifyCsrf()` returns a JSON 403 to fetch/JSON callers (so AJAX handlers surface it inline) and renders a styled "Session expired" page (`views/errors/csrf.php`, "Back to login") to ordinary browser form posts. The session/expected-token comparison is factored into `Auth::verifyCsrfValue($expected)`, shared by `verifyCsrf()` and `verifyInviteCsrf()`; it also requires a non-empty expected token (an empty token never passes).
- **Invite-registration CSRF is session-independent.** The pre-account invite POST (`/app/invite/{code}`) binds its CSRF token to the invite **code** instead of the session: `Auth::inviteCsrfToken($code) = hash_hmac('sha256', 'invite:'.$code, CSRF_INVITE_SECRET)`, emitted by `Auth::inviteCsrfField($code)` and checked by `Auth::verifyInviteCsrf($code)`. A dropped session on mobile (common when the browser backgrounds between rendering the form and submitting) therefore no longer produces a false "session expired" on account creation. `CSRF_INVITE_SECRET` (config.php) defaults to `APP_ENCRYPTION_KEY`.

**iOS Chrome login loop & PWA onboarding (2026-06-17, layered):** iOS Chrome (WebKit) was dropping the freshly-issued session cookie on the post-login hop, causing an infinite login loop. Three layered fixes, applied in increasing order of bluntness:
1. **`session_write_close()` before every redirect** — `Auth::redirect($url)` writes/closes the session before sending `Location`, so the session file and (regenerated) cookie are committed before the browser follows the 302. All post-POST redirects in `AuthController` and `OnboardingController` route through it.
2. **200 meta-refresh on post-auth hops** — `Auth::redirectAfterAuth($url)` returns a `200` HTML page carrying the `Set-Cookie` and navigates via `<meta http-equiv="refresh">` + `location.replace()` (plain-link fallback), because WebKit stores cookies from a 200 far more reliably than from a 302. Used only where the session cookie was just (re)issued: login success, forced-password change, organic + invite registration. All other redirects stay 302.
3. **`SameSite=None`** on the session cookie (above) — the standard fix for WebKit cookie persistence across redirects, applied last for its security trade-off.
- **Onboarding "Continue" button in standalone PWA.** The onboarding footer buttons live **outside** their `<form>` (associated via the `form=` attribute), which iOS standalone PWA WebKit will not submit — the tap registered but nothing navigated. Step 1 (`step1_goal.php`) uses an explicit `type="button"` handler that verifies the CSRF field is present/non-empty, surfaces a visible error on failure (no silent dead button), and submits via `form.requestSubmit()` (so HTML5 + goal-race-date validation still run). A generic helper in `app.js` converts every other outside-form onboarding submit button (steps 2–6) to a script-driven `requestSubmit()` with try/catch; it matches only `type="submit"` buttons, so step 1's bespoke handler is not double-wired.

---

## 12. Workout Day Swapping

Athletes can move workouts within their visible 10-day window. Day swapping is athlete-initiated and does not require coach approval, but is visible to coaches.

### Swap Rules
- **Must-off day override:** Engine warns ("this is a day you marked as unavailable — are you sure?") but does not block. One tap to confirm override. Logged as athlete-initiated must-off override, visible to coach. Life changes week to week — athlete knows their schedule best.
- **2-day separation warning:** If swap would place workout within 2 days of long run, engine warns but allows with confirmation tap.
- **Consecutive quality sessions:** If swap would create two quality sessions on consecutive days, engine warns but allows.
- **Must-off block exceptions:** None — must-off days can always be overridden by athlete with confirmation.
- **Blocked swaps (no override):**
  - Moving workout outside the visible 10-day window
  - Moving workout to a date in the past
  - Any swap that violates a universal guardrail without a workaround

### Coach Visibility
- Swapped workouts show a subtle indicator in the plan view — original date noted in small text below
- Coach can reverse a swap from the plan view
- Must-off overrides flagged as info-level notes on the athlete's activity log

### Database
Add to `planned_workouts` table:
- `original_scheduled_date DATE` — nullable, set when athlete moves a workout
- `athlete_moved BOOL` — default false
- `athlete_moved_at DATETIME` — nullable
- `must_off_override BOOL` — default false

### Implementation status *(shipped — June 2026)*
Athlete day-swap is live. `POST /app/athlete/workout/swap` → `AthleteController::swapWorkout()` (athlete auth + CSRF; JSON body `{workout_id, target_date, force}`). The picker is a 10-day modal in `views/athlete/plan.php` — today through today+9, each day labelled with its current content, must-off days flagged with a lock, and the workout's own day disabled.
- **Window:** `SWAP_WINDOW_DAYS = 10`. Both the picker and the server validate that `target_date` *and* the source workout's current date fall within today..+9, in the athlete's timezone.
- **Move vs. swap:** if the target day already holds a visible, non-cancelled workout in the active plan, the two `scheduled_date`s are exchanged atomically in a transaction; otherwise the source workout's date is moved. Both legs stamp the audit columns above (`original_scheduled_date` preserved as the earliest prior date; `athlete_moved`, `athlete_moved_at`; `must_off_override` per leg).
- **Guards:** coach-locked workouts cannot be moved by the athlete; a must-off target is a soft warning the athlete confirms (re-POST with `force:true`); out-of-window / not-owned / not-visible / cancelled are rejected.
- **Side effects:** affected workouts are re-pushed to Intervals.icu (`IntervalsService::pushWorkout()`, best-effort, wrapped so a push failure can never fail the swap — the DB change is already committed), and the coach is notified via `athlete_day_swap` (controllable, default off). The athlete plan card shows a "moved from <date>" indicator.
- **Deferred from the rules above (not yet built):** the 2-day-to-long-run and consecutive-quality soft warnings, and coach reversal from the plan view.

---

## 13. In-App Messaging

Coaches and athletes communicate within the platform. All messages are tied to the athlete's profile and visible alongside their training data — coaches can see an athlete's compliance, load, and recent workouts in the same view as the message thread.

### Design Principles
- **Asynchronous by design.** No real-time chat expectation. Coaches respond when available.
- **Not a general inbox.** One thread per athlete, between that athlete and their assigned coach only.
- **Context-aware.** Messages display alongside the athlete's training data, not in a separate inbox divorced from context.
- **Notification-driven.** Push notifications alert both parties to new messages. Email fallback if PWA not installed.

### Database Table: `messages`

| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| athlete_id | INT FK | always scoped to an athlete |
| sender_id | INT FK | user_id of sender |
| sender_role | ENUM | athlete, coach, assistant_coach |
| body | TEXT | message content |
| sent_at | DATETIME | |
| read_at | DATETIME | nullable — when recipient read it |
| push_sent | BOOL | whether push notification was sent |
| message_type | ENUM | message, session_note, session_note_reply |
| completed_workout_id | INT FK | nullable — links to session thread origin (set once the workout is completed) |
| planned_workout_id | INT FK | nullable — session-card link to a planned workout, available pre-completion (migration_022) |
| thread_id | INT FK | nullable — self-referencing for session threads |
| reply_count | INT | default 0 — session card: comments after the first (re-float counter, migration 015) |

### `session_notes`
Session-level notes and threaded conversations. Separate from the main message thread but surfaces into it as linked cards.

| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| completed_workout_id | INT FK | nullable (migration_022) — set once the workout is completed; NULL for pre-completion notes |
| planned_workout_id | INT FK | nullable (migration_022) — the planned workout the thread is tied to; available pre-completion |
| athlete_id | INT FK | |
| author_id | INT FK | user_id — athlete or coach |
| author_role | ENUM | athlete, coach, assistant_coach |
| body | TEXT | note content |
| created_at | DATETIME | |
| soft_limit_chars | INT | 500 — configurable, not hard-enforced |
| hard_limit_chars | INT | 1000 — requires confirmation tap to exceed soft limit |

### Session notes & session-card threading *(shipped — June 2026)*

**Athlete session detail.** `GET /app/log/:id` → `AthleteController::session()` renders one completed workout: planned-vs-actual comparison plus the note UI and the full session thread (the athlete's note first, coach replies after, read from `session_notes`). The training log links each row here. Note entry has a character counter that appears at 400 chars, turns amber past the 500 soft limit, caps at the 1000 hard limit, and confirms ("Your note is quite long. Are you sure?") past the soft limit. Athletes can edit their own note (`POST /app/log/note/edit` → `sessionNoteEdit()`); creation/replies go through the existing `POST /app/log/note` → `sessionNoteSave()`. A reply compose box appears once the coach has replied.

**One session card per workout (dedupe + re-float).** Every comment on a workout — athlete note or coach reply — no longer inserts its own thread row. Instead `SessionThread::recordComment()` keeps a single `messages` row per `completed_workout_id` (`message_type = 'session_note'`): the first comment inserts it; each later comment re-floats it to the bottom (`sent_at = NOW()`), bumps `reply_count`, refreshes the preview to the latest comment, and re-attributes it to the latest commenter so unread resolves for the recipient. (`session_note_reply` message rows are no longer created — legacy rows may still exist; full per-comment text always lives in `session_notes`.) The card renders the linked workout's `display_title`, a 120-char latest-comment preview, an "N replies" tally, and (athletes) a "View session →" link to `/app/log/{id}`. The 10-second message poll passes `&since=<newest sent_at>` so a re-floated card (whose id is unchanged) is detected and moved to the bottom client-side without duplicating. Migration 015 adds `messages.reply_count`.

**Coach side.** Coaches comment via `POST /app/coach/athlete/:id/session-note` → `CoachController::coachSessionNoteSave()` (writes a `session_notes` row, then `SessionThread::recordComment()` re-floats the card and fires `coach_session_comment`). Entry points: the "+ Comment on this session" form on session cards in the coach message thread, and a "Comment on session" button in the macro-plan workout detail popout (`#mwd`) shown only when that planned workout has a logged completion (`getPlanWorkouts()` resolves `completed_workout_id`).

### Workout-linked threads — pre/post completion *(shipped — June 2026, migration 022)*

Threads are keyed on `planned_workout_id` so an athlete can open a thread for ANY workout, upcoming or completed, not just from the Log tab after completion. Pre-workout questions and post-completion notes are the same thing: `session_notes` rows tied to a planned workout. `completed_workout_id` is filled in once the workout is logged; the single re-floated `messages` session card carries both ids (`SessionThread::recordCommentPlanned()` / `recordComment()` upsert by `planned_workout_id` first, then `completed_workout_id`).

- **Focused thread view:** `GET /app/messages/workout/{planned_workout_id}` → `AthleteController::workoutThread()` (coach parity: `GET /app/coach/workout/{planned_workout_id}/thread` → `CoachController::workoutThread()`), shared view `views/messages/workout_thread.php` — workout header (badge, title, date, summary) + chronological thread + compose. Send: `POST .../send` → `sendWorkoutMessage()` (athlete) / `CoachController::sendWorkoutMessage()` (coach); inserts a `session_notes` row, re-floats the card, notifies the other party (`athlete_session_note` / `coach_session_comment`).
- **Entry points:** the Today "This Week" accordion cards and the Plan upcoming cards render a button via `render_workout_thread_button()` — "Ask your coach about this workout" (no thread), "1 message · waiting for reply" (`reply_count = 0`), or "View thread (N replies)" (`reply_count > 0`); state comes from `AthleteController::workoutThreadState()`.
- **Main-thread card link:** the session card now links to `/app/messages/workout/{planned_workout_id}` (athlete) / `/app/coach/workout/{planned_workout_id}/thread` (coach), falling back to `/app/log/{completed_workout_id}` for legacy cards with no planned id. Thread/poll queries `COALESCE` planned-workout title/date so pre-completion cards render before any completion exists.
- **Enter-to-send + live polling (2026-06-17 fix):** the focused workout thread reused neither the main thread's Enter-to-send nor its 10s polling (the generic `initMessaging()` keys off `#msgScreen`, which this view doesn't emit). `views/messages/workout_thread.php` now carries a small self-contained script: **Enter sends** (Shift+Enter = newline) by submitting the compose form, and a **10s poller** scoped to the `planned_workout_id` appends new `session_notes` without a reload (deduped, scroll-to-bottom only if already near it, pauses on tab-hide). Read-only, ownership-scoped poll endpoints (symmetric since the view is shared): `GET /app/messages/workout/:id/poll` → `AthleteController::workoutThreadPoll()` and `GET /app/coach/workout/:id/thread/poll` → `CoachController::workoutThreadPoll()`, returning notes after `?after=<id>` via `loadWorkoutThreadNotesAfter()` + `serializeWorkoutNotes()` (`mine` computed per viewer).

### Scheduled coach messages — `scheduled_messages` *(shipped — June 2026, migration 017)*

Backs delayed coach messages. On onboarding completion, `OnboardingController::scheduleWelcomeMessage()` queues a welcome note from the athlete's (now-backfilled) assigned coach with `send_after = NOW() + INTERVAL 12 MINUTE`, first-name-only greeting; no-op when no coach is assigned, idempotent per athlete. `scripts/cron_scheduled_messages.php` (a **separate** lightweight cron — kept apart from the day-cadence `cron_notifications.php`, which must not run hourly) posts due rows into the thread as coach messages, fires `message_from_coach`, and marks them sent (claims `sent=1` before posting to avoid double-send). **Requires an NFSN scheduled task** (every 15 min, or hourly fallback) — effective delay is 12 min to ~one run interval.

| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| athlete_id | INT | |
| sender_id | INT | coach user_id |
| body | TEXT | |
| send_after | DATETIME | |
| sent | TINYINT(1) | default 0 |
| sent_at | DATETIME | nullable |
| created_at | TIMESTAMP | |

MyISAM/utf8, consistent with the live schema.

### Coach UI
- Message thread accessible from athlete profile sidebar
- Unread message count badge on athlete roster row
- Compose from athlete context — coach sees athlete's training data while writing
- All athletes' unread messages surfaced in a unified "Messages" section of coach dashboard — **shipped 2026-06-17 as the coach Messages tab (`/app/coach/messages`); see §8 "Unified Messages tab" for the inbox, live unread badge, desktop two-panel / mobile flow, and polling.**

### Athlete UI
- Message thread accessible from bottom tab nav (messages tab or notification badge)
- Simple threaded view — athlete sees full conversation history with their coach
- Compose reply inline

### Push Notifications for Messages
- New message → push notification to recipient within 60 seconds
- Notification includes sender name and first 60 characters of message
- Tapping notification opens directly to the message thread
- Email fallback triggered if push not delivered within 5 minutes (PWA not installed or notifications declined)

---

## 23. Plan Types

### Plan Type Naming Convention

| Internal name | Customer-facing label |
|---|---|
| race_cycle | Training for [Race Distance] |
| development_plan | Get fitter and run consistently |
| maintenance_plan | Stay fit between races |
| recovery_block | Post-race recovery |
| return_to_running | Return from injury or time off |

### Onboarding Goal Path
Athletes select their path early in onboarding:
- **I have a specific race I'm training for** → race_cycle
- **I want to get fitter and run consistently** → development_plan
- **I'm returning from injury or a long break** → return_to_running

Each path produces a different plan type. Athletes can transition between plan types as their situation changes — always coach-approved.

### Development Plan
- Internal name: development_plan
- Customer-facing: "Get fitter and run consistently"
- Character: maintenance-adjacent but with volume-building emphasis
- Volume builds week over week same as base phase, no race date anchor
- Quality sessions present but simpler — fartlek and hills over track intervals
- Speed work present throughout per coaching philosophy
- No taper, no peak phase
- At 8-12 weeks of consistent volume building: coach prompt to discuss race goal ("This athlete's fitness is ready for a structured cycle")
- Transitions to race_cycle when athlete and coach set a goal race

### Maintenance Plan
- Internal name: maintenance_plan
- Customer-facing: "Stay fit between races"
- Volume: 80-90% of athlete's peak cycle volume, held consistently
- One quality session per week, lower intensity — fartlek and hills preferred
- Long run maintained but shorter than peak cycle
- Speed work present throughout
- No defined end date
- At 12 weeks post-race with no race on calendar: friendly prompt to athlete ("Thinking about your next goal?") and coach nudge ("Consider a goal-setting conversation with [athlete]")
- Not a critical flag — informational only

### Recovery Block
- Internal name: recovery_block
- Customer-facing: "Post-race recovery"
- Triggered automatically after any race (goal or tune-up)
- Duration scales by race distance (see engine spec recovery days table)
- Post-marathon recovery block: approximately 4 weeks
- Includes cross-training based on athlete's available equipment
- Full structure: pending coach's source document (flagged as open item)
- No quality sessions during recovery block
- Transitions to maintenance_plan or race_cycle when recovery complete — coach decides

### Return to Running Plan
See Section 24 for full detail.

---

## 24. Return to Running Plan

### Triggers
- Onboarding path: "I'm returning from injury or a long break"
- Mid-cycle: athlete gets injured during a race cycle — coach switches plan type manually
- Engine flag: athlete has not logged any activity for 3+ weeks — engine suggests return-to-running assessment

### Medical Clearance Gate
Before return-to-running plan activates, athlete must confirm:

☐ I have been cleared by a medical professional to return to running, OR I am returning from a non-injury break and do not require medical clearance.

☐ I understand that this plan is conservative by design and I will communicate with my coach if I experience any pain or discomfort.

Both checkboxes required. Confirmation logged on athlete profile with timestamp. Coach notified when clearance confirmed.

**Note:** Medical clearance confirmation is a friction point to encourage appropriate behavior, not a legal substitute for proper terms of service. A lawyer must review the platform TOS before launch — see Section 25.

### Time Off Classification

| Time off | Classification | Engine starting point |
|---|---|---|
| 1–2 weeks | Short break | Not return-to-running — ease back in over 3–5 days within existing plan |
| 2–6 weeks | Moderate break | Start at run/walk stage 3–4 |
| 6–16 weeks | Significant break | Start at run/walk stage 1 |
| 16–52 weeks | Extended break | Start at stage 1, hold each stage for 2 sessions before advancing |
| 12+ months | Restart | Treat as new athlete — full base classification, development plan most likely first step |

### Run/Walk Progression

Sessions are every other day. Off days are cross-training or rest based on available equipment. No quality sessions anywhere in the progression.

| Stage | Run interval | Walk interval | Sessions at this stage |
|---|---|---|---|
| 1 | 1 min | 3 min | 1 (2 for extended break) |
| 2 | 2 min | 2 min | 1 (2 for extended break) |
| 3 | 3 min | 2 min | 1 (2 for extended break) |
| 4 | 4 min | 1 min | 1 (2 for extended break) |
| 5 | 5 min | 1 min | 1 (2 for extended break) |
| 6 | 6 min | 1 min | 1 (2 for extended break) |
| 7 | 7 min | 1 min | 1 (2 for extended break) |
| 8 | 8 min | 1 min | 1 (2 for extended break) |
| 9 | 9 min | 1 min | 1 (2 for extended break) |
| 10 | First continuous easy run | — | Duration set by engine based on time-off tier |

Minimum 9 sessions (18 days) before continuous running is scheduled. For significant break: approximately 2.5 weeks. For extended break: approximately 5 weeks.

### Progression Rules
- Progression advances only on a clean session — no reported pain or discomfort. A clean session below stage 10 advances `rtr_current_stage` by one.
- If athlete reports discomfort (via the modified RPE prompt): engine **auto-regresses one stage** (floor of stage 1) and raises the `return_to_running_discomfort` flag for the coach immediately. This combines automatic de-load — so the athlete never stalls waiting on coach action — with coach awareness. (This refines the original "hold at current stage" intent: a regression is gentler than holding when something hurt, and the coach is still notified and can re-adjust.)
- If athlete skips a session: engine repeats current stage on next scheduled run day — not a compliance flag, just a hold (the stage is unchanged until a session is actually completed).
- Progression never skips stages regardless of how the athlete feels (one stage per completed session, up or down).
- Coach can manually advance, hold, or regress stages from the athlete plan view. A coach-locked future run/walk session is preserved when the engine re-stages upcoming sessions (the coach override wins).
- **Implementation:** the full progression is **pre-generated upfront** at plan creation — all 10 run/walk sessions at their expected stage (session N at stage N, stage 10 the first continuous run) on an every-other-day cadence, so the coach sees the whole journey in the macro view (the initial 10-day window is visible to the athlete; later sessions are coach-only until the window reaches them). The per-session mechanic is `PlanGenerator::onRunWalkCompletion`, invoked after an athlete logs a run/walk completion: it re-stages the **next pending** session in place to the athlete's new stage (advancing on a clean session, regressing on discomfort), leaving the rest of the pre-generated progression intact. See engine spec §18.10/§18.11.

### Modified RPE Prompt During Return-to-Running
Post-session prompt adds a fifth option:
- Easy / Moderate / Hard / Very Hard / **I felt some discomfort**

"I felt some discomfort" immediately flags the coach (`return_to_running_discomfort`) and auto-regresses the athlete one stage (floor of stage 1). **Implemented** by extending the existing effort-descriptor flow rather than building a separate mechanism: the manual-log "How did it feel?" control renders the fifth pill only for athletes in an active return-to-running plan (otherwise the standard 4 options), writing `effort_descriptor = 'discomfort'` and setting the `completed_workouts.rpe_discomfort` flag. See engine spec §18.11.

### Coach Involvement
- Every completed session in the first two weeks surfaces to coach automatically (elevated monitoring, not just exception-based)
- After two weeks of clean sessions: monitoring returns to normal flag-based model
- Coach can manually advance, hold, or regress stages at any time
- Coach approval required to transition from return-to-running to any other plan type

### Race Addition During Return-to-Running
- Athlete can add a race as aspirational — it is logged but does not trigger plan modifications
- Race appears in athlete profile as "Goal race — pending plan transition"
- Engine does not modify the return-to-running plan for race prep until coach explicitly approves transition
- Coach sees aspirational race in athlete profile and can use it to time the transition conversation

### Cross-Training During Return-to-Running
Off days from running are filled with cross-training based on `athlete_profiles` equipment fields:
- Bike available: easy cycling (duration-based, easy effort)
- Elliptical available: easy elliptical (duration-based, easy effort)
- Pool available: pool running or easy swimming
- No equipment: rest day or walking
Duration starts short (20-30 min) and increases gradually alongside run progression.

### Transition Out of Return-to-Running
After stage 10 (first continuous run) is completed cleanly:
- Engine raises an info flag (`plan_rebuild_needed`, with `details.reason = 'return_to_running_complete'`): "Athlete has completed the return-to-running progression — ready to discuss the next plan type." It does **not** advance the stage further and schedules no further run/walk session (the pre-generated progression ends at the stage-10 session) — mirroring the recovery_block transition pattern.
- Coach schedules goal-setting conversation
- Coach selects next plan type: development_plan, maintenance_plan, or race_cycle
- New plan generates per normal approval flow

---

## 25. Legal & Terms of Service

**Pre-launch requirement — not deferrable.**

The following must be reviewed by a qualified lawyer before SimplyRunFaster accepts paying customers:

1. **Terms of Service** — covering algorithmic plan generation, coach oversight model, data usage, subscription billing terms, cancellation policy
2. **Liability waiver** — specifically covering:
   - Return from injury plans and medical clearance
   - Training plan compliance and injury risk
   - Algorithmic nature of plan generation (not a substitute for medical advice)
   - Watch data accuracy and reliance on third-party platforms
3. **Privacy Policy** — covering health data (HR, pace, training load), payment data (Stripe), SMS/push notification data, and any third-party integrations (Garmin, Polar, Strava if approved)
4. **HIPAA considerations** — health-adjacent data may have specific requirements depending on jurisdiction. Lawyer should advise.
5. **Subscription billing disclosures** — auto-renewal, cancellation, refund policy (required by law in many jurisdictions)

The medical clearance checkbox in the return-to-running flow is a UX friction point, not a legal substitute for proper TOS. Both are required.

### Privacy Policy, Consent & Data Retention *(implemented — 2026-06-16)*

The Privacy Policy is live and the consent + retention machinery is built. (The HIPAA review and a final qualified-lawyer pass over the drafted documents remain open pre-launch items.)

- **Policy page:** `GET /app/privacy` — public, no auth required (allowlisted in the athlete billing gate so a lapsed athlete can still read it). Rendered by `views/static/privacy.php` using the app layout (no authenticated nav). Effective 2026-06-16, covering US (CCPA), EU/UK (GDPR / UK GDPR), Canada (PIPEDA), and Mexico (LFPDPPP); Section 6 lists processors (NearlyFreeSpeech, Stripe, Resend, Intervals.icu). A "Privacy Policy" link appears in the global app footer (`views/layout/html_close.php`) on every page and on the marketing placeholder.
- **Onboarding consent:** two required checkboxes (age/parental consent + Privacy Policy agreement) on the final onboarding step, validated server-side; recorded on `users` as `consent_age` / `consent_privacy` / `consent_given_at` (migration_013). See §9. Existing users at migration time are grandfathered as consented.
- **90-day retention & deletion:** `scripts/cron_delete_expired_accounts.php` (daily NFSN cron, hour 4 UTC; supports `--dry-run`) enforces the policy's retention window. It selects **athlete-role** accounts only and only those with `subscription_status` in (`canceled`, `none`) — never `active`/`trialing`/`comped`/`past_due`, and never coach/admin — in two categories: `90_day_post_cancellation` (canceled, `subscription_end_date` > 90 days past) and `90_day_incomplete_onboarding` (`none`, signed up > 90 days ago, onboarding never completed). For each, it deletes the athlete/user-scoped child rows (messages, session_notes, completed_workouts, planned_workouts, athlete_profiles, engine_flags, training_plans, notification_preferences, device_notify_preferences, push_subscriptions, athletes), then **anonymizes** (does not hard-delete) the `users` row — `email = deleted_<id>@deleted.invalid`, `name = 'Deleted User'`, `password_hash = ''`, `phone_number = NULL`, `stripe_customer_id = NULL`, `deleted_at = NOW()` — so billing records that reference the user id survive (Privacy Policy §9: billing records retained 7 years). Each anonymization is logged to `account_deletions` (see §4). *(Note: `users` has no `push_subscription` column — push data is removed via the `push_subscriptions` table delete.)*
- **Config reminder:** `config/config.php` carries a non-wired comment that the `privacy@simplyrunfaster.com` mailbox must be set up and forwarded before beta launch.

### Terms of Service *(implemented — 2026-06-17, migration_020)*

The Terms of Service is live (drafted for SimplyRunFaster; a final qualified-lawyer pass is still recommended pre-launch).

- **Policy page:** `GET /app/terms` — public, no auth. Rendered by `views/static/terms.php` (same teal-heading / 720px styling as the privacy page, no authenticated nav). Effective 2026-06-17, 18 sections including acceptance, eligibility, not-medical-advice, the **assumption-of-risk & liability waiver (Section 5)**, return-to-running terms, subscription/billing (auto-renewal, cancellation, refunds, price changes, failed payments, Stripe), third-party integrations, IP, termination, disclaimers, limitation of liability, and Tennessee governing law / AAA arbitration / class-action waiver with EU/UK/Mexico carve-outs. A "Terms of Service" link sits in the global app footer beside the Privacy Policy link.
- **Onboarding consent:** a third required checkbox ("I have read and agree to the Terms of Service, including the assumption of risk and liability waiver in Section 5.") on the final onboarding step, validated server-side; recorded on `users` as `consent_tos` / `consent_tos_at` (migration_020). Existing users at migration time are grandfathered as consented. See §9.

---

## 30. Plan Templates

Coaches can save reusable macro plan structures and apply them to new athletes as a starting point rather than generating from scratch each time. Templates speed up the plan generation process for common athlete profiles.

### What a Template Contains
- Plan type (race_cycle, development_plan, etc.)
- Target distance (if race_cycle)
- Cycle length (weeks)
- Phase proportions (or flag to use athlete-profile-derived proportions)
- Week-by-week structure: which workout types appear on which days
- Specific workout library entries pre-assigned to key sessions
- Notes (coach-facing — when to use this template, athlete profile it suits)

### What a Template Does Not Contain
- Athlete-specific pace targets (derived from athlete's pace zones at application time)
- Specific dates (calculated from athlete's race date or start date at application time)
- Volume targets (derived from athlete's current fitness and peak volume ceiling)

### Template Application Flow
1. Coach opens plan approval queue for a new athlete
2. "Apply template" button appears alongside "Generate from scratch"
3. Coach selects a template from their library (filtered by plan type and distance)
4. Engine applies template structure to athlete's specific data — pace zones, volume, dates filled in
5. Generated plan enters normal approval queue — coach reviews before athlete sees anything
6. Coach can edit any workout after template application before approving

### Template Library
- Templates are coach-specific by default (created by and visible to one coach)
- Admin can promote a template to platform-wide (visible to all coaches)
- Templates can be duplicated and modified
- No limit on template count per coach

### Database Table: `plan_templates`

| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| created_by | INT FK | coach user_id |
| platform_wide | BOOL | default false — admin-promoted templates |
| name | VARCHAR | coach-facing name |
| plan_type | ENUM | matches plan type enum |
| target_distance | ENUM | nullable — for race_cycle templates |
| cycle_length_weeks | INT | |
| phase_proportions | JSON | base/build/peak/taper percentages, or null to use athlete-derived |
| week_structures | JSON | array of week templates — day-by-day workout type assignments |
| notes | TEXT | coach-facing usage notes |
| use_count | INT | how many times applied |
| created_at | DATETIME | |
| updated_at | DATETIME | |

---

## 26. Tune-Up Race Handling

### Adding a Race
Both coaches and athletes can add races at any time. Race entry requires:
- Race name (free text)
- Distance (5K / 10K / 15K / Half / Marathon / Ultra / Other)
- If other: distance in miles or km
- Date
- Is this your goal race? (yes / no)

Neither coaches nor athletes are blocked from adding a race at any time. The engine responds with flags and adjustments rather than blocking.

### Engine Response (Immediate, on race addition)
1. Modify the 7 days prior to race:
   - Long run within 7 days: pure aerobic, reduced duration (existing rule)
   - No quality sessions within 48 hours of race date
   - Reduced volume 3–4 days out (mini-taper)
   - One short sharpening session permitted 3–4 days out
2. Insert recovery days after race (per distance scale from engine spec)
3. Check for conflicts with existing planned workouts — flag anything problematic
4. Raise info flag for coach: "[Athlete] added a [distance] race on [date]"
5. If `is_goal_race = true`: raise warning flag: "[Athlete] marked this as their new goal race — plan rebuild may be needed"

### Race Effort
All races are treated as full effort by the engine. No target effort distinction between tune-up and goal races — races are races.

### Post-Race Recalibration Flow
1. Athlete logs result (free text time entry) or result syncs from watch
2. Engine computes proposed pace zones from result using McMillan equivalency math
3. Engine sets `recalibration_proposed = true` on the race record
4. Coach flag raised: "[Athlete] ran [time] at [distance] — proposed pace zone update ready for review"
5. Coach views current zones vs. proposed zones side by side in flag detail
6. Coach approves, modifies proposed zones manually, or dismisses
7. If approved: `athlete_profiles.pace_zones` updated, engine evaluates whether upcoming workout pace targets need adjustment within current plan
8. If dismissed: zones unchanged, flag closed, internal note optional

**Dismissal rationale:** A result that doesn't reflect actual fitness (bad day, illness, heat, deliberately conservative effort) should not automatically reset zones. Coach judgment call is the correct gate.

### Implementation (v1 — as built)

- **Schema:** the pre-existing `races` table (migration_019 extends it) — `race_distance`
  ENUM gains the four ultra keys (`50k`/`50_miler`/`100k`/`100_miler`); `distance_override`
  stores an "other" distance in miles with `distance_override_unit` recording the entered
  unit; `result_notes` holds the athlete's result note (the coach's note stays in `notes`).
  `engine_flags.flag_type` gains `race_added` (info), `goal_race_changed` (warning), and
  `pace_recalibration` (info).
- **Entry:** athletes add races from the Plan tab ("Add a race"); coaches from the athlete
  detail view ("Add race") with inline conflict warnings (quality sessions within 7 days
  before, fetched from `/coach/athlete/:id/race-conflicts`) and a coach-only internal note.
  All race CRUD lives in `RaceController`; routes are `/athlete/race/add`,
  `/athlete/race/result`, `/coach/athlete/:id/race/add`, and
  `/coach/races/:id/recalibrate/{approve,dismiss}`.
- **Display:** races render as terracotta pills on the athlete Plan (rolling window) and the
  coach macro plan. Coach quality cells within 7 days before a race get a yellow border,
  within 3 days a red border. A goal race (profile `goal_race_date`) shows a `GOAL:` pill.
  Marking a race `is_goal_race` syncs `athlete_profiles.goal_race_date` /
  `goal_race_distance` so the goal-race display and future generation agree.
- **Engine:** `PlanGenerator::applyRaceAdjustments()` runs at plan generation and on every
  race add. It is idempotent (caps / type-swaps / deletes, never compounding): race-day
  training workouts removed; long runs within 7 days forced to `continuous_long` /
  `continuous_easy` (3–4 days out capped to 60% of a normal long run, 1–2 days out a ≤30 min
  shakeout); quality removed within 3 days; and a **tiered post-race recovery window** by
  distance (5K 3 · 10K 5 · half 7 · marathon 14 · 50K/50mi 14–16 · 100K/100mi 21). The window
  is resolved by proximity to the race — **days 1–3 rest, days 4–7 easy 30 min, days 8–N
  recovery 40 min** — overwriting any surviving quality/long workout, capped at `plan_end_date`,
  never touching coach-locked rows. See engine spec §9 (Post-Race Recovery) for the full table.
  The `races` query is **strictly scoped to the current `athlete_id`**, with a belt-and-suspenders
  per-row `athlete_id` filter (FIX 9) so one athlete's race can never patch another athlete's plan.
- **Recalibration:** logging a result stores `result_time` (seconds) + `result_notes`, sets
  `recalibration_proposed`, computes `proposed_pace_zones` via `PaceZones::fromRace`, and
  raises a `pace_recalibration` flag carrying the `race_id`. The Alerts view renders a
  current-vs-proposed card with Approve / Modify (edit zones inline) / Dismiss; Approve
  writes `athlete_profiles.pace_zones` (`source = race_result`) and closes the flag.
  Distances PaceZones can't project (ultra/other) raise the flag without an auto-proposal.

### Pace Zone Provenance (`pace_zones_source`)
Every populated `pace_zones` profile records how it was derived:
- `race_result` — derived from a logged race / time trial. Framed as **verified**.
- `easy_pace_estimate` — derived from the athlete's typical easy pace (engine spec
  Section 2, pathway 2). Framed as **estimated** in the coach edit page. Automatically
  replaced by a verified profile when a race result is logged and recalibration (above)
  is approved.
- `manual` — coach-entered override.

The coach edit page surfaces this provenance ("Verified — race result" / "Estimated —
easy pace" / "Manual — coach set" / "No zones yet"). The easy-pace estimate is refreshed
when the athlete or coach updates the easy-pace range, but it never overwrites a
`race_result` or `manual` profile.

### Pace Zone Visibility
- Default: athlete can see their pace zones in the athlete-facing UI
- Coach or admin can hide zones per athlete (`pace_zones_visible = false`)
- When hidden: athlete sees effort-based language only ("5K effort," "threshold effort") — no pace numbers anywhere in athlete UI
- Coach always sees zones regardless of visibility setting
- When hiding: coach leaves an internal note (`pace_zones_hidden_reason`) visible to other coaches/admins but never to athlete
- Common reasons to hide: stale zones pending recalibration, insufficient race data, athlete benefits from effort-based rather than pace-based training

---

## 27. Coach Accounts & Admin

### Role Hierarchy

| Role | Access Level |
|---|---|
| `admin` | Full platform access — create/manage coach accounts, view all athletes, all financial data, platform settings, all plans |
| `coach` | Full coaching tools for assigned athletes — plans, flags, messaging, plan approval, workout editing |
| `assistant_coach` | Coaching tools for assigned athletes — can edit workouts, message athletes, review flags, but cannot unilaterally approve full plan rebuilds (requires coach or admin sign-off) |
| `athlete` | Athlete-facing portal only — rolling plan view, logging, messaging with assigned coach |

### Coach Account Creation
- No public coach signup flow
- Admin creates coach accounts from admin panel
- Admin sets role (coach or assistant_coach) and assigns initial athlete roster
- Coach receives email with temporary password + login link
- Coach completes a brief profile setup (name, bio, optional photo) on first login
- Admin can reassign athletes between coaches at any time
- Admin can bulk reassign all athletes from one coach to another (for coach departures)

### Assistant Coach Constraints
- Can view and edit any workout in assigned athletes' plans
- Can message assigned athletes
- Can review and dismiss info/warning flags
- Cannot approve plan rebuilds — approval queue routes to assigned head coach or admin
- Cannot create invite links (coach and admin only)
- Cannot access financial/billing data

### Athlete-Coach Assignment
- Admin assigns athletes to coaches from admin panel
- Invite links pre-assign coach at generation time
- Self-serve signups (ad-driven) auto-assigned by load balancing (fewest active athletes) or manually by admin
- Assignment visible to athlete ("Your coach is [name]") with coach photo/bio on athlete profile

### Invite Link Flow
1. Coach or admin generates invite link from dashboard
2. Link tagged with: assigned coach, optional Stripe coupon code, optional notes
3. Expiry: 7 days by default, configurable per link
4. Max uses: 1 by default (unique invite), configurable for batch invites (e.g. running club of 10)
5. Athlete clicks link → branded signup page → account creation → onboarding form → onboarding call → plan
6. Invite code stored on athlete record for source tracking

### Self-Serve Signup Flow (Milestone 8)
1. Athlete arrives from ad or organic search
2. Marketing landing page → "Get started" CTA
3. Account creation → onboarding form → Stripe checkout → onboarding call scheduled → plan generated pending approval
4. Coach assigned automatically (load balancing) or manually by admin
5. UTM parameters captured at landing and stored on athlete record (signup_source, ad_campaign_id, ad_source)
6. Conversion tracking: signup_source field enables ad ROI analysis from day one

### Admin Panel (distinct from coach dashboard)
Admin-only views:
- Coach roster — all coach accounts, their athlete counts, role management
- Full athlete roster — all athletes across all coaches
- Invite link management — generate, view, revoke
- Platform-wide billing overview — MRR, churn, comp list
- Engine health — batch job status, flag queue volume, sync error rates
- Notification delivery stats — push/email/SMS delivery rates

---

## 28. Notification System

> **✅ Implemented (June 2026).** The full notification system below has shipped to production. Component map:
> - **`src/Notifications.php`** — central dispatcher. All sends route through `Notifications::send($userId, $type, $data)`: resolves per-user preferences, enforces always-on types, evaluates quiet hours in the user's own timezone (via `src/Timezone.php`), and dispatches to enabled channels with email fallback.
> - **`src/EmailTemplates.php`** — HTML + plain-text email bodies (teal wordmark/CTA, manage-preferences footer): `plan_approved`, `plan_pending_approval`, `message_*`, `critical/warning/info_flag`, `weekly_summary`, `weekly_athlete_digest`, plus a generic fallback. Sent via **Resend** (`src/Mailer.php`).
> - **Web Push** — `minishlink/web-push` (pinned `^9.0` for the PHP 8.1 web runtime) with VAPID keys in `config/config.local.php`. Subscriptions persist in the **`push_subscriptions`** table (one row per device, multi-device); `Notifications::sendPush()` fans out to all of a user's devices and prunes dead endpoints on 404/410. `POST /app/push/subscribe` saves a device; `assets/js/app.js` auto-subscribes when permission is granted and shows a one-time enable prompt otherwise; the service worker (`sw.js`) renders the push and handles `notificationclick`.
> - **`scripts/cron_notifications.php`** — daily (NFSN scheduler, hour 13 UTC): `tomorrow_plan`, `weekly_summary`, `pre_race_reminder`, `rpe_prompt`, `weekly_athlete_digest`. Day-level gating with per-user timezone + quiet-hours handling. *Note: a once-daily run honors `preferred_time` only to the cron's cadence — exact per-user delivery times would require scheduling the script hourly (the queries already read `preferred_time`/`preferred_day`).*
> - **Preferences UI** — dedicated pages for athletes (`/app/settings/notifications`) and coaches (`/app/coach/settings/notifications`), shared partial `views/partials/notifications_form.php`. Always-on rows are locked; controllable rows expand to inline push/email channel pickers; SMS is shown disabled ("Coming soon"). Each change saves immediately via AJAX (`POST .../notifications`). Default rows are seeded role-aware by `scripts/run_migration_009.php` / `Notifications::ensureUserDefaults()`.
>
> **Deviations from the spec as written:**
> - **SMS** is deferred. `channel_sms` exists in the schema (always `0`) and is never dispatched.
> - **`coach_session_comment`** is wired (June 2026): the coach mirror of the athlete session note. `POST /app/coach/athlete/:id/session-note` → `CoachController::coachSessionNoteSave()` writes a `session_notes` row (author_role coach/assistant_coach), re-floats the workout's single session card via `SessionThread::recordComment()` (see §13 — it no longer creates a separate `session_note_reply` message; legacy rows may persist), and fires `coach_session_comment` to the athlete. Entry points: the "+ Comment on this session" form on session cards in the coach message thread, **and** a "Comment on session" button in the macro-plan workout detail popout, shown whenever that planned workout has a logged completion. This largely closes the earlier UI-Section-15 "comment on any completed workout" gap — a coach can now comment on a completed planned workout even with no athlete note; an *unplanned* completion with no card is still not a surfaced entry point. (The message poll JSON now carries `completed_workout_id` and `reply_count`.)
> - **`athlete_day_swap`** is wired (June 2026): `AthleteController::swapWorkout()` fires it to the coach after an athlete moves or swaps a workout within the 10-day window (controllable, default off). See §12.
> - Coaches' **flag digest vs. immediate** timing control (below) is not yet built; warning/info flags dispatch immediately when enabled.

### Always-On Notifications (not user-controllable)

**Athletes — always receive:**
- Plan approved and ready to view
- Direct message from coach

**Coaches — always receive:**
- Plan pending approval
- Critical engine flag (missed multiple workouts, load spike, plan rebuild needed)
- Direct message from athlete

Rationale: critical flags and direct messages cannot be opted out of. A coach opting out of critical flags could leave an athlete in a dangerous training situation with no human awareness.

### Athlete-Controllable Notifications

| Notification | Default | Delivery | Notes |
|---|---|---|---|
| Tomorrow's plan | On | Push + optional email | Delivered at athlete's preferred time (default 8pm). Includes workout name, duration, description excerpt, watch push confirmation if applicable. Nothing sent on rest days by default (configurable). Race-day-eve version always sends regardless of quiet hours. |
| RPE prompt after quality session / long run | On | Push | Sent 30 min after activity syncs or at a fixed time post-workout window |
| Coach commented on a session | On | Push | Distinct from direct message |
| Weekly summary | On | Push + optional email | End of week — compliance, load, next week preview. Athlete sets preferred day/time (default Sunday 6pm) |
| Pre-race reminder | On | Push | "You're racing in X days" — sent at 7, 3, and 1 day out |
| Upcoming long run reminder | Off | Push | Optional extra nudge the evening before long run day |

### Coach-Controllable Notifications

| Notification | Default | Delivery | Notes |
|---|---|---|---|
| Warning-level engine flags | On | Push | Lower severity — compliance trends, load warnings |
| Info-level engine flags | Off | Push | Compliance patterns, minor deviations, must-off overrides |
| Athlete logged a session note | On | Push | Can get noisy at scale — coaches with 50+ athletes may want email digest instead |
| Athlete manually logged a workout | Off | Push | Useful but low priority |
| Athlete swapped a workout day | Off | Push | Coach can review in dashboard on their own schedule |
| Weekly athlete digest | On | Push + email | One notification covering all athletes — compliance summary, open flags count, upcoming races |
| Individual athlete weekly summary | Off | Push | Per-athlete granular summary — for coaches who want deeper weekly review |

### Delivery Channel Control
Per notification type, both athletes and coaches can set any combination of:
- Push notification (PWA) — default on
- Email — always available
- SMS — available after phone number verified; restricted to high-signal notifications only (always-on category, tomorrow's plan, pre-race reminders, critical flags)
- Neither (for controllable notifications only — always-on notifications must have at least one channel active)

**SMS restrictions:** SMS is intentionally limited to high-signal notifications to control cost and avoid fatigue. Low-signal notifications (info flags, day swap alerts, individual athlete summaries) are not available via SMS regardless of preference setting.

**WhatsApp:** Deferred to v2. Higher international engagement but requires WhatsApp Business API approval and message template restrictions make it complex for v1.

### Timing Controls

**Athletes:**
- Tomorrow's plan notification — preferred delivery time (default 8pm, 30-min increments)
- Weekly summary — preferred day and time
- Quiet hours — push notifications suppressed during this window (default 10pm–7am). Race-day-eve notification bypasses quiet hours.

**Coaches:**
- Quiet hours — same concept (default 10pm–7am)
- Flag digest vs. immediate — coaches can choose to receive warning/info flags immediately or batched into a daily digest (default: immediate for warnings, daily digest for info)

### Database Table: `notification_preferences` *(implemented — migration 009)*

| Column | Type | Notes |
|---|---|---|
| user_id | INT FK | |
| notification_type | VARCHAR(60) | matches notification type keys above |
| enabled | BOOL | |
| channel_push | BOOL | default true |
| channel_email | BOOL | default false |
| channel_sms | BOOL | default false — reserved; SMS deferred, currently always 0 |
| quiet_hours_start | TIME | default 22:00 |
| quiet_hours_end | TIME | default 07:00 |
| preferred_time | TIME | for scheduled notifications (tomorrow's plan, weekly summary) |
| preferred_day | TINYINT | 0–6, for weekly notifications |
| updated_at | DATETIME | |
| PRIMARY KEY | (user_id, notification_type) | |

Default rows are seeded role-aware on migration run (`scripts/run_migration_009.php` → `Notifications::ensureUserDefaults()`); a missing row falls back to the canonical defaults in `Notifications::DEFAULTS`.

### Database Table: `push_subscriptions` *(implemented)*
Web Push subscriptions are stored one row per device (not as a single blob on `users`), so a user can be subscribed on multiple devices simultaneously and dead endpoints can be pruned individually.

| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| user_id | INT FK | |
| endpoint | TEXT | push service endpoint (unique device) |
| p256dh | TEXT | client public key |
| auth | TEXT | auth secret |
| user_agent | VARCHAR(255) | device label |
| created_at / last_used_at | TIMESTAMP / DATETIME | `last_used_at` bumped on each successful send |

### Watch Push vs. App Push
For athletes with a connected watch, the "Tomorrow's plan" notification includes a line confirming the workout was pushed to the watch ("Pushed to your Garmin"). This replaces a separate "workout pushed to watch" notification — one notification, more information, less noise.

### Web Push Delivery Quality *(implemented — June 2026)*
To keep notifications out of Chrome's "possible spam" heuristic, every Web Push is sent structured and intentional (`sw.js` push handler + `Notifications::sendPush`):
- **Icon + badge** on every notification (`/assets/icons/icon-192.png`).
- **Tag = notification type** — `Notifications` emits `type` in the push payload so Chrome groups/dedupes related notifications, with **`renotify: true`** so a newer one of the same type replaces and re-alerts rather than silently dropping.
- **`vibrate: [200, 100, 200]`** on all notifications; **`requireInteraction: true`** for always-on types (`plan_approved`, `critical_flag`, `message_from_coach`, `message_from_athlete`) so high-value alerts stay on screen until tapped.
- **Substantive body text** — every type has a specific (non-generic) title and a meaningful body that names the athlete/coach where relevant; message snippets render up to 80 chars.

---

## 29. Payment & Billing

### Payment Processor
Stripe is the recommended payment processor. All subscription billing, coupon management, and comping is handled through Stripe's native features. The application stores Stripe customer and subscription IDs but never stores raw payment data.

### Subscription Model
- Monthly recurring subscription per athlete
- Target price point: $25–50/month (final pricing TBD at launch)
- Billing anchored to signup date (not calendar month)
- Stripe Billing handles all recurring charge logic

### Stripe Integration Points

| Feature | Stripe Mechanism |
|---|---|
| Monthly subscription | Stripe Subscriptions + Products |
| Free launch cohort | Trial period (indefinite or defined end date) |
| Indefinite comps | 100% off coupon applied to subscription |
| Promotional discounts | Percentage or flat-rate coupon with duration limit |
| Referral rewards | Credit balance or coupon applied at signup |
| Failed payment handling | Stripe dunning (automatic retry + notifications) |
| Cancellation | Subscription cancellation with access through period end |

### Database Additions

**`athletes` table additions:**
| Column | Type | Notes |
|---|---|---|
| stripe_customer_id | VARCHAR | Stripe customer object ID |
| stripe_subscription_id | VARCHAR | Active subscription ID |
| billing_status | ENUM | active, trialing, comped, past_due, cancelled, paused |
| billing_notes | TEXT | Coach-facing note (e.g. "founding athlete — comp indefinitely") |
| trial_ends_at | DATETIME | nullable |
| comp_reason | ENUM | founding_athlete, coach_relationship, promotional, referral, other |

### Coach Dashboard Billing View
Coaches see billing status for each athlete at a glance on the roster view:
- Color-coded status indicators (active / trialing / comped / past due)
- Billing notes visible inline
- Ability to apply or remove comps directly from the dashboard (triggers Stripe coupon application via API)
- Alert when an athlete's payment lapses — flagged alongside training flags

### Coupon and Comp Management

> **Partially implemented (June 2026) — admin billing overview.** The admin billing page (`/app/admin/billing`, `AdminController::billing()`, admin-only) lists every athlete's subscription state with status-filter chips. Each row links to the athlete's Stripe customer dashboard (`https://dashboard.stripe.com/customers/{stripe_customer_id}`, when one exists) and offers a one-click **Comp** action: `POST /app/admin/billing/comp` → `AdminController::comp()` (admin role check + CSRF) sets `users.subscription_status = 'comped'`, clears `grace_period_ends` and `subscription_end_date`, and cancels any live Stripe subscription immediately via the SDK (degrades gracefully when Stripe is unconfigured). Already-comped athletes show a muted "Comped" label instead of the button. *Note: canonical subscription state lives on the `users` row (Milestone 8), not the legacy `athletes.billing_status` referenced below.*
>
> Still to build: percentage discounts, trial extension, and free-text billing notes; and surfacing comp controls inline on the **coach** roster (today they live only on the admin page).

Coaches and admins can manage billing from the dashboard without needing to access Stripe directly:
- Apply a 100% comp (indefinite or with an end date)
- Apply a percentage discount for a defined period
- Extend a trial
- Add a billing note
- All actions write to Stripe via API and update `billing_status` in the database

### Self-Signup Flow (Milestone 7)
When self-signup is enabled, athletes complete onboarding and enter payment details before their plan is generated. Stripe Checkout or Stripe Elements handles the payment UI. Trial periods can be offered at signup (e.g., first 2 weeks free) to reduce friction.

### Access Control Logic
The application checks `billing_status` on each authenticated request for athlete-facing pages:
- `active` or `comped` or `trialing` → full access
- `past_due` → limited access with payment prompt (can still see current week's plan, cannot see future weeks)
- `cancelled` or `paused` → read-only access to historical training log, no new plan generation

### Referral Mechanics (Post-Launch)
A simple referral system can be layered on after launch:
- Each athlete gets a unique referral link
- Successful referral (referred athlete completes onboarding and pays first month) triggers a credit or discount on the referring athlete's next bill
- Tracked via Stripe customer metadata and a `referrals` table in the database

---

*This section should be read alongside Milestone 7 in the build sequence. Stripe integration can begin in parallel with Milestone 5 or 6 — it does not need to block earlier milestones.*

### Milestone 1 — Core Data, Auth & PWA Foundation
- Database schema (as above)
- User auth (register, login, roles)
- Athlete onboarding form (watch setup step clearly optional/skippable)
- Coach dashboard scaffold (roster view)
- PWA manifest + service worker (basic offline cache for plan view)
- Dark/light mode toggle wired to user preference (server-side)

### Milestone 2 — In-App Messaging + Push Notifications ✅ COMPLETE (June 2026)
- Messages table and thread UI (athlete + coach)
- Push notification service worker integration
- Web Push API — notification events from Section 28 (`Notifications::send` wired into plan approval/pending, messages both ways, engine flags, session notes both ways (athlete `athlete_session_note` + coach `coach_session_comment`), manual logs; scheduled events via `cron_notifications.php`)
- Email fallback via **Resend** (`src/Mailer.php` + `src/EmailTemplates.php`) for non-PWA users / when no push device is registered
- Unread badge counts on coach roster and athlete nav
- Notification preferences UI (athlete + coach) with always-on locks, per-channel control, quiet hours, and immediate AJAX save

See Section 28 for the full implementation map and the deviations (SMS deferred; `coach_session_comment` wired via the coach session-note endpoint; `athlete_day_swap` wired to the day-swap endpoint — see §12).

### Milestone 3 — Engine v1
- Workout library (seed with initial templates)
- Plan generator (rule-based, outputs planned_workouts)
- Plan approval queue (UI + email notification)
- Guardrail enforcement
- Manual workout logging (no watch yet)

### Milestone 4 — Garmin Integration
- OAuth connect flow
- Push structured workouts to Garmin
- Pull completed activities, map to completed_workouts
- Compliance scoring
- ATL/CTL/TSB computation
- Submit Strava API application at this milestone (real usage data from beta cohort available)

### Milestone 5 — Rolling Adjustment Engine
- Post-workout adjustment logic
- Weekly batch re-evaluation
- Flag system
- Coach flag review UI

### Milestone 6 — Athlete Portal Polish + Manual Logging
- Rolling window UI
- Planned vs. actual view
- RPE / notes logging
- Manual workout entry (first-class flow, not fallback)
- Offline plan view (service worker cache)

### Milestone 7 — Polar Integration
- Accesslink OAuth
- Push/pull (mirror Garmin implementation)

### Milestone 7b — COROS Integration (parallel or sequential with Polar)
- COROS API OAuth
- Push/pull (developer-friendly API, minimal friction)
- Broadens platform compatibility without Strava dependency

### Milestone 8 — Payments & Scale
- Stripe subscription integration
- Athlete self-signup flow (ad-driven)
- UTM/conversion tracking
- Performance / query optimization

### Milestone 9 — Strava Integration (if API approved)
- Strava OAuth connect flow
- Pull completed activities via Strava webhook
- Universal compatibility layer — any watch that syncs to Strava now feeds SimplyRunFaster
- Does not replace direct integrations

---

## 31. Profile Editing & Change Flags

Athletes and coaches can edit a training profile after onboarding. Implemented in
`src/ProfileForm.php` (shared sanitize / diff / save) with two entry points:

- **Athlete — Training Settings** (`GET/POST /app/settings/training`, linked from the
  Settings tab). Single consolidated form: goal, current fitness, typical easy pace,
  availability (incl. must-off days + fixed/flex scheduling), history, cross-training.
- **Coach — Edit Profile** (`GET/POST /app/coach/athlete/:id/edit`, button on the coach
  athlete view). All athlete fields **plus** coach-only controls: `peak_volume_ceiling_mins`,
  `pace_zones_visible` toggle, and `pace_zones_hidden_reason` (shown only when zones are
  hidden, per §26). The page also surfaces pace-zone provenance (Verified / Estimated /
  Manual / None).

### Save behavior
- Writes `athlete_profiles` and bumps `updated_at`. **Does NOT trigger plan regeneration** —
  values are simply available for the next plan generation/rebuild to read.
- If the typical easy-pace range changed and current zones are empty or
  `pace_zones_source = 'easy_pace_estimate'`, zones are re-derived from the new easy pace
  (engine spec §2, pathway 2). Verified (`race_result`) and `manual` zones are never
  clobbered by an easy-pace edit.

### Change flag (`profile_updated`)
Every save with at least one changed field inserts a new `engine_flags` row:
- `flag_type = 'profile_updated'`, `severity = 'info'`.
- `message`: human-readable summary, e.g. *"Liam updated their training profile:
  Training days 3 → 5, Goal race date Nov 1, 2026 → Oct 18, 2026"* (athlete-facing field
  changes only; coach-only field changes are excluded from the summary). Coach-initiated
  edits read *"Coach updated Liam's training profile: …"*.
- `details`: JSON `{ actor_role, changes: [{ field, label, coach_only, old_display,
  new_display }] }` — the full field-by-field diff, including coach-only fields.
- **No deduplication** (contrast `limited_development_opportunity`): each save is a
  distinct event the coach should see individually — always insert, never merge. The coach
  flags view and athlete view render the diff as a before/after list via
  `render_profile_diff()`.

### Schema audit decisions (migration_004)
- Most target fields already existed in production and were left as-is: `years_running`,
  `months_at_current_volume`, `peak_weekly_minutes` (reused for "highest weekly volume" —
  **no duplicate `highest_weekly_volume_minutes` column was created**), `scheduling_preference`,
  `primary_workout_day`, `track_access` (kept enum value `road_reps_ok`, not `road_ok`).
- `workout_day_preference` does **not** exist in production (never implemented) — no DROP
  was issued (a bare `DROP COLUMN` errors on MariaDB 5.3).
- `must_off_days` widened `VARCHAR(20)` → `LONGTEXT`.
- Added `typical_easy_pace_min` / `typical_easy_pace_max` (INT, sec/mile) and
  `pace_zones_source` ENUM.

### Out of scope (follow-up)
- Wiring `must_off_days`, `scheduling_preference`, `primary_workout_day`, and `track_access`
  into PlanGenerator scheduling/archetype selection — columns exist and are editable now,
  but engine **consumption** of these fields is future work. (`long_run_day` and
  `current_weekly_minutes` are already consumed and continue to be.)
- The previously-drafted "pace ranges in workout instructions" follow-up should run
  **after** this lands: it is far more impactful once new athletes (the majority at any
  time) have zone data via the easy-pace pathway, rather than only athletes with race history.

---

## 12. Open Questions (Requires Coach Input Before Engine Build)

These must be answered before Milestone 2 begins. The engine's rule logic depends entirely on the answers:

1. **Periodization model** — How do you structure base → build → peak → taper? What determines the length of each phase given race distance and weeks to race?
2. **Intensity distribution** — What's your philosophy on easy vs. hard day split? (e.g., 80/20, HR zone-based, pace-based?)
3. **Long run structure** — Is the long run always the same character (easy/aerobic), or does it evolve through the training cycle?
4. **Workout day templates** — What does a canonical week look like across the training cycle? (e.g., Tuesday workout, Thursday tempo, Sunday long, all others easy?)
5. **Volume progression model** — How do you build mileage? Straight build with cutback weeks? Which week is cutback week?
6. **Compliance thresholds** — At what point is an athlete's compliance bad enough to trigger a flag? A plan rebuild?
7. **Fitness inputs at onboarding** — How do you translate "current weekly mileage + recent race time" into starting pace zones and load targets?

---

*This document should be treated as the source of truth for v1 architecture. All implementation decisions should reference back to the principles in Section 2. The engine logic in Section 5 is intentionally underspecified pending coach input (Section 12).*

---

## Appendix: Coach assignments & the assistant-coach permission model (migration 024)

### Source of truth
`coach_assignments` (one row per athlete, `UNIQUE(athlete_id)`) is the authority for who coaches an athlete:
- `coach_id` — the head coach (user_id).
- `assistant_coach_id` — optional assistant coach (user_id).

**`athletes.coach_id` is kept in sync** with `coach_assignments.coach_id` on every write (via `CoachAssignments::assignCoach`), so all pre-existing reads (`Auth::getAthlete`, `Notifications::athleteContext`, the billing join, the coach roster queries) keep working unchanged. Never write `athletes.coach_id` directly for assignment changes — go through `CoachAssignments`.

`src/CoachAssignments.php` owns this: `assignCoach()`, `setAssistant()`, `ensure()`, `coachId()`, `assistantCoachId()`, `canAccess()`, and `scope()`.

### Roles & access
- **admin** — full access to every athlete plus the admin panel (`/app/admin/*`).
- **coach** (head coach) — their assigned athletes (`coach_assignments.coach_id`). No admin panel.
- **assistant_coach** — only athletes where `coach_assignments.assistant_coach_id = their id`.

`CoachAssignments::scope($userId, $role, 'a')` returns an `[sqlFragment, params]` pair used by every roster/list query in `CoachController` (head coaches/admins scope by `a.coach_id`, assistants by an `IN (coach_assignments …)` subquery). `getAthleteForCoach()` is the per-athlete gate (scope OR admin-override).

### Assistant-coach capability matrix
Allowed: view roster/plan/flags/load/profile (assigned athletes only); message athletes (stored `sender_role='coach'` — no role distinction athlete-facing); approve/reject plans; add workouts **from the archetype picker only** (tagged `planned_workouts.added_by_role='assistant_coach'`, shown as a coach-only "AC" badge, never to the athlete); remove workouts; edit the profile (the only path to pace zones — raises an `assistant_pace_zone_edit` info flag for the head coach); request plan regeneration; dismiss **info-level** engine flags only.

Denied (403): generate plans from scratch (they request regeneration instead); admin panel / billing; dismiss warning/critical flags; free-form workout entry; any athlete not assigned to them; create/deactivate accounts.

### Regeneration request flow
Assistant coaches can't generate. On an assigned athlete they get a **Request plan regeneration** button (replaces Generate Plan), inserting a `plan_regeneration_requests` row (`status='pending'`). The head coach sees a "Regen request" badge on the roster row and an Approve/Dismiss banner on the athlete detail view. Approve runs `PlanGenerator::generate(..., 'coach_manual')` and marks the request approved; Dismiss marks it dismissed (optional note).

### Account creation & forced password change
Admins create coach / assistant-coach accounts from `/app/admin/users/create`: a temporary password is generated, `users.must_change_password=1` is set, and a Resend welcome email is sent. A front-controller gate (`index.php`) redirects any logged-in user with `must_change_password=1` to `/app/change-password` (allowlisting that screen + logout/theme/offline) until they set a new password. `users.active=0` blocks login (deactivation). Assistant coaches carry `users.managed_by` = their head coach's user_id.

### Onboarding wiring
On onboarding completion, `OnboardingController::ensureCoachAssignment()` creates the `coach_assignments` row with `coach_id = invite_links.created_by` (the inviting coach; fallback user 1 for organic/missing invites) and mirrors it to `athletes.coach_id`. The scheduled welcome message resolves its sender from `coach_assignments.coach_id`.

---

## 32. Coaching Intelligence Layer (Phases 1–4 complete)

A capture-and-surface layer that records how coaches actually adjust plans and how athletes actually behave, turns repeated patterns into reusable coaching rules, and feeds those rules back into the engine. **Phase 1 builds the pipes, not the analysis** — no predictive modeling, no cross-athlete ML. Schema is migration_027 (MyISAM, utf8, no FKs, LONGTEXT for all JSON). The coach **Alerts** page was renamed and expanded into **Intelligence** (`/app/coach/intelligence`; `/app/coach/alerts` and the legacy `/app/coach/flags` redirect/alias to it).

### New tables (migration_027)

**`coach_adjustments`** — one row per planned-workout change (coach or athlete-initiated), with a frozen athlete-context snapshot so patterns stay analyzable after the profile evolves.
| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| planned_workout_id | INT | 0 for profile-level edits (pace_zone_edit) |
| athlete_id / coach_id | INT | coach_id = acting coach; for athlete swaps, the athlete's assigned head coach |
| adjusted_at | DATETIME | |
| flagged_for_review | TINYINT(1) | the flag mechanic (Part 3); default 0 |
| change_type | ENUM | archetype_substitution, duration_change, day_swap, workout_removed, workout_added, instructions_edited, pace_zone_edit |
| before_* / after_* | archetype_code VARCHAR(64), workout_type VARCHAR(32), duration_mins INT, scheduled_date DATE, instructions LONGTEXT | before/after snapshots; pace_zones JSON is stored in the `*_instructions` slot for pace_zone_edit |
| ctx_goal_distance / ctx_phase / ctx_week_number / ctx_classification / ctx_weekly_mins / ctx_plan_week | VARCHAR/INT | context snapshot at adjustment time |
| reason_tag / reason_notes | ENUM / TEXT | optional, added during weekly review (Phase 2) |
| coaching_decision_id | INT | set when this adjustment is promoted to a rule |

Capture is a pipe that must never break the action it observes: `CoachAdjustments::record()` swallows and logs its own errors. Wired into `CoachController::rescheduleWorkout` (day_swap), `removeWorkout` (workout_removed), `addWorkout` (workout_added), `editPlannedWorkout` (archetype_substitution / duration_change / instructions_edited by what changed), `editProfileSave` (pace_zone_edit, when zones change), and `AthleteController::swapWorkout` (day_swap, attributed to the assigned coach).

**`coaching_decisions`** — coaching rules distilled from flagged adjustments (or authored manually). `status` active/inactive/proposed; `trigger_json` + `action_json` (LONGTEXT); `scope_distances/scope_phases/scope_plan_types`; `times_fired` / `last_fired_at`; `source` manual / proposed_from_adjustment. Rules created from a flagged adjustment are saved **active**.

**`athlete_behavior_log`** — daily behavior metrics; **90-day rolling retention** pruned by the daily cleanup cron (`cron_delete_expired_accounts.php`). `metric_type` ENUM (rpe_vs_target, completion_rate, easy_pace_drift, response_time, engagement_score — only the first, second, and last are produced in Phase 1), `metric_value` FLOAT, `metric_context` LONGTEXT, `plan_week`, `phase`.

**`coaching_intelligence_flags`** — pattern flags surfaced on the Intelligence page. `flag_type` ENUM (rpe_trending_high, rpe_trending_low, compliance_dropping, compliance_streak, engagement_dropping, adaptation_ahead_of_schedule, dropout_risk, plan_adjustment_recommended), `severity` info/warning/opportunity, `title`/`detail`/`suggested_action`/`suggested_adjustment`, `status` open/actioned/dismissed.

Also: **`users.last_login_at`** (set in `Auth::loginUser` on every successful auth; drives engagement scoring) and **`training_plans.coach_generation_notes`** (LONGTEXT; the decision-resolver audit per generation).

### Behavior metrics (daily, `CoachingIntelligence::run`, invoked from `cron_notifications.php`)
Runs once daily for each active athlete with an active plan, after the existing notification jobs.
- **completion_rate** = completed workouts (7d) / visible non-cancelled planned workouts (last 7d up to today), capped at 1.0. Skipped when no planned workouts in the window.
- **rpe_vs_target** = mapped(effort_descriptor) − mapped(expected effort of the planned type), per qualifying completed quality/long session in the last 7 days, deduped by completed-workout id so a session is logged once. Effort map: easy 3 / moderate 5 / hard 7 / very_hard 9 / discomfort 10 (`completed_workouts.effort_descriptor`, not a numeric rpe). Expected map keys both the real `planned_workouts.workout_type` ENUM and the spec aliases (easy_run/long_run/hill_session/workout). Positive = working harder than prescribed.
- **engagement_score** = 0–100 composite: athlete messages in 7d (3+ → 30, 1-2 → 20, 0 → 0) + days since last completed workout (0-1 → 35, 2-3 → 25, 4-6 → 10, 7+ → 0) + days since `last_login_at` (0-1 → 25, 2-3 → 15, 4-7 → 5, 8+/NULL → 0).

### Intelligence flags (daily, after behavior logging)
14-day dedup per (athlete, flag_type) regardless of status. A flag needs the athlete's assigned coach to attribute to.
| Flag | Severity | Condition |
|---|---|---|
| rpe_trending_high | warning | avg of last 3 rpe_vs_target entries > +1.5 (≥3 entries) |
| rpe_trending_low | info | avg of last 3 rpe_vs_target entries < −1.5 (≥3 entries) |
| compliance_dropping | warning | most-recent completion_rate < 0.60 AND the entry ~7 days prior > 0.75 |
| compliance_streak | opportunity | last 3 consecutive completion_rate entries all = 1.0 |
| engagement_dropping | warning | most-recent engagement_score < 40 AND the entry ~7 days prior > 60 |
| dropout_risk | warning | last 3 consecutive engagement_score entries all < 20 |

### Flag-for-review mechanic (Part 3)
A flag toggle sits in the coach workout-detail popout header (`#mwd-flag`, teal `#1D9E75` when set). `POST /app/coach/workout/flag {planned_workout_id, flagged}` → `CoachController::flagWorkout()`: updates `flagged_for_review` on the workout's existing `coach_adjustments` rows, or inserts a minimal `instructions_edited` marker row (before == after) when flagging a workout with no prior adjustment. `getPlanWorkouts()` surfaces `MAX(flagged_for_review)` so the calendar renders the current state.

### Intelligence page — three sections (`CoachController::intelligence`, `views/coach/intelligence.php`)
1. **Athlete Flags** — open `coaching_intelligence_flags` + open `engine_flags` for the coach's athletes, ordered critical → warning → opportunity → info, most-recent first. Left-border color by severity (amber warning, teal opportunity, gray info). Intelligence-flag Action button routes contextually: dropout_risk / engagement_dropping → athlete messages; the rest → athlete plan. Engine flags keep their existing View/Dismiss + pace-recalibration + profile-diff cards. Dismiss → `…/intelligence/flag/:id/dismiss` (intel) or the existing `…/flags/:id/dismiss` (engine).
2. **Flagged for Review** — `coach_adjustments WHERE flagged_for_review=1 AND coaching_decision_id IS NULL`. Each row shows the human change label, a before → after summary, and **Add as rule** / **Dismiss**. Add-as-rule opens a modal (pre-filled title, required reason, distance + phase scope pre-checked from the captured context) → `…/intelligence/adjustment/:id/rule` (`saveDecision`): auto-generates `trigger_json` `{goal_distance, phase, classification}` and `action_json` (archetype_substitution → exclude before + weight after ×2; duration_change → `duration_adjustment` delta; day_swap → `{}`), inserts the decision active, sets `coaching_decision_id` + clears the flag.
3. **Decision Library** — the coach's `coaching_decisions` (Title | Scope | Times fired | Last fired | Status); one-click active/inactive toggle (`…/intelligence/decision/:id/toggle`). Empty state prompts flagging adjustments to start.

### Decision resolver (engine, `PlanGenerator` + `src/CoachingDecisions.php`)
`generate()` loads the active decisions for the athlete's coaches (head + assistant via `coach_assignments`) once. Inside `insertWeekWorkouts`, `applyCoachingDecisions()` builds the per-week context (goal_distance, phase, classification, plan_type), matches each decision's `trigger_json` (absent keys are wildcards; present arrays must contain the context value), and applies `action_json` to the quality selection: `exclude_archetypes` → removed from the candidate pool; `weight_multipliers` → multiplied into the selection weights; `force_archetype` → dominant weight; `max_quality_per_week` → extra quality slots fall back to `continuous_easy`. **Conflict resolution:** when two matching decisions act on the same archetype with conflicting actions (one excludes, one weights), the **higher id (more recent) wins**, and the conflict is logged to `coach_generation_notes` (`"Decision conflict: A vs B on <archetype>. B took precedence."`). After generation, `finalizeCoachingDecisions()` writes `coach_generation_notes` (`"Coaching decisions applied: …"` or `"No coaching decisions matched."`) and bumps `times_fired` / `last_fired_at` on each fired decision. The table starts empty; rules accumulate only from coach review.

### Weekly digest (`scripts/cron_coaching_digest.php` — Monday, hour 8 UTC)
Per head coach, **only when there is something to report** (≥1 open intelligence flag OR ≥1 flagged adjustment pending) — no empty digests. Four sections: Needs Attention (warning flags, max 5), Opportunities (opportunity flags, max 3), Pending Reviews (count), Roster Health (active athletes + 7-day average completion across the roster). Sent via `Mailer::send` + `EmailTemplates::build('coaching_digest', …)` (teal "Open Intelligence" CTA, no em dashes, no content tables). **Requires a manual NFSN scheduler entry** (SSH cannot add scheduled tasks).

### Phase 2 — pattern proposer, roster insights, weekly review (migration_028)

Phase 2 adds the *analysis* layer on top of Phase 1's capture pipes: it notices when a coach keeps making the same kind of change and drafts a rule from it, surfaces patterns that span multiple athletes, and packages the whole week into one review flow.

**New schema (migration_028, runner `scripts/run_migration_028.php`, idempotent):**
- `coaching_decisions.proposed_from_count` INT, `coaching_decisions.proposed_at` DATETIME — pattern-proposer bookkeeping.
- `coach_adjustments.proposed_decision_id` INT — set when an adjustment contributed to (or is already covered by) a proposal, so the proposer never reconsiders it.
- **`coach_roster_insights`** — cross-athlete patterns (`insight_type` ENUM: compliance_cluster, engagement_cluster, upcoming_races, adjustment_pattern, streak_cluster, workload_spike; `severity` info/warning/opportunity; `athlete_ids` LONGTEXT JSON; `status` open/dismissed).
- **`weekly_review_log`** — one row per (coach_id, week_start Monday); `completed_at` + counts (items_reviewed / decisions_added / flags_actioned / flags_dismissed). UNIQUE (coach_id, week_start).

**Pattern proposer (`src/PatternProposer.php`, `analyze($coachId, $db)`).** Called per coach from the Monday digest cron (so proposals are fresh in the email) and may run on demand. Groups un-reviewed, un-proposed `coach_adjustments` from the last 90 days by (change_type, ctx_phase, ctx_goal_distance). Threshold is roster-size aware: `<20` athletes → 2, `20–50` → 3, `>50` → 5. For each group ≥ threshold: if an active/proposed decision already covers the same (distance, phase) scope, the adjustments are tagged with that decision id and skipped; otherwise it drafts a **proposed** `coaching_decision` (auto title, `trigger_json` from the group keys + classification when consistent, `action_json` from the most common after-state — archetype_substitution → exclude most-common before + weight most-common after ×2; duration_change → median delta minutes; others → `{}` for coach review), tags the contributing adjustments with `proposed_decision_id`, and logs an `adjustment_pattern` roster insight. Like the rest of the layer, `analyze()` swallows/logs its own errors.

**Roster insights (`CoachingIntelligence::generateRosterInsights($coachId, $db)`).** Run per coach from the daily cron (after individual flags) and again from the Monday digest cron. Generates: compliance_cluster (3+ open compliance_dropping flags this week, warning), engagement_cluster (3+ engagement_dropping/dropout_risk, warning), upcoming_races (any athlete racing in the next 14 days, info), streak_cluster (3+ compliance_streak, opportunity), workload_spike (3+ rpe_trending_high, warning). Dedup: an insight_type is not re-created if one for the coach was created within the last 7 days (so the daily cron does not produce duplicates; upcoming_races therefore refreshes ~weekly).

**Weekly review UI (`GET /app/coach/intelligence/review`, `views/coach/intelligence_review.php`).** A single scrollable page with an estimated time (1 min/proposal + 30 s/flagged adjustment + 30 s/insight, clamped 2–15 min) and five sections: (1) **Proposed decisions** — editable title, required reason, scope pills, plain-English trigger/action summary, Approve (`…/decision/:id/approve`, sets active) / Modify (full modal → `…/decision/:id/modify`) / Dismiss (`…/decision/:id/dismiss`, sets inactive); (2) **Roster insights** — severity-bordered cards with athlete pills, Dismiss (`…/intelligence/insight/:id/dismiss`); (3) **Flagged adjustments** — the Phase 1 add-as-rule / dismiss flow embedded inline; (4) **Upcoming races** — athletes racing in the next 14 days (informational); (5) **Complete** — `…/intelligence/review/complete` INSERT/UPDATEs `weekly_review_log` for the current Monday with `completed_at` + counts. Review-flow forms post `from=review` so actions return to the review page; the Intelligence page / library default back to `…/intelligence`.

**Intelligence page additions** (without replacing the Phase 1 sections): a teal weekly-review prompt banner at the top (item count + est. minutes, or a muted "completed [day] at [time]" once `weekly_review_log.completed_at` is set for the current week); a **Roster insights** section above Athlete Flags (max 3, "View all" reveals the rest inline); a **Proposed decisions** section above Flagged for Review (count badge + top-2 preview + "Review all" link); and proposed rows in the **Decision Library** carry an amber "Proposed" badge, "Based on [N] adjustments", and inline Approve/Dismiss (the inline Approve auto-fills a reason; the weekly-review Approve requires one).

**Weekly digest additions.** `cron_coaching_digest.php` runs `PatternProposer::analyze()` then `CoachingIntelligence::generateRosterInsights()` per coach before building the email, and adds three sections after Opportunities: Proposed Rules (count + up to 3 titles + a "Review proposed rules" link to the review page), Roster Insights (up to 3, severity-ordered), and Upcoming Races (next 14 days). The send guard now also fires when proposed rules or roster insights exist. All Phase 2 work is guarded on the `coach_roster_insights` table so a pre-migration-028 run is a clean no-op.

### Phase 3 — predictive flags & athlete response modeling (migration_029)

Phase 3 adds forward-looking flags and per-athlete response modeling. It is an **engine, not AI**: every prediction is a deterministic, interpretable formula over the athlete's own history with named inputs (a coach can read exactly why a flag fired). It is **coach-facing only** (nothing is shown to athletes or auto-sent), **never touches the generation path** (no autonomous plan changes), and emits nothing below **4 weeks** of behavior history ("Not enough data yet"). Every prediction and metric carries a **confidence tier** (none/low/medium/high) that scales with weeks of data and per-metric sample size. All thresholds live in `src/PredictiveConstants.php` (the §17 intensity-factor philosophy — tunable without touching logic). READ-ONLY against athlete_behavior_log / coaching_intelligence_flags / training_load / completed_workouts / planned_workouts / athlete_profiles; writes only to its own intelligence tables.

**New schema (migration_029, runner `scripts/run_migration_029.php`, idempotent):**
- **`athlete_response_profiles`** — one row per athlete: `computed_at`, `weeks_of_data`, and a LONGTEXT `metrics_json` payload (each metric carries value / sample_size / confidence).
- `coaching_intelligence_flags` += `confidence` ENUM(low,medium,high) NULL, `prediction_horizon_days` INT NULL, `predicted_for_date` DATE NULL (NULL for Phase 1/2 flags), and four new `flag_type` values: `predicted_fatigue`, `predicted_dropout`, `injury_risk_pattern`, `adaptation_ahead`.

**Response profiling (`src/ResponseProfiler.php`).** Interpretable per-athlete metrics over a 182-day window: easy- vs quality-day RPE-vs-prescribed delta, sustained volume tolerance (highest weekly minutes held ≥2 consecutive compliant weeks), recovery signature (mean days from a TSB dip back to normalized), and cutback response (compliance bounce on cutback weeks). Each reports "not enough data" below its sample floor. Stored in athlete_response_profiles; surfaced in the athlete context panel; individualizes the predictions.

**Predictive flags (`src/PredictiveFlags.php`).** Four deterministic predictions, each UPSERTing the single open `coaching_intelligence_flags` row of its type per athlete (refreshing confidence/horizon/detail) and auto-resolving (dismissing) when the condition clears; a cooldown respects coach dismissals before re-surfacing:
- `predicted_fatigue` (warning, ~10d) — sustained volume ramp ≥ ratio + RPE trending high + TSB sharply negative and falling.
- `injury_risk_pattern` (warning, ~14d) — acute:chronic volume spike + RPE trending high *simultaneously*. Framed as a load PATTERN for coach attention, never a diagnosis or guarantee (principle 4); the hedge lives in the coach-facing copy.
- `predicted_dropout` (warning, ~21d) — engagement TRAJECTORY (negative slope) + low absolute score. Coexists with (does not replace) Phase 1 `engagement_dropping` / `dropout_risk`. *(Coexist-vs-supersede UX is a pending product decision; defaulted to coexist.)*
- `adaptation_ahead` (opportunity, ~14d) — high compliance + quality RPE trending easy + CTL rising with no fatigue/injury signal → a coach-approved PROPOSAL. **Accept** creates a pending `plan_regeneration_requests` row that routes through the EXISTING regeneration approval flow (head coach approves → generation); Phase 3 never calls PlanGenerator.

**Cron wiring.** The daily cron (after Phase 1/2) calls `PredictiveFlags::run()` to recompute every active athlete's response profile and predictive flags; the Monday digest refreshes them first so predictive flags (being coaching_intelligence_flags) flow into the existing Needs Attention / Opportunities sections. Both guarded on `athlete_response_profiles` so a pre-migration-029 run is a clean no-op.

**UI.** Intelligence page predictive flags show a confidence + horizon badge (and `adaptation_ahead` shows Accept → regeneration / Dismiss). The athlete context panel adds a **Predictions** section and a **Response profile** summary with a "Not enough data yet" empty state below 4 weeks (shared `views/partials/predictive.php`).

### Phase 4 — multi-coach support & coaching philosophy export (migration_030)

Phase 4 turns the single-coach intelligence layer into a multi-coach one. **Every feature is dormant until a second coaching account exists**: `CoachAssignments::multiCoach($db)` (≥2 active `coach`/`assistant_coach` users) gates all Phase 4 UI and behavior — with one sole coach nothing appears or changes. The coaching philosophy export is the lone exception (available to any coach). Builds on the existing `CoachAssignments` head/assistant model and the `coaching_decisions` resolver; no change to generation math or anything athlete-facing.

**New schema (migration_030, runner `scripts/run_migration_030.php`, idempotent; 031 reserved for regen):**
- `coaching_decisions.status` ENUM += `proposed_by_assistant`; `coaching_decisions.shared` TINYINT(1) (head-coach roster-wide sharing); `coaching_decisions.rationale` TEXT (the "why", for the export).
- `coaching_intelligence_flags.status` ENUM += `superseded`, with a one-time backfill of the Phase 3 `[auto-resolved]` marker rows. **Trap cleanup:** `PredictiveFlags::resolveSuperseded()` now writes `status='superseded'` (not `dismissed` + text marker) and the `predicted_dropout` cooldown keys off `status <> 'superseded'` (coach dismissals still cool down; superseded handoffs re-arm freely).

**Decision sharing (resolver).** `CoachingDecisions::loadActiveForAthlete()` now resolves `status='active' AND (shared=1 OR created_by IN {athlete's head + assistant})`. A head coach toggles `shared` on their own active rules (Decision Library). Shared active rules apply across every coach's athletes; a coach's non-shared active rules stay scoped to their own athletes. `proposed` and `proposed_by_assistant` never reach generation — only `active` does.

**Assistant proposals.** An assistant coach's add-as-rule saves `status='proposed_by_assistant'` (mirrors the assistant request→approve capability pattern). The managing head coach sees an **Assistant proposals** section on the Intelligence page and Approves (→`active`) or Dismisses (→`inactive`). Excluded from the resolver until active; an assistant cannot self-activate (the active/inactive toggle ignores proposed statuses).

**Inheritance / playbook import.** A one-time **"Import founding coach's rules"** button (shown to a coach with no rules yet, when multi-coach) copies the founding coach's active rules — **shared and non-shared alike** — as editable `proposed` copies owned by the importer; the originals and their shared flags are untouched. ("Here's my full playbook, make it yours.") Not auto-seeded at account creation.

**Admin coach analytics (`/app/admin/coaches`, dormancy-gated).** Read-only per-coach aggregations: average athlete compliance (last 28 days), mean flag-resolution time (last 90 days — counts genuine coach actions `actioned`/`dismissed` only, **excludes** `superseded` auto-resolutions), and retention (active ÷ all-time-assigned athletes).

**Coaching philosophy export (`/app/coach/intelligence/philosophy`, any coach).** A print-styled standalone page (browser save-to-PDF; server-side PDF deferred) rendering the coach's active rules — their own plus the shared rules they rely on — with title, plain-prose trigger/action, and rationale.

### Roadmap
- **Phase 1:** capture pipes, behavior metrics, pattern flags, flag-for-review, decision resolver, weekly digest. **Complete.**
- **Phase 2:** weekly-review UI, pattern proposer (rules from recurring adjustments), cross-athlete roster insights. **Complete.**
- **Phase 3:** predictive flags (predicted_fatigue / injury_risk_pattern / predicted_dropout / adaptation_ahead) and athlete response modeling. **Complete.**
- **Phase 4:** multi-coach support — decision sharing, assistant proposals, playbook import, admin coach analytics, coaching philosophy export. **Complete.** (The Coaching Intelligence Layer is now feature-complete across all four phases.)

## 33. Regen carry-over of athlete-exposed weeks (migration_031)

By default a regeneration now **preserves every whole week the athlete has already seen** instead of wiping the plan and rebuilding from scratch. `PlanGenerator::generate($athleteId, $trigger, bool $fullWipe = false)`:

- **Capture (before archive):** `capturePreservation()` looks at the prior **ACTIVE** plan and collects, within the new plan's forward span (>= tomorrow): every row in an **exposed whole week** (a Mon–Sun week with ≥1 `visible_to_athlete=1` row) plus any **coach_locked** row anywhere (principle 4 — coach edits are sticky). Returns null (→ legacy behavior) when there is no active prior plan or nothing exposed/locked.
- **Generation math is UNCHANGED:** the new plan is generated full-span exactly as before (volume/cutback/phase/continuity/lead-in/code-week anchoring). The carried signatures/codes are merged into the anti-repeat history so the fresh remainder never duplicates a carried quality instance within the 28-day hard block.
- **Carry-over (after generation):** `applyPreservation()` deletes the freshly-generated rows on the carried dates and **MOVES** the preserved prior rows into the new plan — reassigning `plan_id` and stamping `carried_over_from_plan_id` / `carried_over_at`, keeping the row's **id** (so the `srf_{id}` Intervals.icu event survives — never delete+recreate), content, visibility, and `coach_locked`. Because exposed units are whole Mon–Sun weeks, the fresh remainder begins at a clean Monday with no mixed weeks. A volume seam at the carried→fresh boundary is accepted (every regen is coach-reviewed in `pending_approval`).
- **Intervals continuity:** `archivePreviousPlans()` / `IntervalsService::deleteEventsForPlan()` take an exclude list so carried workouts' events are spared; non-carried prior events are still deleted.
- **Schema (migration_031):** `planned_workouts.carried_over_from_plan_id` INT NULL + `carried_over_at` DATETIME NULL.
- **Full-wipe escape hatch:** a REQUIRED checkbox on both regen entry points (coach Generate Plan + head-coach regeneration approval), default unchecked, labelled "Regenerate entire plan including days that have been exposed to the athlete." Checked → `$fullWipe=true` → legacy archive-all + delete-all-events + rebuild.
- **Coach badge:** carried rows show a coach-only "↻" badge (`.macro-carried-badge`) in the macro plan, mirroring the assistant-coach "AC" badge; never shown to athletes.
- Verified by `scripts/verify_regen_carryover.php` (byte-identical carry + same id + event intact, coach_locked preserved, clean Monday boundary, anti-repeat within window, full-wipe carries nothing).
