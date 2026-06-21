import Chart from 'chart.js/auto';

const dataElement = document.getElementById('platform-dashboard-chart-data');

if (dataElement) {
    const chartData = JSON.parse(dataElement.textContent || '{}');
    const colors = ['#006073', '#31a8cc', '#7899a2', '#8ad1e7', '#4a5568', '#b8eaff'];
    const initializedCharts = new WeakSet();

    Chart.defaults.color = '#3f484b';
    Chart.defaults.font.family = "'Inter', ui-sans-serif, system-ui, sans-serif";
    Chart.defaults.borderColor = '#d8e3fa';

    const chartsFor = (name) => document.querySelectorAll(`[data-dashboard-chart="${name}"]`);
    const isVisible = (canvas) => canvas.offsetParent !== null;
    const hasValues = (values) => Array.isArray(values) && values.some((value) => Number(value) !== 0);

    const sharedPlugins = {
        legend: {
            position: 'bottom',
            labels: {
                boxWidth: 10,
                boxHeight: 10,
                color: '#3f484b',
                font: {
                    size: 12,
                    weight: '700',
                },
                padding: 14,
                usePointStyle: true,
            },
        },
        tooltip: {
            backgroundColor: '#263142',
            borderColor: '#8ad1e7',
            borderWidth: 1,
            padding: 10,
            titleColor: '#ebf1ff',
            bodyColor: '#ebf1ff',
            displayColors: true,
        },
    };

    const axisOptions = {
        x: {
            border: {
                display: false,
            },
            grid: {
                display: false,
            },
            ticks: {
                color: '#6f797c',
                font: {
                    size: 11,
                    weight: '700',
                },
            },
        },
        y: {
            beginAtZero: true,
            border: {
                display: false,
            },
            grid: {
                color: '#e7eeff',
                drawTicks: false,
            },
            ticks: {
                color: '#6f797c',
                font: {
                    size: 11,
                    weight: '700',
                },
                padding: 8,
            },
        },
    };

    const doughnut = (name, dataset) => {
        const canvases = chartsFor(name);

        if (!dataset || !hasValues(dataset.values)) {
            return;
        }

        canvases.forEach((canvas) => {
            if (!isVisible(canvas) || initializedCharts.has(canvas)) {
                return;
            }

            new Chart(canvas, {
                type: 'doughnut',
                data: {
                    labels: dataset.labels,
                    datasets: [{
                        data: dataset.values,
                        backgroundColor: colors,
                        borderColor: '#ffffff',
                        borderRadius: 3,
                        borderWidth: 2,
                        hoverOffset: 4,
                        spacing: 2,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '64%',
                    plugins: sharedPlugins,
                },
            });
            initializedCharts.add(canvas);
        });
    };

    const groupedBar = (name, dataset, labels) => {
        const canvases = chartsFor(name);

        if (canvases.length === 0 || !dataset || !Array.isArray(dataset.labels) || dataset.labels.length === 0) {
            return;
        }

        canvases.forEach((canvas) => {
            if (!isVisible(canvas) || initializedCharts.has(canvas)) {
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
                        borderColor: colors[index],
                        borderRadius: {
                            topLeft: 4,
                            topRight: 4,
                        },
                        borderSkipped: false,
                        borderWidth: 0,
                        maxBarThickness: 34,
                    })),
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    datasets: {
                        bar: {
                            categoryPercentage: 0.62,
                            barPercentage: 0.82,
                        },
                    },
                    plugins: sharedPlugins,
                    scales: axisOptions,
                },
            });
            initializedCharts.add(canvas);
        });
    };

    const singleBar = (name, dataset, label) => {
        const canvases = chartsFor(name);

        if (!dataset || !hasValues(dataset.values)) {
            return;
        }

        canvases.forEach((canvas) => {
            if (!isVisible(canvas) || initializedCharts.has(canvas)) {
                return;
            }

            new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: dataset.labels,
                    datasets: [{
                        label,
                        data: dataset.values,
                        backgroundColor: '#006073',
                        borderColor: '#006073',
                        borderRadius: {
                            topLeft: 4,
                            topRight: 4,
                        },
                        borderSkipped: false,
                        borderWidth: 0,
                        maxBarThickness: 38,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        ...sharedPlugins,
                        legend: {
                            display: false,
                        },
                    },
                    scales: axisOptions,
                },
            });
            initializedCharts.add(canvas);
        });
    };

    const initializeDashboardCharts = () => {
        doughnut('planDistribution', chartData.planDistribution);
        doughnut('licenseHealth', chartData.licenseHealth);
        groupedBar('tenantIncomeExpense', chartData.tenantIncomeExpense, ['Income', 'Expense']);
        groupedBar('packageUsage', chartData.packageUsage, ['Current', 'Limit']);
        singleBar('geographicNet', chartData.geographicNet, 'Net');
    };

    initializeDashboardCharts();
    window.addEventListener('platform-dashboard:tab-changed', initializeDashboardCharts);
}
