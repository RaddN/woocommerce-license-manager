jQuery(document).ready(function($) {
    // Set global chart options
    const chartOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'right',
                labels: {
                    boxWidth: 12,
                    padding: 15
                }
            }
        }
    };
    
    // Create Active vs Deactive chart
    if ($("#active-deactive-chart").length) {
        new Chart($("#active-deactive-chart"), {
            type: 'doughnut',
            data: {
                labels: wcLicenseAnalytics.activeVsDeactive.labels,
                datasets: [{
                    data: wcLicenseAnalytics.activeVsDeactive.data,
                    backgroundColor: wcLicenseAnalytics.activeVsDeactive.backgroundColor
                }]
            },
            options: chartOptions
        });
    }
    
    // Create Multisite vs Single Site chart
    if ($("#multisite-chart").length) {
        new Chart($("#multisite-chart"), {
            type: 'doughnut',
            data: {
                labels: wcLicenseAnalytics.multisite.labels,
                datasets: [{
                    data: wcLicenseAnalytics.multisite.data,
                    backgroundColor: wcLicenseAnalytics.multisite.backgroundColor
                }]
            },
            options: chartOptions
        });
    }
    
    // Create WordPress Versions chart
    if ($("#wordpress-versions-chart").length) {
        new Chart($("#wordpress-versions-chart"), {
            type: 'doughnut',
            data: {
                labels: wcLicenseAnalytics.wordpressVersions.labels,
                datasets: [{
                    data: wcLicenseAnalytics.wordpressVersions.data,
                    backgroundColor: wcLicenseAnalytics.wordpressVersions.backgroundColor
                }]
            },
            options: chartOptions
        });
    }
    
    // Create MySQL Versions chart
    if ($("#mysql-versions-chart").length) {
        new Chart($("#mysql-versions-chart"), {
            type: 'doughnut',
            data: {
                labels: wcLicenseAnalytics.mysqlVersions.labels,
                datasets: [{
                    data: wcLicenseAnalytics.mysqlVersions.data,
                    backgroundColor: wcLicenseAnalytics.mysqlVersions.backgroundColor
                }]
            },
            options: chartOptions
        });
    }
    
    // Create PHP Versions chart
    if ($("#php-versions-chart").length) {
        new Chart($("#php-versions-chart"), {
            type: 'doughnut',
            data: {
                labels: wcLicenseAnalytics.phpVersions.labels,
                datasets: [{
                    data: wcLicenseAnalytics.phpVersions.data,
                    backgroundColor: wcLicenseAnalytics.phpVersions.backgroundColor
                }]
            },
            options: chartOptions
        });
    }
    
    // Create Server Software chart
    if ($("#server-software-chart").length) {
        new Chart($("#server-software-chart"), {
            type: 'doughnut',
            data: {
                labels: wcLicenseAnalytics.serverSoftware.labels,
                datasets: [{
                    data: wcLicenseAnalytics.serverSoftware.data,
                    backgroundColor: wcLicenseAnalytics.serverSoftware.backgroundColor
                }]
            },
            options: chartOptions
        });
    }
    
    // Initialize dropdown actions for deactivation reasons filters
    $('.dropdown-toggle').on('click', function(e) {
        e.preventDefault();
        $(this).next('.dropdown-menu').toggleClass('show');
    });
    
    // Close dropdown when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.dropdown').length) {
            $('.dropdown-menu').removeClass('show');
        }
    });
});