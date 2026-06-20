import Chart from 'chart.js/auto';

const dataElement = document.getElementById('platform-dashboard-chart-data');

if (dataElement) {
    const chartData = JSON.parse(dataElement.textContent || '{}');
    const colors = ['#00677f', '#2babce', '#7899a2', '#356575', '#13a0c3', '#b7e7fa'];

    const chartFor = (name) => document.querySelector(`[data-dashboard-chart="${name}"]`);

    const hasValues = (values) => Array.isArray(values) && values.some((value) => Number(value) !== 0);

    const doughnut = (name, dataset) => {
        const canvas = chartFor(name);

        if (!canvas || !dataset || !hasValues(dataset.values)) {
            return;
        }

        new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: dataset.labels,
                datasets: [{
                    data: dataset.values,
                    backgroundColor: colors,
                    borderWidth: 0,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                    },
                },
            },
        });
    };

    const groupedBar = (name, dataset, labels) => {
        const canvas = chartFor(name);

        if (!canvas || !dataset || !Array.isArray(dataset.labels) || dataset.labels.length === 0) {
            return;
        }

        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: dataset.labels,
                datasets: labels.map((label, index) => ({
                    label,
                    data: dataset[index === 0 ? 'income' : 'expense'] || dataset[index === 0 ? 'current' : 'max'],
                    backgroundColor: colors[index],
                    borderWidth: 0,
                })),
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                    },
                },
            },
        });
    };

    const singleBar = (name, dataset, label) => {
        const canvas = chartFor(name);

        if (!canvas || !dataset || !hasValues(dataset.values)) {
            return;
        }

        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: dataset.labels,
                datasets: [{
                    label,
                    data: dataset.values,
                    backgroundColor: colors[0],
                    borderWidth: 0,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                    },
                },
            },
        });
    };

    doughnut('planDistribution', chartData.planDistribution);
    doughnut('licenseHealth', chartData.licenseHealth);
    groupedBar('tenantIncomeExpense', chartData.tenantIncomeExpense, ['Income', 'Expense']);
    groupedBar('packageUsage', chartData.packageUsage, ['Current', 'Limit']);
    singleBar('geographicNet', chartData.geographicNet, 'Net');
}
