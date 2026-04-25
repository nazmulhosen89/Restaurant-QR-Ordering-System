<?php if ( is_user_logged_in() ) : ?>
    <div class="qrrs-login-card">
        <h3>You are already logged in.</h3>
        <a href="<?php echo home_url('/restaurant-dashboard/'); ?>" class="btn-login">Go to Dashboard</a>
    </div>
<?php else : ?>

<div class="qrrs-login-wrapper">
    <div class="qrrs-login-card">
        <h2>Restaurant Staff Login</h2>
        <p>Use your credentials to access the system.</p>

        <?php if ( isset($_GET['login']) && $_GET['login'] == 'failed' ) : ?>
            <div class="error-msg">Invalid username or password!</div>
        <?php endif; ?>

        <form method="POST" action="">
            <?php wp_nonce_field( 'qrrs_login_action', 'qrrs_login_nonce' ); ?>

            <div class="form-group">
                <label for="log">Username</label>
                <input type="text" name="log" id="log" required placeholder="Enter username">
            </div>

            <div class="form-group">
                <label for="pwd">Password</label>
                <input type="password" name="pwd" id="pwd" required placeholder="Enter password">
            </div>

            <div class="form-group">
                <label>
                    <input type="checkbox" name="rememberme" value="forever"> Remember Me
                </label>
            </div>

            <button type="submit" class="btn-login">Login to Dashboard</button>
        </form>
    </div>
</div>
<?php endif; ?>

<style>
    .qrrs-login-wrapper { display: flex; justify-content: center; align-items: center; padding: 40px 0; }
    .qrrs-login-card { background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); width: 100%; max-width: 400px; border: 1px solid #eee; }
    .qrrs-login-card h2 { margin-top: 0; font-size: 24px; color: #333; }
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; margin-bottom: 5px; font-weight: 600; color: #555; }
    .form-group input[type="text"], .form-group input[type="password"] { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; }
    .btn-login { width: 100%; padding: 14px; background: #000; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; font-weight: bold; transition: 0.3s; }
    .btn-login:hover { background: #333; }
    .error-msg { background: #ffe4e6; color: #e11d48; padding: 12px; border-radius: 6px; margin-bottom: 20px; text-align: center; font-size: 14px; border: 1px solid #fecdd3; }
</style>