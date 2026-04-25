/**
 * bulk-hours.js - Admin bulk hours management page
 */
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var cfg = window.BulkHoursConfig || {};
        if (cfg.flashSuccess) showBulkToast(cfg.flashSuccess, 'success', 5500);
        if (cfg.flashError) showBulkToast(cfg.flashError, 'danger', 5500);

        document.querySelectorAll('.hours-input').forEach(function (input) {
            validateHours(input);
        });
    });

    function showBulkToast(message, variant, ttl) {
        if (!message) return;
        ttl = ttl || 5500;
        if (typeof window.showToast === 'function') { window.showToast(message, variant, ttl); return; }
        var wrap = document.getElementById('bulkHoursInlineToastWrap');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.id = 'bulkHoursInlineToastWrap';
            wrap.style.cssText = 'position:fixed;top:76px;right:16px;z-index:1080;max-width:420px;';
            document.body.appendChild(wrap);
        }
        var toast = document.createElement('div');
        var cls = variant === 'success' ? 'alert-success' : (variant === 'danger' ? 'alert-danger' : (variant === 'warning' ? 'alert-warning' : 'alert-secondary'));
        toast.className = 'alert ' + cls + ' shadow-sm mb-2';
        toast.textContent = String(message);
        wrap.appendChild(toast);
        setTimeout(function () { if (toast.parentNode) toast.parentNode.removeChild(toast); }, ttl);
    }

    window.validateHours = function (input) {
        var minAllowed = parseFloat(input.dataset.min || '0') || 0;
        var maxAllowed = parseFloat(input.dataset.maxAllowed || input.max || '0') || 0;
        var isOverAllocated = String(input.dataset.overAllocated || '0') === '1';
        var infoElement = input.nextElementSibling;

        if (input.value === '') {
            input.style.borderColor = '';
            input.style.backgroundColor = '';
            if (infoElement) { infoElement.textContent = 'Min: ' + minAllowed.toFixed(1) + 'h, Max: ' + maxAllowed.toFixed(1) + 'h'; infoElement.className = 'text-muted hours-info'; }
            input.setCustomValidity('');
            return;
        }

        var newHours = parseFloat(input.value) || 0;
        var originalHours = parseFloat(input.dataset.original);

        if (newHours < minAllowed) {
            input.style.borderColor = '#dc3545'; input.style.backgroundColor = '#f8d7da';
            if (infoElement) { infoElement.textContent = 'Min: ' + minAllowed.toFixed(1) + 'h'; infoElement.className = 'text-danger hours-info'; }
            input.setCustomValidity('Cannot be lower than ' + minAllowed.toFixed(1) + ' hours');
        } else if (newHours > maxAllowed) {
            input.style.borderColor = '#dc3545'; input.style.backgroundColor = '#f8d7da';
            if (infoElement) { infoElement.textContent = isOverAllocated ? 'Project over-allocated. Max: ' + maxAllowed.toFixed(1) + 'h' : 'Max: ' + maxAllowed.toFixed(1) + 'h'; infoElement.className = 'text-danger hours-info'; }
            input.setCustomValidity('Cannot exceed ' + maxAllowed.toFixed(1) + ' hours');
        } else if (newHours > 0 && newHours !== originalHours) {
            input.style.borderColor = '#198754'; input.style.backgroundColor = '#d1e7dd';
            if (infoElement) { infoElement.textContent = (maxAllowed - newHours).toFixed(1) + 'h left'; infoElement.className = 'text-success hours-info'; }
            input.setCustomValidity('');
        } else {
            input.style.borderColor = ''; input.style.backgroundColor = '';
            if (infoElement) infoElement.textContent = '';
            input.setCustomValidity('');
        }
    };

    window.applyBulkUpdate = function () {
        var form = document.getElementById('bulkUpdateForm');
        var inputs = form.querySelectorAll('.hours-input');
        var hasChanges = false, hasErrors = false;
        inputs.forEach(function (input) {
            if (input.value !== '' && parseFloat(input.value) !== parseFloat(input.dataset.original)) hasChanges = true;
            if (!input.checkValidity()) hasErrors = true;
        });
        if (!hasChanges) { showBulkToast('No changes detected. Please modify some hours before saving.', 'warning'); return; }
        if (hasErrors) { showBulkToast('Please fix the validation errors before saving.', 'warning'); return; }
        var doSubmit = function () { form.submit(); };
        if (typeof confirmModal === 'function') confirmModal('Are you sure you want to apply these changes?', doSubmit);
        else if (window.confirm('Are you sure you want to apply these changes?')) doSubmit();
    };

    window.resetChanges = function () {
        document.querySelectorAll('.hours-input').forEach(function (input) {
            input.value = ''; input.style.borderColor = ''; input.style.backgroundColor = '';
            if (input.nextElementSibling) input.nextElementSibling.textContent = '';
            input.setCustomValidity('');
        });
        var ta = document.querySelector('textarea[name="bulk_reason"]');
        if (ta) ta.value = '';
    };

    window.increaseAll = function (amount) {
        document.querySelectorAll('.hours-input').forEach(function (input) {
            var current = parseFloat(input.dataset.original) || 0;
            var maxAllowed = parseFloat(input.dataset.maxAllowed || input.max || current) || current;
            input.value = Math.min(maxAllowed, current + amount).toFixed(1);
            window.validateHours(input);
        });
    };

    window.decreaseAll = function (amount) {
        document.querySelectorAll('.hours-input').forEach(function (input) {
            var current = parseFloat(input.dataset.original) || 0;
            var minAllowed = parseFloat(input.dataset.min || '0') || 0;
            input.value = Math.max(minAllowed, current - amount).toFixed(1);
            window.validateHours(input);
        });
    };

    window.clearAll = function () {
        document.querySelectorAll('.hours-input').forEach(function (input) {
            input.value = ''; input.style.borderColor = ''; input.style.backgroundColor = '';
            if (input.nextElementSibling) input.nextElementSibling.textContent = '';
            input.setCustomValidity('');
        });
    };
})();
