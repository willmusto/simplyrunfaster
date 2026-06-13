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
| phone_number | VARCHAR | nullable — E.164 format, used for SMS notifications |
| phone_verified | BOOL | default false — verified via SMS code before SMS notifications activate |
| signup_source | ENUM | invite, organic, ad_campaign, other |
| invite_code | VARCHAR | nullable — the invite code used at signup |
| ad_campaign_id | VARCHAR | nullable — UTM campaign tag for ad-driven signups |
| ad_source | VARCHAR | nullable — UTM source (e.g. instagram, facebook) |
| created_at | DATETIME | |

### `athlete_profiles`
Populated by onboarding form(s). The engine reads this at plan generation time.

| Column | Type | Notes |
|---|---|---|
| athlete_id | INT FK | |
| goal_race_date | DATE | primary target race |
| goal_race_distance | VARCHAR | 5K, 10K, HM, marathon, ultra, etc. |
| goal_finish_time | VARCHAR | optional time goal |
| current_weekly_mileage | FLOAT | self-reported at onboarding |
| training_days_per_week | INT | availability |
| long_run_day | TINYINT | preferred day of week (0=Sun) |
| workout_day_preference | VARCHAR | JSON array of preferred days |
| experience_level | ENUM | beginner, intermediate, advanced |
| injury_history | TEXT | free text or structured JSON |
| hr_zones | JSON | if available from watch data |
| pace_zones | JSON | calculated from recent race/time trial |
| watch_platform | ENUM | garmin, polar, apple, wahoo, none |
| watch_connected | BOOL | default false |
| pace_zones_visible | BOOL | default true — coach/admin can hide from athlete UI |
| pace_zones_hidden_reason | TEXT | nullable — internal coach note explaining why zones are hidden |
| cross_training_bike | ENUM | none, stationary, road_gravel |
| cross_training_elliptical | ENUM | none, gym, home |
| cross_training_pool | BOOL | default false |
| cross_training_other | TEXT | nullable — free text |
| updated_at | DATETIME | |

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
| display_summary | VARCHAR | nullable; engine-generated subtitle (e.g. "30 min · 2.0–3.0 miles") — non-editable |
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
| flag_type | ENUM | missed_workouts, hr_elevated, load_spike, compliance_low, plan_rebuild_needed, compliance_trend, compliance_pattern, excessive_fatigue, fitness_decline, taper_concern, insufficient_base, return_to_running_discomfort, limited_development_opportunity, long_run_day_conflict, display_generation_incomplete |
| severity | ENUM | info, warning, critical |
| flag_date | DATE | |
| details | JSON | machine-readable context |
| message | TEXT | human-readable summary for coach — canonical column name is `message` |
| status | ENUM | open, dismissed, acted_on |
| reviewed_by | INT FK | nullable |
| reviewed_at | DATETIME | nullable |

**Flag deduplication:** Before inserting any engine flag, the engine checks for an existing open flag of the same `flag_type` for the same athlete and skips the insert if one is found. This applies to all engine-raised flags at generation and evaluation time — a given flag type will appear at most once in the open state per athlete. **Exception: `display_generation_incomplete`** is inserted without deduplication — each plan generation raises its own flag independently, because display completeness is plan-specific. See engine spec §18.8.

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
Legacy template table seeded from initial workout library (WL-001 through WL-023, documented in the Workout Library document). **This table is no longer joined by AthleteController, CoachController, or TrainingLoad.php for archetype-generated workouts.** It remains in the database for historical reference and as a seed source for initial content. The archetype engine (workout_archetypes table + ArchetypeSelector) is the active workout prescription layer. Archetype/variant swapping UI was removed in Milestone 3.5 and will be redesigned in a future milestone.

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
| added_by_role | ENUM | athlete, coach, admin |
| race_name | VARCHAR | free text |
| race_distance | ENUM | 5K, 10K, 15K, half, marathon, ultra, other |
| distance_override | FLOAT | nullable — miles, used when distance = other |
| race_date | DATE | |
| is_goal_race | BOOL | default false |
| result_time | INT | nullable — finish time in seconds, set after race |
| result_synced_from_watch | BOOL | default false |
| recalibration_proposed | BOOL | default false |
| recalibration_approved | BOOL | nullable |
| recalibration_approved_by | INT FK | nullable |
| recalibration_approved_at | DATETIME | nullable |
| proposed_pace_zones | JSON | nullable — engine-computed zones from result |
| notes | TEXT | nullable — coach notes on this race |
| created_at | DATETIME | |

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

