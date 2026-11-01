/**
 * SHINKA — Login administrativo.
 *
 * Faz autenticação e redireciona o usuário conforme seu papel.
 */

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('adminLoginForm');
    const message = document.getElementById('loginMessage');
    const button = document.getElementById('loginButton');
    const API = new URL('../index.php?route=', window.location.href).href;

    function showMessage(text, type = '') {
        message.textContent = text;
        message.className =
            `form-message${type ? ` ${type}` : ''}`;
    }

    async function parseResponse(response) {
        const text = await response.text();
        let data;

        try {
            data = JSON.parse(text);
        } catch (error) {
            console.error(
                'Resposta inválida do servidor:',
                text
            );

            throw new Error(
                'O servidor retornou uma resposta inválida.'
            );
        }

        if (!response.ok || !data.success) {
            throw new Error(
                data.message ||
                'Não foi possível autenticar.'
            );
        }

        return data;
    }

    form.addEventListener(
        'submit',
        async (event) => {
            event.preventDefault();

            showMessage('');

            button.disabled = true;
            button.textContent = 'Autenticando…';

            try {
                const response = await fetch(
                    `${API}api/admin/login`,
                    {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type':
                                'application/x-www-form-urlencoded'
                        },
                        body: new URLSearchParams(
                            new FormData(form)
                        )
                    }
                );

                const data =
                    await parseResponse(response);

                showMessage(
                    data.message,
                    'successo'
                );

                setTimeout(() => {
                    window.location.href =
                        data.redirect ||
                        'index.html#dashboard';
                }, 400);
            } catch (error) {
                showMessage(
                    error.message ||
                    'Erro de conexão com o servidor.',
                    'error'
                );

                button.disabled = false;
                button.textContent =
                    'Entrar com segurança';
            }
        }
    );
});