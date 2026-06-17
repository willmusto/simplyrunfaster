<?php
/**
 * Intervals.icu setup guide — publicly accessible (no auth).
 *
 * Routed from index.php at GET /app/integrations/intervals/guide. Mirrors the
 * privacy page's standalone layout (app shell, no authenticated nav). The
 * "Connect Intervals.icu" button targets the OAuth connect route when logged in,
 * otherwise login.
 */
require_once __DIR__ . '/../../views/layout/base.php';

$connectUrl = Auth::check() ? '/app/integrations/intervals/connect' : '/app/login';

$pageTitle = 'Connect Your Watch to SimplyRunFaster';
$extraCss  = <<<CSS
<style>
    .guide-page { max-width: 720px; margin: 0 auto; padding: 40px 20px 96px; line-height: 1.6; }
    .guide-page h1 { color: #1D9E75; font-size: 26px; font-weight: 700; letter-spacing: -0.02em; margin-bottom: 12px; }
    .guide-page .guide-intro { font-size: 16px; color: var(--text-primary); margin-bottom: 28px; }
    .guide-step { display: flex; gap: 14px; margin: 0 0 22px; }
    .guide-step .step-num {
        flex: 0 0 30px; width: 30px; height: 30px; border-radius: 50%;
        background: #1D9E75; color: #fff; font-weight: 700; font-size: 15px;
        display: flex; align-items: center; justify-content: center;
    }
    .guide-step .step-body { flex: 1; }
    .guide-step h2 { font-size: 17px; font-weight: 600; margin: 2px 0 6px; }
    .guide-step p { font-size: 14px; color: var(--text-primary); margin: 0 0 8px; }
    .guide-step .step-note { font-size: 13px; color: var(--text-muted); }
    .guide-page .btn-guide {
        display: inline-block; background: #1D9E75; color: #fff; text-decoration: none;
        font-weight: 600; font-size: 14px; padding: 9px 16px; border-radius: 8px; margin: 4px 0;
    }
    .guide-page .btn-guide.secondary { background: transparent; color: #1D9E75; border: 1px solid #1D9E75; }
    .guide-next { background: var(--recessed-bg, #F5F3EF); border-radius: 12px; padding: 18px 20px; margin: 28px 0; }
    .guide-next h2 { font-size: 16px; font-weight: 600; margin: 0 0 10px; }
    .guide-next ul { list-style: none; margin: 0; padding: 0; }
    .guide-next li { font-size: 14px; margin-bottom: 8px; padding-left: 24px; position: relative; }
    .guide-next li::before { content: "✓"; color: #1D9E75; font-weight: 700; position: absolute; left: 0; }
    .guide-faq h2 { color: #1D9E75; font-size: 19px; font-weight: 600; margin: 28px 0 12px; }
    .guide-faq .faq-q { font-size: 15px; font-weight: 600; margin: 14px 0 2px; }
    .guide-faq .faq-a { font-size: 14px; color: var(--text-primary); margin: 0; }
    .guide-page a { color: #1D9E75; }
</style>
CSS;

include __DIR__ . '/../../views/layout/html_open.php';
?>
<main class="guide-page">
    <h1>Connect Your Watch to SimplyRunFaster</h1>
    <p class="guide-intro">
        SimplyRunFaster sends your training plan workouts directly to your GPS watch and imports your
        completed runs automatically, through your free Intervals.icu account.
    </p>

    <div class="guide-step">
        <div class="step-num">1</div>
        <div class="step-body">
            <h2>Create a free Intervals.icu account</h2>
            <p>Go to intervals.icu and sign up for free.</p>
            <a class="btn-guide secondary" href="https://intervals.icu" target="_blank" rel="noopener">Open Intervals.icu →</a>
        </div>
    </div>

    <div class="guide-step">
        <div class="step-num">2</div>
        <div class="step-body">
            <h2>Connect your watch to Intervals.icu</h2>
            <p>In Intervals.icu: Settings → Connect → select your watch brand. Supported: Garmin, COROS, Polar, Suunto, Wahoo, Amazfit, Apple Watch, or Huawei.</p>
            <p class="step-note">This connects your watch to Intervals.icu, not to SimplyRunFaster directly.</p>
        </div>
    </div>

    <div class="guide-step">
        <div class="step-num">3</div>
        <div class="step-body">
            <h2>Connect Intervals.icu to SimplyRunFaster</h2>
            <a class="btn-guide" href="<?= htmlspecialchars($connectUrl) ?>">Connect Intervals.icu →</a>
        </div>
    </div>

    <div class="guide-next">
        <h2>What happens next</h2>
        <ul>
            <li>Your workouts appear on your Intervals.icu calendar</li>
            <li>They sync to your watch automatically</li>
            <li>Completed runs import into SimplyRunFaster within minutes</li>
            <li>Your coach sees your actual pace, heart rate, and effort</li>
        </ul>
    </div>

    <div class="guide-faq">
        <h2>FAQ</h2>
        <p class="faq-q">Do I need a paid Intervals.icu account?</p>
        <p class="faq-a">No, free is fine.</p>

        <p class="faq-q">Which watches work?</p>
        <p class="faq-a">Garmin, COROS, Polar, Suunto, Wahoo, Amazfit, Apple Watch, or Huawei.</p>

        <p class="faq-q">What data do you access?</p>
        <p class="faq-a">Completed run activities and your planned workout calendar. Nothing else.</p>

        <p class="faq-q">Can I disconnect?</p>
        <p class="faq-a">Yes. Go to Settings → Connected Devices.</p>
    </div>
</main>
<?php include __DIR__ . '/../../views/layout/html_close.php'; ?>
