<?php if ( is_user_logged_in() ) : ?>
    <div class="qrrs-login-card">
        <h3>You are already logged in.</h3>
        <a href="<?php echo home_url('/restaurant-dashboard/'); ?>" class="btn-login">Go to Dashboard</a>
    </div>
<?php else : ?>

<div class="qrrs-login-wrapper">
    
    <div class="qrrs-login-card">
        <div class="site-branding">
        <?php
            the_custom_logo();
        ?>
        </div>
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
    </style>