/**
 * SMM Panel - Admin Control Center Vanilla JavaScript (ES6+)
 */

// Initialize Admin Dashboard Charts if canvas element exists
document.addEventListener('DOMContentLoaded', () => {
    const chartCanvas = document.getElementById('adminOrdersChart');
    if (chartCanvas && typeof Chart !== 'undefined') {
        const ctx = chartCanvas.getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                datasets: [{
                    label: 'Orders Volume',
                    data: [12, 19, 15, 25, 32, 28, 45],
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37, 99, 235, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0, 0, 0, 0.05)' }
                    },
                    x: {
                        grid: { display: false }
                    }
                }
            }
        });
    }

    // Attach SweetAlert2 confirmations to elements with data-confirm
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', async (e) => {
            const prompt = el.getAttribute('data-confirm') || 'Are you sure you want to proceed?';
            if (typeof window.notify !== 'undefined') {
                e.preventDefault();
                const ok = await window.notify.confirm(prompt);
                if (ok) {
                    if (el.tagName === 'A') {
                        window.location.href = el.href;
                    } else if (el.form) {
                        el.form.submit();
                    }
                }
            }
        });
    });
});
