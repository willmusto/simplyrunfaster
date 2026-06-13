# Running Coaching Platform — Engine Logic Specification
*Version 1.0 — Companion to System Architecture Document*

---

## 1. Coaching Philosophy Foundation

The engine is built on a synthesis of four coaching traditions:

- **Lydiard:** Aerobic base is foundational for every athlete at every distance. No athlete skips base development. The aerobic foundation determines the ceiling for everything that follows.
- **Hanson:** Cumulative fatigue is a training stimulus. Long runs derive meaning partly from being run on accumulated fatigue, not always on fresh legs. Weekly load is considered holistically, not workout by workout.
- **Hart:** Top-end footspeed is a ceiling for every runner regardless of goal distance. Speed development is present in every phase for every athlete, not reserved for short-distance specialists or peak phase only.
- **Daniels (math only, not terminology):** Pace equivalency relationships between distances are used internally for pace zone derivation. McMillan equivalency tables are the implementation. No Daniels terminology is used in athlete-facing content or internal documentation.

**The engine's core philosophical output:** Every week contains multiple quality stimuli at different intensities and durations. Easy days are genuine recovery, not filler. Intensity distribution emerges from the week's structure rather than being a percentage target worked backward from.

---

## 2. Pace Zone Derivation

### Source
McMillan Running equivalency tables, implemented as internal math. Not attributed in athlete-facing content or coach-facing UI.

### Inputs
- Most recent race result (distance + time), OR
- Coach-entered time trial result, OR
- Goal race pace (used for prescription targets, not fitness assessment)

### Output
A pace zone profile for the athlete covering:
- Easy effort range (min/mile)
- Long run effort range (min/mile)
- Marathon pace
- Half marathon pace
- 10K pace
- 5K pace
- Mile pace
- 800m pace
- 400m pace

### Usage
- Pace zones are used for **quality session prescription only**
- Easy runs are **never** prescribed by pace — time on feet only
- Zones are recalculated when a new race result or time trial is entered
- Coaches can manually override any zone

---

## 3. Training Prescription Rules

### Easy Runs
- **Always prescribed by duration (time on feet), never by distance or pace**
- Compliance is evaluated against duration and effort, not distance covered
- Effort signal: HR data from watch (primary), RPE (not collected for easy runs)
- HR discrepancy flagged silently for coach review — not surfaced to athlete

### Quality Sessions
- Prescribed by either duration or distance depending on workout type (specified per workout template in library)
- Post-activity RPE collected via single-tap prompt on watch or mobile
- Four options presented to athlete: **Easy / Moderate / Hard / Very Hard**
- Maps internally to 1–10 scale: Easy=1-3, Moderate=4-5, Hard=6-7, Very Hard=8-10
- Athlete never sees numerical RPE values

### Long Runs
- Always prescribed by duration (time on feet)
- Character: embedded workout (default) or pure aerobic effort
- **Hard constraint:** Within 7 days of any race (goal or tune-up), long run is always pure aerobic, no embedded workout
- Duration scales down in race week context based on taper phase and proximity to race
- Roughly 25-30% of long runs across a cycle are pure aerobic efforts; remainder have embedded workout segments
- Phase-aware: base phase long runs lean more aerobic, peak phase long runs more often contain embedded workouts, build phase transitions between the two

### Strides
- Almost never prescribed as standalone sessions
- Attached to easy runs as a finishing stimulus
- Exception: beginner athletes may receive a structured stride session as a workout (e.g., 10x20 second sprints with walk-back recovery)

---

## 4. Phase Structure

### Phase Proportions (% of total cycle length)

| Phase | Default % | Notes |
|---|---|---|
| Base | 30% | Expandable for undertrained athletes |
| Build | 30% | Expandable for well-trained athletes |
| Peak | 20% | Largely fixed |
| Taper | 15% | Largely fixed |
| **Remainder** | 5% | Added to base or build, never peak or taper |

Percentages are applied to total cycle length and rounded to whole weeks. Remainder weeks are added to base phase by default, or build phase if athlete is well-trained.

### Minimum Cycle Lengths by Distance

| Distance | Minimum Cycle |
|---|---|
| 5K | 8 weeks |
| 10K | 10 weeks |
| Half Marathon | 12 weeks |
| Marathon | 14 weeks |
| Ultra | TBD (v2) |

### Phase Length Examples

| Total Cycle | Base | Build | Peak | Taper |
|---|---|---|---|---|
| 8 weeks | 2–3 | 2–3 | 2 | 1 |
| 10 weeks | 3 | 3 | 2 | 1.5 |
| 12 weeks | 3.6 | 3.6 | 2.4 | 1.8 |
| 16 weeks | 4.8 | 4.8 | 3.2 | 2.4 |
| 20 weeks | 6 | 6 | 4 | 3 |
| 24 weeks | 7.2 | 7.2 | 4.8 | 3.6 |

### Profile-Adjusted Phase Proportions

| Athlete Base Classification | Base % | Build % | Peak % | Taper % |
|---|---|---|---|---|
| Well trained | 20% | 40% | 20% | 15% |
| Workable | 30% | 30% | 20% | 15% |
| Insufficient | 40% | 25% | 20% | 15% |

Remainder always goes to base or build, never peak or taper.

---

## 5. Athlete Base Classification

Classification is based on **combination of weekly mileage and length of running experience.** Both factors must meet the threshold — mileage alone or experience alone is insufficient.

### Classification Matrix

| Distance | Well Trained | Workable | Insufficient |
|---|---|---|---|
| 5K | 6+ months, 25+ mpw | 3+ months, 15+ mpw | Below workable |
| 10K | 9+ months, 30+ mpw | 6+ months, 20+ mpw | Below workable |
| Half Marathon | 12+ months, 35+ mpw | 9+ months, 25+ mpw | Below workable |
| Marathon | 18+ months, 45+ mpw | 12+ months, 35+ mpw | Below workable |

### Engine Response by Classification

**Well Trained**
- Standard phase proportions (shifted toward build)
- No flags raised at onboarding
- Normal plan generation and approval flow

**Workable**
- Standard phase proportions
- Informational flag raised for coach awareness at onboarding call
- Coach may adjust goal focus (completion vs. time) or proceed as-is
- Early weeks are more conservative than athlete may expect

**Insufficient**
- Plan generates but enters mandatory hold state (not standard approval queue)
- Athlete sees week one only until onboarding call resolves
- Coach decides: redirect to shorter distance, extend cycle, or proceed with heavily modified plan
- Engine raises `insufficient_base` flag, severity: critical

---

## 6. Volume Progression

### Weekly Build Rate
- Default: consistent week-over-week build
- Individual run length cap: **maximum 15% longer than any individual run in the prior 30 days**
- Weekly volume increase: **maximum 10%** week-over-week (universal guardrail)

### Cutback Weeks
- Frequency: every 4th or 5th week (engine determines based on cycle length and phase)
- Volume reduction: 20–25% below the preceding week
- **Implemented cutback ratio: 0.80× (20% reduction — the conservative end of the range).** 0.75× (25%) risks dropping `weeklyMins` below the per-slot floor sum (long-run floor + quality-slot floor + easy-slot floor). When that happens, easy slots clamp at their minimum regardless of `weeklyMins`, producing apparent growth suppression in the 1–2 weeks post-cutback with no bug in the build formula. 0.80× keeps `weeklyMins` above this floor threshold for narrow-schedule athletes (≤3 days) at typical development-plan volumes (~120–160 min/week).
- Intensity distribution: maintained at comparable levels, with 3–5% reduction by default
- Week shape unchanged: still 1 long run, 1 primary workout, easy days — everything dials back proportionally
- Cutback weeks do not count against phase progression — the following week resumes building

