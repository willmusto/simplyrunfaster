<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>SimplyRunFaster | Real coaching. Real results.</title>
    <meta name="description" content="SimplyRunFaster pairs every athlete with a real coach, not an algorithm. Your coach reviews your plan, watches your training, and thinks about you specifically. Starting at $39/month.">
    <meta property="og:title" content="SimplyRunFaster | Real coaching. Real results.">
    <meta property="og:description" content="A real coach reviews your plan, watches your training, and thinks about you specifically. Starting at $39/month.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://simplyrunfaster.com/">
    <meta name="theme-color" content="#1D9E75">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" href="/assets/icons/icon-192.png">
    <style>
        /* Marketing page. Same design system as the app (UI direction doc):
           warm off-white surfaces, forest teal used sparingly, hairline borders,
           depth from surface contrast, no shadows, no decoration. Left-aligned
           structure; the closing CTA is the one deliberate centered moment. */
        :root {
            --accent-fill:   #E1F5EE;
            --accent-mid:    #1D9E75;
            --accent-strong: #0F6E56;
            --accent-dark:   #085041;
            --accent-on-dark:#5DCAA5;
            --page-bg:       #F5F3EF;
            --card-bg:       #FFFFFF;
            --recessed-bg:   #F0EDE7;
            --dark-bg:       #161616;
            --border-color:  rgba(0, 0, 0, 0.08);
            --border-strong: rgba(0, 0, 0, 0.15);
            --text-primary:  #1A1A1A;
            --text-secondary:#6B6B6B;
            --text-muted:    #9A9A9A;
            --radius-card:   12px;
            --radius-sm:     8px;
            --measure:       640px;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { -webkit-text-size-adjust: 100%; scroll-behavior: smooth; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 16px;
            line-height: 1.6;
            color: var(--text-primary);
            background: var(--page-bg);
        }

        .wrap { max-width: 1000px; margin: 0 auto; padding: 0 24px; }

        /* ── Type system: hierarchy from scale and weight, nothing else ── */
        .eyebrow {
            font-size: 11px; font-weight: 600; letter-spacing: 0.1em;
            text-transform: uppercase; color: var(--accent-strong); margin-bottom: 16px;
        }
        h1 {
            font-size: clamp(44px, 7.5vw, 76px); font-weight: 800;
            letter-spacing: -0.035em; line-height: 1.02;
        }
        h2 {
            font-size: clamp(28px, 4.5vw, 38px); font-weight: 700;
            letter-spacing: -0.025em; line-height: 1.12; margin-bottom: 20px;
        }
        .body-copy { font-size: 17px; color: var(--text-secondary); max-width: var(--measure); }
        .body-copy p + p { margin-top: 16px; }

        /* ── Nav ── */
        .nav {
            position: sticky; top: 0; z-index: 50;
            background: rgba(245, 243, 239, 0.92);
            backdrop-filter: blur(8px);
            border-bottom: 0.5px solid var(--border-color);
        }
        .nav-inner {
            max-width: 1000px; margin: 0 auto; padding: 14px 24px;
            display: flex; align-items: center; justify-content: space-between; gap: 16px;
        }
        .wordmark { font-size: 18px; font-weight: 700; letter-spacing: -0.02em; color: var(--text-primary); text-decoration: none; }
        .wordmark span { color: var(--accent-mid); }
        .nav-links { display: flex; align-items: center; gap: 20px; }
        .nav-signin { font-size: 14px; font-weight: 500; color: var(--text-secondary); text-decoration: none; }
        .nav-signin:hover { color: var(--text-primary); }

        .btn {
            display: inline-block; text-decoration: none; font-weight: 600;
            border-radius: var(--radius-sm); text-align: center; transition: background .15s;
        }
        .btn-primary { background: var(--accent-mid); color: #fff; padding: 13px 26px; font-size: 15px; }
        .btn-primary:hover { background: var(--accent-strong); }
        .btn-sm { padding: 8px 16px; font-size: 13px; }
        .text-link {
            font-size: 15px; font-weight: 500; color: var(--text-secondary);
            text-decoration: underline; text-underline-offset: 3px; text-decoration-color: var(--border-strong);
        }
        .text-link:hover { color: var(--text-primary); }

        section { padding: 88px 0; }
        .band-card { background: var(--card-bg); border-top: 0.5px solid var(--border-color); border-bottom: 0.5px solid var(--border-color); }

        /* ── Hero: left-aligned, typographic; right side anchored by an app-UI
               rendition built from the app's own design tokens (swapped for a real
               screenshot when one is provided). Hidden below 900px so the mobile
               hero stays spare. ── */
        .hero { padding: 104px 0 96px; }
        .hero-grid { display: grid; grid-template-columns: 1fr; gap: 48px; align-items: center; }
        @media (min-width: 900px) { .hero-grid { grid-template-columns: minmax(0, 11fr) minmax(0, 9fr); } }
        .hero h1 { max-width: 12ch; }
        .hero-sub { font-size: clamp(17px, 2.2vw, 19px); color: var(--text-secondary); max-width: 34rem; margin: 26px 0 36px; }
        .hero-ctas { display: flex; gap: 22px; align-items: center; flex-wrap: wrap; }

        .hero-visual { display: none; }
        @media (min-width: 900px) { .hero-visual { display: block; } }
        .app-card {
            background: var(--card-bg); border: 0.5px solid var(--border-color);
            border-radius: var(--radius-card); padding: 20px 22px; max-width: 360px;
        }
        .app-label {
            font-size: 10px; font-weight: 600; letter-spacing: 0.08em;
            text-transform: uppercase; color: var(--text-muted); margin-bottom: 10px;
        }
        .app-pill {
            display: inline-block; font-size: 11px; font-weight: 500;
            background: var(--accent-fill); color: var(--accent-dark);
            border-radius: 6px; padding: 3px 9px; margin-bottom: 8px;
        }
        .app-title { font-size: 16px; font-weight: 600; letter-spacing: -0.01em; }
        .app-meta { font-size: 12px; color: var(--text-muted); margin: 3px 0 12px; }
        .app-desc { font-size: 13px; color: var(--text-secondary); line-height: 1.55; }
        .app-note {
            margin: 14px -22px -20px; padding: 12px 22px 16px;
            background: var(--recessed-bg);
            border-radius: 0 0 var(--radius-card) var(--radius-card);
            font-size: 12.5px; color: var(--text-secondary); line-height: 1.5;
        }
        .app-note .from { font-size: 10px; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-muted); display: block; margin-bottom: 3px; }
        .app-card-back {
            max-width: 320px; margin: -8px 0 0 48px; position: relative; z-index: -1;
            transform: translateY(-4px);
        }
        .app-week { display: flex; gap: 8px; align-items: center; }
        .app-day { font-size: 9px; font-weight: 600; color: var(--text-muted); text-align: center; flex: 1; }
        .app-dot {
            width: 26px; height: 26px; border-radius: 50%; margin: 4px auto 0;
            display: flex; align-items: center; justify-content: center;
            font-size: 8.5px; font-weight: 700; color: #fff;
        }

        /* ── How it works: ruled structural list, not icon cards ── */
        .steps { border-top: 1px solid var(--border-strong); margin-top: 12px; max-width: 780px; }
        .step {
            display: grid; grid-template-columns: 72px 1fr; gap: 8px 20px;
            padding: 26px 0; border-bottom: 1px solid var(--border-color); align-items: baseline;
        }
        .step-num {
            font-size: 15px; font-weight: 700; color: var(--text-muted);
            font-variant-numeric: tabular-nums; letter-spacing: 0.04em;
        }
        .step h3 { font-size: 18px; font-weight: 650; letter-spacing: -0.01em; }
        .step p { grid-column: 2; font-size: 15.5px; color: var(--text-secondary); max-width: 56ch; }
        @media (max-width: 560px) {
            .step { grid-template-columns: 1fr; }
            .step p { grid-column: 1; }
        }

        /* ── Track record: the signature moment. Dark, stark, typographic. ── */
        .record { background: var(--dark-bg); color: #fff; padding: 96px 0; }
        .record .eyebrow { color: var(--accent-on-dark); }
        .record h2 { color: #fff; max-width: 16ch; }
        .stat-rows { margin: 48px 0 56px; border-top: 1px solid rgba(255,255,255,0.1); }
        .stat-row {
            display: grid; grid-template-columns: minmax(120px, 220px) 1fr;
            gap: 24px; align-items: center;
            padding: 30px 0; border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .stat-row .num {
            font-size: clamp(64px, 9vw, 112px); font-weight: 800; line-height: 0.9;
            letter-spacing: -0.04em; color: var(--accent-on-dark);
            font-variant-numeric: tabular-nums;
        }
        .stat-row .num sup { font-size: 0.45em; font-weight: 700; vertical-align: super; }
        .stat-row .statement { font-size: clamp(16px, 2.2vw, 20px); color: rgba(255,255,255,0.88); max-width: 34ch; line-height: 1.45; }
        .record .body-copy { color: rgba(255,255,255,0.62); font-size: 16px; }
        .record .body-copy strong { color: rgba(255,255,255,0.9); }
        @media (max-width: 560px) {
            .stat-row { grid-template-columns: 1fr; gap: 8px; padding: 24px 0; }
        }

        /* ── Pricing ── */
        .price-cards { display: grid; grid-template-columns: 1fr; gap: 16px; margin-top: 40px; }
        @media (min-width: 720px) { .price-cards { grid-template-columns: 1fr 1fr; max-width: 860px; } }
        .price-card {
            background: var(--card-bg); border: 0.5px solid var(--border-color);
            border-radius: var(--radius-card); padding: 32px 28px; display: flex; flex-direction: column;
        }
        .price-card.featured { border: 1.5px solid var(--accent-mid); position: relative; }
        .founding-badge {
            position: absolute; top: -11px; left: 24px;
            background: var(--accent-mid); color: #fff;
            font-size: 10px; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase;
            padding: 4px 10px; border-radius: 999px;
        }
        .price-tier { font-size: 13px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: var(--text-muted); }
        .price-amount { font-size: 44px; font-weight: 750; letter-spacing: -0.03em; margin: 8px 0 2px; font-variant-numeric: tabular-nums; }
        .price-amount small { font-size: 15px; font-weight: 500; color: var(--text-muted); letter-spacing: 0; }
        .price-note { font-size: 13px; color: var(--accent-strong); margin-bottom: 20px; min-height: 20px; }
        .price-list { list-style: none; margin: 0 0 26px; flex: 1; }
        .price-list li {
            font-size: 14.5px; color: var(--text-secondary); padding: 7px 0 7px 24px; position: relative;
        }
        .price-list li::before {
            content: ""; position: absolute; left: 1px; top: 14px;
            width: 12px; height: 7px;
            border-left: 2px solid var(--accent-mid); border-bottom: 2px solid var(--accent-mid);
            transform: rotate(-45deg);
        }
        .price-plus { font-size: 13px; font-weight: 600; color: var(--text-primary); margin-bottom: 4px; }
        .fine-print {
            margin-top: 24px; max-width: 860px; background: var(--recessed-bg); border-radius: var(--radius-sm);
            padding: 18px 20px; font-size: 13.5px; color: var(--text-secondary);
        }
        .fine-print-label { font-weight: 600; color: var(--text-primary); display: block; margin-bottom: 4px; font-size: 12.5px; }

        /* ── Placeholders (bio, testimonials): honest, clearly unfinished ── */
        .quote-tag {
            display: inline-block; margin-bottom: 10px; font-size: 10px; font-weight: 600;
            letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-muted);
            border: 0.5px solid var(--border-strong); border-radius: 999px; padding: 2px 8px;
        }
        .coach-card {
            display: flex; gap: 22px; align-items: flex-start; max-width: 720px;
            background: var(--card-bg); border: 1px dashed var(--border-strong);
            border-radius: var(--radius-card); padding: 26px; margin-top: 8px;
        }
        .coach-photo {
            flex-shrink: 0; width: 88px; height: 88px; border-radius: 50%;
            background: var(--recessed-bg); border: 1px dashed var(--border-strong);
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; color: var(--text-muted);
        }
        .coach-copy p { font-size: 15px; color: var(--text-secondary); font-style: italic; margin-top: 6px; }
        @media (max-width: 480px) { .coach-card { flex-direction: column; } }

        .placeholder-note { font-size: 13px; color: var(--text-muted); font-style: italic; margin-top: 10px; }
        .quotes { display: grid; grid-template-columns: 1fr; gap: 14px; margin-top: 28px; }
        @media (min-width: 720px) { .quotes { grid-template-columns: repeat(3, 1fr); } }
        .quote-card {
            background: var(--card-bg); border: 1px dashed var(--border-strong);
            border-radius: var(--radius-card); padding: 22px;
        }
        .quote-text { font-size: 14.5px; color: var(--text-secondary); font-style: italic; }
        .quote-attr { margin-top: 12px; font-size: 12.5px; color: var(--text-muted); }

        /* ── Closing CTA: the one deliberate centered moment ── */
        .footer-cta { text-align: center; padding: 104px 0; }
        .footer-cta h2 { font-size: clamp(34px, 5.5vw, 52px); letter-spacing: -0.03em; }
        .footer-cta .body-copy { margin: 0 auto 34px; max-width: 30rem; font-size: 16px; }

        footer {
            border-top: 0.5px solid var(--border-color);
            padding: 26px 0 40px; font-size: 13px; color: var(--text-muted);
        }
        .footer-inner {
            max-width: 1000px; margin: 0 auto; padding: 0 24px;
            display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
        }
        .footer-links { display: flex; gap: 18px; }
        .footer-links a { color: var(--text-muted); text-decoration: none; }
        .footer-links a:hover { color: var(--text-secondary); text-decoration: underline; }

        @media (max-width: 560px) {
            section { padding: 64px 0; }
            .hero { padding: 72px 0 64px; }
            .footer-cta { padding: 72px 0; }
        }
    </style>
</head>
<body>

<nav class="nav">
    <div class="nav-inner">
        <a class="wordmark" href="/">Simply<span>Run</span>Faster</a>
        <div class="nav-links">
            <a class="nav-signin" href="/app/login">Sign in</a>
            <a class="btn btn-primary btn-sm" href="/app/register">Get started</a>
        </div>
    </div>
</nav>

<!-- Hero -->
<header class="hero">
    <div class="wrap">
        <div class="hero-grid">
            <div>
                <div class="eyebrow">Online running coaching</div>
                <h1>Real coaching. Real results.</h1>
                <p class="hero-sub">SimplyRunFaster pairs every athlete with a real coach, not an algorithm. Your coach reviews your plan, watches your training, and thinks about you specifically. Starting at $39/month.</p>
                <div class="hero-ctas">
                    <a class="btn btn-primary" href="/app/register">Get started &rarr;</a>
                    <a class="text-link" href="#how-it-works">How it works &darr;</a>
                </div>
            </div>
            <div class="hero-visual" aria-hidden="true">
                <div class="app-card">
                    <div class="app-label">Today</div>
                    <span class="app-pill">Tempo</span>
                    <div class="app-title">Tempo Intervals</div>
                    <div class="app-meta">45 min &middot; Thursday</div>
                    <div class="app-desc">Warm up with 12 minutes of easy running. Then 3 x 8 minutes at a comfortably hard tempo effort with 3 minutes easy between. Cool down with 10 minutes easy.</div>
                    <div class="app-note">
                        <span class="from">From your coach</span>
                        Strong long run Sunday. Hold the middle rep honest today and we build from there.
                    </div>
                </div>
                <div class="app-card app-card-back">
                    <div class="app-label">This week</div>
                    <div class="app-week">
                        <div class="app-day">M<span class="app-dot" style="background:var(--border-strong);"></span></div>
                        <div class="app-day">T<span class="app-dot" style="background:#7FB7A3;">40</span></div>
                        <div class="app-day">W<span class="app-dot" style="background:var(--border-strong);"></span></div>
                        <div class="app-day">T<span class="app-dot" style="background:var(--accent-mid);">45</span></div>
                        <div class="app-day">F<span class="app-dot" style="background:#7FB7A3;">35</span></div>
                        <div class="app-day">S<span class="app-dot" style="background:var(--border-strong);"></span></div>
                        <div class="app-day">S<span class="app-dot" style="background:var(--accent-dark);">1h30</span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- The Problem -->
<section class="band-card">
    <div class="wrap">
        <div class="eyebrow">The problem</div>
        <h2>Training apps aren&rsquo;t coaching.</h2>
        <div class="body-copy">
            <p>You&rsquo;ve tried the apps. You follow the plan on Monday, miss Thursday, and by Saturday you&rsquo;re improvising. The app doesn&rsquo;t notice. Nobody notices.</p>
            <p>Real coaching is different. A real coach sees when you&rsquo;re struggling before you do. They adjust your plan not because a rule fired, but because they made a judgment call about your training. They remember that you mentioned your left knee felt tight last week. They write you a note at the end of the month that tells you what all those runs actually meant.</p>
            <p>SimplyRunFaster is real coaching, made accessible by technology that handles the structure so your coach can focus on the parts that actually require a human.</p>
        </div>
    </div>
</section>

<!-- How It Works -->
<section id="how-it-works">
    <div class="wrap">
        <div class="eyebrow">How it works</div>
        <h2>Here&rsquo;s how it works.</h2>
        <div class="steps">
            <div class="step">
                <div class="step-num">01</div>
                <h3>Tell us about your goals.</h3>
                <p>You complete a short onboarding form: your goal race, your current training, your schedule. Then you have a 20-minute call with your coach to make sure we get it right.</p>
            </div>
            <div class="step">
                <div class="step-num">02</div>
                <h3>Your coach builds your plan.</h3>
                <p>Our engine generates a training plan built around your goals, your fitness, and your life. Your coach reviews every workout before you see a single one. Nothing goes to you without a human sign-off.</p>
            </div>
            <div class="step">
                <div class="step-num">03</div>
                <h3>Train. We watch.</h3>
                <p>Your plan lives on your phone and your watch. Every session is tracked. Every week your coach reviews your training: what you did, how it went, what it means. When something needs to change, it changes.</p>
            </div>
            <div class="step">
                <div class="step-num">04</div>
                <h3>Your coach checks in.</h3>
                <p>Every month you get a letter from your coach. Not a stats dashboard. A letter, specific to your training, your month, your progress. Written in plain English by someone who actually looked at your data and thought about you.</p>
            </div>
        </div>
    </div>
</section>

<!-- Track Record: the signature moment -->
<section class="record">
    <div class="wrap">
        <div class="eyebrow">The track record</div>
        <h2>The numbers are simple.</h2>
        <div class="stat-rows">
            <div class="stat-row">
                <div class="num">1</div>
                <div class="statement">athlete in 15+ years of coaching who didn&rsquo;t run a personal best</div>
            </div>
            <div class="stat-row">
                <div class="num">1</div>
                <div class="statement">major overuse injury across an entire coaching career, and it was the coach himself</div>
            </div>
            <div class="stat-row">
                <div class="num">15<sup>+</sup></div>
                <div class="statement">years coaching runners at high school, collegiate, and adult levels</div>
            </div>
        </div>
        <div class="body-copy">
            <p>We don&rsquo;t have a flashy percentage to sell you. We have a record. In fifteen years of coaching runners of all abilities, from high school athletes to adult marathoners, only one athlete failed to set a personal best. And in all that time, only one athlete sustained a major overuse injury across an entire career. That was the coach himself.</p>
            <p>We tell you this not to brag. We tell you this because <strong>you deserve to know who is looking at your training.</strong></p>
        </div>
    </div>
</section>

<!-- Your coach (bio + photo placeholder: primary trust signal, populated when provided) -->
<section>
    <div class="wrap">
        <div class="eyebrow">Your coach</div>
        <h2>A real person. A real record.</h2>
        <div class="coach-card">
            <div class="coach-photo" aria-hidden="true"><span>Photo</span></div>
            <div class="coach-copy">
                <span class="quote-tag">Placeholder</span>
                <p>[Coach bio: first-person, in brand voice. Provided by the coach before launch.]</p>
            </div>
        </div>
    </div>
</section>

<!-- Pricing -->
<section id="pricing" class="band-card">
    <div class="wrap">
        <div class="eyebrow">Pricing</div>
        <h2>Simple pricing. No surprises.</h2>
        <div class="price-cards">
            <div class="price-card featured">
                <div class="founding-badge">Founding member rate</div>
                <div class="price-tier">Standard</div>
                <div class="price-amount">$39<small>/month</small></div>
                <div class="price-note">Founding member rate: locked for life while your account is active</div>
                <ul class="price-list">
                    <li>A coach who reviews your plan before you see it</li>
                    <li>Training that adjusts to your actual life</li>
                    <li>Structured workouts on your watch (Garmin and Polar)</li>
                    <li>A monthly coaching letter from your coach</li>
                    <li>In-app messaging with your coach</li>
                    <li>All plan types: race cycles, development plans, maintenance, return from injury</li>
                </ul>
                <a class="btn btn-primary" href="/app/register">Get started &rarr;</a>
            </div>
            <div class="price-card">
                <div class="price-tier">Premium</div>
                <div class="price-amount">$79<small>/month</small></div>
                <div class="price-note"></div>
                <div class="price-plus">Everything in Standard, plus:</div>
                <ul class="price-list">
                    <li>Guaranteed 48-hour coach response time</li>
                    <li>Monthly 20-minute video check-in with your coach</li>
                </ul>
                <a class="btn btn-primary" href="/app/register">Get started &rarr;</a>
            </div>
        </div>
        <div class="fine-print">
            <span class="fine-print-label">Fine print (that isn&rsquo;t fine print):</span>
            No contracts. Cancel any time. Founding member pricing is locked as long as your account remains active; if you close your account and return, standard pricing applies. Watch integration (Garmin, Polar) is available but not required. The platform works fully without a smartwatch.
        </div>
    </div>
</section>

<!-- Who This Is For -->
<section>
    <div class="wrap">
        <div class="eyebrow">Who this is for</div>
        <h2>This isn&rsquo;t for everyone.<br>It might be for you.</h2>
        <div class="body-copy">
            <p>SimplyRunFaster is built for runners who are already serious about the sport and want to get meaningfully better. If you&rsquo;ve finished a race and immediately wondered how to go faster next time, you&rsquo;re our athlete.</p>
            <p>We&rsquo;re not the right fit if you&rsquo;re just getting started (there are great apps for that). We&rsquo;re not the right fit if you want an app that tells you you&rsquo;re doing great no matter what. We&rsquo;re the right fit if you want a coach who will tell you the truth, build you a real plan, and be genuinely invested in your result.</p>
        </div>
        <div style="margin-top:30px;">
            <a class="btn btn-primary" href="/app/register">Start with a free onboarding call &rarr;</a>
        </div>
    </div>
</section>

<!-- Testimonials (placeholder structure; populated from beta athlete results) -->
<section class="band-card">
    <div class="wrap">
        <div class="eyebrow">From our athletes</div>
        <h2>Results, in their words.</h2>
        <p class="placeholder-note">Testimonials are being collected from founding athletes and will appear here.</p>
        <div class="quotes">
            <div class="quote-card">
                <span class="quote-tag">Placeholder</span>
                <div class="quote-text">&ldquo;[Specific result: time, distance, PR margin]. [One sentence about the experience of being coached.]&rdquo;</div>
                <div class="quote-attr">[First name, last initial] &middot; [City] &middot; [Goal race]</div>
            </div>
            <div class="quote-card">
                <span class="quote-tag">Placeholder</span>
                <div class="quote-text">&ldquo;[Specific result: time, distance, PR margin]. [One sentence about the experience of being coached.]&rdquo;</div>
                <div class="quote-attr">[First name, last initial] &middot; [City] &middot; [Goal race]</div>
            </div>
            <div class="quote-card">
                <span class="quote-tag">Placeholder</span>
                <div class="quote-text">&ldquo;[Specific result: time, distance, PR margin]. [One sentence about the experience of being coached.]&rdquo;</div>
                <div class="quote-attr">[First name, last initial] &middot; [City] &middot; [Goal race]</div>
            </div>
        </div>
    </div>
</section>

<!-- Closing CTA -->
<section class="footer-cta">
    <div class="wrap">
        <h2>Ready to run faster?</h2>
        <div class="body-copy">
            <p>Founding member pricing, $39/month locked for life, is available now. Start with a free onboarding call. No commitment until you see your plan.</p>
        </div>
        <a class="btn btn-primary" href="/app/register">Get started &rarr;</a>
    </div>
</section>

<footer>
    <div class="footer-inner">
        <a class="wordmark" href="/" style="font-size:15px;">Simply<span>Run</span>Faster</a>
        <div class="footer-links">
            <a href="/app/login">Sign in</a>
            <a href="/app/privacy">Privacy Policy</a>
        </div>
    </div>
</footer>

</body>
</html>
