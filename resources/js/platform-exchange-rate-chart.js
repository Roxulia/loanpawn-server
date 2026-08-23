import Chart from 'chart.js/auto';

const dataElement = document.getElementById('admin-exchange-rate-chart-data');
const canvas = document.querySelector('[data-admin-exchange-rate-chart]');

if (dataElement && canvas) {
    const payload = JSON.parse(dataElement.textContent || '{}');
    const points = Array.isArray(payload.points) ? payload.points : [];

    if (points.length > 0) {
        new Chart(canvas, {
            type: 'line',
            data: {
                labels: points.map((point) => point.date),
                datasets: [
                    {
                        label: 'Buying close',
                        data: points.map((point) => Number(point.buying_close)),
                        borderColor: '#00677f',
                        backgroundColor: 'rgba(0, 103, 127, 0.12)',
                        pointBackgroundColor: '#00677f',
                        tension: 0.22,
                    },
                    {
                        label: 'Selling close',
                        data: points.map((point) => Number(point.selling_close)),
                        borderColor: '#ba1a1a',
                        backgroundColor: 'rgba(186, 26, 26, 0.10)',
                        pointBackgroundColor: '#ba1a1a',
                        tension: 0.22,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxHeight: 10, boxWidth: 10, padding: 16, usePointStyle: true },
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => `${context.dataset.label}: ${new Intl.NumberFormat('en-US', { maximumFractionDigits: 6 }).format(context.parsed.y)}`,
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false } },
                    y: {
                        beginAtZero: false,
                        ticks: {
                            callback: (value) => new Intl.NumberFormat('en-US', { maximumFractionDigits: 6 }).format(value),
                        },
                    },
                },
            },
        });
    }
}
