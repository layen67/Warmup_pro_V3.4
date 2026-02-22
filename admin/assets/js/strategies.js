jQuery(document).ready(function($) {
    let chartInstance = null;

    function renderChart() {
        const ctx = document.getElementById('pw-strategy-chart').getContext('2d');
        const start = parseInt($('#pw-st-start').val()) || 10;
        const max = parseInt($('#pw-st-max').val()) || 1000;
        const type = $('#pw-st-type').val();
        const val = parseFloat($('#pw-st-value').val()) || 10;

        let data = [];
        let labels = [];
        let current = start;

        for (let day = 1; day <= 30; day++) {
            labels.push('J'+day);
            data.push(current);

            if (type === 'mixed') {
                // Mixed: Linear (J1-J5) then Exponential
                if (day <= 5) {
                    current += val;
                } else {
                    // Exponential phase
                    let rate = val > 1 ? val / 100 : (val || 0.10);
                    current = Math.floor(current * (1 + rate));
                }
            } else if (type === 'exponential') {
                let rate = val > 1 ? val / 100 : (val || 0.10);
                current = Math.floor(current * (1 + rate));
            } else {
                current += val;
            }
            if (current > max) current = max;
        }

        if (chartInstance) chartInstance.destroy();

        chartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Volume Journalier',
                    data: data,
                    borderColor: '#2271b1',
                    fill: false
                }]
            }
        });
    }

    $('#pw-strategy-form input, #pw-strategy-form select').on('change', renderChart);

    // Add Strategy
    $('#pw-add-strategy-btn').on('click', function() {
        $('#pw-strategy-form')[0].reset();
        $('#pw-st-id').val('');
        $('#pw-strategy-modal').show();
        renderChart();
    });

    // Edit Strategy
    $('.pw-edit-strategy').on('click', function() {
        const tr = $(this).closest('tr');
        const data = tr.data('json');
        const conf = data.config;

        $('#pw-st-id').val(data.id);
        $('#pw-st-name').val(data.name);
        $('#pw-st-desc').val(data.description);
        $('#pw-st-start').val(conf.start_volume);
        $('#pw-st-max').val(conf.max_volume);
        $('#pw-st-type').val(conf.growth_type);
        $('#pw-st-value').val(conf.growth_value);
        $('#pw-st-bounce').val(conf.safety_rules ? conf.safety_rules.max_hard_bounce : 2.0);

        $('#pw-strategy-modal').show();
        renderChart();
    });

    // Save
    $('#pw-save-strategy').on('click', function() {
        const btn = $(this);
        btn.prop('disabled', true);
        
        let formData = $('#pw-strategy-form').serialize();
        formData += '&action=pw_save_strategy&nonce=' + pwAdmin.nonce;

        $.post(pwAdmin.ajaxurl, formData, function(res) {
            btn.prop('disabled', false);
            if(res.success) location.reload();
            else alert('Erreur: ' + res.data.message);
        });
    });

    // Delete
    $('.pw-delete-strategy').on('click', function() {
        if(!confirm('Supprimer cette stratégie ?')) return;
        const id = $(this).closest('tr').data('id');
        $.post(pwAdmin.ajaxurl, {
            action: 'pw_delete_strategy',
            nonce: pwAdmin.nonce,
            id: id
        }, function(res) {
            if(res.success) location.reload();
        });
    });
});
