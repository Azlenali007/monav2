/**
 * SMM Panel - Vanilla JS Application Core (ES6+)
 */

// Global SweetAlert2 notification helper
window.notify = {
    success: (message, title = 'Success') => {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'success',
                title: title,
                text: message,
                timer: 2500,
                showConfirmButton: false
            });
        } else {
            alert(message);
        }
    },
    error: (message, title = 'Error') => {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'error',
                title: title,
                text: message
            });
        } else {
            alert(message);
        }
    },
    confirm: async (message, title = 'Are you sure?') => {
        if (typeof Swal !== 'undefined') {
            const res = await Swal.fire({
                title: title,
                text: message,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#2563eb',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, proceed'
            });
            return res.isConfirmed;
        }
        return window.confirm(message);
    }
};

document.addEventListener('DOMContentLoaded', () => {
    // Dismiss flash alert notifications
    const closeBtns = document.querySelectorAll('[role="alert"] button');
    closeBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            btn.closest('[role="alert"]').remove();
        });
    });

    // Auto-dismiss alerts after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('[role="alert"]').forEach(alert => {
            alert.style.transition = 'opacity 0.4s ease';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 400);
        });
    }, 5000);

    // Register Progressive Web App (PWA) Service Worker
    if ('serviceWorker' in navigator && window.location.protocol.startsWith('http')) {
        navigator.serviceWorker.register('/sw.js').catch(err => {
            console.warn('PWA service worker registration skipped:', err);
        });
    }

    // Capture PWA Install Prompt
    let deferredInstallPrompt = null;
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredInstallPrompt = e;
        const installBtns = document.querySelectorAll('.pwa-install-trigger');
        installBtns.forEach(btn => btn.classList.remove('hidden'));
    });

    window.installPWA = async () => {
        if (!deferredInstallPrompt) {
            notify.success('To install this app, tap Share on iOS or menu on Android and select "Add to Home Screen".', 'Install App');
            return;
        }
        deferredInstallPrompt.prompt();
        const { outcome } = await deferredInstallPrompt.userChoice;
        if (outcome === 'accepted') {
            document.querySelectorAll('.pwa-install-trigger').forEach(b => b.classList.add('hidden'));
        }
        deferredInstallPrompt = null;
    };

    // Global Currency Switcher
    window.switchCurrency = async (currencyCode) => {
        try {
            const formData = new FormData();
            formData.append('currency', currencyCode);
            const res = await fetch('/api/currency.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            if (data.success) {
                window.location.reload();
            } else {
                notify.error(data.error || 'Failed to switch currency');
            }
        } catch (err) {
            console.error('Currency switch error:', err);
        }
    };
});
