<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>SimplyRunFaster | Real coaching. Real results.</title>
    <meta name="description" content="SimplyRunFaster pairs every athlete with a real coach, not an algorithm, who reviews your plan, watches your training, and thinks about you specifically. Starting at $39/month.">
    <meta property="og:title" content="SimplyRunFaster | Real coaching. Real results.">
    <meta property="og:description" content="A real coach reviews your plan, watches your training, and thinks about you specifically. Starting at $39/month.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://simplyrunfaster.com/">
    <meta name="theme-color" content="#1D9E75">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" href="/assets/icons/icon-192.png">
    <style>
        /* Marketing page: self-contained, light palette from the app design system. */
        :root {
            --accent-fill:   #E1F5EE;
            --accent-mid:    #1D9E75;
            --accent-strong: #0F6E56;
            --accent-dark:   #085041;
            --page-bg:       #F5F3EF;
            --card-bg:       #FFFFFF;
            --recessed-bg:   #F0EDE7;
            --border-color:  rgba(0, 0, 0, 0.08);
            --border-strong: rgba(0, 0, 0, 0.15);
            --text-primary:  #1A1A1A;
            --text-secondary:#6B6B6B;
            --text-muted:    #9A9A9A;
            --radius-card:   12px;
            --radius-sm:     8px;
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

        .wrap        { max-width: 1000px; margin: 0 auto; padding: 0 24px; }
        .wrap-narrow { max-width: 680px;  margin: 0 auto; padding: 0 24px; }

        /* ── Top nav ── */
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
        .wordmark { font-size: 19px; font-weight: 700; letter-spacing: -0.02em; color: var(--text-primary); text-decoration: none; }
        .wordmark span { color: var(--accent-mid); }
        .nav-links { display: flex; align-items: center; gap: 18px; }
        .nav-signin { font-size: 14px; font-weight: 500; color: var(--text-secondary); text-decoration: none; }
        .nav-signin:hover { color: var(--text-primary); }

        /* ── Buttons ── */
        .btn {
            display: inline-block; text-decoration: none; font-weight: 600;
            border-radius: var(--radius-sm); text-align: center;
            transition: background .15s;
        }
        .btn-primary { background: var(--accent-mid); color: #fff; padding: 13px 28px; font-size: 15px; }
        .btn-primary:hover { background: var(--accent-strong); }
        .btn-sm { padding: 9px 18px; font-size: 14px; }
        .btn-ghost {
            color: var(--accent-strong); padding: 13px 20px; font-size: 15px; font-weight: 500;
        }
        .btn-ghost:hover { color: var(--accent-dark); }

        /* ── Section scaffolding ── */
        section { padding: 72px 0; }
        .section-label {
            font-size: 12px; font-weight: 600; letter-spacing: 0.08em;
            text-transform: uppercase; color: var(--accent-strong); margin-bottom: 14px;
        }
        h2 { font-size: clamp(26px, 4.5vw, 34px); font-weight: 700; letter-spacing: -0.02em; line-height: 1.2; margin-bottom: 18px; }
        .body-copy { font-size: 17px; color: var(--text-secondary); }
        .body-copy p + p { margin-top: 16px; }
        .body-copy strong { color: var(--text-primary); }

        /* ── Hero ── */
        .hero { padding: 88px 0 80px; text-align: center; }
        .hero h1 {
            font-size: clamp(38px, 7vw, 60px); font-weight: 700;
            letter-spacing: -0.03em; line-height: 1.08; margin-bottom: 22px;
        }
        .hero-sub {
            font-size: clamp(17px, 2.5vw, 20px); color: var(--text-secondary);
            max-width: 620px; margin: 0 auto 34px;
        }
        .hero-ctas { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; align-items: center; }

        /* ── Problem ── */
        .problem { background: var(--card-bg); border-top: 0.5px solid var(--border-color); border-bottom: 0.5px solid var(--border-color); }

        /* ── How it works ── */
        .steps { display: grid; grid-template-columns: 1fr; gap: 16px; margin-top: 34px; }
        @media (min-width: 720px) { .steps { grid-template-columns: 1fr 1fr; } }
        .step {
            background: var(--card-bg); border: 0.5px solid var(--border-color);
            border-radius: var(--radius-card); padding: 26px;
        }
        .step-num {
            display: inline-flex; align-items: center; justify-content: center;
            width: 34px; height: 34px; border-radius: 50%;
            background: var(--accent-fill); color: var(--accent-dark);
            font-weight: 700; font-size: 15px; margin-bottom: 14px;
        }
        .step h3 { font-size: 17px; font-weight: 600; margin-bottom: 8px; }
        .step p { font-size: 15px; color: var(--text-secondary); }

        /* ── Track record ── */
        .record { background: var(--accent-dark); color: #fff; }
        .record .section-label { color: #5DCAA5; }
        .record h2 { color: #fff; }
        .stats { display: grid; grid-template-columns: 1fr; gap: 28px; margin: 40px 0; }
        @media (min-width: 720px) { .stats { grid-template-columns: repeat(3, 1fr); } }
        .stat-num { font-size: clamp(52px, 8vw, 72px); font-weight: 700; line-height: 1; color: #5DCAA5; letter-spacing: -0.02em; }
        .stat-label { font-size: 15px; margin-top: 10px; color: rgba(255, 255, 255, 0.85); max-width: 300px; }
        .record .body-copy { color: rgba(255, 255, 255, 0.85); }
        .record .body-copy strong { color: #fff; }

        /* ── Pricing ── */
        .price-cards { display: grid; grid-template-columns: 1fr; gap: 16px; margin-top: 34px; }
        @media (min-width: 720px) { .price-cards { grid-template-columns: 1fr 1fr; } }
        .price-card {
            background: var(--card-bg); border: 0.5px solid var(--border-color);
            border-radius: var(--radius-card); padding: 30px; display: flex; flex-direction: column;
        }
        .price-card.featured { border: 1.5px solid var(--accent-mid); position: relative; }
        .founding-badge {
            position: absolute; top: -12px; left: 24px;
            background: var(--accent-mid); color: #fff;
            font-size: 11px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase;
            padding: 4px 10px; border-radius: 999px;
        }
        .price-tier { font-size: 15px; font-weight: 600; color: var(--text-secondary); }
        .price-amount { font-size: 42px; font-weight: 700; letter-spacing: -0.02em; margin: 6px 0 2px; }
        .price-amount small { font-size: 16px; font-weight: 500; color: var(--text-muted); letter-spacing: 0; }
        .price-note { font-size: 13px; color: var(--accent-strong); font-style: italic; margin-bottom: 18px; }
        .price-list { list-style: none; margin: 0 0 24px; flex: 1; }
        .price-list li {
            font-size: 15px; color: var(--text-secondary); padding: 7px 0 7px 26px; position: relative;
        }
        .price-list li::before {
            content: ""; position: absolute; left: 2px; top: 14px;
            width: 14px; height: 8px;
            border-left: 2.5px solid var(--accent-mid); border-bottom: 2.5px solid var(--accent-mid);
            transform: rotate(-45deg);
        }
        .price-plus { font-size: 14px; font-weight: 600; color: var(--text-primary); margin-bottom: 4px; }
        .fine-print {
            margin-top: 26px; background: var(--recessed-bg); border-radius: var(--radius-sm);
            padding: 18px 20px; font-size: 14px; color: var(--text-secondary);
        }
        .fine-print-label { font-weight: 600; color: var(--text-primary); display: block; margin-bottom: 4px; font-size: 13px; }

        /* ── Coach bio (placeholder until provided) ── */
        .coach-card {
            display: flex; gap: 20px; align-items: flex-start;
            background: var(--card-bg); border: 1px dashed var(--border-strong);
            border-radius: var(--radius-card); padding: 24px; margin-top: 8px;
        }
        .coach-photo {
            flex-shrink: 0; width: 96px; height: 96px; border-radius: 50%;
            background: var(--recessed-bg); border: 1px dashed var(--border-strong);
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; color: var(--text-muted);
        }
        .coach-copy p { font-size: 15px; color: var(--text-secondary); font-style: italic; margin-top: 8px; }
        @media (max-width: 480px) { .coach-card { flex-direction: column; align-items: center; text-align: center; } }

        /* ── Who for ── */
        .whofor { background: var(--card-bg); border-top: 0.5px solid var(--border-color); border-bottom: 0.5px solid var(--border-color); }
        .whofor-cta { margin-top: 28px; }

        /* ── Testimonials ── */
        .placeholder-note {
            font-size: 13px; color: var(--text-muted); font-style: italic; margin-top: 12px;
        }
        .quotes { display: grid; grid-template-columns: 1fr; gap: 16px; margin-top: 30px; }
        @media (min-width: 720px) { .quotes { grid-template-columns: repeat(3, 1fr); } }
        .quote-card {
            background: var(--card-bg); border: 1px dashed var(--border-strong);
            border-radius: var(--radius-card); padding: 24px;
        }
        .quote-text { font-size: 15px; color: var(--text-secondary); font-style: italic; }
        .quote-attr { margin-top: 14px; font-size: 13px; color: var(--text-muted); }
        .quote-tag {
            display: inline-block; margin-bottom: 12px; font-size: 10px; font-weight: 600;
            letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-muted);
            border: 0.5px solid var(--border-strong); border-radius: 999px; padding: 2px 8px;
        }

        /* ── Footer CTA ── */
        .footer-cta { text-align: center; }
        .footer-cta .body-copy { max-width: 520px; margin: 0 auto 30px; }

        /* ── Footer ── */
        footer {
            border-top: 0.5px solid var(--border-color);
            padding: 28px 0 40px; font-size: 13px; color: var(--text-muted);
        }
        .footer-inner {
            max-width: 1000px; margin: 0 auto; padding: 0 24px;
            display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
        }
        .footer-links { display: flex; gap: 18px; }
        .footer-links a { color: var(--text-muted); text-decoration: none; }
        .footer-links a:hover { color: var(--text-secondary); text-decoration: underline; }

        @media (max-width: 480px) {
            section { padding: 56px 0; }
            .hero { padding: 64px 0 56px; }
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
        <h1>Real coaching.<br>Real results.</h1>
        <p class="hero-sub">SimplyRunFaster pairs every athlete with a real coach, not an algorithm. Your coach reviews your plan, watches your training, and thinks about you specifically. Starting at $39/month.</p>
        <div class="hero-ctas">
            <a class="btn btn-primary" href="/app/register">Get started &rarr;</a>
            <a class="btn btn-ghost" href="#how-it-works">How it works &darr;</a>
        </div>
    </div>
</header>

<!-- The Problem -->
<section class="problem">
    <div class="wrap-narrow">
        <div class="section-label">The problem</div>
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
        <div class="section-label">How it works</div>
        <h2>Here&rsquo;s how it works.</h2>
        <div class="steps">
            <div class="step">
                <div class="step-num">1</div>
                <h3>Tell us about your goals.</h3>
                <p>You complete a short onboarding form: your goal race, your current training, your schedule. Then you have a 20-minute call with your coach to make sure we get it right.</p>
            </div>
            <div class="step">
                <div class="step-num">2</div>
                <h3>Your coach builds your plan.</h3>
                <p>Our engine generates a training plan built around your goals, your fitness, and your life. Your coach reviews every workout before you see a single one. Nothing goes to you without a human sign-off.</p>
            </div>
            <div class="step">
                <div class="step-num">3</div>
                <h3>Train. We watch.</h3>
                <p>Your plan lives on your phone and your watch. Every session is tracked. Every week your coach reviews your training: what you did, how it went, what it means. When something needs to change, it changes.</p>
            </div>
            <div class="step">
                <div class="step-num">4</div>
                <h3>Your coach checks in.</h3>
                <p>Every month you get a letter from your coach. Not a stats dashboard. A letter, specific to your training, your month, your progress. Written in plain English by someone who actually looked at your data and thought about you.</p>
            </div>
        </div>
    </div>
</section>

<!-- Track Record -->
<section class="record">
    <div class="wrap">
        <div class="section-label">The track record</div>
        <h2>The numbers are simple.</h2>
        <div class="stats">
            <div class="stat">
                <div class="stat-num">1</div>
                <div class="stat-label">athlete in 15+ years of coaching who didn&rsquo;t run a personal best</div>
            </div>
            <div class="stat">
                <div class="stat-num">1</div>
                <div class="stat-label">major overuse injury across an entire coaching career, and it was the coach himself</div>
            </div>
            <div class="stat">
                <div class="stat-num">15+</div>
                <div class="stat-label">years coaching runners at high school, collegiate, and adult levels</div>
            </div>
        </div>
        <div class="body-copy wrap-narrow" style="padding:0;">
            <p>We don&rsquo;t have a flashy percentage to sell you. We have a record. In fifteen years of coaching runners of all abilities, from high school athletes to adult marathoners, only one athlete failed to set a personal best. And in all that time, only one athlete sustained a major overuse injury across an entire career. That was the coach himself.</p>
            <p>We tell you this not to brag. We tell you this because you deserve to know who is looking at your training.</p>
        </div>
    </div>
</section>

<!-- Your coach (bio + photo placeholder: the primary trust signal, positioning doc
     section 12 item 1. Populated when Will provides the first-person bio and photo;
     clearly marked as a placeholder until then, never a fabricated bio.) -->
<section class="coach-bio">
    <div class="wrap-narrow">
        <div class="section-label">Your coach</div>
        <h2>A real person. A real record.</h2>
        <div class="coach-card">
            <div class="coach-photo" aria-hidden="true">
                <span>Photo</span>
            </div>
            <div class="coach-copy">
                <span class="quote-tag">Placeholder</span>
                <p>[Coach bio: first-person, in brand voice. Provided by the coach before launch.]</p>
            </div>
        </div>
    </div>
</section>

<!-- Pricing -->
<section id="pricing">
    <div class="wrap">
        <div class="section-label">Pricing</div>
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
                <div class="price-note" style="visibility:hidden;">&nbsp;</div>
                <div class="price-plus">Everything in Standard, plus:</div>
                <ul class="price-list">
                    <li>Guaranteed 48-hour coach response time</li>
                    <li>Monthly 20-minute video check-in with your coach</li>
                </ul>
                <a class="btn btn-primary" href="/app/register" style="background:var(--accent-strong);">Get started &rarr;</a>
            </div>
        </div>
        <div class="fine-print">
            <span class="fine-print-label">Fine print (that isn&rsquo;t fine print):</span>
            No contracts. Cancel any time. Founding member pricing is locked as long as your account remains active; if you close your account and return, standard pricing applies. Watch integration (Garmin, Polar) is available but not required. The platform works fully without a smartwatch.
        </div>
    </div>
</section>

<!-- Who This Is For -->
<section class="whofor">
    <div class="wrap-narrow">
        <div class="section-label">Who this is for</div>
        <h2>This isn&rsquo;t for everyone. It might be for you.</h2>
        <div class="body-copy">
            <p>SimplyRunFaster is built for runners who are already serious about the sport and want to get meaningfully better. If you&rsquo;ve finished a race and immediately wondered how to go faster next time, you&rsquo;re our athlete.</p>
            <p>We&rsquo;re not the right fit if you&rsquo;re just getting started (there are great apps for that). We&rsquo;re not the right fit if you want an app that tells you you&rsquo;re doing great no matter what. We&rsquo;re the right fit if you want a coach who will tell you the truth, build you a real plan, and be genuinely invested in your result.</p>
        </div>
        <div class="whofor-cta">
            <a class="btn btn-primary" href="/app/register">Start with a free onboarding call &rarr;</a>
        </div>
    </div>
</section>

<!-- Testimonials (placeholder structure; populated from beta athlete results) -->
<section>
    <div class="wrap">
        <div class="section-label">From our athletes</div>
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

<!-- Footer CTA -->
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
