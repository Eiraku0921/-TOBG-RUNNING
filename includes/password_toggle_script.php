<script>
    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
        const input = document.getElementById(button.getAttribute('data-password-toggle'));
        if (!input) {
            return;
        }

        button.addEventListener('click', () => {
            const willShow = input.type === 'password';
            input.type = willShow ? 'text' : 'password';
            button.setAttribute('aria-pressed', willShow ? 'true' : 'false');
            button.setAttribute('aria-label', willShow ? 'パスワードを隠す' : 'パスワードを表示');
            button.querySelector('.icon-eye').classList.toggle('d-none', willShow);
            button.querySelector('.icon-eye-off').classList.toggle('d-none', !willShow);
        });
    });
</script>
