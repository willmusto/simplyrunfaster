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
- **Typical easy-pace range** (when no race/time-trial exists) — see "Derivation pathways" below, OR
- Goal race pace (used for prescription targets, not fitness assessment)

### Derivation pathways
Three input pathways feed the **same** McMillan equivalency math (Riegel exponent
1.06). They differ only in how the projection basis is established:
1. **Race result / time trial** → projection basis is the performance itself.
   `pace_zones_source = 'race_result'`.
2. **Typical easy pace** (McMillan "project from a training pace" mode) → the entered
   easy-pace range is converted to an equivalent marathon-pace velocity (easy pace is
   treated as ~20% slower than marathon pace), which becomes the projection basis. This
   is an *estimate*, framed as such in the UI. `pace_zones_source = 'easy_pace_estimate'`.
3. **Manual coach override** → `pace_zones_source = 'manual'`.

When a real race result later arrives, the Section 26 recalibration flow replaces the
estimate and sets `pace_zones_source = 'race_result'` (verified). The easy-pace estimate
pathway never overwrites verified or manual zones. Implemented in `src/Engine/PaceZones.php`.

If an athlete provides neither a race result nor a typical easy pace, `pace_zones`
remains empty: the athlete sees a Today-tab prompt to add pace data, and a coach-facing
`pace_zones_missing` info flag is raised at onboarding.

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

**Storage:** persisted as JSON in `athlete_profiles.pace_zones`. All values are **seconds
per mile** (per-mile-equivalent pace, including the track distances). `easy`/`long` are
`{min,max}` ranges; the named race distances are scalar paces. Example:
```json
{
  "source": "easy_pace_estimate", "generated_at": "2026-06-14",
  "easy": {"min": 540, "max": 600}, "long": {"min": 540, "max": 600},
  "marathon": 475, "half_marathon": 456, "10K": 436,
  "5K": 418, "mile": 390, "800": 354, "400": 342
}
```
Implementation: `src/Engine/PaceZones.php`. Constants: Riegel exponent `1.06`; easy pace
treated as `1.20×` marathon pace (`EASY_TO_MARATHON_RATIO`) on the easy-pace pathway. On
the easy-pace pathway the `easy`/`long` ranges echo the athlete's entered range; on the
race pathway they bracket marathon pace by +18%..+30%.

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
- **Implemented cutback ratio: 0.80× (20% reduction — the conservative end of the range).** 0.75× (25%) risks dropping `weeklyMins` below the per-slot floor sum (long-run floor + quality-slot floor + easy-slot floor). When that happens, easy slots clamp at their minimum regardless of `weeklyMins`. (0.80× keeps `weeklyMins` above this floor threshold for narrow-schedule athletes at typical development-plan volumes ~120–160 min/week.) **Correction (item 3):** an earlier version of this note attributed all post-cutback growth suppression to that floor-clamping and claimed "no bug in the build formula." That was wrong — there was a real build-formula bug, now fixed; see **Post-Cutback Build Base** below.
- Intensity distribution: maintained at comparable levels, with 3–5% reduction by default
- Week shape unchanged: still 1 long run, 1 primary workout, easy days — everything dials back proportionally
- Cutback weeks do not count against phase progression — the following week resumes building

### Post-Cutback Build Base (item 3 — bug fixed)

