/**
 * JetPack Store Manager - Dashboard Login (Frontend)
 *
 * Phase 5: extracted from inline <script> to keep templates clean.
 * Requires `jpsm_login_vars` via wp_localize_script.
 */
(function () {
    function onReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    onReady(function () {
        if (typeof jpsm_login_vars === 'undefined') {
            return;
        }

        var form = document.getElementById('jpsm-login-form');
        if (!form) {
            return;
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var btn = document.getElementById('jpsm_login_btn');
            var msg = document.getElementById('jpsm_login_msg');
            var keyInput = document.getElementById('jpsm_key');

            if (!btn || !msg || !keyInput) {
                return;
            }

            var key = keyInput.value || '';

            btn.textContent = 'Verificando...';
            btn.disabled = true;
            msg.style.display = 'none';

            var formData = new FormData();
            formData.append('action', 'jpsm_login');
            formData.append('key', key);
            formData.append('nonce', jpsm_login_vars.nonce || '');

            fetch(jpsm_login_vars.ajax_url, { method: 'POST', body: formData })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data && data.success) {
                        btn.textContent = '✅ Acceso Correcto';
                        btn.style.background = '#22c55e';
                        setTimeout(function () { location.reload(); }, 800);
                        return;
                    }

                    btn.textContent = 'Ingresar';
                    btn.disabled = false;
                    msg.textContent = '❌ ' + ((data && data.data) ? data.data : 'Error de acceso');
                    msg.style.display = 'block';
                })
                .catch(function () {
                    btn.textContent = 'Ingresar';
                    btn.disabled = false;
                    msg.textContent = '❌ Error de conexión';
                    msg.style.display = 'block';
                });
        });
    });
})();

