<?php
/**
 * Static privacy policy — publicly accessible (no auth).
 *
 * Routed from index.php at GET /app/privacy. Uses the app layout shell
 * (html_open / html_close) but no authenticated nav. Policy text is embedded
 * directly below; update the "Last updated" line and the effective date
 * together whenever the text changes.
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
        color: #1D9E75;
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
        color: #1D9E75;
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
    .privacy-page a { color: #1D9E75; }
    .privacy-page strong { font-weight: 600; }
    .privacy-table {
        width: 100%;
        border-collapse: collapse;
        margin: 14px 0;
        font-size: 14px;
    }
    .privacy-table th,
    .privacy-table td {
        border: 1px solid var(--divider, #E2DED7);
        padding: 8px 10px;
        text-align: left;
        vertical-align: top;
    }
    .privacy-table th {
        background: var(--recessed-bg, #F5F3EF);
        font-weight: 600;
    }
</style>
CSS;

include __DIR__ . '/../../views/layout/html_open.php';
?>
<main class="privacy-page">
    <h1>Privacy Policy</h1>
    <p class="privacy-effective">Effective date: June 16, 2026 &middot; Last updated: June 16, 2026</p>

    <p>
        This Privacy Policy explains how SimplyRunFaster collects, uses, shares, and protects
        your personal information, and the rights you have over that information. We have written
        it to comply with the privacy laws that apply to our users, including the California
        Consumer Privacy Act (CCPA/CPRA) in the United States, the General Data Protection
        Regulation (GDPR) in the European Union, the UK GDPR and Data Protection Act in the United
        Kingdom, the Personal Information Protection and Electronic Documents Act (PIPEDA) in
        Canada, and the Federal Law on Protection of Personal Data Held by Private Parties
        (LFPDPPP) in Mexico.
    </p>

    <h2>1. Who We Are</h2>
    <p>
        SimplyRunFaster is a running-coaching service operated by <strong>Will Musto</strong>, as a
        sole proprietorship. For the purposes of GDPR and UK GDPR, Will Musto is the
        &ldquo;data controller&rdquo; responsible for your personal information. For the purposes
        of the LFPDPPP, Will Musto is the &ldquo;responsable&rdquo; (data controller).
    </p>
    <p>
        If you have any questions about this policy or your personal information, you can reach us
        at <a href="mailto:privacy@simplyrunfaster.com">privacy@simplyrunfaster.com</a>.
    </p>

    <h2>2. Scope</h2>
    <p>
        This policy applies to personal information we collect through the SimplyRunFaster web
        application and progressive web app at simplyrunfaster.com (the &ldquo;Service&rdquo;), and
        through any related communications such as email. It does not apply to third-party
        services we link to or integrate with, each of which has its own privacy policy.
    </p>

    <h2>3. Age Requirements</h2>
    <p>
        You must be at least <strong>18 years old</strong> to create an account on your own behalf.
        A person under 18 may use the Service only with the verifiable consent of a parent or legal
        guardian, who accepts this policy on the minor&rsquo;s behalf and is responsible for the
        minor&rsquo;s use of the Service.
    </p>
    <p>
        We do not knowingly collect personal information from children under 13 without verifiable
        parental consent. If you believe a child under 13 has provided us personal information
        without such consent, please contact us at
        <a href="mailto:privacy@simplyrunfaster.com">privacy@simplyrunfaster.com</a> and we will
        delete it.
    </p>

    <h2>4. Data We Collect</h2>
    <p>We collect the following categories of personal information:</p>
    <ul>
        <li>
            <strong>Account information</strong> — your name, email address, password (stored only
            as a one-way hash), time zone, display preferences, and, if you choose to provide it,
            your phone number.
        </li>
        <li>
            <strong>Training and health information</strong> — information you provide during
            onboarding and ongoing use, such as your running history, goals, current fitness,
            injury history, training availability, completed workouts, and any notes or messages
            you exchange with your coach. Some of this may be considered health-related data.
        </li>
        <li>
            <strong>Billing information</strong> — your subscription status and billing history. We
            do <strong>not</strong> collect or store your full payment card details; these are
            handled entirely by Stripe (see Section 7).
        </li>
        <li>
            <strong>Communications</strong> — messages you send to your coach or to us, and your
            email and notification preferences.
        </li>
        <li>
            <strong>Technical information</strong> — limited technical data necessary to operate the
            Service, such as your browser/user-agent string for active push-notification devices
            and basic server logs. We do <strong>not</strong> use third-party advertising or
            analytics trackers.
        </li>
    </ul>

    <h2>5. How We Use Your Data</h2>
    <p>We use your personal information to:</p>
    <ul>
        <li>provide the coaching Service, including building and adjusting your training plan;</li>
        <li>enable communication between you and your coach;</li>
        <li>manage your account, subscription, and billing;</li>
        <li>send you service-related notifications you have opted into;</li>
        <li>secure the Service and prevent abuse; and</li>
        <li>comply with our legal obligations.</li>
    </ul>
    <p>
        <strong>Legal bases (GDPR / UK GDPR).</strong> Where GDPR or UK GDPR applies, we rely on the
        following legal bases: <strong>performance of a contract</strong> (to provide the Service you
        sign up for); <strong>consent</strong> (for example, for optional push notifications and for
        processing health-related training data, which you may withdraw at any time);
        <strong>legitimate interests</strong> (to secure and improve the Service, where not
        overridden by your rights); and <strong>legal obligation</strong> (for example, retaining
        billing records).
    </p>
    <p>
        <strong>Legal basis (LFPDPPP, Mexico).</strong> Where the LFPDPPP applies, we process your
        personal data to fulfil the service relationship you enter into with us, and, for sensitive
        data such as health-related training information, on the basis of your express consent,
        which you provide during onboarding and may revoke at any time.
    </p>

    <h2>6. Data Sharing</h2>
    <p>
        We do not sell your personal information. We share it only with the service providers
        (&ldquo;processors&rdquo;) we rely on to operate the Service, and only to the extent needed
        for them to perform their function:
    </p>
    <table class="privacy-table">
        <thead>
            <tr>
                <th>Provider</th>
                <th>Purpose</th>
                <th>Data shared</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>NearlyFreeSpeech.NET</td>
                <td>Web and database hosting</td>
                <td>All data stored by the Service (hosted on their infrastructure)</td>
            </tr>
            <tr>
                <td>Stripe</td>
                <td>Subscription payments and billing</td>
                <td>Name, email, and payment details you enter at checkout</td>
            </tr>
            <tr>
                <td>Resend</td>
                <td>Transactional email delivery</td>
                <td>Email address and the contents of service emails</td>
            </tr>
            <tr>
                <td>Intervals.icu</td>
                <td>Optional training-data integration</td>
                <td>Training/activity data, only if you connect the integration</td>
            </tr>
        </tbody>
    </table>
    <p>
        We may also disclose personal information where required by law, to enforce our terms, or to
        protect the rights, safety, and security of our users or the Service.
    </p>

    <h2>7. Payment Data</h2>
    <p>
        All payments are processed by <strong>Stripe</strong>. When you subscribe, your payment card
        details are entered directly into Stripe&rsquo;s secure systems and are never transmitted to
        or stored on our servers. We store only a Stripe customer reference and your subscription
        status. Stripe&rsquo;s handling of your payment data is governed by
        <a href="https://stripe.com/privacy" target="_blank" rel="noopener">Stripe&rsquo;s Privacy Policy</a>.
    </p>

    <h2>8. Third-Party Integrations</h2>
    <p>
        SimplyRunFaster offers an <strong>optional</strong> integration with
        <strong>Intervals.icu</strong>. If you choose to connect it, you authorize the connection
        through Intervals.icu&rsquo;s OAuth flow, and we exchange training and activity data with
        your Intervals.icu account to inform your coaching. This integration is entirely optional,
        you control whether to enable it, and you can disconnect it at any time. Data handled within
        Intervals.icu is subject to its own privacy policy.
    </p>

    <h2>9. Data Retention</h2>
    <p>We keep your personal information only as long as we need it:</p>
    <ul>
        <li>
            <strong>Active accounts</strong> — we retain your information for as long as your account
            is active.
        </li>
        <li>
            <strong>After cancellation</strong> — if you cancel your subscription, we retain your
            account data for <strong>90 days</strong>, after which it is automatically anonymized or
            deleted. This window lets you reactivate without losing your training history.
        </li>
        <li>
            <strong>Billing records</strong> — we retain billing and transaction records for up to
            <strong>7 years</strong> as required for tax and accounting purposes, even after an
            account is otherwise deleted.
        </li>
        <li>
            <strong>On request</strong> — if you ask us to delete your account, we will do so within
            <strong>30 days</strong>, subject to the billing-record retention noted above.
        </li>
    </ul>

    <h2>10. Your Rights</h2>
    <h3>All users</h3>
    <p>
        Regardless of where you live, you can access, correct, or delete your personal information,
        and you can contact us at
        <a href="mailto:privacy@simplyrunfaster.com">privacy@simplyrunfaster.com</a> with any privacy
        request. We will not discriminate against you for exercising your rights.
    </p>
    <h3>European Union and United Kingdom (GDPR / UK GDPR)</h3>
    <p>
        If you are in the EU or UK, you have the right to access, rectify, erase, restrict, or object
        to the processing of your personal data; the right to data portability; and the right to
        withdraw consent at any time where processing is based on consent. You also have the right to
        lodge a complaint with your local supervisory authority or, at EU level, the European Data
        Protection Board (EDPB).
    </p>
    <h3>California (CCPA/CPRA)</h3>
    <p>
        If you are a California resident, you have the right to know what personal information we
        collect and how we use it, the right to access and delete it, the right to correct
        inaccurate information, and the right to opt out of the &ldquo;sale&rdquo; or
        &ldquo;sharing&rdquo; of personal information. <strong>We do not sell or share your personal
        information.</strong>
    </p>
    <h3>Canada (PIPEDA)</h3>
    <p>
        If you are in Canada, you have the right to access your personal information and to challenge
        its accuracy, and to withdraw consent subject to legal and contractual limits. You may also
        file a complaint with the Office of the Privacy Commissioner of Canada (OPC).
    </p>
    <h3>Mexico (LFPDPPP)</h3>
    <p>
        If you are in Mexico, you have your &ldquo;ARCO&rdquo; rights — Access, Rectification,
        Cancellation, and Opposition — as well as the right to revoke your consent and to limit the
        use or disclosure of your data. You may also file a complaint with the Instituto Nacional de
        Transparencia, Acceso a la Informaci&oacute;n y Protecci&oacute;n de Datos Personales (INAI).
    </p>

    <h2>11. Cookies</h2>
    <p>
        SimplyRunFaster uses a single <strong>session cookie</strong> that is strictly necessary to
        keep you logged in. We do <strong>not</strong> use advertising cookies, third-party
        analytics, or cross-site tracking of any kind.
    </p>

    <h2>12. Security</h2>
    <p>
        We protect your information using industry-standard measures, including encryption in transit
        (HTTPS), one-way hashing of passwords, and access controls that limit who can view your data.
        No method of transmission or storage is perfectly secure, but we work to protect your
        information and to address vulnerabilities promptly.
    </p>

    <h2>13. Changes to This Policy</h2>
    <p>
        We may update this policy from time to time. When we make material changes, we will update
        the &ldquo;Effective date&rdquo; above and, where appropriate, notify you through the Service
        or by email. Your continued use of the Service after an update means you accept the revised
        policy.
    </p>

    <h2>14. Contact Us</h2>
    <p>
        For any privacy question or to exercise your rights, contact us at
        <a href="mailto:privacy@simplyrunfaster.com">privacy@simplyrunfaster.com</a>.
    </p>
    <p>You also have the right to contact your local data-protection authority, including:</p>
    <ul>
        <li><strong>Mexico</strong> — Instituto Nacional de Transparencia, Acceso a la Informaci&oacute;n y Protecci&oacute;n de Datos Personales (INAI).</li>
        <li><strong>Canada</strong> — Office of the Privacy Commissioner of Canada (OPC).</li>
        <li><strong>European Union</strong> — your national supervisory authority or the European Data Protection Board (EDPB).</li>
        <li><strong>United Kingdom</strong> — the Information Commissioner&rsquo;s Office (ICO).</li>
    </ul>
</main>
<?php include __DIR__ . '/../../views/layout/html_close.php'; ?>
