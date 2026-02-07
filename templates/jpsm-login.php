<?php

if (!defined('ABSPATH')) {
    exit;
}

// Optional inline fallback styles (only when enqueue failed and head already printed).
echo isset($inline_styles) ? $inline_styles : '';

?>
<div class="jpsm-login-container">
    <div class="jpsm-login-card">
        <h2>🚀 JetPack Admin</h2>
        <p>Ingresa tu contraseña para continuar</p>

        <form id="jpsm-login-form">
            <input type="password" id="jpsm_key" class="jpsm-input" placeholder="Contraseña de Acceso" required>
            <button type="submit" id="jpsm_login_btn" class="jpsm-btn">Ingresar</button>
            <div id="jpsm_login_msg" class="jpsm-error"></div>
        </form>
    </div>
</div>

