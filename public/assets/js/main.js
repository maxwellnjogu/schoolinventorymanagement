/**
 * St. Jude High School Inventory Management System
 * Main Frontend Interactivity Script (Phase 1)
 * Adheres strictly to Secure Web Coding standards (No unsafe innerHTML, safe DOM APIs)
 */

document.addEventListener('DOMContentLoaded', function () {
    // 1. Mobile Sidebar Toggle
    const sidebarToggle = document.getElementById('sidebarToggle');
    const appSidebar = document.getElementById('appSidebar');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');

    if (sidebarToggle && appSidebar) {
        sidebarToggle.addEventListener('click', function () {
            appSidebar.classList.toggle('show');
            if (sidebarBackdrop) {
                sidebarBackdrop.classList.toggle('show');
            }
        });
    }

    if (sidebarBackdrop && appSidebar) {
        sidebarBackdrop.addEventListener('click', function () {
            appSidebar.classList.remove('show');
            sidebarBackdrop.classList.remove('show');
        });
    }

    // 2. Demo Account Quick-Fill Buttons (on Login Page)
    const demoButtons = document.querySelectorAll('.btn-use-account');
    const usernameInput = document.getElementById('username');
    const passwordInput = document.getElementById('password');

    if (demoButtons.length > 0 && usernameInput && passwordInput) {
        demoButtons.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                const u = btn.getAttribute('data-user') || '';
                const p = btn.getAttribute('data-pass') || '';
                usernameInput.value = u;
                passwordInput.value = p;
                usernameInput.focus();
            });
        });
    }

    // 3. Password Visibility Toggle
    const togglePassBtn = document.getElementById('togglePassword');
    if (togglePassBtn && passwordInput) {
        togglePassBtn.addEventListener('click', function () {
            const isPassword = passwordInput.getAttribute('type') === 'password';
            passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
            const icon = togglePassBtn.querySelector('i');
            if (icon) {
                if (isPassword) {
                    icon.classList.remove('bi-eye');
                    icon.classList.add('bi-eye-slash');
                } else {
                    icon.classList.remove('bi-eye-slash');
                    icon.classList.add('bi-eye');
                }
            }
        });
    }

    // 4. Auto-dismiss alerts after 6 seconds
    const flashAlerts = document.querySelectorAll('.alert-dismissible');
    flashAlerts.forEach(function (alertElem) {
        setTimeout(function () {
            if (alertElem && alertElem.parentNode) {
                alertElem.classList.remove('show');
                setTimeout(function () {
                    alertElem.remove();
                }, 300);
            }
        }, 6000);
    });
});
