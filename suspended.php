<?php
require __DIR__ . '/app/bootstrap.php';

$user = current_user();

if (!$user) {
    redirect('/');
}

// If not suspended, route to their regular portal area
if (($user['status'] ?? '') !== 'Suspended') {
    redirect(strtolower($user['role'] ?? '') === 'admin' ? '/admin/' : '/client/');
}

// Passes 2 arguments to satisfy render_header($title, $section)
render_header('Account Suspended', 'client');
?>

<div class="auth-card" style="max-width: 520px; margin: 60px auto; text-align: center; padding: 40px 30px;">
    <div style="font-size: 54px; margin-bottom: 16px;">🚫</div>

    <div class="eyebrow" style="color: #dc2626; font-weight: 700; letter-spacing: 0.05em;">ACCESS RESTRICTED</div>
    <h1 style="margin: 8px 0 16px; font-size: 1.85rem;">Account Suspended</h1>

    <p style="color: var(--muted-color, #64748b); line-height: 1.6; margin-bottom: 24px;">
        Your account (<strong><?= e($user['email']) ?></strong>) has been temporarily suspended by an administrator. You cannot submit jobs or access portal services while suspended.
    </p>

    <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 14px 18px; margin-bottom: 28px; text-align: left; font-size: 0.9rem; color: #991b1b;">
        <strong>Need help?</strong> If you believe this is an error or would like to appeal, please contact the print room administrator or support team.
    </div>

    <div style="display: flex; gap: 12px; justify-content: center;">
        <a class="button button-soft" href="/logout.php">Sign out</a>
    </div>
</div>

<?php render_footer(); ?>