### Coach-Set Peak Volume Ceiling
- Each athlete profile has a `peak_volume_ceiling` field (in minutes per week, consistent with time-on-feet philosophy)
- Default value derived from onboarding data: current weekly time on feet × 1.4 (conservative multiplier)
- Coach can adjust at any time
- Engine never exceeds this ceiling regardless of progression logic
- When ceiling is reached, engine maintains volume and shifts stress toward intensity

---

## 7. Weekly Template Structure

### Canonical Week

| Stimulus Type | Frequency | Notes |
|---|---|---|
| Long run | 1x always | Pure aerobic or embedded workout |
| Primary workout | 1x always | Structured quality session |
| Secondary workout or hill session | 0–1x | Proactive if athlete runs 5+ days/week |
| Easy run with strides | 0–3x | Strides attached as finishing stimulus |
| Pure easy run | Remainder of running days | |
| Rest | Minimum 1x/week | |

### Scheduling Logic

**Fixed day mode:** Athlete nominates long run day and primary workout day at onboarding. Engine schedules around those anchors.

**Flex mode:** Engine schedules long run and primary workout based on:
- Athlete's available days (from onboarding)
- Minimum 2-day separation between primary workout and long run
- Minimum 1 rest day per week
- Secondary workout slotted at least 1 day from any other quality stimulus

### Second Workout Scheduling
- Engine schedules secondary workout slot proactively when athlete commits to 5+ days/week running
- Not reserved for peak phase — can appear in any phase when load supports it
- Secondary workout is lighter than primary (hills, fartlek, or short speed session rather than track intervals)
- Never scheduled within 2 days of primary workout or long run

### Onboarding Scheduling Questions
- How many days per week are you running?
- Which days are absolute rest days (must-off days)?
- Do you prefer fixed workout days or flexible scheduling?
- If fixed: which day for long run? Which day for primary workout?
- Do you have track access?

---

## 8. Phase Character

### Base Phase
**Primary stress:** Volume accumulation
**Speed stimulus:** Present but neuromuscular in character — strides, short hill sprints, gentle fartlek
**Long run character:** More often pure aerobic; embedded workouts are progression-style or terrain-based rather than pace-specific
**Primary workout character:** Aerobic threshold, tempo efforts, hill circuits
**Secondary stimulus:** Hill-specific sessions, easy fartlek

### Build Phase
**Primary stress:** Volume maintained, intensity increasing
**Speed stimulus:** More structured — longer hill repeats, tempo intervals, beginning of race-pace work
**Long run character:** Transitioning — more embedded workouts, beginning to introduce goal pace segments
**Primary workout character:** Tempo intervals, cruise intervals, race-pace segments
**Secondary stimulus:** Hill repeats, structured fartlek, short speed sessions

### Peak Phase
**Primary stress:** Specificity — highest intensity work, most race-specific sessions
**Speed stimulus:** Track sessions, true race-pace efforts, sub-race-pace efforts for speed development
**Long run character:** Most often contains embedded workout; race-specific pace work within long run
**Primary workout character:** Race-pace intervals, speed endurance work, tune-up races
**Secondary stimulus:** Sharpening sessions, short speed work

### Taper Phase
**Primary stress:** Fatigue shedding while maintaining sharpness
**Volume:** Reducing week over week toward race week
**Intensity:** Maintained — short, sharp efforts preserve neuromuscular readiness
**Long run:** Shorter duration, always pure aerobic within 7 days of race
**Race week:** One short shakeout run, otherwise rest and easy movement

---

## 9. Race Handling

### Goal Race
- Defined at onboarding
- Anchors the entire plan structure (phase proportions calculated backward from race date)
- Cannot be moved by the engine — requires coach action

### Tune-Up Races
- Can be added by coach at any time
- Engine treats tune-up race week as a modified peak week:
  - Reduced volume in days preceding race
  - Long run the week before is pure aerobic
  - Post-race recovery days inserted automatically (scaled to race distance)
  - Race result ingested as new fitness data point — pace zones recalculated if result is more recent than current reference

### Pre-Race Long Run Rule
- Within 7 days of any race (goal or tune-up): long run is always pure aerobic, no embedded workout
- Duration reduced contextually based on taper phase and proximity to race
- Engine applies this rule automatically — not overridable by engine adjustments, only by coach manual edit

### Post-Race Recovery
Engine inserts recovery days after any race automatically:

| Race Distance | Recovery Days (easy/rest only) |
|---|---|
| 5K | 2–3 days |
| 10K | 3–5 days |
| Half Marathon | 5–7 days |
| Marathon | 10–14 days |

---

## 10. Compliance Scoring

### Easy Run Compliance
- **Duration compliance:** actual duration / prescribed duration (capped at 1.0)
- **Effort compliance:** derived from HR data when available; falls back to manual self-report when no watch connected
  - HR in easy zone (zone 1–2): full effort compliance
  - HR in zone 3: minor flag, logged silently
  - HR in zone 4+: effort compliance flag raised for coach review
  - No watch: engine notes absence of HR data; coach can see which athletes are watch-less at a glance
- Overall easy run compliance score: weighted average of duration and effort components
- No RPE collected for easy runs
- **Manual logging:** athletes without a watch log duration and a simple effort descriptor (Easy / Moderate / Hard). Engine treats manual easy-run effort descriptor as effort compliance signal.

### Quality Session Compliance
- **Execution compliance:** did the athlete complete the prescribed structure?
- **Effort compliance:** RPE collected post-activity (all athletes, watch or no watch)
  - Expected RPE range defined per workout template
  - Discrepancy between expected and reported RPE flagged for coach review
- HR cross-reference used as secondary signal when watch data available
- Distance or duration compliance depending on how workout was prescribed
- **Manual logging:** athletes without a watch report duration completed, structure completed (yes/partial/no), and RPE. Engine scores compliance from these inputs.

### Long Run Compliance
- Duration compliance scored same as easy run
- Effort compliance evaluated by HR
- If embedded workout: workout segment compliance scored separately from easy portions

### Compliance Score Thresholds

| Score | Status | Engine Action |
|---|---|---|
| 0.85–1.0 | Good | No action |
| 0.70–0.84 | Acceptable | Logged, monitored for trends |
| 0.50–0.69 | Poor | Flag raised for coach review |
| Below 0.50 | Critical | Flag raised, rolling adjustment triggered |

### Trend-Based Flags
- 3 consecutive weeks of acceptable or below compliance → `compliance_trend` warning flag
- 2 consecutive weeks of poor or critical compliance → `compliance_low` critical flag
- Consistent pattern (e.g., always skips Thursdays, always runs faster than easy effort) → `compliance_pattern` info flag for coach awareness

---

## 11. Training Load Model

### Metrics Computed Daily
- **ATL (Acute Training Load):** 7-day exponentially weighted average of daily training stress
- **CTL (Chronic Training Load):** 42-day exponentially weighted average of daily training stress
- **TSB (Training Stress Balance):** CTL minus ATL
  - Positive TSB: athlete is fresh (reduced fatigue relative to fitness)
  - Negative TSB: athlete is under load (accumulated fatigue)
  - Race day target: TSB slightly positive (typically +5 to +15)

