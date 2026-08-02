(function () {
    'use strict';

    var form = document.getElementById('loginForm');
    var submitBtn = document.getElementById('submitBtn');
    if (!form) return;

    document.querySelectorAll('.toggle-pw').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = document.getElementById(btn.dataset.target);
            if (!target) return;
            var hidden = target.type === 'password';
            target.type = hidden ? 'text' : 'password';
            btn.querySelector('.eye-open').style.display = hidden ? 'none' : '';
            btn.querySelector('.eye-closed').style.display = hidden ? '' : 'none';
        });
    });

    function getField(input) {
        return input.closest('.field');
    }

    function removeJsError(field) {
        var e = field && field.querySelector('.field-error-msg.js-err');
        if (e) e.remove();
    }

    function setError(input, msg) {
        var field = getField(input);
        if (!field) return;
        field.classList.add('field--error');
        removeJsError(field);
        var span = document.createElement('span');
        span.className = 'field-error-msg js-err';
        span.textContent = msg;
        field.appendChild(span);
    }

    function clearError(input) {
        var field = getField(input);
        if (!field) return;
        field.classList.remove('field--error');
        removeJsError(field);
    }

    var emailInput = document.getElementById('email');
    var passwordInput = document.getElementById('password');

    function validateEmail() {
        var v = emailInput.value.trim();
        var re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!v || !re.test(v)) {
            setError(emailInput, 'Enter a valid email address.');
            return false;
        }
        clearError(emailInput);
        return true;
    }

    function validatePassword() {
        if (!passwordInput.value) {
            setError(passwordInput, 'Password is required.');
            return false;
        }
        clearError(passwordInput);
        return true;
    }

    emailInput && emailInput.addEventListener('blur', validateEmail);
    passwordInput && passwordInput.addEventListener('blur', validatePassword);

    [emailInput, passwordInput].forEach(function (el) {
        el && el.addEventListener('focus', function () { clearError(el); });
    });

    form.addEventListener('submit', function (e) {
        var ok = [validateEmail(), validatePassword()].every(Boolean);

        if (!ok) {
            e.preventDefault();
            var first = form.querySelector('.field--error input');
            if (first) {
                first.scrollIntoView({ behavior: 'smooth', block: 'center' });
                first.focus();
            }
            return;
        }

        submitBtn.classList.add('loading');
        submitBtn.disabled = true;
    });
})();
