<?php
/**
 * Static privacy policy — publicly accessible (no auth).
 *
 * Routed from index.php at GET /app/privacy. Uses the app layout shell
 * (html_open / html_close) but no authenticated nav. Policy text is embedded
 * directly below; update the "Last updated" line when the text changes.
 */
require_once __DIR__ . '/../../views/layout/base.php';

$pageTitle = 'Privacy Policy';
$extraCss  = <<<CSS
<style>
    .privacy-page {
        max-width: 720px;
        margin: 0 auto;
        padding: 48px 20px 96px;
        line-height: 1.65;
    }
    .privacy-page h1 {
        color: var(--accent-mid);
        font-size: 28px;
        font-weight: 700;
        letter-spacing: -0.02em;
        margin-bottom: 6px;
    }
    .privacy-page .privacy-effective {
        color: var(--text-muted);
        font-size: 13px;
        margin-bottom: 32px;
    }
    .privacy-page h2 {
        color: var(--accent-mid);
        font-size: 19px;
        font-weight: 600;
        margin: 32px 0 10px;
    }
    .privacy-page h3 {
        font-size: 15px;
        font-weight: 600;
        margin: 20px 0 6px;
    }
    .privacy-page p,
    .privacy-page li {
        font-size: 15px;
        color: var(--text-primary);
    }
    .privacy-page ul {
        margin: 8px 0 8px 22px;
    }
    .privacy-page li { margin-bottom: 6px; }
    .privacy-page a { color: var(--accent-mid); }
    .privacy-page strong { font-weight: 600; }
    .privacy-table {
        width: 100%;
        border-collapse: collapse;
        margin: 14px 0;
        font-size: 14px;
    }
    .privacy-table th,
    .privacy-table td {
        border: 1px solid var(--divider);
        padding: 8px 10px;
        text-align: left;
        vertical-align: top;
    }
    .privacy-table th {
        background: var(--recessed-bg);
        font-weight: 600;
    }
</style>
CSS;