### Training Stress Calculation
Each completed workout generates a training stress score based on:
- Duration
- Intensity (HR zone distribution or RPE)
- Workout type modifier — expressed as an `intensity_factor` per archetype (see Section 17)

**Intensity load pre-computation:** `intensity_load` is written to `planned_workouts` at plan generation time as `target_duration × effective_intensity_factor`, where the effective factor is the resolved variant's `intensity_factor` if it specifies one, otherwise the archetype's `generation.intensity_factor` (see §17). `TrainingLoad.php` reads this value and derives `intensity_factor = intensity_load / target_duration` for use in the `actual_duration × intensity_factor` stress formula. This means training stress is computed against the archetype's or variant's prescribed intensity regardless of the athlete's actual pace. For pre-archetype (legacy) workouts where `intensity_load` is null, `TrainingLoad.php` falls back to the `workout_library` join to read the intensity factor directly.

### Load-Based Engine Flags

| Condition | Flag Type |
|---|---|
| ATL spike >20% in one week | `load_spike` warning |
| TSB more negative than -30 | `excessive_fatigue` warning |
| CTL declining for 3+ weeks without taper context | `fitness_decline` info |
| TSB not recovering toward positive entering race week | `taper_concern` warning |

---

## 12. Engine Adjustment Logic

### Post-Workout Rolling Adjustment (Autonomous)
Runs after each completed workout is synced. Operates within the visible 7–10 day window only. Never touches `coach_locked` workouts.

**Permitted autonomously:**
- Swap next easy run → rest day (triggered by compliance score below 0.50 or ATL spike)
- Reduce next easy run duration by up to 15%
- Downgrade next workout intensity (e.g., reduce pace targets, shorten intervals) if HR data or RPE suggests excess fatigue
- Shift a non-locked workout forward 1 day within the window

**Not permitted autonomously (raises flag for coach):**
- Removing a quality session entirely
- Adding volume above weekly cap
- Moving goal race date
- Any change to a `coach_locked` workout
- Any change outside the 10-day visible window
- Any change that would violate a universal guardrail

### Weekly Batch Re-evaluation (Sunday Night)
Runs once per week for each active athlete.

1. Score the week: compliance, load absorbed, deviation from plan
2. Recompute ATL/CTL/TSB
3. Evaluate macro plan trajectory
4. If on track: minor adjustments to upcoming weeks within guardrails, no approval needed
5. If off track: raise `plan_rebuild_needed` flag, notify coach

### Plan Rebuild Triggers
- Athlete completes onboarding (automatic, first plan)
- End of training block (engine-initiated)
- Coach manually requests rebuild
- Engine determines plan is unrecoverable (`plan_rebuild_needed` flag)
- Tune-up race result significantly changes fitness assessment

**All plan rebuilds require coach approval before athlete sees changes.**

---

## 13. Universal Guardrails (v1)

Hard rules the engine cannot violate autonomously under any circumstances:

| Rule | Limit |
|---|---|
| Individual run length increase | Max 15% longer than any run in prior 30 days |
| Weekly volume increase | Max 10% week-over-week |
| Weekly volume decrease (unplanned) | Max 20% |
| Quality sessions per week | Max 2 (primary + secondary) |
| Long run as % of weekly volume | Max 35% |
| Consecutive hard days | Max 2 before mandatory easy/rest day |
| Minimum rest days per week | 1 |
| Taper minimum duration | Per minimum cycle length by distance |
| Maximum plan length | 24 weeks |
| Minimum plan length | Per minimum cycle length by distance |
| Pre-race long run | Always pure aerobic within 7 days of any race |

Any adjustment that would violate a guardrail is blocked. Engine either finds an alternative or raises a flag for coach review.

Profile-influenced guardrails are a **v2 feature.**

---

## 14. Workout Library Structure

### Library Entry Fields
Each workout template in the library contains:
- Name (internal)
- Athlete-facing description (plain language, no jargon)
- Workout type (easy / long / tempo / interval / hill / fartlek / race_pace / recovery / rest)
- Phase tags (base / build / peak / taper — can be multiple)
- Distance tags (5K / 10K / half / marathon — can be multiple)
- Prescription type (time-based or distance-based)
- Structure (JSON: warmup, main set, cooldown with targets per segment)
- Effort targets (pace zone references or HR zone references)
- Track required (yes / no / preferred)
- Secondary stimulus flag (suitable as secondary workout vs. primary only)
- Long run embedded flag (suitable as embedded long run workout vs. standalone only)
- Minimum athlete base classification required
- Created by (coach user ID)

### Initial Library Population
**Method:** Strava MCP session — pull 8 years of training data, identify recurring workout structures, build initial library from actual coached sessions.

**Target:** 30–50 foundational workouts covering all phases and distances before engine launch. Library grows continuously as coaches add new templates.

### Workout Categories Needed (Minimum Viable Library)

**Base phase:**
- Easy run with strides (time-based)
- Hill sprint session (short, steep, neuromuscular)
- Easy fartlek (time-based, loose structure)
- Pure easy long run (time-based)
- Progression long run (easy → moderate finish)

**Build phase:**
- Tempo intervals (time or distance)
- Cruise intervals
- Longer hill repeats
- Structured fartlek
- Long run with marathon pace segments
- Long run with progression finish

**Peak phase:**
- Race-pace intervals (distance-based)
- Speed endurance intervals (sub-race-pace)
- Track session (short reps, race pace and faster)
- Long run with race-pace segment
- Sharpening session (short, fast, low volume)

**All phases:**
- Easy run (time-based, no strides)
- Easy run with strides
- Rest day
- Cross-training day

---

## 15. Plan Type Awareness

The engine generates different plan structures based on plan type. Plan type is set at onboarding or changed by coach during a cycle.

| Plan type | Engine behavior |
|---|---|
| race_cycle | Backward-plans from goal race date — base/build/peak/taper phases |
| development_plan | Forward-plans from current fitness — volume building, no race anchor |
| maintenance_plan | Holds at 80-90% of peak volume — no phase progression |
| recovery_block | Conservative post-race structure — cross-training heavy, no quality sessions |
| return_to_running | Run/walk progression — every other day, 9 stages before continuous running |

### Cross-Training Prescription
Engine reads `athlete_profiles` cross-training equipment fields when filling non-running days in recovery blocks and return-to-running plans:
- Bike: easy cycling, duration-based
- Elliptical: easy elliptical, duration-based  
- Pool: pool running or easy swimming
- None: rest day or walking
All cross-training prescribed at easy effort. Duration scales with plan stage.

---

## 16. Onboarding Data Requirements

The following fields must be collected at onboarding to enable plan generation. Fields marked * are required before plan generation begins. All others can be collected during the onboarding call.

### Goal *
- Target race distance *
- Target race date *
- Time goal (optional — if none, plan defaults to completion focus)

### Current Fitness *
- Current weekly time on feet (minutes/week) *
- Longest recent run (duration in minutes) *
- Most recent race result (distance + time) — optional but enables pace zone derivation
- How long at current volume (months) *

### Experience *
- Years running *
- Highest-ever weekly volume (time on feet)
- Injury history (free text)

