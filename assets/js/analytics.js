jQuery(function ($) {
    const charts = window.wcLicenseAnalytics && window.wcLicenseAnalytics.charts ? window.wcLicenseAnalytics.charts : {};

    const baseOptions = {
        responsive: true,
        maintainAspectRatio: false,
        animation: {
            duration: 450,
        },
        plugins: {
            legend: {
                position: "bottom",
                labels: {
                    boxWidth: 12,
                    padding: 18,
                    usePointStyle: true,
                    pointStyle: "circle",
                },
            },
            tooltip: {
                displayColors: true,
                backgroundColor: "#0f172a",
                titleColor: "#f8fafc",
                bodyColor: "#e2e8f0",
                padding: 12,
            },
        },
        scales: {
            x: {
                ticks: {
                    color: "#475569",
                    maxRotation: 0,
                    autoSkip: true,
                },
                grid: {
                    display: false,
                },
            },
            y: {
                beginAtZero: true,
                ticks: {
                    color: "#475569",
                    precision: 0,
                },
                grid: {
                    color: "rgba(148, 163, 184, 0.18)",
                },
            },
        },
    };

    Object.entries(charts).forEach(([canvasId, config]) => {
        const canvas = document.getElementById(canvasId);
        if (!canvas || !config || !Array.isArray(config.data?.labels) || !config.data.labels.length) {
            return;
        }

        const options = $.extend(true, {}, baseOptions, config.options || {});
        if (config.type === "doughnut" || config.type === "pie") {
            delete options.scales;
        }

        new Chart(canvas, {
            type: config.type,
            data: config.data,
            options,
        });
    });
});
