<?php
/**
 * Template Name: JDD Fullscreen App
 * Description: A blank canvas template for the JetPack Drive Downloader app.
 */

// Disable admin bar for this view to ensure true fullscreen
add_filter('show_admin_bar', '__return_false');
?>
<?php
// Check Access
$access_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['jdd_email'])) {
    $email = sanitize_email($_POST['jdd_email']);
    if (JPSM_Access_Manager::set_access_cookie($email)) {
        // Reload to confirm cookie
        echo "<script>window.location.reload();</script>";
        exit;
    } else {
        $access_error = '❌ Email no encontrado o sin acceso.';
    }
}

$has_access = JPSM_Access_Manager::check_current_session();
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>JetPack Downloader</title>
    <?php wp_head(); ?>
    <style>
        html,
        body {
            margin: 0;
            padding: 0;
            height: 100%;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #18181b;
            color: white;
        }

        .jdd-login-container {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 100%;
            text-align: center;
            pading: 20px;
        }

        .jdd-login-box {
            background: #27272a;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
            width: 100%;
            max-width: 400px;
        }

        .jdd-input {
            width: 100%;
            padding: 12px;
            margin: 15px 0;
            border-radius: 8px;
            border: 1px solid #3f3f46;
            background: #18181b;
            color: white;
            font-size: 16px;
            box-sizing: border-box;
        }

        .jdd-btn {
            width: 100%;
            padding: 12px;
            border-radius: 8px;
            border: none;
            background: #7c3aed;
            color: white;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: 0.2s;
        }

        .jdd-btn:hover {
            background: #6d28d9;
        }

        .jdd-error {
            color: #ef4444;
            margin-bottom: 15px;
        }
    </style>
</head>

<body <?php body_class(); ?>>

    <?php if (!$has_access): ?>
        <div class="jdd-login-container">
            <div class="jdd-login-box">
                <h2 style="margin-top:0;">🔐 Acceso Privado</h2>
                <p style="color: #a1a1aa; margin-bottom: 20px;">Ingresa el correo con el que realizaste tu compra.</p>

                <?php if ($access_error): ?>
                    <div class="jdd-error"><?php echo $access_error; ?></div>
                <?php endif; ?>

                <form method="POST">
                    <input type="email" name="jdd_email" class="jdd-input" placeholder="tu@correo.com" required autofocus>
                    <button type="submit" class="jdd-btn">Entrar</button>
                </form>
            </div>
        </div>
    <?php else: ?>
        <?php echo do_shortcode('[jetpack_drive_downloader]'); ?>
    <?php endif; ?>

    <?php wp_footer(); ?>
</body>

</html>