### Availability *
- Days per week available to run *
- Which days are must-off days *
- Fixed or flex scheduling preference *
- If fixed: preferred long run day, preferred primary workout day

### Watch Setup
- Watch platform (Garmin / Polar / Apple / Wahoo / None)
- OAuth connect (if applicable)
- Track access (yes / no)

### Preferences
- Units (miles / km)
- Notification preferences

---

## 17. Training Stress Scoring Formula

### Model: Duration × Intensity Factor

Daily training stress score = duration in minutes × intensity factor per workout type.

This is a simplified custom model appropriate for v1. It does not require power meter data (TSS), heart rate data (hrTSS), or GPS pace data (rTSS) — it functions fully with manual logging and scales naturally when watch data is available.

**Key design decision:** Intensity factors reflect the coach's intuition about relative workout stress validated against coaching philosophy. They are stored as configurable constants — not hardcoded — and can be adjusted based on beta athlete data without touching engine logic.

### Intensity Factors

Factors are stored in each archetype's `generation.intensity_factor` field in the `workout_archetypes` table. They are configurable per archetype — changing a factor in the database takes effect on the next plan generation without touching engine logic.

**Archetype intensity factors (all 17 system archetypes):**

| Archetype code | Intensity factor | Notes |
|---|---|---|
| `continuous_easy` | 0.50 | Base aerobic stimulus |
| `easy_with_strides` | 0.55 | Slight neuromuscular addition from strides |
| `plyometric_hill_circuits` | 0.60 | Short plyometric efforts, full circuit recovery |
| `structured_fartlek_ladder` | 0.70 | Mixed intensity — ladder structure |
| `hill_sprints` | 0.70 | Neuromuscular — short all-out efforts, full walk-back recovery |
| `sustained_hill_repeats` | 0.70 | Sustained uphill efforts, jog recovery |
| `continuous_long` | 0.80 | Duration does most of the work |
| `tempo_intervals` | 0.85 | Sustained threshold effort with recovery |
| `long_run_with_pickups` | 0.85 | Easy long run with embedded speed pickups |
| `continuous_progression_tempo` | 0.90 | Continuous build to threshold, no recovery breaks |
| `progression_long` | 0.90 | Long run building through effort zones |
| `short_speed_repeats` | 0.95 | Fast but short; full recovery limits cumulative load |
| `equal_distance_repeats` | 1.00 | High-intensity VO2 intervals |
| `fast_finish_long` | 1.00 | Aerobic long run with a hard closing segment |
| `high_volume_time_intervals` | 1.00 | Cumulative fatigue model — 20-rep volume sessions |
| `goal_pace_long_segments` | 1.05 | Extended race-specific stress in long run |
| `mixed_distance_repeats` | 1.05 | Combined long + short intervals, high intensity |

**Variant-level intensity_factor override:** Archetype variants can specify their own `intensity_factor` that takes precedence over the parent archetype's `generation.intensity_factor`. The engine resolves the effective factor as `resolved_variant.intensity_factor` if present, otherwise `archetype.generation.intensity_factor`. The primary live example is the `recovery_easy` variant of `continuous_easy`, which carries **0.30** — vs. the archetype-level 0.50 — reflecting that a recovery-type easy run prescribes meaningfully lighter physiological load than a standard easy session of the same duration. Any variant that does not specify its own `intensity_factor` inherits the archetype-level default.

**Non-archetype workout types** (used by return_to_running and recovery_block plan generators, written directly to planned_workouts without an archetype):

| Workout type | Intensity factor | Notes |
|---|---|---|
| Rest | 0 | No stimulus |
| Cross training | 0.40 | Reduced impact stress vs. running |
| Recovery run (recovery_block) | 0.30 | Very gentle active recovery |
| Return-to-running easy session | 0.40 | Walk/run intervals and stage 10 continuous |
| 5K race | 1.30 | All-out short effort |
| 10K race | 1.35 | Sustained maximum effort |
| Half marathon race | 1.40 | Long sustained maximum effort |
| Marathon race | 1.50 | Categorical outlier |
| Ultra | 1.6+ | TBD — v1.5 |

### Why Duration Does the Heavy Lifting

An 18-minute 5K (factor 1.3) generates 23 stress points.
A 3-hour marathon (factor 1.5) generates 270 stress points.

The model naturally reflects physiological reality — a marathoner who runs both distances will generate appropriately different stress scores from each without needing to artificially inflate short race factors. Duration and intensity factor together produce the right relative stress scores.

### ATL/CTL/TSB Calculations

**ATL (Acute Training Load — "Fatigue"):**
Rolling 7-day average of daily stress scores.
Rises quickly with hard training, drops quickly with rest.
Represents short-term fatigue.

**CTL (Chronic Training Load — "Fitness"):**
Rolling 42-day average of daily stress scores.
Builds slowly, drops slowly.
Represents accumulated aerobic fitness.

**TSB (Training Stress Balance — "Form"):**
CTL minus ATL.
Negative = carrying fatigue (training block)
Positive = fresh and sharp (taper or rest)
Race day target: TSB slightly positive, approximately +20 to +25

### Sanity Check — Sample Build Phase Week

| Day | Workout | Duration | Factor | Stress |
|---|---|---|---|---|
| Mon | Rest | — | 0 | 0 |
| Tue | Interval session | 60 min | 1.0 | 60 |
| Wed | Easy run | 50 min | 0.5 | 25 |
| Thu | Easy run with strides | 45 min | 0.55 | 24.75 |
| Fri | Rest | — | 0 | 0 |
| Sat | Hill session | 55 min | 0.7 | 38.5 |
| Sun | Long run w/ goal pace | 100 min | 0.85 | 85 |
| **Total** | | | | **233** |
| **Daily avg** | | | | **~33** |

After several weeks of similar training (CTL building to ~38-40), a taper week dropping to ~15-18 daily average produces a TSB of approximately +20 to +25 on race day. This is the target race-day form range.

### Calibration Period
Intensity factors are stored as configurable constants. During the 30-45 day beta period, the coach compares engine TSB values against coaching intuition for each athlete. Factors are adjusted where the model diverges from observed athlete fatigue and readiness. This is expected and built into the development process — v1 factors are a validated first draft, not a permanent specification.

### Future Sophistication (v2)
When watch data is more universally available across the athlete roster, the engine can incorporate hrTSS (heart rate-based stress scoring) as a more precise alternative to the duration × factor model. The architecture supports this — daily stress scores are computed and stored separately from the formula that generates them, so the formula can be upgraded without schema changes.

---

## 18. Archetype Engine

The archetype system is the active workout prescription layer as of Milestone 3. It replaces the static workout_library (WL-001 through WL-023) as the primary prescription mechanism. The library table remains in the database for historical reference.

### 18.1 What an Archetype Is

A workout archetype is a parameterized workout type stored in the `workout_archetypes` table. Each archetype has:
- A stable **code slug** (e.g. `tempo_intervals`, `sustained_hill_repeats`)
- **Selection rules** — which slot types, phases, distances, plan types, and athlete classifications it is eligible for
- **Weights** — per-phase, per-distance, per-classification scoring for weighted random selection
- **Generation metadata** — prescription model, intensity factor, progression model
- **Parameters** — per-classification `{min, max}` ranges for concrete workout parameters (rep count, rep duration, volume, etc.)
- **Display fields** — title, summary, and description templates with `{{token}}` placeholders
- **Structure template** — rendered into `planned_workouts.structure` at generation time

