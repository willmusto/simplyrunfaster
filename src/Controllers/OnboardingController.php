<?php
/**
 * Onboarding flow — 6 steps, writes to athlete_profiles
 *
 * Steps:
 *  1 - goal (race_cycle, development_plan, return_to_running)
 *  2 - fitness (current volume, recent race, time at volume)
 *  3 - experience (years running, injury history)
 *  4 - availability (days/week, must-off days, scheduling preference)
 *  5 - watch (platform, skippable)
 *  6 - preferences (units, notifications)
 */
class OnboardingController
{
    private const TOTAL_STEPS = 6;

    public static function start(): void
    {
        Auth::requireRole('athlete');
        $athlete = Auth::getAthlete();
        if ($athlete && $athlete['onboarding_completed_at']) {
            header('Location: /app');
            exit;
        }
        header('Location: /app/onboarding/1');
        exit;
    }

    public static function step(array $params): void
    {
        Auth::requireRole('athlete');
        $step = max(1, min((int)($params['step'] ?? 1), self::TOTAL_STEPS));

        // Enforce sequential completion
        $progress = $_SESSION['onboarding_progress'] ?? 1;
        if ($step > $progress) {
            header('Location: /app/onboarding/' . $progress);
            exit;
        }

        $error = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);

        $data  = $_SESSION['onboarding_data'] ?? [];
        $athlete = Auth::getAthlete();

        $view = match($step) {
            1 => 'step1_goal',
            2 => 'step2_fitness',
            3 => 'step3_experience',
            4 => 'step4_availability',
            5 => 'step5_watch',
            6 => 'step6_preferences',
        };