include __DIR__ . '/../../views/layout/html_open.php';
?>
<main class="privacy-page">
    <h1>SimplyRunFaster Privacy Policy</h1>
    <p class="privacy-effective">Effective Date: June 16, 2026 &middot; Last Updated: June 16, 2026</p>

    <h2>1. Who We Are</h2>
    <p>
        SimplyRunFaster (&ldquo;we,&rdquo; &ldquo;us,&rdquo; or &ldquo;our&rdquo;) is a personalized
        running coaching platform operated as a sole proprietorship by Will Musto. Our platform is
        accessible at simplyrunfaster.com and provides customized training plans, coach messaging,
        and progress tracking services.
    </p>
    <p>For privacy-related inquiries, contact us at:</p>
    <p><a href="mailto:privacy@simplyrunfaster.com">privacy@simplyrunfaster.com</a></p>

    <h2>2. Scope of This Policy</h2>
    <p>
        This Privacy Policy applies to all users of SimplyRunFaster, including athletes and coaches,
        regardless of where they are located. We are committed to complying with applicable privacy
        laws including:
    </p>
    <ul>
        <li><strong>United States:</strong> California Consumer Privacy Act (CCPA) and applicable federal privacy law</li>
        <li><strong>European Union / EEA:</strong> General Data Protection Regulation (GDPR)</li>
        <li><strong>United Kingdom:</strong> UK GDPR</li>
        <li><strong>Canada:</strong> Personal Information Protection and Electronic Documents Act (PIPEDA)</li>
        <li><strong>Mexico:</strong> Ley Federal de Protecci&oacute;n de Datos Personales en Posesi&oacute;n de los Particulares (LFPDPPP)</li>
    </ul>

    <h2>3. Age Requirements and Parental Consent</h2>
    <p>
        SimplyRunFaster is available to users of all ages. Users under the age of 18 must have
        parental or guardian consent to use the platform. By creating an account, you confirm that
        you are 18 years of age or older, or that you have obtained verifiable parental or guardian
        consent.
    </p>
    <p>
        We do not knowingly collect personal data from children under 13 without verifiable parental
        consent. If you believe we have collected data from a child under 13 without consent, contact
        us immediately at <a href="mailto:privacy@simplyrunfaster.com">privacy@simplyrunfaster.com</a>
        and we will delete it promptly.
    </p>

    <h2>4. Data We Collect</h2>
    <h3>Account and profile data:</h3>
    <ul>
        <li>Name, email address, password (stored as a one-way hash &mdash; we cannot read your password)</li>
        <li>Phone number (optional, for SMS notifications if enabled)</li>
        <li>Timezone and theme preference</li>
    </ul>
    <h3>Training and health data:</h3>
    <ul>
        <li>Goal race date, goal race distance, goal finish time</li>
        <li>Current weekly training volume, training history, experience level</li>
        <li>Typical easy pace, availability and scheduling preferences</li>
        <li>Must-off days, injury history</li>
        <li>Pace zones and heart rate zones (derived or imported)</li>
        <li>Completed workout data including duration, distance, RPE, and session notes</li>
    </ul>
    <h3>Billing data:</h3>
    <ul>
        <li>Subscription status and billing interval</li>
        <li>We do not store payment card numbers or full payment details &mdash; these are handled exclusively by Stripe (see Section 7)</li>
    </ul>
    <h3>Communications:</h3>
    <ul>
        <li>Messages between athletes and coaches within the platform</li>
        <li>Notification preferences</li>
    </ul>
    <h3>Technical data:</h3>
    <ul>
        <li>IP address, browser type, device type</li>
        <li>Web Push notification subscription tokens</li>
        <li>Session cookies for authentication</li>
    </ul>

    <h2>5. How We Use Your Data</h2>
    <p>We use your data to:</p>
    <ul>
        <li>Create and manage your account</li>
        <li>Generate personalized training plans</li>
        <li>Facilitate communication between athletes and coaches</li>
        <li>Send notifications about your training plan, messages, and account</li>
        <li>Process subscription payments</li>
        <li>Sync workouts to and from connected third-party platforms (with your consent)</li>
        <li>Improve the platform based on aggregate, anonymized usage patterns</li>
        <li>Comply with legal obligations</li>
    </ul>
    <h3>Legal basis for processing (GDPR / UK GDPR):</h3>
    <ul>
        <li><strong>Contract performance:</strong> Processing necessary to deliver the coaching services you signed up for</li>
        <li><strong>Legitimate interests:</strong> Platform security, fraud prevention, and service improvement</li>
        <li><strong>Consent:</strong> Push notifications, optional integrations (e.g. Intervals.icu), and marketing communications</li>
        <li><strong>Legal obligation:</strong> Compliance with applicable law</li>
    </ul>
    <h3>Legal basis for processing (LFPDPPP &mdash; Mexico):</h3>
    <p>
        We process your data with your consent, as necessary for the performance of our contractual
        relationship, and as required by applicable law. This notice (aviso de privacidad)
        constitutes the required disclosure under Mexican law.
    </p>

    <h2>6. Data Sharing</h2>
    <p>We do not sell your personal data. We do not share your data with advertisers.</p>
    <p>
        We share data only with the following categories of service providers, strictly for the
        purpose of operating the platform:
    </p>
    <table class="privacy-table">
        <thead>
            <tr>
                <th>Provider</th>
                <th>Purpose</th>
                <th>Location</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>NearlyFreeSpeech.net</td>
                <td>Web hosting and database</td>
                <td>United States</td>
            </tr>
            <tr>
                <td>Stripe, LLC</td>
                <td>Payment processing</td>
                <td>United States / Ireland (EU users)</td>
            </tr>
            <tr>
                <td>Resend</td>
                <td>Transactional email delivery</td>
                <td>United States</td>
            </tr>
            <tr>
                <td>Intervals.icu</td>
                <td>Training plan sync and watch integration (optional, consent required)</td>
                <td>United States</td>
            </tr>
        </tbody>
    </table>
    <p>
        Each provider is bound by data processing terms consistent with applicable law. Stripe
        maintains a GDPR-compliant Data Processing Agreement automatically included in their terms of
        service. International data transfers from the EU/EEA to the United States are covered by
        Standard Contractual Clauses where required.
    </p>
    <p>
        We may disclose your data if required by law, court order, or to protect the rights and
        safety of our users.
    </p>

    <h2>7. Payment Data</h2>
    <p>
        All payment processing is handled by Stripe, LLC. When you enter payment information, it is
        transmitted directly to Stripe over an encrypted connection. SimplyRunFaster stores only your
        Stripe customer ID and subscription status &mdash; never your card number, CVV, or full
        billing details. Stripe&rsquo;s privacy policy is available at
        <a href="https://stripe.com/privacy" target="_blank" rel="noopener">stripe.com/privacy</a>.
    </p>

    <h2>8. Third-Party Integrations</h2>
    <h3>Intervals.icu (optional):</h3>
    <p>
        If you choose to connect your Intervals.icu account, we will access your Intervals.icu
        calendar to push structured workouts and receive completed activity data. This integration
        requires your explicit authorization via Intervals.icu&rsquo;s OAuth consent flow. You can
        revoke this access at any time from your Settings page or directly within Intervals.icu.
    </p>
    <p>
        We do not access any Intervals.icu data beyond what is necessary to push planned workouts and
        receive completed activity confirmations.
    </p>

    <h2>9. Data Retention</h2>
    <p>
        We retain your data for as long as your account is active. When your account is cancelled or
        subscription lapses:
    </p>
    <ul>
        <li>Your training data, messages, and profile are retained for 90 days to allow reactivation</li>
        <li>After 90 days, your personal data is permanently deleted from our systems</li>
        <li>Billing records are retained for 7 years as required for tax and accounting compliance &mdash; these records contain only transaction amounts and dates, not payment card details</li>
        <li>Anonymized, aggregate training data (with no personally identifying information) may be retained indefinitely for platform improvement purposes</li>
    </ul>
    <p>
        You may request deletion of your data at any time by emailing
        <a href="mailto:privacy@simplyrunfaster.com">privacy@simplyrunfaster.com</a>. We will process
        deletion requests within 30 days. Note that billing records required for legal compliance
        cannot be deleted within the legally mandated retention period.
    </p>

    <h2>10. Your Rights</h2>
    <p>
        Depending on your location, you may have the following rights regarding your personal data:
    </p>
    <h3>All users:</h3>
    <ul>
        <li>Access the personal data we hold about you</li>
        <li>Correct inaccurate data</li>
        <li>Request deletion of your data (subject to legal retention requirements)</li>
        <li>Withdraw consent for optional processing (e.g. push notifications, third-party integrations)</li>
    </ul>
    <h3>EU / UK users (GDPR / UK GDPR):</h3>
    <ul>
        <li>Data portability &mdash; receive your data in a machine-readable format</li>
        <li>Object to processing based on legitimate interests</li>
        <li>Restrict processing in certain circumstances</li>
        <li>Lodge a complaint with your local supervisory authority</li>
    </ul>
    <h3>California users (CCPA):</h3>
    <ul>
        <li>Know what personal data we collect and how it is used</li>
        <li>Delete your personal data</li>
        <li>Opt out of the sale of personal data (we do not sell personal data)</li>
        <li>Non-discrimination for exercising your privacy rights</li>
    </ul>
    <h3>Canadian users (PIPEDA):</h3>
    <ul>
        <li>Access your personal data and challenge its accuracy</li>
        <li>Withdraw consent (subject to legal or contractual restrictions)</li>
        <li>File a complaint with the Office of the Privacy Commissioner of Canada</li>
    </ul>
    <h3>Mexican users (LFPDPPP):</h3>
    <ul>
        <li>Access, rectify, cancel, or oppose (derechos ARCO) the processing of your personal data by contacting us at <a href="mailto:privacy@simplyrunfaster.com">privacy@simplyrunfaster.com</a></li>
        <li>Revoke your consent at any time</li>
        <li>Limit the use or disclosure of your data</li>
    </ul>
    <p>
        To exercise any of these rights, contact us at
        <a href="mailto:privacy@simplyrunfaster.com">privacy@simplyrunfaster.com</a>. We will respond
        within 30 days (or within the timeframe required by applicable law).
    </p>

    <h2>11. Cookies and Tracking</h2>
    <p>
        SimplyRunFaster uses session cookies solely for authentication purposes &mdash; to keep you
        logged in during your session. We do not use tracking cookies, advertising cookies, or
        analytics cookies. We do not use Google Analytics or any third-party analytics service.
    </p>
    <p>
        Web Push notification tokens are stored to deliver notifications you have opted into. You can
        revoke push notification permission at any time through your browser or device settings.
    </p>

    <h2>12. Security</h2>
    <p>
        We implement appropriate technical and organizational measures to protect your personal data,
        including:
    </p>
    <ul>
        <li>HTTPS encryption for all data in transit</li>
        <li>Passwords stored as one-way hashes (bcrypt)</li>
        <li>Payment data handled exclusively by PCI-compliant Stripe</li>
        <li>Access to production data restricted to authorized personnel only</li>
    </ul>
    <p>
        No method of transmission or storage is 100% secure. If we become aware of a data breach
        affecting your personal data, we will notify you as required by applicable law.
    </p>

    <h2>13. Changes to This Policy</h2>
    <p>
        We may update this Privacy Policy from time to time. We will notify you of material changes by
        email or via an in-app notification. The &ldquo;Last Updated&rdquo; date at the top of this
        policy reflects the most recent revision. Continued use of SimplyRunFaster after changes are
        posted constitutes acceptance of the updated policy.
    </p>

    <h2>14. Contact Us</h2>
    <p>For any privacy-related questions, requests, or complaints:</p>
    <p>
        Email: <a href="mailto:privacy@simplyrunfaster.com">privacy@simplyrunfaster.com</a><br>
        Operator: Will Musto, SimplyRunFaster<br>
        Website: simplyrunfaster.com
    </p>
    <p>
        EU/UK users may also lodge complaints with their local data protection authority. A list of EU
        supervisory authorities is available at
        <a href="https://edpb.europa.eu" target="_blank" rel="noopener">edpb.europa.eu</a>.
    </p>
    <p>
        Mexican users may contact the Instituto Nacional de Transparencia, Acceso a la
        Informaci&oacute;n y Protecci&oacute;n de Datos Personales (INAI) at
        <a href="https://inai.org.mx" target="_blank" rel="noopener">inai.org.mx</a>.
    </p>
    <p>
        Canadian users may contact the Office of the Privacy Commissioner of Canada at
        <a href="https://priv.gc.ca" target="_blank" rel="noopener">priv.gc.ca</a>.
    </p>
</main>
<?php include __DIR__ . '/../../views/layout/html_close.php'; ?>