### 18.2 Archetype Selection

`ArchetypeSelector::selectForSlot()` is called for each plan slot. It:

1. Filters all archetypes to those eligible for the slot's (slot_type, phase, goal_distance, classification, plan_type, constraints) context
2. Scores eligible archetypes by summing weights across phase, goal_distance, classification, and plan_type dimensions; applies a −5 soft penalty for recently-used codes (soft-penalty window: default 10 days)
3. Picks a winner by weighted random draw
4. Resolves concrete parameter values via `resolveParameters()`

`PlanGenerator::resolveSlotInstance` then resolves a variant via `addDerivedParams` and enforces the hard-block check. `easy_with_strides` bypasses the weighted selection and is always assigned to easy_strides slots directly.

**Anti-repeat retry loop:** `resolveSlotInstance` runs up to four selection attempts. When an attempt produces a hard-blocked instance:

- The blocked **instance signature** is added to `$excludeSigs`. Subsequent attempts still call `selectForSlot` with no code-level exclusions, so they can re-select the same archetype and independently draw a different variant — giving multi-variant archetypes (e.g. `short_speed_repeats` with 5+ variants) a fair chance to land on an unblocked instance.
- **Code-level exclusion is not used for anti-repeat blocking.** Earlier versions added the blocked archetype's code to `$excludeCodes` and passed it to `selectForSlot`. This was over-broad: blocking one variant effectively eliminated all other variants of that archetype from subsequent attempts, artificially shrinking the effective candidate pool. For archetypes with multiple variants, the correct scope of an anti-repeat hard-block is the specific instance signature that was used — not the entire archetype.
- If all four attempts are exhausted or the selector finds no eligible candidates, the slot falls back to `continuous_easy`.

**Minimum viable parameter rejection (per-slot, post-capping):** A second rejection path operates inside the same retry loop, after `addDerivedParams` applies fit-to-slot capping. `isBelowMinimumViableInstance()` checks the capped values against `minimum_viable_params` thresholds stored in `generation.minimum_viable_params` (falling back to `MIN_VIABLE_INSTANCE_PARAMS` in `PlanGenerator` for DB rows not yet updated from seed). If any threshold is violated, the archetype is rejected **by code** for that slot attempt — all variants excluded for the remaining attempts — because the constraint is structural: the slot is too short for any viable instance of that archetype regardless of variant. Code-level exclusion is correct here and intentionally distinct from anti-repeat blocking, which is always signature-scoped.

Eight archetypes define minimum viable thresholds:

| Archetype | Minimum viable threshold |
|---|---|
| `sustained_hill_repeats` | `rep_count ≥ 3` |
| `hill_sprints` | `sprint_count ≥ 4` |
| `tempo_intervals` | `rep_count ≥ 2` |
| `continuous_progression_tempo` | `continuous_work_minutes ≥ 15` |
| `equal_distance_repeats` | `rep_count ≥ 3` |
| `short_speed_repeats` | `rep_count ≥ 4` |
| `high_volume_time_intervals` | `rep_count ≥ 6` |
| `structured_fartlek_ladder` | `round_count ≥ 1` |

The minimum viable gate is downstream of the duration eligibility gate: the duration gate pre-screens archetypes before selection based on minimum session footprint vs. weekly training volume; minimum viable rejection catches structurally inadequate instances after the slot's specific target duration has been applied via fit-to-slot capping.

**Exhaustion fallback (confirmed, not newly introduced):** When all selection attempts are consumed by minimum viable rejections, anti-repeat hard-blocks, or an empty eligible pool, `resolveSlotInstance` falls back to `continuous_easy` for that slot. This fallback predates the minimum viable mechanism; it was verified during the minimum-viable review pass. It is the safety net that makes per-slot archetype rejection safe to apply broadly: a quality slot that exhausts its viable candidate pool receives an easy run rather than a degenerate quality session.

**Anti-repeat history scoping:** `loadAntiRepeatHistory` queries `planned_workouts` joined to `training_plans` and **includes only plans with status `pending_approval` or `active`** — archived and abandoned plans are excluded entirely from the 28-day/10-day lookback. Rationale: the anti-repeat window is meant to reflect workouts the athlete is actually executing. Superseded plans (from genuine rebuilds or from repeated test regenerations) contain future weeks that were never run. Loading their instance signatures pre-occupies the variety pool for the new plan with training that never happened.

**Hard-block window (default 28 days):** A given instance signature cannot be placed again within this window.

**workout_type from resolved variant:** `planned_workouts.workout_type` is set from the resolved variant's `workout_type` when the variant specifies one, not from the slot type that triggered selection. This matters when a variant's character diverges from the slot it fills — for example, the `recovery_easy` variant of `continuous_easy` sets `workout_type='recovery'` regardless of whether it fills an easy slot, a quality-slot fallback, or a long-run fallback. Deriving workout_type from the slot would propagate an incorrect type (e.g. `interval` or `long`) to the athlete-facing UI and to any downstream filter that uses `workout_type` to determine pill color or compliance category.

### 18.3 Parameter Resolution and Rounding

`ArchetypeSelector::resolveParameters()` converts each parameter's `{min, max, type}` range into a single concrete value using the midpoint of the athlete's classification range.

**Rounding rules for `_seconds` parameters:** Raw midpoints of duration ranges produce non-coach-plausible numbers (e.g. 83 seconds from a {45, 120} range). Parameters whose key ends in `_seconds` are rounded to coach-friendly increments:

| Raw midpoint range | Rounded to nearest |
|---|---|
| < 30 s | 5 s |
| 30–90 s | 15 s |
| > 90 s | 30 s |

Example: `rep_duration_seconds` for `sustained_hill_repeats`, workable classification, {45, 120} range → midpoint 83 → rounds to **90 sec**.

Integer parameters without the `_seconds` suffix are resolved to the raw nearest-integer midpoint. Float parameters round to 2 decimal places.

**Enum parameter resolution fallback:** When a parameter's spec defines `allowed_values` but no classification `{min, max}` range and no explicit `default`, there is no midpoint to compute. `resolveParameters()` falls back to `allowed_values[0]` for these parameters (checked after `default`, before `min`). This was the root cause of `short_speed_repeats` rendering generic boilerplate: `effort_zone` (`allowed_values: [repetition, mile, 800]`, no range, no default prior to the fix) resolved to null, and the `{{effort_zone}}` token in the description template rendered as an empty string. The fix was two-pronged: add the `allowed_values[0]` fallback in `resolveParameters()`, and update the description template to use the static string "near-sprint effort" rather than a token that resolves to Daniels-terminology content ("repetition effort" — prohibited in athlete-facing text per §1). See §18.7 for the corrected template.

**Special-case parameter derivations in `addDerivedParams`**

One archetype requires additional parameter handling beyond the standard midpoint-from-range mechanism:

**`structured_fartlek_ladder` — variant-aware `work_intervals_seconds` derivation.** This parameter uses type `array_integer` with an `allowed_patterns` list; `resolveParameters()` cannot produce a midpoint for an array of patterns and returns null. `addDerivedParams()` picks the variant first (so the variant code is known before any other processing), then selects the appropriate interval pattern from a fixed map:

