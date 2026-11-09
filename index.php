<?php
require __DIR__.'/app/bootstrap.php';
refresh_user();
if ($u=current_user()) redirect(strtolower($u['role'])==='admin'?'/admin/':'/client/');
render_header('Welcome','client');
?><div class="auth-shell"><div class="auth-art"><div class="art-orbit orbit-one"></div><div class="art-orbit orbit-two"></div><div class="art-document">PDF<span>READY TO PRINT</span></div><div class="art-caption"><span>DOCUMENTS,</span><br>IN MOTION.</div></div><div class="auth-panel"><div class="eyebrow">WELCOME BACK</div><h1>Sign in to<br>Print Portal</h1><p class="muted">Submit and track your print jobs in one place.</p><form method="post" action="/login.php" class="form-stack"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><label>Email address<input type="email" name="email" autocomplete="username" required placeholder="you@company.com"></label><label>Password<input type="password" name="password" autocomplete="current-password" required placeholder="Enter your password"></label><button class="button button-primary button-wide" type="submit">Sign in <span>→</span></button></form><p class="hint">Need an account? Contact your administrator.</p></div></div><?php render_footer();