---

## 6. Watch Integration (Optional — Athlete Value-Add)

**Watch integration is entirely optional for athletes.** Athletes without a watch can log workouts manually and self-report RPE. The engine functions fully with manual data. Watch sync is a value-add that improves data quality and athlete convenience, not a platform requirement.

### Strava Integration — Status: Pending API Approval

Strava's API would provide a universal pull layer — any watch platform that syncs to Strava (Garmin, Polar, COROS, Suunto, Apple Watch, phone-based) would feed into SimplyRunFaster automatically. This would significantly broaden compatibility without requiring direct integrations with every platform.

**However:** Strava's production API approval process is notoriously slow and opaque. Approval is not guaranteed and may take months or never arrive. SimplyRunFaster should apply for Strava API access immediately upon beta launch but must not be architecturally dependent on it.

**Application strategy:** Submit Strava API application at beta launch with real usage data from initial cohort. If approved before general availability, Strava becomes the primary pull integration. If not approved, direct watch integrations cover the majority of the target athlete demographic.

**Architecture decision:** Build direct watch integrations as the primary data layer. Strava is a parallel application in progress, not a milestone dependency.

### Phase 1: Garmin (Build First)

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

Coach dashboard also includes:
- In-app messaging thread per athlete (see Section 14)
- Push notification controls (see Section 15)

---

## 9. Onboarding Flow

Triggered on first login. Completion sets `onboarding_completed_at` and queues plan generation.

**Form sections (can be split across multiple screens):**

1. **Goal** — target race, distance, date, time goal (optional)
2. **Current fitness** — current weekly mileage, longest recent long run, most recent race result (optional)
3. **Availability** — days per week, preferred long run day, any days unavailable
4. **History** — years running, injury history, highest-ever weekly mileage
5. **Watch setup** — platform selection, OAuth connect (optional — clearly skippable, no friction if declined)
6. **Preferences** — units (miles/km), notifications, dark/light mode

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
- Transactional email (Postmark or SendGrid)
- SMS (Twilio — for SMS notification channel and phone number verification)

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

**Service worker lifecycle:** `sw.js` calls `self.skipWaiting()` in the install event so a newly installed SW activates immediately without waiting for existing tabs to close. The activate event deletes all caches whose name differs from `CACHE_NAME`, then calls `self.clients.claim()` to take immediate control of all open clients — stale-cache deletion completes before `clients.claim()` resolves. A `{type: 'SKIP_WAITING'}` message listener allows DevTools force-activation of a waiting SW during testing without requiring a reinstall.

**PRECACHE list:** The SW pre-caches `/app`, `/app/plan`, `/app/offline`, and `/manifest.json`. `app.css` and `app.js` are **not** pre-cached. SW cache matching does not ignore query strings by default, so pre-caching the un-versioned path would never match requests for the versioned URL. These assets are instead cached lazily by the `cacheFirst` handler on the first request — when the versioned URL produces a cache miss, the file is fetched from the network and stored under the versioned key. A new deploy changes the `?v=` value, producing a fresh cache miss and a fresh fetch automatically.

**Install-time HTTP cache bypass:** PRECACHE items are fetched with `{cache: 'reload'}` mode during install, bypassing the browser's HTTP cache. Without this, a browser that has `app.css` cached with `max-age=31536000, immutable` would return the stale cached bytes to the SW during install — meaning a CACHE_NAME bump would create a new SW cache containing old CSS, producing no visible change for the user.