| Variant code | Pattern (seconds) | Formatted string |
|---|---|---|
| `descending` | [90, 60, 30] | 90–60–30 sec |
| `ascending` | [60, 120, 180, 240] | 1–2–3–4 min |
| `symmetric` | [60, 120, 180, 120, 60] | 1–2–3–2–1 min |
| `sharp_descending` | [60, 30, 15] | 60–30–15 sec |

The derivation is variant-aware because ascending, descending, and symmetric ladders are structurally distinct — a descending ladder's longest effort comes first; a symmetric ladder peaks in the middle. A single generic pattern applied to all variants would produce sessions that do not match their stated structure (e.g., prescribing 90–60–30 sec as a "Symmetric Fartlek Ladder" is structurally incorrect). A `fartlek_ladder_sequence` string is computed from the resolved pattern: values that are all whole-minute multiples format as "X–Y–Z min"; all others as "X–Y–Z sec". This string is stored in `resolved_params` so it is available to `{{fartlek_ladder_sequence}}` in the description template.

**`equal_distance_repeats` — `target_effort` unresolvable; token removed from template.** The `target_effort` enum parameter (`allowed_values: [10K, 5K, 3K]`) carries no classification range and no explicit default, so `resolveParameters()` returns null and `{{target_effort}}` renders as an empty string. Rather than patching the parameter spec with a synthetic default effort that would not reflect actual prescription logic, the `{{target_effort}}` token was removed from the description template. See §18.7 for the corrected template.

**`equal_distance_repeats` — `rep_distance_meters` always re-derived from `quality_volume / rep_count`.** The archetype stores `rep_distance_meters` as `allowed_values: [400, 600, 800, 1000, 1200, 1600]`, which causes `resolveParameters()` to set `rep_distance_meters = 400` (the first allowed value). 400m is not the correct instance distance — it is a reference list of valid rep lengths, not a resolved selection. `addDerivedParams` overrides this for `equal_distance_repeats` specifically: `rep_distance_meters = round(quality_volume_meters / rep_count / 10) × 10`. For a workable midpoint (quality_volume=4200m, rep_count=6), this gives 700m — the correct instance-specific rep length.

**`short_speed_repeats` and `equal_distance_repeats` — `rep_duration_seconds` derived from quality-pace estimate.** These archetypes use distance-based rep structure (rep_distance_meters rather than rep_duration_seconds in their parameter schemas), so `resolveParameters()` does not produce a `rep_duration_seconds`. `addDerivedParams` derives it: `rep_duration_seconds = max(20, round(rep_distance_meters / 1609.34 × avg_quality_pace × 60))`, where `avg_quality_pace` is the midpoint of the classification's 5K pace range (workable: [7.5, 10.5] min/mile → 9.0 min/mile; well_trained: [5.5, 7.5] → 6.5 min/mile). For `short_speed_repeats` 7×200m at workable: 200/1609.34 × 9.0 × 60 = 67 sec. This derivation runs before fit-to-slot capping so the cap uses an accurate per-rep cycle time. It also enables `computeMainSetMinutes()` (see §18.5 extension below) to return a non-null value for these archetypes, allowing `target_duration` to reflect the honest session footprint (WU + main + CD) rather than the slot allocation.

**`target_duration` — sum-of-parts vs. slot allocation.** `storedDuration = computeActualDuration(instance) ?? targetMinutes`. `computeActualDuration` returns `round(warmup_minutes + computeMainSetMinutes() + cooldown_minutes)` when both are non-null; otherwise falls back to the slot allocation (`targetMinutes`). `computeMainSetMinutes` covers all archetypes that have a derivable main-set time: `tempo_intervals`, `high_volume_time_intervals`, `sustained_hill_repeats`, `hill_sprints`, `structured_fartlek_ladder`, `continuous_progression_tempo`, `short_speed_repeats` (rep_count × (rep_duration_seconds + 90) / 60), `equal_distance_repeats` (rep_count × rep_duration_seconds × 2 / 60, vo2_standard 1:1 recovery), `plyometric_hill_circuits` (circuit_count × (hill_sprint_duration_seconds + 90) / 60). `mixed_distance_repeats` is the only structured archetype still using slot allocation — it has no per-rep structure to derive a main-set duration from. For short_speed_repeats, the honest session footprint (WU 15 + main 18 + CD 10 = 43 min) intentionally exceeds the quality slot allocation (30 min): the slot allocation budgets quality work intensity, not total session time. The eligibility gate (`min_session_duration_minutes ≤ weeklyMins × 0.40`) is the real constraint on viability, not the slot allocation size.

### 18.4 Athlete-Facing Display Format

Display text is rendered at generation time by substituting `{{token}}` placeholders in the archetype's `display.title_template`, `display.summary_template`, and `display.description_template` using `resolved_params`.

**Format convention by prescription type:**

- **Duration-first archetypes** (`lead_with: "duration"` — easy, long, fartlek, time-based hill sessions): summary leads with duration: `"45 min · 3.0–4.2 miles"`
- **Distance-first archetypes** (`lead_with: "distance"` — intervals, tempo, distance-based workouts): summary leads with quality volume: `"4.5 miles · 34–47 min"`

**Distance rounding:** Distance values in `display_summary` are rounded to one decimal place for distances ≥ 1 mile (e.g. `3.0–4.2 miles`). Distances in range strings are derived from `computeDistanceRange()` using classification-based easy pace ranges and the session's effective duration.

### 18.5 Distance Range Computation

`computeDistanceRange()` converts a session duration into a mileage range using classification- and goal-distance-based easy pace bands. For structured archetypes that have warmup and cooldown blocks, the computation accounts for the structure rather than treating the full session as continuous easy running:

- **Warmup/cooldown decomposition** applies to all structured archetypes that have non-zero `warmup_minutes` + `cooldown_minutes` parameters. The warmup and cooldown contribute their full minutes; the main-set minutes are scaled before combining.
- **Hill/plyometric scaling:** For `sustained_hill_repeats`, `hill_sprints`, and `plyometric_hill_circuits`, the main-set minutes are multiplied by **0.6×** before combining with warmup/cooldown. This accounts for the significantly reduced ground speed of uphill running (~40% slower per minute than flat easy pace).
- **All other structured archetypes** (intervals, tempo, fartlek) use a **1.0×** main-set factor — an approximation that treats main-set duration as equivalent to easy pace for distance estimation purposes.

### 18.6 Workout Display Field Semantics

Three `planned_workouts` columns hold athlete-facing display content:

| Column | Written by | Editable? | Purpose |
|---|---|---|---|
| `display_title` | Engine at generation | No | Short workout name (e.g. "6 × 90 sec Hill Repeats", "Tempo Intervals") — shown as the workout headline in both coach and athlete UI |
| `display_summary` | Engine at generation | No | One-line subtitle (e.g. "30 min · 2.2–3.0 miles", "4.5 miles · 34–47 min") — shown under the title as the at-a-glance prescription |
| `athlete_instructions` | Engine at generation; editable by coach | Yes — writes back to `planned_workouts.athlete_instructions` | Primary description paragraph shown to both coach and athlete; coaches write their annotation here (replaces the old unused `description` column for this purpose) |

`CoachController::getPlanWorkouts()` returns `COALESCE(athlete_instructions, display_summary, '')` as the `description` alias for backward compatibility with the calendar UI. `CoachController::editPlannedWorkout()` saves coach edits to `athlete_instructions` only — `display_title` and `display_summary` are never overwritten by coach edits.