The build progression carries a `buildBase` (the last non-cutback week's `weeklyMins`) that **advances only on build weeks**. A cutback week computes `weeklyMins = buildBase × cutbackRatio` (0.80 development / 0.75 race) **without advancing `buildBase`**, so the following build week resumes at `buildBase × 1.08` (development) / `× 1.10` (race) — i.e. `preCutbackPeak × 1.08`, treating the cutback as a pause. This clears the +5% minimum-net-progression floor (`max(preCutbackPeak × 1.05, preCutbackPeak × 1.08) = preCutbackPeak × 1.08`), subject to the `peak_volume_ceiling` cap (when the ceiling binds, volume maintains at the ceiling per "Coach-Set Peak Volume Ceiling").

**The bug:** previously a single `prevWeeklyMins` was used for both the cutback base and the build base, and was reassigned to the cutback-week value (`prevWeeklyMins = cutbackVolume`). The next build week was therefore `cutbackVolume × 1.08` = `preCutbackPeak × 0.864` — a **net −13.6%** vs. the pre-cutback peak — and the suppression **compounded** across every cutback (each cutback lowered the base the build resumed from), so over a 12-week plan with cutbacks at weeks 4/8/12 the athlete barely progressed. Verified on Liam (development, 120→ceiling 360): the corrected trajectory climbs 130→238 min/week over 12 weeks with post-cutback weeks resuming above the pre-cutback peak (e.g. wk3 151 → wk4 cutback 121 → wk5 163), where the old code stalled around 120–154 the whole plan.

### Honest-Duration-Aware Slot Allocation

`insertWeekWorkouts()` allocates a week's `weeklyMins` across long, quality, and easy slots so that the **sum of each workout's stored `target_duration` equals `weeklyMins`** (exactly, for weeks with a single easy slot; within rounding for multi-easy-slot weeks). The allocation order matters because quality `target_duration` is the honest sum-of-parts (warmup + main + cooldown), and quality sessions are resolved to fill the week's available budget:

1. **Long** — floor-based: `max(longFloor 60, min(floor(weeklyMins × 0.28), maxLongRun × 1.15, weeklyMins × 0.35))`. At development-plan volumes the 60-min floor binds.
2. **Quality** — resolved *before* easy, targeting the per-slot budget `qualTarget` (see below). Each quality slot is selected and its parameters resolved (fit-to-slot capping scales rep counts / work durations toward `qualTarget`), producing its honest `target_duration` via `computeActualDuration()`.
3. **Easy** — distributes the remainder: `easyMins = clamp(easyFloor, easyCap, floor((weeklyMins − longMins×longCount − actualQualityMins) / easyCount))`.

Computing `easyMins` from the *actual* resolved quality duration is what keeps stored weekly totals equal to the generator's targets. Resolving quality before easy is a reordering from the original date-ordered single pass; quality instances are cached and reused so anti-repeat history is updated exactly once per slot.

**`qualTarget` / `perSlotQualBudget` — quality sessions scale with weekly volume.** Each quality slot's duration budget reserves the long allocation and the easy-slot floor:

```
perSlotQualBudget = floor((weeklyMins − longMins×longCount − easyFloor×easyCount) / qualCount)
qualTarget        = max(easyFloor, perSlotQualBudget)
```

`qualTarget` is the `targetMinutes` passed to fit-to-slot capping, so quality archetypes resolve toward the actual available budget (≈31 min on a narrow cutback week up to ≈64 min at peak for a 3-day athlete) rather than the former flat `qualMins = max(30, min(40, weeklyMins × 0.20))` cap. **This is a deliberate coaching-philosophy choice** aligned with the workout library — WL-008 (1000m repeats) and WL-014 (tempo intervals) run 50–70 min with realistic warmup/cooldown, well above the old 30–40 cap. Concretely, `tempo_intervals`, `sustained_hill_repeats`, `equal_distance_repeats`, and `continuous_progression_tempo` now resolve to viable multi-rep structures (e.g. `3 × 11 min` tempo, `4 × 700m`, `6 × 90 sec` hill repeats) on mid/high-volume weeks instead of capping to ~1 rep and being minimum-viable-rejected. Before this change those archetypes were eligible (their `minimum_session_duration_minutes` cleared the `weeklyMins × 0.40` gate) but always doomed at the nominal 30-min target, wasting selection attempts and leaving `short_speed_repeats` as the only placeable quality archetype.

`perSlotQualBudget` is also the `max_quality_duration` upper bound enforced at selection time (in `resolveSlotInstance`, alongside the minimum-viable check): a candidate whose honest `computeActualDuration()` exceeds it is rejected and the next eligible candidate is tried, falling through ultimately to `continuous_easy`. Because `qualTarget` is now the resolution target, fit-to-slot capping keeps most candidates' honest duration ≤ budget, so few are rejected. The exception is `short_speed_repeats`, which has no fit-to-slot cap and resolves to a fixed ~43-min footprint regardless of target — it is therefore admitted only when `perSlotQualBudget ≥ 43` (`weeklyMins ≥ 133` for a single-quality, single-easy, 60-min-long week) and **reliably** budget-rejected on cutback/low-rebuild weeks below that threshold. Continuous archetypes have a null actual duration and are never budget-rejected, so `continuous_easy` is always the guaranteed fallback. The total/floor guarantees hold for every random draw regardless of which archetype fills the slot.

**Residual low-volume fallback.** On the narrowest weeks (cutback, `perSlotQualBudget ≈ 31–33`) the budget is too small for any structured archetype to clear `minimum_viable_params`, so the quality slot falls back to `continuous_easy` — appropriate for a recovery week. `short_speed_repeats` variety remains constrained by Item 10 (all five variants resolve to an identical `7 × 200m`, so each use 28-day-hard-blocks the rest); this limits SSR repetition but no longer starves the quality pool now that the other archetypes are placeable.

### Coach-Set Peak Volume Ceiling
- Each athlete profile has a `peak_volume_ceiling` field (in minutes per week, consistent with time-on-feet philosophy)
- Default value derived from onboarding data: current weekly time on feet × 1.4 (conservative multiplier)
- Coach can adjust at any time
- Engine never exceeds this ceiling regardless of progression logic
- When ceiling is reached, engine maintains volume and shifts stress toward intensity

### Days-Per-Week Ramp (item 1)

The requested `training_days_per_week` is no longer forced from week 1. Each week the scheduled day count is capped to what that week's `weeklyMins` can structurally support under the canonical week shape (§7: 1 long ≥ long-floor, remaining days each ≥ easy-floor):

```
supportedDays(weeklyMins) = 1 + floor((weeklyMins − longFloor) / easyFloor)
numDays = max(2, min(requested training_days_per_week, supportedDays))
```

With `longFloor = 60`, `easyFloor = 30`: 120 min → 3 days, 150 → 4, 180 → 5. As `weeklyMins` grows week-over-week (build progression), `numDays` ramps toward the requested value; cutback/taper weeks may dip it back. **Why:** forcing 5 days at 120 min/week reserves `60 (long) + 4×30 (easy floors) = 180 > 120`, leaving the per-slot quality budget negative, so every quality slot fell back to `continuous_easy` (Liam's all-easy plans). Running fewer days at low volume restores a positive quality budget. **Interaction with §7 scheduling:** `numDays` is the *target* count; `must_off_days` independently constrains the available pool, so the actual scheduled count is `min(numDays, available_days)`. Fixed/flex anchor logic (long-run day, primary-workout day, 2-day separation, ≥1 rest) is unchanged — the ramp only lowers how many of the available days get filled. Computed in `buildDaySchedule` from the per-week `weeklyMins` it already receives.

### Schedule-Day-Ramp Flag (item 2)

When the requested `training_days_per_week` exceeds week-1 `supportedDays`, an **informational** `schedule_day_ramp` engine flag is raised at generation (migration_007): *"Schedule will start at N day(s)/week (you requested M) and ramp up as weekly volume increases."* This is **not** a hold state (distinct from `insufficient_base`) — the plan generates and is fully usable. It is raised in `generateDevelopmentPlan` / `generateRaceCycle` / `generateMaintenancePlan` for week 1. Because profile edits do not auto-regenerate (they raise `profile_updated` for coach review, then the coach regenerates manually), the flag surfaces on that subsequent regeneration rather than being duplicated into the edit path.

### Cross-Cycle Volume Continuity (item 4 — confirmed gap, fixed)

`current_weekly_minutes` is an onboarding/coach-edited profile field. Previously **every** plan cycle read it as the week-1 base, so a `block_end`/`engine_rebuild` rebuild restarted from the onboarding-era value and `peak_volume_ceiling` was effectively unreachable across an athlete's lifetime without the coach manually bumping the field each cycle. Fix (`resolveStartingWeeklyMins`):

- **`onboarding` / `coach_manual`** → week-1 base is `current_weekly_minutes` (no prior trajectory assumed; coach-manual is treated as an intentional restart from the stated value).
- **`block_end` / `engine_rebuild`** → week-1 base is the prior plan's **peak weekly volume** (max of per-7-day stored `target_duration` sums — the achieved trajectory, not the literal final week which is typically a planned cutback/taper dip), capped by `peak_volume_ceiling`. The build then continues from there (`× 1.08/1.10`).
- **Manual-edit override** → if the profile was edited after the prior plan was generated (`athlete_profiles.updated_at > prior plan.generated_at`), `current_weekly_minutes` takes precedence over derived continuity (a deliberate new baseline, e.g. after a layoff). *Heuristic limitation:* `athlete_profiles` has a single `updated_at`, not a per-column timestamp, so any profile edit since the last plan counts as a potential baseline change — a conservative bias toward respecting the stated value.

Maintenance plans are already ceiling-anchored (85% of ceiling, constant), so they are cross-cycle continuous without this logic.

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
- **Typical easy-day pace (range)** — conditional: shown only when no recent race result
  is provided. Stored as `typical_easy_pace_min` / `typical_easy_pace_max` (seconds/mile).
  Enables the easy-pace pace-zone estimate (Section 2, pathway 2). Also editable later on
  the athlete Training Settings page and coach edit page, since easy pace shifts with fitness.
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

**Archetype intensity factors (all 19 system archetypes):**

| Archetype code | Intensity factor | Notes |
|---|---|---|
| `run_walk_intervals` | 0.40 | Staged run/walk for return-to-running and insufficient base |
| `standalone_strides` | 0.45 | Short stride-only neuromuscular session |
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

**Non-archetype workout types** (used by recovery_block, and the cross-train/rest days of return_to_running, written directly to planned_workouts without an archetype):

| Workout type | Intensity factor | Notes |
|---|---|---|
| Rest | 0 | No stimulus |
| Cross training | 0.40 | Reduced impact stress vs. running |
| Recovery run (recovery_block) | 0.30 | Very gentle active recovery |
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

**`short_speed_repeats` and `equal_distance_repeats` — discrete per-variant rep distance + volume-derived `rep_count`.** Both archetypes now prescribe a distinct `rep_distance_meters` per variant, stored in the variant JSON and read from `resolved_variant.rep_distance_meters` in `addDerivedParams` (SSR: `economy_200s`→200, `broken_speed_set`→150, `speed_300s`→300, `speed_endurance_400s`→400, `repetition_session`→600; EDR: `600s`→600, `800s`→800, `1000s`→1000, `1200s`→1200 — EDR's variant set was reduced to these four "5K–10K specificity" distances and `allowed_values` restricted accordingly). After the distance is set, `rep_count = clamp(round(quality_volume_meters / rep_distance_meters), classification_min, classification_max)` — preserving total quality volume while snapping to a coach-plausible distance (longer reps → fewer reps), and clamped so it never drops below `minimum_viable_params`. This replaced EDR's earlier continuous derivation (`round(quality_volume / rep_count / 10) × 10`, which produced non-standard distances like 700m) and gives each variant a distinct display title and instance signature. Example resolutions (workable): EDR `5 × 600m`, `4 × 800m` / `3 × 800m`, `3 × 1000m`; SSR `8 × 200m`, `5 × 300m`, `4 × 400m`, `4 × 600m`, `10 × 150m`. Fit-to-slot capping may further reduce `rep_count` to fit `qualTarget` on tight weeks.

**`short_speed_repeats` and `equal_distance_repeats` — `rep_duration_seconds` derived from quality-pace estimate.** These archetypes use distance-based rep structure (rep_distance_meters rather than rep_duration_seconds in their parameter schemas), so `resolveParameters()` does not produce a `rep_duration_seconds`. `addDerivedParams` derives it: `rep_duration_seconds = max(20, round(rep_distance_meters / 1609.34 × avg_quality_pace × 60))`, where `avg_quality_pace` is the midpoint of the classification's 5K pace range (workable: [7.5, 10.5] min/mile → 9.0 min/mile; well_trained: [5.5, 7.5] → 6.5 min/mile). For `short_speed_repeats` 7×200m at workable: 200/1609.34 × 9.0 × 60 = 67 sec. This derivation runs before fit-to-slot capping so the cap uses an accurate per-rep cycle time. It also enables `computeMainSetMinutes()` (see §18.5 extension below) to return a non-null value for these archetypes, allowing `target_duration` to reflect the honest session footprint (WU + main + CD) rather than the slot allocation.

**`target_duration` — sum-of-parts vs. slot allocation.** `storedDuration = computeActualDuration(instance) ?? targetMinutes`. `computeActualDuration` returns `round(warmup_minutes + computeMainSetMinutes() + cooldown_minutes)` when both are non-null; otherwise falls back to the slot allocation (`targetMinutes`). `computeMainSetMinutes` covers all archetypes that have a derivable main-set time: `tempo_intervals`, `high_volume_time_intervals`, `sustained_hill_repeats`, `hill_sprints`, `structured_fartlek_ladder`, `continuous_progression_tempo`, `short_speed_repeats` (rep_count × (rep_duration_seconds + 90) / 60), `equal_distance_repeats` (rep_count × rep_duration_seconds × 2 / 60, vo2_standard 1:1 recovery), `plyometric_hill_circuits` (circuit_count × (hill_sprint_duration_seconds + 90) / 60). `mixed_distance_repeats` is the only structured archetype still using slot allocation — it has no per-rep structure to derive a main-set duration from. For short_speed_repeats, the honest session footprint (WU 15 + main 18 + CD 10 = 43 min) intentionally exceeds the quality slot allocation (30 min): the slot allocation budgets quality work intensity, not total session time. The eligibility gate (`min_session_duration_minutes ≤ weeklyMins × 0.40`) is the real constraint on viability, not the slot allocation size.

### 18.4 Athlete-Facing Display Format

Display text is rendered at generation time by substituting `{{token}}` placeholders in the archetype's `display.title_template`, `display.summary_template`, and `display.description_template` using `resolved_params`.

**Format convention by prescription type:**

- **Duration-first archetypes** (`lead_with: "duration"` — easy, long, fartlek, time-based hill sessions): summary leads with the same effective duration stored in `planned_workouts.target_duration`: `"45 min · 3.5–4 miles"`. For structured archetypes this is `computeActualDuration(instance) ?? targetMinutes`, so `display_summary` does not continue to show the pre-capped slot allocation when `target_duration` uses the honest sum-of-parts duration.
- **Distance-first archetypes** (`lead_with: "distance"` — intervals, tempo, distance-based workouts): summary leads with half-mile quality volume: `"4.5 miles · 34–47 min"`. Time ranges are still computed from the raw resolved distance before display rounding.

**Distance rounding:** Distance values in `display_summary` are rounded to the nearest half mile. `computeDistanceRange()` rounds each endpoint to the nearest 0.5 mile, clamps the lower bound to at least 0.5 miles, and widens a collapsed range by 0.5 mile on the upper bound only so short sessions never render as `0–0.5 miles` or a degenerate `1–1 miles` range. Single distance tokens (`{{distance}}`, `{{total_distance}}`) are also rounded to the nearest half mile for display, with singular `1 mile` when applicable.

### 18.5 Distance Range Computation

`computeDistanceRange()` converts the session's effective display/stored duration into a mileage range using classification- and goal-distance-based easy pace bands. The duration source is reconciled with `target_duration`: callers pass `computeActualDuration(instance) ?? targetMinutes`, so structured workouts that use sum-of-parts duration for storage use that same duration for the leading summary duration and mileage estimate. The range is intentionally approximate; no separate hill/plyometric main-set scaling is applied in the display layer.

### 18.6 Workout Display Field Semantics

Three `planned_workouts` columns hold athlete-facing display content:

| Column | Written by | Editable? | Purpose |
|---|---|---|---|
| `display_title` | Engine at generation | No | Short workout name (e.g. "6 × 90 sec Hill Repeats", "Tempo Intervals") — shown as the workout headline in both coach and athlete UI |
| `display_summary` | Engine at generation | No | One-line subtitle (e.g. "30 min · 2.5–3 miles", "4.5 miles · 34–47 min") — shown under the title as the at-a-glance prescription |
| `athlete_instructions` | Engine at generation; editable by coach | Yes — writes back to `planned_workouts.athlete_instructions` | Primary description paragraph shown to both coach and athlete; coaches write their annotation here (replaces the old unused `description` column for this purpose) |

`CoachController::getPlanWorkouts()` returns `COALESCE(athlete_instructions, display_summary, '')` as the `description` alias for backward compatibility with the calendar UI. `CoachController::editPlannedWorkout()` saves coach edits to `athlete_instructions` only — `display_title` and `display_summary` are never overwritten by coach edits.

---

### 18.7 Archetype Display — Per-Archetype Notes

Twelve of the 17 system archetypes required display template corrections across two passes. The corrections ensure every athlete-facing title and description reflects the actual resolved parameter values for that workout instance.

**First pass (initial implementation review):**

| Archetype | What was wrong | Corrected title template | Corrected description behavior |
|---|---|---|---|
| `short_speed_repeats` | Title fell back to `generated_workout_title` (a generic DB column). Description was boilerplate with no rep count or distance. | `{{rep_count}} × {{rep_distance_meters}}m` | Describes the exact rep count at near-sprint effort with walk-back recovery. `rep_distance_meters` defaults to 200 — added to the archetype's `parameters` spec. |
| `continuous_progression_tempo` | Title fell back to a single `generated_workout_title` ("Continuous Progression Tempo") for both variants; description was generic ("let the effort build naturally…") regardless of duration or variant. | `{{variant_name}}` → "Linear Progression Tempo" / "Wave Progression Tempo" | Description now renders `{{progression_instruction}}` — an instance-specific, structure-aware string that splits the (capped) `continuous_work_minutes` into thirds with actual minute values and differentiates the two variants: **linear** = steady 3-segment build (easy → moderate → tempo); **wave** = oscillating surges/floats that trend faster overall. Effort-based language only (no pace numbers) in the builder itself, per §2/§3. **Pace-zone citation is now appended after this string when the athlete's zones are visible — see §18.9 (resolves §19 item 14);** the builder text is unchanged, the pace clause is added separately. |
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

**All 17 archetypes now produce instance-specific `display_title` and `athlete_instructions`, and render a populated distance or time range in `display_summary`.** plan_id=47 (Liam's development plan, regenerated 2026-06-14) is the first plan produced under the fully corrected engine — display completeness, duration honesty (sum-of-parts `target_duration` covering all 9 derivable archetypes), honest-duration-aware slot allocation with quality sessions scaled to the per-week budget (stored weekly totals equal generator targets, every easy slot ≥ floor — see §6), eligibility gating, minimum-viable-params, post-generation display validation, volume progression (0.80× cutback ratio, uninterrupted ×1.08 build resumption after each cutback), warmup/cooldown description completeness across all 10 structured archetypes, and correct `workout_type` for all continuous_easy fallback variants (standard_easy→easy, recovery_easy→recovery, regardless of which slot type triggered selection) — all in place together, with zero violations across all categories. With quality-session duration scaling in place, 9 of Liam's 12 weeks carry a real structured quality session spanning five archetypes (`sustained_hill_repeats`, `continuous_progression_tempo`, `equal_distance_repeats`, `tempo_intervals`, `short_speed_repeats`); only the three cutback weeks fall back to `continuous_easy`.

---

### 18.8 Post-Generation Display Validation

`PlanGenerator::validateGeneratedDisplays()` runs after every plan generation, before the plan is written to the approval queue. It scans every `planned_workouts` row for the new plan where `archetype_code IS NOT NULL` and checks two conditions:

1. **Unresolved template tokens:** any `{{word}}` pattern remaining in the concatenated `display_title`, `display_summary`, or `athlete_instructions` text.
2. **Numeric-archetype display with no digits:** for workouts with a quality `workout_type` (`interval`, `tempo`, `hill`, `fartlek`, `speed`) or any of the 10 archetypes in the explicit `numericArchetypes` list (`equal_distance_repeats`, `mixed_distance_repeats`, `short_speed_repeats`, `sustained_hill_repeats`, `hill_sprints`, `tempo_intervals`, `continuous_progression_tempo`, `high_volume_time_intervals`, `structured_fartlek_ladder`, `plyometric_hill_circuits`), the combined display text must contain at least one digit.

If either condition is violated for any workout in the plan, a `display_generation_incomplete` engine flag is raised for the athlete. This flag is **not deduped by open status** — each plan generation raises its own flag independently, because display completeness is plan-specific (an earlier open flag from a prior generation does not suppress a flag for the new plan).

This is the systemic safety net for any future archetype resolution regression: if a new parameter is added to an archetype's template without a corresponding default or resolution path, the validation pass will catch it before the coach sees the plan rather than surfacing as blank or malformed display text to athletes.

---

### 18.9 Quality Pace-Zone Citation (resolves §19 item 14)

When an athlete's `pace_zones_visible` flag is set **and** `athlete_profiles.pace_zones` is populated, quality-session instructions cite the relevant pace zone *alongside* the existing effort-language description. When the flag is off, or `pace_zones` is empty/null, output is **byte-for-byte identical** to the prior effort-only text.

**Mechanism — additive append, not template rewrite.** The archetype `description_template` strings are unchanged. After `renderTemplate()` and `normalizeInstructionText()`, `PlanGenerator::appendPaceCitation()` appends a single citation sentence to `athlete_instructions` (and the mirrored `description`). The decoded zones are cached once per generation in `PlanGenerator::$paceZones` (set in `generate()` from the profile; `null` when hidden or empty). When `$paceZones` is `null`, `appendPaceCitation()` returns the instruction string untouched — this is what guarantees the byte-identical effort-only fallback. The clause itself is built by `PaceZones::qualityCitation($code, $resolvedParams, $zones, $variantCode)`, which returns `null` for any effort-only archetype. Because the append only *adds* a sentence (with digits, no `{{tokens}}`), `validateGeneratedDisplays()` continues to pass — the numeric-archetype digit check becomes trivially satisfied and no unresolved tokens are introduced.

**Formatting.** All zone values are seconds/mile. `PaceZones::formatRange()` renders a low/high pair as `"M:SS–M:SS/mile"` (en-dash, matching the typical-easy-pace display on the profile pages, which uses `ProfileForm::formatPaceSecs()`). Cited zones render as a **narrow range**, never a bare single pace — it reads more naturally beside effort language ("Target roughly 7:12–7:38/mile on the tempo work."). Two range shapes are used: a **±5 sec/mile band** around a single scalar zone (`SCALAR_PACE_HALF_BAND`), and a **two-zone band** for efforts that genuinely span a range (threshold, mixed reps, fartlek).

**Per-archetype mapping:**

| Archetype | Cited? | Zone key(s) | Mapping logic |
|---|---|---|---|
| `tempo_intervals` | Yes | `10K`–`half_marathon` band | Threshold/tempo efforts sit between 10K (fast end) and half-marathon (slow end) pace. The band is cited directly rather than interpolated to a midpoint — it honestly reflects the threshold range. |
| `continuous_progression_tempo` | Yes | `10K`–`half_marathon` band | Same threshold band; the citation refers to the final sustained tempo segment that `{{progression_instruction}}` builds toward. |
| `high_volume_time_intervals` | Yes | `10K`–`half_marathon` band | Same threshold band (the "controlled hard" on-segments are threshold work). |
| `equal_distance_repeats` | Yes | nearest of `5K`/`mile`/`800`/`400`, ±5s | `rep_distance_meters` snaps to the nearest key by **geometric breakpoints** (566 / 1134 / 2236 m). E.g. 600/800/1000 m → `800`; 1200 m → `mile`. |
| `short_speed_repeats` | Yes | nearest of `5K`/`mile`/`800`/`400`, ±5s | Same distance→key mapping. Short reps (150–400 m) → `400` (the fastest available zone; near-sprint reps reference 400 pace). |
| `mixed_distance_repeats` | Yes | `mile`–`5K` band | No single rep distance (combines lengths/speeds); the band from mile (fast end) to 5K (slow end) spans the mixed efforts. |
| `structured_fartlek_ladder` | Yes | `5K`–`10K` band | Duration-segment session with no rep distance; the band spans the quicker short efforts (`5K`) to the controlled longer efforts (`10K`). |
| `fast_finish_long` | Yes (finish only) | finish variant → `half_marathon` or `marathon`, ±5s | A long run, but only the closing fast segment is pace-prescribed. The finish character comes from the variant: `threshold_finish` → `half_marathon` (nearest single zone to threshold); `marathon_finish`/`steady_finish` → `marathon`. `finish_zone` param is a fallback hint. |
| `sustained_hill_repeats` | **No** | — | Effort-only regardless of visibility. |
| `hill_sprints` | **No** | — | Effort-only regardless of visibility. |
| `plyometric_hill_circuits` | **No** | — | Effort-only regardless of visibility. |

**Hill-terrain decision.** The three hill archetypes are left effort-only even when zones are visible. Uphill running is ~40% slower per minute than flat easy pace (the basis for the 0.6× hill factor in §18.5), so flat-road pace zones do not transfer to graded terrain — citing a road pace on a hill rep would misprescribe the effort. Hill sessions remain governed by their effort language and grade guidance.

**Out of scope (never cited).** Easy/long archetypes are never pace-prescribed per §2/§3 and produce no citation: `continuous_easy`, `continuous_long`, `progression_long`, `goal_pace_long_segments`, `long_run_with_pickups`, `easy_with_strides`. (`fast_finish_long` is the sole long-slot exception, and only for its fast-finish segment.) The citation gate is **by archetype code**, not by `workout_type` — this is why `fast_finish_long` (`workout_type = long`) is cited while the other long archetypes are not.

Implementation: `PaceZones::qualityCitation()` + formatters; `PlanGenerator::appendPaceCitation()`. Deterministic unit coverage in `scripts/test_pace_citation.php`; integration check (visible-on/visible-off regeneration for Liam) in `scripts/verify_pace_citation_liam.php`.

---

### 18.10 Beginner / Return-to-Running Archetypes (foundation for §19 item 6)

Two archetypes serve the `insufficient` classification tier (the first archetypes to use `min_classification: insufficient`) and the `return_to_running` plan type, which previously produced nothing but `continuous_easy` fallback (every system archetype weighted `return_to_running` at 0).

**`run_walk_intervals`** — staged run/walk, ten variants (`stage_1`…`stage_10`), each a distinct stage. Stages 1-9 are N reps of (run X min / walk Y min) bookended by a 10-min brisk-walk warmup and 5-min walk cooldown; stage 10 is a 45-min first continuous run (warmup/cooldown folded into the continuous effort). Stage structure (run/walk minutes, rep count) is stored per-variant and copied into `resolved_params` by `PlanGenerator::buildRunWalkParams()`, which also builds the instance-specific, **effort-only** `{{run_walk_title}}` and `{{run_walk_instruction}}` (no pace numbers, ever — `PaceZones::qualityCitation()` returns null for this code regardless of `pace_zones_visible`). `instance_signature` includes the stage variant. `intensity_factor` 0.40.

**`standalone_strides`** — short (~18 min) neuromuscular session: brief warmup, 4-6 × ~25 sec relaxed strides with full recovery, brief cooldown. No substantial continuous-running block (distinct from `easy_with_strides`, which appends strides to a full easy run). Honest fixed duration computed in `addDerivedParams` (warmup + stride window + cooldown), not the slot allocation. `intensity_factor` 0.45.

**Stage selection (deterministic, not variety-seeking).** The stage/variant is never a weighted random pick:
- **`return_to_running`** presets the stage from `training_plans.rtr_current_stage` via `resolveRunWalkStage()`.
- **Development insufficient-base** goes through the normal selector; `addDerivedParams` forces a **fixed early stage (stage 1)** when no variant is preset.

Because placement is deterministic and these sessions are meant to recur (a beginner repeats a stage until they progress), both codes are listed in `PlanGenerator::REPEATABLE_ARCHETYPES` and **bypass the anti-repeat hard block** and (for `run_walk_intervals`) the per-slot quality-duration budget — the run/walk session is the athlete's primary session, not budgeted quality work. `target_duration` is the honest fixed total (`computeActualDuration` reads `duration_minutes` for these codes).

**Insufficient-only weighting.** Both carry `min_classification: insufficient` (so they are also *eligible* for workable/well_trained, since the floor is a minimum), but all of their `phase`/`goal_distance`/`plan_type` weights are 0 and only `classification.insufficient` is positive. Because `pickWeighted` sums the four weight dimensions, the total score is 0 for workable/well_trained athletes (excluded from the draw) and positive only for insufficient — cleanly restricting them to the insufficient tier without a `max_classification` mechanism. `run_walk_intervals` fills `easy` + quality slots; `standalone_strides` fills `easy` slots. (The existing classification-rank logic in `ArchetypeSelector` already treated `insufficient` as the lowest valid tier — rank 0 — so no enum/whitelist change was needed.)

**Return-to-running generation pathway.** `generateReturnToRunning()` is a dedicated branch (separate from the phase/weight selection system). For this foundation it produces a **static initial 10-day rolling window at stage 1**: run days on an every-other-day cadence capped by `training_days_per_week` as an upper bound and never on `must_off_days`, each a stage-1 `run_walk_intervals`; non-run days are low-impact cross-training (if the athlete has `cross_training_*` equipment) or rest, both carrying a note pointing to coach-provided drills (rehab Phases I-III are handled by the coach off-platform). `rtr_current_stage` is set to 1 at creation; `plan_end_date` is the window end (start + 9 days). The **adaptive per-session stage progression** that advances the stage and extends the window is the follow-on (see §19 item 6).

**Schema.** `training_plans.rtr_current_stage` — nullable `INT`, NULL for every non-`return_to_running` plan (migration_006; MariaDB-safe plain column).

**Insufficient-base hold state.** The mandatory hold state (critical `insufficient_base` flag, week-one-only visibility) is raised in `generateRaceCycle` for goal-race plans. These archetype additions do not touch that mechanism: they add eligible options to the development-plan pool for insufficient athletes (who previously got only `continuous_easy`), and race-cycle insufficient generation is unchanged (the new archetypes are not in `race_cycle`'s `plan_types`, so that path still raises the flag and falls back as before).

---

## 19. Open Items (Follow-On Specification Required)

These items are intentionally deferred and must be resolved before the relevant engine components are built:

1. **Workout library content** — ✅ Resolved. 23 templates documented in workout library document; archetype system (17 archetypes) is the active prescription layer as of Milestone 3.

2. **Tune-up race handling** — ✅ Resolved. See Section 26 of Architecture document.

3. **Training stress scoring formula** — ✅ Resolved. See Section 17.

4. **Ultra-specific considerations** — Deferred to v1.5. Phase structure, volume ceilings, long run character, and back-to-back long run protocols for ultra athletes.

5. **Profile-influenced guardrails** — Deferred to v2. Universal guardrails hold for all athletes in v1 regardless of experience level.

6. **Beginner-specific archetype gating** — 🟡 **Foundation built; adaptive progression pending.** Two beginner archetypes now exist as real entries: `run_walk_intervals` (10 deterministic stages) and `standalone_strides`, both `min_classification: insufficient` and insufficient-only weighted (see §18.10). `return_to_running` has a dedicated generation pathway (`generateReturnToRunning`) that no longer falls back to `continuous_easy` — it produces a static initial 10-day rolling window of stage-1 run/walk on an every-other-day cadence (capped by `training_days_per_week`, respecting `must_off_days`), with cross-training/rest + coach-drill notes on off days. `training_plans.rtr_current_stage` (migration_006) tracks the stage, initialised to 1. Development-plan insufficient-base athletes now draw `run_walk_intervals` (fixed stage 1) and `standalone_strides` for their quality/easy slots instead of `continuous_easy`-only. **Still pending (follow-on):** the adaptive per-session stage-progression mechanic that advances `rtr_current_stage` based on completed sessions and extends the plan window as the athlete climbs from stage 1 to the stage-10 first continuous run. This foundation deliberately generates only the static stage-1 initial state.

7. **Post-marathon recovery block structure** — Coach has a written document detailing the full month-long recovery block week by week. Pending coach providing source document. Until then, recovery block duration is defined (4 weeks for marathon, scaling by distance) but internal week-by-week structure is a placeholder.

8. **Archetype/variant swapping UI** — The "Swap to library template" coach UI was removed in Milestone 3.5. A proper archetype/variant swap interface (allowing coaches to substitute a different archetype or variant for a planned workout) is an open design item for a future milestone.

9. **Distance range precision** — `computeDistanceRange()` treats all non-hill main-set time as equivalent to flat easy pace for distance estimation. For intervals and tempo sessions, this overstates the likely range (athletes cover less distance per minute during hard structured efforts than easy running). A structured-effort pace table for quality sessions is a future refinement.

10. **`short_speed_repeats` variant display uniformity** — ✅ **Resolved.** Each variant now carries a distinct `rep_distance_meters` in its variant JSON (`economy_200s` → 200, `broken_speed_set` → 150, `speed_300s` → 300, `speed_endurance_400s` → 400, `repetition_session` → 600; `allowed_values` extended to include 500/600). `addDerivedParams` reads the per-variant distance and derives `rep_count = clamp(round(quality_volume_meters / rep_distance_meters), classification_min, classification_max)`, so total speed volume stays within the archetype's 800–2400 m band regardless of distance (longer reps → fewer reps) and `rep_count` never drops below `minimum_viable_params`. The five variants now render distinct titles and structures for a workable athlete — `8 × 200m`, `10 × 150m`, `5 × 300m`, `4 × 400m`, `4 × 600m` (honest durations 40–48 min, all ≥ the 40-min `minimum_session_duration`). *Note:* the `instance_signature` already included the `variant` field, so the variants were never literally signature-identical (cross-variant hard-blocking was not the actual scarcity mechanism — that was the doomed-quality-pool attempt-waste, fixed by quality-duration scaling in §6). The real defect was display/training **uniformity** (every instance looked like `7 × 200m`), which is what this fix resolves. Empirically, `short_speed_repeats` now recurs 1–3× across a 12-week plan with genuinely distinct variant displays, as one option within a 4–6-archetype quality pool. **The same per-variant-distinctness pattern was subsequently generalized** (see §18.7): `equal_distance_repeats` now uses discrete per-variant rep distances {600, 800, 1000, 1200}m (5K–10K specificity per WL-008) with `rep_count = clamp(round(quality_volume_meters / rep_distance), min, max)` — replacing the continuous 700m-style derivation; and `continuous_progression_tempo` now renders distinct variant titles ("Linear Progression Tempo" / "Wave Progression Tempo") with structure-aware, instance-specific instructions, so its repeated appearances (it can fill several of a 12-week plan's quality slots) no longer look identical.

11. **Low-volume athlete quality pool variety** — ✅ **Largely resolved** by quality-session duration scaling (§6) and item 10. Quality archetypes now resolve toward the per-week budget (`qualTarget`) rather than a flat 30–40 min target, so `tempo_intervals`, `sustained_hill_repeats`, `equal_distance_repeats`, and `continuous_progression_tempo` resolve to viable multi-rep structures and fill mid/high-volume weeks instead of being capped to ~1 rep and minimum-viable-rejected. For Liam (development plan, ~130–154 min/week) a representative generation now carries a structured quality session in 9 of 12 weeks across 4–6 distinct archetypes; only the three narrowest cutback weeks (budget ≈ 31–33) fall back to `continuous_easy`, which is appropriate for recovery weeks. *Residual:* the cutback-week fallback is inherent to the floor structure at very low volume (a reduced-footprint "starter" quality tier could fill them, but easy/recovery is defensible there). Monitor with real athlete data.

12. **Floor-aware cutback formula** *(largely addressed from a different angle)* — The floor-binding collision (cutback/low-volume weeks where the per-slot floor sum exceeds `weeklyMins`, squeezing the quality budget to zero) is now addressed primarily by the **days-per-week ramp (item 15 / §6)**, which lowers the scheduled day count when volume is low so the floor sum scales down with it, rather than by making the cutback ratio floor-aware. The original idea (`max(prevWeeklyMins × 0.75, floorSum + margin)`) remains a possible refinement but is lower priority now that the day-ramp keeps the budget positive across cutback and low-rebuild weeks. Revisit only if a configuration still produces a degenerate week after the ramp.

13. **`validateGeneratedDisplays()` does not check warmup/cooldown description completeness** *(low priority)* — The post-generation validator checks for unresolved template tokens and numeric-archetype displays without digits, but does not verify that `athlete_instructions` mentions the warmup and cooldown when `warmup_minutes` and `cooldown_minutes` are present in `resolved_params`. If a new archetype is added with warmup/cooldown in its parameters but those values are omitted from the `description_template`, the validator will pass silently. A third validation condition could be added: for any archetype where `warmup_minutes > 0` or `cooldown_minutes > 0` in `resolved_params`, the combined display text must contain the words "warm" and "cool" (or a comparable structural check). Not built: all 10 structured archetypes now carry explicit warmup/cooldown language in their description templates, so the gap only matters for future archetype additions.

14. **Effort-language-only policy for quality prescriptions is being revisited** — ✅ **Resolved.** Quality instructions now cite the athlete's derived pace zones *alongside* the effort language **when `pace_zones_visible` is set and `pace_zones` is populated**, and fall back to byte-for-byte-identical effort-only text otherwise. This was implemented as an additive append (`PlanGenerator::appendPaceCitation()` → `PaceZones::qualityCitation()`), not a template rewrite, so the established effort framing is untouched and the hidden/empty case is provably unchanged. Threshold/tempo efforts (`tempo_intervals`, `continuous_progression_tempo`, `high_volume_time_intervals`) cite the 10K–half-marathon band; distance reps (`equal_distance_repeats`, `short_speed_repeats`) cite the nearest of the 5K/mile/800/400 zones (±5 sec/mile); `mixed_distance_repeats` and `structured_fartlek_ladder` cite mile–5K and 5K–10K bands respectively; `fast_finish_long` cites the finish segment's zone (threshold→half-marathon, marathon/steady→marathon). The three hill archetypes are intentionally left effort-only — flat-road pace zones do not transfer to graded terrain (uphill is ~40% slower per minute; see §18.5). Full per-archetype mapping, formatting rules, and the byte-identity guarantee are documented in **§18.9**. The `{{progression_instruction}}` builder is unchanged — the pace clause is appended after it rather than interpolated into it.

15. **Volume/schedule allocation — days ramp, infeasible flag, cutback base, cross-cycle continuity** — ✅ **Resolved** (diagnosed from Liam generating zero quality at 5 days / 120 min). Four related fixes, fully documented in **§6**:
    - **Item 1 — days-per-week ramp:** `buildDaySchedule` caps the scheduled day count to `supportedDays(weeklyMins) = 1 + floor((weeklyMins − longFloor)/easyFloor)`, ramping toward `training_days_per_week` as volume grows. Stops the all-`continuous_easy` degenerate week (forcing 5 days at 120 min reserved 180 min of floors, zeroing the quality budget).
    - **Item 2 — infeasible-config flag:** new informational `schedule_day_ramp` engine flag (migration_007), raised at generation when requested days exceed week-1 supported days. Not a hold state.
    - **Item 3 — post-cutback growth base:** **was a real bug** (the build base reset to the cutback-week volume, making the next build week `preCutbackPeak × 0.864` and compounding across cutbacks). Fixed with a separate `buildBase` advanced only on build weeks. The prior §6 note claiming "no bug in the build formula" is corrected.
    - **Item 4 — cross-cycle volume continuity:** **was a confirmed gap** (every cycle re-read onboarding-era `current_weekly_minutes`, making `peak_volume_ceiling` unreachable without manual coach bumps). `block_end`/`engine_rebuild` now derive week-1 volume from the prior plan's peak (capped by ceiling); a manual `current_weekly_minutes` edit since the prior plan (`profile.updated_at > prior.generated_at`) overrides continuity.

---

*This document is a companion to the System Architecture document and should be read alongside it. Together they constitute the complete specification for v1 of the platform. Both documents should be provided to the coding agent before Milestone 2 begins. Milestone 1 (database schema, auth, onboarding forms, coach scaffold) can proceed from the Architecture document alone.*