**CACHE_NAME convention:** `srf-YYYYMMDD`. Bumped on every deploy by running `sed -i "s/srf-[0-9]*/srf-$(date +%Y%m%d)/" sw.js` in the project root. The activate event uses string equality (`key !== CACHE_NAME`) to identify stale caches — any change to the string, regardless of direction, invalidates the old cache.

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
| sender_role | ENUM | athlete, coach |
| body | TEXT | message content |
| sent_at | DATETIME | |
| read_at | DATETIME | nullable — when recipient read it |
| push_sent | BOOL | whether push notification was sent |
| message_type | ENUM | message, session_note, session_note_reply |
| completed_workout_id | INT FK | nullable — links to session thread origin |
| thread_id | INT FK | nullable — self-referencing for session threads |

### `session_notes`
Session-level notes and threaded conversations. Separate from the main message thread but surfaces into it as linked cards.

| Column | Type | Notes |
|---|---|---|
| id | INT PK | |
| completed_workout_id | INT FK | the session this note belongs to |
| athlete_id | INT FK | |
| author_id | INT FK | user_id — athlete or coach |
| author_role | ENUM | athlete, coach |
| body | TEXT | note content |
| created_at | DATETIME | |
| soft_limit_chars | INT | 500 — configurable, not hard-enforced |
| hard_limit_chars | INT | 1000 — requires confirmation tap to exceed soft limit |

### Coach UI
- Message thread accessible from athlete profile sidebar
- Unread message count badge on athlete roster row
- Compose from athlete context — coach sees athlete's training data while writing
- All athletes' unread messages surfaced in a unified "Messages" section of coach dashboard

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
| 1 | 1 min | 2 min | 1 (2 for extended break) |
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
- Progression advances only on a clean session — no reported pain or discomfort
- If athlete reports pain or discomfort (via post-session note or modified RPE prompt): engine holds progression at current stage, flags coach immediately
- If athlete skips a session: engine repeats current stage on next scheduled run day — not a compliance flag, just a hold
- Progression never skips stages regardless of how the athlete feels
- Coach can manually advance or hold stages from athlete plan view

### Modified RPE Prompt During Return-to-Running
Post-session prompt adds a fifth option:
- Easy / Moderate / Hard / Very Hard / **I felt some discomfort**
"I felt some discomfort" immediately flags coach and holds progression.

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
- Engine raises info flag: "[Athlete] has completed return-to-running progression — ready to discuss next plan type"
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

### Database Table: `notification_preferences`

| Column | Type | Notes |
|---|---|---|
| user_id | INT FK | |
| notification_type | VARCHAR | matches notification type keys above |
| enabled | BOOL | |
| channel_push | BOOL | default true |
| channel_email | BOOL | default false |
| channel_sms | BOOL | default false — only settable for high-signal notification types |
| quiet_hours_start | TIME | default 22:00 |
| quiet_hours_end | TIME | default 07:00 |
| preferred_time | TIME | for scheduled notifications (tomorrow's plan, weekly summary) |
| preferred_day | TINYINT | 0–6, for weekly notifications |
| updated_at | DATETIME | |
| PRIMARY KEY | (user_id, notification_type) | |

### Watch Push vs. App Push
For athletes with a connected watch, the "Tomorrow's plan" notification includes a line confirming the workout was pushed to the watch ("Pushed to your Garmin"). This replaces a separate "workout pushed to watch" notification — one notification, more information, less noise.

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

### Milestone 2 — In-App Messaging + Push Notifications
- Messages table and thread UI (athlete + coach)
- Push notification service worker integration
- Web Push API — all notification events from Section 11
- Email fallback (Postmark/SendGrid) for non-PWA users
- Unread badge counts on coach roster and athlete nav

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