---

### 18.7 Archetype Display — Per-Archetype Notes

Twelve of the 17 system archetypes required display template corrections across two passes. The corrections ensure every athlete-facing title and description reflects the actual resolved parameter values for that workout instance.

**First pass (initial implementation review):**

| Archetype | What was wrong | Corrected title template | Corrected description behavior |
|---|---|---|---|
| `short_speed_repeats` | Title fell back to `generated_workout_title` (a generic DB column). Description was boilerplate with no rep count or distance. | `{{rep_count}} × {{rep_distance_meters}}m` | Describes the exact rep count at near-sprint effort with walk-back recovery. `rep_distance_meters` defaults to 200 — added to the archetype's `parameters` spec. |
| `continuous_progression_tempo` | Title fell back to `generated_workout_title`. Description did not reference continuous work duration. | `{{continuous_work_minutes}} min Progression Tempo` | Describes the exact continuous work duration with no recovery breaks. |
| `mixed_distance_repeats` | Title fell back to `generated_workout_title`. Description did not reference quality volume. | `{{variant_name}}` | Describes `quality_volume_meters`m of mixed-distance intervals. |
| `plyometric_hill_circuits` | Title was unaffected. Description was boilerplate with no circuit count or sprint duration. | (unchanged) | Now incorporates `{{circuit_count}}` circuits of `{{hill_sprint_duration_seconds}}`-second uphill sprints followed by plyometric drills. |

**Second pass (systematic 17-archetype audit):**

| Archetype | What was wrong | Corrected title template | Corrected description behavior |
|---|---|---|---|
| `structured_fartlek_ladder` | Description boilerplate ("A structured fartlek session…") with no ladder sequence, round count, or warmup/cooldown context. `work_intervals_seconds` also resolved to null — the parameter uses `allowed_patterns`, incompatible with midpoint resolution (see §18.3 for derivation). | (unchanged — `{{variant_name}}` already renders the variant-specific name, e.g. "Descending Fartlek Ladder") | Now leads with `{{round_count}} × {{fartlek_ladder_sequence}}` — the full resolved ladder (e.g. "2 × 90–60–30 sec") — plus effort guidance and warmup/cooldown durations. |
| `equal_distance_repeats` | Description boilerplate ("Run each repeat at a consistent controlled effort…") with no rep count or distance. | (unchanged — `{{rep_count}} × {{rep_distance_meters}}m` was already instance-specific) | Now leads with `{{rep_count}} × {{rep_distance_meters}}m`. A third-pass correction removed a broken `{{target_effort}}` token — see note below. |
| `high_volume_time_intervals` | Description boilerplate ("A high-volume time-based workout…") with no rep count, work duration, or recovery duration. | (unchanged — title was already instance-specific: `{{rep_count}} × {{work_duration_seconds}} sec On / {{recovery_duration_seconds}} sec Off`) | Now leads with `{{rep_count}} × {{work_duration_seconds}} sec on / {{recovery_duration_seconds}} sec off at threshold effort`. |
| `sustained_hill_repeats` | Description referenced grade and jog-back protocol generally but omitted `{{rep_count}}` and `{{rep_duration_seconds}}`. | (unchanged — title was already instance-specific: `{{rep_count}} × {{rep_duration_seconds}} sec Hill Repeats`) | Now leads with `{{rep_count}} × {{rep_duration_seconds}} sec uphill`, followed by grade guidance and checkpoint recovery detail. |
| `hill_sprints` | Description included `{{sprint_duration_seconds}}` but omitted `{{sprint_count}}`. | (unchanged — title was already instance-specific: `{{sprint_count}} × {{sprint_duration_seconds}} sec Hill Sprints`) | Now leads with `{{sprint_count}} × {{sprint_duration_seconds}} sec` uphill sprints. |
| `tempo_intervals` | Description boilerplate ("Run each tempo segment at a comfortably hard effort…") with no rep count or rep duration. | (unchanged — `{{generated_workout_title}}` renders as "Tempo Intervals") | Now leads with `{{rep_count}} × {{rep_duration_minutes}} min` tempo reps with warmup and cooldown context. |
| `fast_finish_long` | Description generic ("Run aerobically for most of the session, then close with a strong final segment…") with no finish percentage or effort zone specified. | (unchanged) | Now incorporates `{{finish_segment_percent}}%` and `{{finish_zone}}` effort, making the closing segment prescription explicit. |
| `easy_with_strides` | Description referenced `{{stride_count}}` but not `{{stride_duration_seconds}}`. | (unchanged) | Now states `{{stride_count}} × {{stride_duration_seconds}} sec` relaxed strides. |

The remaining five archetypes (`continuous_easy`, `continuous_long`, `progression_long`, `goal_pace_long_segments`, `long_run_with_pickups`) were not modified. `long_run_with_pickups` was already fully instance-specific (`{{pickup_count}}` and `{{pickup_duration_seconds}}` in the description). The other four use intentionally narrative descriptions appropriate to their workout character — the duration and distance range in `display_summary` provide the instance-specific prescription context for those sessions.

**Third-pass corrections (minimum-viable-params review pass):**

`equal_distance_repeats` — `{{target_effort}}` token removed. The second-pass template introduced `{{target_effort}}` in the description, but this parameter is an enum type with no classification range, so `resolveParameters()` returns null and it rendered as an empty string (producing "at  effort" in the output). The fix was to remove the token rather than patch the parameter spec with a synthetic default. Corrected template: `"{{rep_count}} × {{rep_distance_meters}}m. Run each repeat at a consistent controlled effort. {{repeat_consistency_instruction}}"`.

`sustained_hill_repeats` — conditional checkpoint recovery instruction. The second-pass description template originally had the quarter/halfway/three-quarter recovery text hardcoded. It now uses `{{checkpoint_recovery_instruction}}`, a conditional string injected by `addConditionalInstructionParams()` based on the post-capping `rep_count`:

| rep_count after capping | Rendered instruction |
|---|---|
| ≥ 4 | "At the quarter, halfway, and three-quarter points of the workout, take 45–90 seconds standing recovery if you need it." |
| = 3 | "If you need extra recovery, take one short 45–90 second standing reset around halfway." |
| ≤ 2 | Empty string — but this case is unreachable: `isBelowMinimumViableInstance()` rejects any `sustained_hill_repeats` instance with `rep_count < 3` before it reaches an athlete. |

A `normalizeInstructionText()` post-render pass handles any DB rows that still carry the pre-import hardcoded template: it replaces the old quarter/halfway/three-quarter text with the conditional string when `rep_count < 4`, and leaves it untouched when `rep_count ≥ 4`.