        include __DIR__ . '/../../views/onboarding/' . $view . '.php';
    }

    public static function stepSubmit(array $params): void
    {
        Auth::requireRole('athlete');
        Auth::verifyCsrf();

        $step = max(1, min((int)($params['step'] ?? 1), self::TOTAL_STEPS));

        $handler = match($step) {
            1 => [self::class, 'saveStep1'],
            2 => [self::class, 'saveStep2'],
            3 => [self::class, 'saveStep3'],
            4 => [self::class, 'saveStep4'],
            5 => [self::class, 'saveStep5'],
            6 => [self::class, 'saveStep6'],
        };

        $handler($step);
    }

    // ── Step handlers ──────────────────────────────────────────

    private static function saveStep1(int $step): void
    {
        $planType = $_POST['plan_type'] ?? '';
        if (!in_array($planType, ['race_cycle', 'development_plan', 'return_to_running'], true)) {
            $_SESSION['flash_error'] = 'Please select your goal.';
            header('Location: /app/onboarding/1');
            exit;
        }

        $distance = $_POST['goal_race_distance'] ?? null;

        // Hyrox runs the mile engine under a UI facade (mile spec Part 2): store
        // goal_race_distance='mile' with is_hyrox=1. Plain "Mile / 1500m" stores is_hyrox=0.
        $isHyrox = 0;
        if ($distance === 'hyrox') { $distance = 'mile'; $isHyrox = 1; }
        elseif ($distance === 'mile') { $isHyrox = 0; }

        // Ultra distances require a trail/road answer before continuing (ultra spec Part 2).
        $ultraDistances = ['50k', '50_miler', '100k', '100_miler'];
        $ultraSurface   = in_array($_POST['ultra_surface'] ?? '', ['trail', 'road'], true)
            ? $_POST['ultra_surface'] : null;
        if ($planType === 'race_cycle' && in_array($distance, $ultraDistances, true) && $ultraSurface === null) {
            // Preserve the distance selection so the surface question reappears with it chosen.
            $_SESSION['onboarding_data']['plan_type']          = $planType;
            $_SESSION['onboarding_data']['goal_race_distance'] = $distance;
            $_SESSION['flash_error'] = 'Please tell us whether this is a trail or road ultra.';
            header('Location: /app/onboarding/1');
            exit;
        }

        $_SESSION['onboarding_data']['plan_type']      = $planType;
        $_SESSION['onboarding_data']['goal_race_date'] = $_POST['goal_race_date'] ?? null;
        $_SESSION['onboarding_data']['goal_race_distance'] = $distance;
        $_SESSION['onboarding_data']['goal_finish_time']   = $_POST['goal_finish_time'] ?? null;
        $_SESSION['onboarding_data']['is_hyrox']           = $isHyrox;
        // Only store a surface for ultra distances; clear it otherwise.
        $_SESSION['onboarding_data']['ultra_surface'] =
            in_array($distance, $ultraDistances, true) ? $ultraSurface : null;

        // A race cycle is structurally undefined without a goal race date (phase lengths,
        // taper, and total weeks all derive from it), so it is required before advancing —
        // mirroring the client-side check and the PlanGenerator guard. Other plan types
        // (development / maintenance / return-to-running) keep the date optional.
        // Session data above is already stored, so the form repopulates on redirect.
        if ($planType === 'race_cycle' && empty($_SESSION['onboarding_data']['goal_race_date'])) {
            $_SESSION['flash_error'] = 'Please enter your goal race date to continue.';
            header('Location: /app/onboarding/1');
            exit;
        }

        // Return-to-running: capture time off and medical clearance on step 1
        if ($planType === 'return_to_running') {
            $_SESSION['onboarding_data']['return_time_off_band']       = $_POST['time_off_band'] ?? null;
            $_SESSION['onboarding_data']['medical_clearance_confirmed'] = isset($_POST['medical_clearance_1']) && isset($_POST['medical_clearance_2']) ? 1 : 0;
        }

        self::advance($step);
    }

    private static function saveStep2(int $step): void
    {
        $weekly = (int)($_POST['current_weekly_minutes'] ?? 0);
        $longest = (int)($_POST['longest_recent_run_mins'] ?? 0);
        $months  = (int)($_POST['months_at_current_volume'] ?? 0);

        if ($weekly < 1 || $longest < 1 || $months < 0) {
            $_SESSION['flash_error'] = 'Please fill in your current training volume.';
            header('Location: /app/onboarding/2');
            exit;
        }

        $_SESSION['onboarding_data']['current_weekly_minutes']    = $weekly;
        $_SESSION['onboarding_data']['longest_recent_run_mins']   = $longest;
        $_SESSION['onboarding_data']['months_at_current_volume']  = $months;
        $_SESSION['onboarding_data']['most_recent_race_distance'] = $_POST['recent_race_distance'] ?? null;
        $_SESSION['onboarding_data']['most_recent_race_time']     = self::parseTimeToSeconds($_POST['recent_race_time'] ?? '');
        $_SESSION['onboarding_data']['most_recent_race_date']     = $_POST['recent_race_date'] ?? null;

        // Easy-pace fallback (only meaningful when no race result was given).
        $_SESSION['onboarding_data']['typical_easy_pace_min'] = ProfileForm::parsePace($_POST['typical_easy_pace_min'] ?? '');
        $_SESSION['onboarding_data']['typical_easy_pace_max'] = ProfileForm::parsePace($_POST['typical_easy_pace_max'] ?? '');

        self::advance($step);
    }

    private static function saveStep3(int $step): void
    {
        $years = $_POST['years_running'] ?? '';
        if ($years === '' || $years < 0) {
            $_SESSION['flash_error'] = 'Please enter how long you\'ve been running.';
            header('Location: /app/onboarding/3');
            exit;
        }

        $_SESSION['onboarding_data']['years_running']       = (float)$years;
        $_SESSION['onboarding_data']['peak_weekly_minutes'] = (int)($_POST['peak_weekly_minutes'] ?? 0);
        $_SESSION['onboarding_data']['injury_history']      = trim($_POST['injury_history'] ?? '');
        $_SESSION['onboarding_data']['experience_level']    = $_POST['experience_level'] ?? 'intermediate';

        self::advance($step);
    }

    private static function saveStep4(int $step): void
    {
        $days = (int)($_POST['training_days_per_week'] ?? 0);
        if ($days < 1 || $days > 7) {
            $_SESSION['flash_error'] = 'Please select how many days per week you\'re running.';
            header('Location: /app/onboarding/4');
            exit;
        }

        $mustOffRaw = $_POST['must_off_days'] ?? '[]';
        $mustOff    = json_decode($mustOffRaw, true);
        if (!is_array($mustOff)) $mustOff = [];

        $schedPref = in_array($_POST['scheduling_preference'] ?? '', ['fixed','flex'], true)
            ? $_POST['scheduling_preference']
            : 'flex';

        $_SESSION['onboarding_data']['training_days_per_week']  = $days;
        $_SESSION['onboarding_data']['must_off_days']           = json_encode($mustOff);
        $_SESSION['onboarding_data']['scheduling_preference']   = $schedPref;
        $_SESSION['onboarding_data']['long_run_day']            = isset($_POST['long_run_day']) ? (int)$_POST['long_run_day'] : null;
        $_SESSION['onboarding_data']['primary_workout_day']     = isset($_POST['primary_workout_day']) ? (int)$_POST['primary_workout_day'] : null;
        $_SESSION['onboarding_data']['track_access']            = in_array($_POST['track_access'] ?? '', ['yes','no','road_reps_ok'], true)
            ? $_POST['track_access'] : 'road_reps_ok';

        self::advance($step);
    }

    private static function saveStep5(int $step): void
    {
        // Watch step is skippable — always succeeds
        $platform = in_array($_POST['watch_platform'] ?? '', ['garmin','polar','apple','wahoo','none'], true)
            ? $_POST['watch_platform']
            : 'none';

        $_SESSION['onboarding_data']['watch_platform']      = $platform;
        $_SESSION['onboarding_data']['cross_training_bike'] = in_array($_POST['cross_training_bike'] ?? '', ['none','stationary','road_gravel'], true)
            ? $_POST['cross_training_bike'] : 'none';
        $_SESSION['onboarding_data']['cross_training_elliptical'] = in_array($_POST['cross_training_elliptical'] ?? '', ['none','gym','home'], true)
            ? $_POST['cross_training_elliptical'] : 'none';
        $_SESSION['onboarding_data']['cross_training_pool'] = isset($_POST['cross_training_pool']) ? 1 : 0;

        self::advance($step);
    }

    private static function saveStep6(int $step): void
    {
        // Final step — both consent boxes are required before the account is
        // finalized. Validate server-side; the client `required` attribute is a
        // convenience only and must not be trusted.
        $consentAge     = isset($_POST['consent_age']);
        $consentPrivacy = isset($_POST['consent_privacy']);
        $consentTos     = isset($_POST['consent_tos']);
        if (!$consentAge || !$consentPrivacy || !$consentTos) {
            $_SESSION['flash_error'] = 'Please confirm you meet the age requirement and agree to the Privacy Policy and Terms of Service to finish.';
            // Preserve the units selection the athlete just made.
            if (in_array($_POST['units'] ?? '', ['miles','km'], true)) {
                $_SESSION['onboarding_data']['units'] = $_POST['units'];
            }
            header('Location: /app/onboarding/6');
            exit;
        }

        $units = in_array($_POST['units'] ?? '', ['miles','km'], true) ? $_POST['units'] : 'miles';
        $_SESSION['onboarding_data']['units'] = $units;

        self::persistToDatabase();
        self::recordConsent();

        // Clear onboarding session data
        unset($_SESSION['onboarding_data'], $_SESSION['onboarding_progress']);

        // Trigger plan generation
        $athlete = Auth::getAthlete();
        if ($athlete) {
            // Establish the authoritative coach assignment from the invite link's
            // created_by (fallback user 1). This also mirrors coach_id onto the
            // athletes row, so it must run before the welcome message is scheduled.
            try {
                self::ensureCoachAssignment((int)$athlete['id']);
            } catch (Throwable $e) {
                error_log('ensureCoachAssignment failed for athlete ' . $athlete['id'] . ': ' . $e->getMessage());
            }

            try {
                PlanGenerator::generate((int)$athlete['id'], 'onboarding');
            } catch (Throwable $e) {
                error_log('PlanGenerator::generate failed for athlete ' . $athlete['id'] . ': ' . $e->getMessage());
            }

            // Schedule the coach's welcome message (delivered after a short delay by
            // scripts/cron_scheduled_messages.php). Guarded so it never blocks signup.
            try {
                self::scheduleWelcomeMessage((int)$athlete['id']);
            } catch (Throwable $e) {
                error_log('scheduleWelcomeMessage failed for athlete ' . $athlete['id'] . ': ' . $e->getMessage());
            }
        }

        // Billing: comped athletes (and any non-Stripe environment) go straight
        // to the app; everyone else is sent to Stripe Checkout to subscribe.
        $checkoutUrl = self::checkoutRedirectUrl();
        header('Location: ' . ($checkoutUrl ?: '/app'));
        exit;
    }

    /**
     * After onboarding, decide where to send the athlete. Returns a Stripe
     * Checkout URL when a subscription is required, or null to enter the app
     * (comped, already subscribed, or Stripe not configured).
     */
    private static function checkoutRedirectUrl(): ?string
    {
        $db     = Database::get();
        $userId = Auth::userId();

        $row = Billing::userBillingRow($userId, $db);
        if (!$row) return null;
        if (in_array($row['subscription_status'] ?? 'none', ['comped', 'active', 'trialing'], true)) {
            return null;
        }
        if (!Billing::isConfigured()) return null;

        // Pull discount/interval from the invite the athlete signed up with.
        $couponId = null;
        $interval = 'monthly';
        $inv = $db->prepare(
            'SELECT il.stripe_coupon_id, il.billing_interval
             FROM invite_links il JOIN users u ON u.invite_code = il.code
             WHERE u.id = ? LIMIT 1'
        );
        $inv->execute([$userId]);
        if ($r = $inv->fetch(PDO::FETCH_ASSOC)) {
            $couponId = $r['stripe_coupon_id'] ?: null;
            if (in_array($r['billing_interval'] ?? '', ['monthly', 'annual'], true)) {
                $interval = $r['billing_interval'];
            }
        }

        return Billing::createCheckoutSession($userId, $interval, $couponId, $db);
    }

    // ── DB write ───────────────────────────────────────────────

    /**
     * Persist the onboarding consent flags onto the user's row. Called only
     * after both consent checkboxes have been validated as checked.
     */
    private static function recordConsent(): void
    {
        $db = Database::get();
        $db->prepare(
            'UPDATE users SET consent_age = 1, consent_privacy = 1, consent_given_at = NOW(),
                              consent_tos = 1, consent_tos_at = NOW() WHERE id = ?'
        )->execute([Auth::userId()]);
    }

    /**
     * Queue the coach's welcome message for an athlete who just finished onboarding.
     * Sends from the (now-backfilled) assigned coach after a ~12-minute delay via
     * scripts/cron_scheduled_messages.php. No-op when no coach is assigned, and
     * idempotent (one welcome per athlete).
     */
    private static function scheduleWelcomeMessage(int $athleteId): void
    {
        $db = Database::get();

        $stmt = $db->prepare(
            'SELECT a.coach_id, u.name
             FROM athletes a JOIN users u ON u.id = a.user_id
             WHERE a.id = ? LIMIT 1'
        );
        $stmt->execute([$athleteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }

        // Resolve the sender from coach_assignments (authoritative), falling back to
        // the mirrored athletes.coach_id. No coach → nothing to send from.
        $senderId = CoachAssignments::coachId($athleteId, $db) ?? (int)($row['coach_id'] ?? 0);
        if (!$senderId) {
            return;
        }

        // Already scheduled (e.g. a re-submitted final step)? Don't duplicate.
        $exists = $db->prepare('SELECT 1 FROM scheduled_messages WHERE athlete_id = ? LIMIT 1');
        $exists->execute([$athleteId]);
        if ($exists->fetchColumn()) {
            return;
        }

        $first = explode(' ', trim((string)($row['name'] ?? '')))[0] ?: 'there';
        $body  = "Hey {$first}, welcome to SimplyRunFaster! Really glad you are here. "
               . "Your plan is being put together and I will have it ready for your review shortly. "
               . "In the meantime, feel free to message me here any time - this is how we will "
               . "communicate throughout your training. Looking forward to working with you.";

        $db->prepare(
            'INSERT INTO scheduled_messages (athlete_id, sender_id, body, send_after)
             VALUES (?, ?, ?, NOW() + INTERVAL 12 MINUTE)'
        )->execute([$athleteId, $senderId, $body]);
    }

    /**
     * Create the authoritative coach_assignments row for a freshly-onboarded athlete.
     * coach_id resolves from the invite link's created_by (the inviting coach); for
     * organic signups or a missing/invalid invite it falls back to user 1 (admin).
     * CoachAssignments::assignCoach also mirrors coach_id onto the athletes row.
     */
    private static function ensureCoachAssignment(int $athleteId): void
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT il.created_by
             FROM invite_links il
             JOIN users u ON u.invite_code = il.code
             WHERE u.id = (SELECT user_id FROM athletes WHERE id = ?)
             LIMIT 1'
        );
        $stmt->execute([$athleteId]);
        $coachId = (int)($stmt->fetchColumn() ?: 0) ?: 1;
        CoachAssignments::assignCoach($athleteId, $coachId, $coachId, $db);
    }

    private static function persistToDatabase(): void
    {
        $db      = Database::get();
        $userId  = Auth::userId();
        $athlete = Auth::getAthlete($userId);
        if (!$athlete) return;

        $athleteId = (int)$athlete['id'];
        $d         = $_SESSION['onboarding_data'] ?? [];

        // Compute plan_type
        $planType = $d['plan_type'] ?? 'development_plan';

        $fields = [
            'plan_type'                  => $planType,
            'goal_race_date'             => $d['goal_race_date'] ?: null,
            'goal_race_distance'         => $d['goal_race_distance'] ?: null,
            'ultra_surface'              => in_array($d['ultra_surface'] ?? null, ['trail','road'], true) ? $d['ultra_surface'] : null,
            'is_hyrox'                   => !empty($d['is_hyrox']) ? 1 : 0,
            'hyrox_ever'                 => !empty($d['is_hyrox']) ? 1 : 0,
            'goal_finish_time'           => $d['goal_finish_time'] ?: null,
            'current_weekly_minutes'     => (int)($d['current_weekly_minutes'] ?? 0) ?: null,
            'longest_recent_run_mins'    => (int)($d['longest_recent_run_mins'] ?? 0) ?: null,
            'months_at_current_volume'   => (int)($d['months_at_current_volume'] ?? 0),
            'most_recent_race_distance'  => $d['most_recent_race_distance'] ?: null,
            'most_recent_race_time'      => (int)($d['most_recent_race_time'] ?? 0) ?: null,
            'most_recent_race_date'      => $d['most_recent_race_date'] ?: null,
            'typical_easy_pace_min'      => $d['typical_easy_pace_min'] ?? null,
            'typical_easy_pace_max'      => $d['typical_easy_pace_max'] ?? null,
            'years_running'              => (float)($d['years_running'] ?? 0) ?: null,
            'peak_weekly_minutes'        => (int)($d['peak_weekly_minutes'] ?? 0) ?: null,
            'experience_level'           => $d['experience_level'] ?? 'intermediate',
            'injury_history'             => $d['injury_history'] ?: null,
            'training_days_per_week'     => (int)($d['training_days_per_week'] ?? 0) ?: null,
            'must_off_days'              => $d['must_off_days'] ?: '[]',
            'scheduling_preference'      => $d['scheduling_preference'] ?? 'flex',
            'long_run_day'               => isset($d['long_run_day']) && $d['long_run_day'] !== '' ? (int)$d['long_run_day'] : null,
            'primary_workout_day'        => isset($d['primary_workout_day']) && $d['primary_workout_day'] !== '' ? (int)$d['primary_workout_day'] : null,
            'track_access'               => $d['track_access'] ?? 'road_reps_ok',
            'watch_platform'             => $d['watch_platform'] ?? 'none',
            'cross_training_bike'        => $d['cross_training_bike'] ?? 'none',
            'cross_training_elliptical'  => $d['cross_training_elliptical'] ?? 'none',
            'cross_training_pool'        => (int)($d['cross_training_pool'] ?? 0),
            'units'                      => $d['units'] ?? 'miles',
            'medical_clearance_confirmed' => (int)($d['medical_clearance_confirmed'] ?? 0),
            'medical_clearance_at'       => ($d['medical_clearance_confirmed'] ?? 0) ? date('Y-m-d H:i:s') : null,
            'return_time_off_band'       => $d['return_time_off_band'] ?? null,
        ];

        // Set peak_volume_ceiling_mins: current volume × 1.4
        if (!empty($fields['current_weekly_minutes'])) {
            $fields['peak_volume_ceiling_mins'] = (int)round($fields['current_weekly_minutes'] * 1.4);
        }

        $cols       = implode(', ', array_map(fn($k) => "`$k`", array_keys($fields)));
        $setClauses = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($fields)));
        $stmt = $db->prepare(
            "INSERT INTO athlete_profiles (athlete_id, $cols)
             VALUES (?, " . implode(', ', array_fill(0, count($fields), '?')) . ")
             ON DUPLICATE KEY UPDATE $setClauses"
        );

        $values = array_merge([$athleteId], array_values($fields), array_values($fields));
        $stmt->execute($values);

        // Mark onboarding complete on athletes table; backfill coach_id from invite_links if still NULL
        $db->prepare(
            'UPDATE athletes
             SET onboarding_completed_at = NOW(),
                 status    = \'active\',
                 coach_id  = COALESCE(
                     coach_id,
                     (SELECT il.assigned_coach_id
                      FROM invite_links il
                      JOIN users u ON u.invite_code = il.code
                      WHERE u.id = ?)
                 )
             WHERE id = ?'
        )->execute([$userId, $athleteId]);

        // ── Pace-zone derivation from onboarding inputs ───────────────────
        // Race result takes precedence (verified); otherwise the typical easy
        // pace gives an estimate. If neither exists, raise a coach info flag.
        $raceTime = (int)($fields['most_recent_race_time'] ?? 0);
        $raceDist = $fields['most_recent_race_distance'] ?? null;
        $easyMin  = $fields['typical_easy_pace_min'] ?? null;
        $easyMax  = $fields['typical_easy_pace_max'] ?? null;

        $zones = null;
        $source = null;
        if ($raceTime > 0 && $raceDist) {
            $zones  = PaceZones::fromRace($raceDist, $raceTime);
            $source = 'race_result';
        } elseif (!empty($easyMin)) {
            $zones  = PaceZones::fromEasyPace((int)$easyMin, (int)($easyMax ?: $easyMin));
            $source = 'easy_pace_estimate';
        }

        if ($zones) {
            $db->prepare(
                'UPDATE athlete_profiles SET pace_zones = ?, pace_zones_source = ? WHERE athlete_id = ?'
            )->execute([json_encode($zones), $source, $athleteId]);
        } else {
            $first = explode(' ', trim($athlete['name'] ?? 'Athlete'))[0] ?: 'Athlete';
            $db->prepare(
                'INSERT INTO engine_flags
                 (athlete_id, flag_type, severity, flag_date, message, status, created_at)
                 VALUES (?, "pace_zones_missing", "info", CURDATE(), ?, "open", NOW())'
            )->execute([
                $athleteId,
                "{$first} has no race result or typical easy pace on file. Pace assignments can't be calculated yet.",
            ]);
        }
    }

    // ── Utility ────────────────────────────────────────────────

    private static function advance(int $step): void
    {
        $next = $step + 1;
        $_SESSION['onboarding_progress'] = max($_SESSION['onboarding_progress'] ?? 1, $next);
        header('Location: /app/onboarding/' . $next);
        exit;
    }

    private static function parseTimeToSeconds(string $time): ?int
    {
        if (!$time) return null;
        $parts = array_map('intval', explode(':', $time));
        return match(count($parts)) {
            3 => $parts[0] * 3600 + $parts[1] * 60 + $parts[2],
            2 => $parts[0] * 60  + $parts[1],
            1 => $parts[0],
            default => null,
        };
    }
}