**All 17 archetypes now produce instance-specific `display_title` and `athlete_instructions`, and render a populated distance or time range in `display_summary`.** plan_id=23 (Liam's development plan, regenerated 2026-06-13) is the first plan produced under the fully corrected engine — display completeness, duration honesty (sum-of-parts `target_duration` covering all 9 derivable archetypes), eligibility gating, minimum-viable-params, post-generation display validation, volume progression (0.80× cutback ratio, uninterrupted ×1.08 build resumption after each cutback), and warmup/cooldown description completeness across all 10 structured archetypes all in place together, with zero violations across all categories.

---

### 18.8 Post-Generation Display Validation

`PlanGenerator::validateGeneratedDisplays()` runs after every plan generation, before the plan is written to the approval queue. It scans every `planned_workouts` row for the new plan where `archetype_code IS NOT NULL` and checks two conditions:

1. **Unresolved template tokens:** any `{{word}}` pattern remaining in the concatenated `display_title`, `display_summary`, or `athlete_instructions` text.
2. **Numeric-archetype display with no digits:** for workouts with a quality `workout_type` (`interval`, `tempo`, `hill`, `fartlek`, `speed`) or any of the 10 archetypes in the explicit `numericArchetypes` list (`equal_distance_repeats`, `mixed_distance_repeats`, `short_speed_repeats`, `sustained_hill_repeats`, `hill_sprints`, `tempo_intervals`, `continuous_progression_tempo`, `high_volume_time_intervals`, `structured_fartlek_ladder`, `plyometric_hill_circuits`), the combined display text must contain at least one digit.

If either condition is violated for any workout in the plan, a `display_generation_incomplete` engine flag is raised for the athlete. This flag is **not deduped by open status** — each plan generation raises its own flag independently, because display completeness is plan-specific (an earlier open flag from a prior generation does not suppress a flag for the new plan).

This is the systemic safety net for any future archetype resolution regression: if a new parameter is added to an archetype's template without a corresponding default or resolution path, the validation pass will catch it before the coach sees the plan rather than surfacing as blank or malformed display text to athletes.

---

## 19. Open Items (Follow-On Specification Required)

These items are intentionally deferred and must be resolved before the relevant engine components are built:

1. **Workout library content** — ✅ Resolved. 23 templates documented in workout library document; archetype system (17 archetypes) is the active prescription layer as of Milestone 3.

2. **Tune-up race handling** — ✅ Resolved. See Section 26 of Architecture document.

3. **Training stress scoring formula** — ✅ Resolved. See Section 17.

4. **Ultra-specific considerations** — Deferred to v1.5. Phase structure, volume ceilings, long run character, and back-to-back long run protocols for ultra athletes.

5. **Profile-influenced guardrails** — Deferred to v2. Universal guardrails hold for all athletes in v1 regardless of experience level.

6. **Beginner-specific archetype gating** — Some archetypes have `min_classification: workable` but no beginner-only archetypes exist yet. Return-to-running and insufficient-base athletes need beginner-specific options (e.g. run/walk intervals, standalone stride sessions) as archetype entries, not just library templates.

7. **Post-marathon recovery block structure** — Coach has a written document detailing the full month-long recovery block week by week. Pending coach providing source document. Until then, recovery block duration is defined (4 weeks for marathon, scaling by distance) but internal week-by-week structure is a placeholder.

8. **Archetype/variant swapping UI** — The "Swap to library template" coach UI was removed in Milestone 3.5. A proper archetype/variant swap interface (allowing coaches to substitute a different archetype or variant for a planned workout) is an open design item for a future milestone.

9. **Distance range precision** — `computeDistanceRange()` treats all non-hill main-set time as equivalent to flat easy pace for distance estimation. For intervals and tempo sessions, this overstates the likely range (athletes cover less distance per minute during hard structured efforts than easy running). A structured-effort pace table for quality sessions is a future refinement.

10. **`short_speed_repeats` variant display uniformity** — Variants `speed_300s`, `economy_200s`, `speed_endurance_400s`, `broken_speed_set`, and `speed_endurance_600s` currently all resolve to the same display title format (e.g. `7 × 200m`) for a workable athlete. Confirm whether this is correct: variant names likely describe effort targets or training emphasis rather than different rep distances — all variants may prescribe 200m reps but at different intensities or with different recovery structures, which would make uniform titles correct. If variants are instead meant to prescribe different rep distances, the `rep_distance_meters` parameter or per-variant `display.title_template` overrides are the fix. Needs a quick review of each variant's JSON definition in the `workout_archetypes` table. **Interaction with item 11:** For low-volume athletes where `short_speed_repeats` fills most or all quality slots, whether variants prescribe different rep distances or the same distance with different effort targets becomes more consequential — uniform display titles across variants make weeks of `short_speed_repeats` visually indistinguishable in the plan view even if the underlying training stimulus genuinely varies. Review items 10 and 11 together.

11. **Low-volume athlete quality pool variety** *(low priority — monitor with real athlete data)* — For athletes with ~30-minute quality slots (roughly `current_weekly_minutes ≈ 130`), `short_speed_repeats` may be the only consistently eligible quality archetype after the duration gate and minimum-viable-params gate together exclude archetypes requiring substantial warmup/cooldown time (e.g. `tempo_intervals` needs 15 + 2×10 + 10 = 45 min minimum; `sustained_hill_repeats` needs ≥ 35 min for three reps). Anti-repeat blocking then exhausts the five `short_speed_repeats` variants, and later-plan quality slots fall back to `continuous_easy`. This is philosophically consistent with the Hart-influenced speed-development principle underlying the engine — short speed reps are a legitimate primary quality stimulus at low training volume — but warrants monitoring as real athlete feedback comes in. If variety proves insufficient, options include: a reduced-footprint "starter" tier of quality archetypes designed for shorter slots, adjusted warmup/cooldown floors for lower-volume athletes, or widening the anti-repeat soft-penalty window to allow variant reuse sooner. *See also item 10 (variant display uniformity) — the two items interact most strongly at low training volumes where `short_speed_repeats` dominates the quality pool.*

12. **Floor-aware cutback formula** *(low priority)* — The 0.80× cutback ratio resolves the floor-binding collision for narrow-schedule athletes at development-plan volumes, but the formula is not floor-aware in general: it doesn't compute the per-slot floor sum and guarantee the post-cutback `weeklyMins` stays above it. A more robust approach would be `max(prevWeeklyMins × 0.75, floorSum + margin)` where `floorSum` is the sum of per-slot absolute floors for the athlete's schedule (long-run floor + quality-slot floor + easy-slot floors). This would use the full 25% cutback when volume permits it and automatically limit depth when it doesn't. Deferred: 0.80× is sufficient for all current athlete configurations. Revisit if a high-volume athlete (≥200 min/week) joins where a shallower cutback produces a smaller-than-intended reduction, or if schedule width increases enough that floor sums shift.

13. **`validateGeneratedDisplays()` does not check warmup/cooldown description completeness** *(low priority)* — The post-generation validator checks for unresolved template tokens and numeric-archetype displays without digits, but does not verify that `athlete_instructions` mentions the warmup and cooldown when `warmup_minutes` and `cooldown_minutes` are present in `resolved_params`. If a new archetype is added with warmup/cooldown in its parameters but those values are omitted from the `description_template`, the validator will pass silently. A third validation condition could be added: for any archetype where `warmup_minutes > 0` or `cooldown_minutes > 0` in `resolved_params`, the combined display text must contain the words "warm" and "cool" (or a comparable structural check). Not built: all 10 structured archetypes now carry explicit warmup/cooldown language in their description templates, so the gap only matters for future archetype additions.

---

*This document is a companion to the System Architecture document and should be read alongside it. Together they constitute the complete specification for v1 of the platform. Both documents should be provided to the coding agent before Milestone 2 begins. Milestone 1 (database schema, auth, onboarding forms, coach scaffold) can proceed from the Architecture document alone.*
