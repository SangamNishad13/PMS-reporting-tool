/**
 * profile.js - Profile page hours lookup
 */
document.addEventListener('DOMContentLoaded', function () {
    var cfg = window.ProfileConfig || {};
    var phFetch = document.getElementById('ph_fetch');
    if (!phFetch) return;
    var phDate = document.getElementById('ph_date');
    var phResult = document.getElementById('ph_result');

    phFetch.addEventListener('click', function () {
        var date = phDate.value;
        phResult.innerHTML = '<p class="text-muted">Loading...</p>';

        var params = 'user_id=' + encodeURIComponent(cfg.userId) + '&date=' + encodeURIComponent(date);
        var xhr = new XMLHttpRequest();
        xhr.open('GET', cfg.baseDir + '/api/user_hours.php?' + params, true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            if (xhr.status === 200) {
                var res;
                try { res = JSON.parse(xhr.responseText); } catch (e) {
                    phResult.innerHTML = '<p class="text-danger">Invalid response from server.</p>';
                    return;
                }
                if (!res.success) {
                    phResult.innerHTML = '<p class="text-danger">' + escapeHtml(res.error || 'Error loading hours') + '</p>';
                    return;
                }
                var html = '<h6>Total: <span class="badge bg-info">' + parseFloat(res.total_hours).toFixed(2) + ' hrs</span></h6>';
                if (res.entries && res.entries.length) {
                    html += '<div class="list-group mt-2">';
                    res.entries.forEach(function (en) {
                        var title = en.project_title || '—';
                        var page = en.page_name || '—';
                        var time = en.tested_at ? new Date(en.tested_at).toLocaleString() : '';
                        html += '<div class="list-group-item">';
                        html += '<div class="d-flex w-100 justify-content-between"><strong>' + escapeHtml(title) + '</strong><small>' + escapeHtml(time) + '</small></div>';
                        html += '<div class="mb-1">Page: ' + escapeHtml(page) + '</div>';
                        html += '<div>Hours: <span class="badge bg-secondary">' + parseFloat(en.hours_spent || 0).toFixed(2) + '</span></div>';
                        if (en.comments) html += '<div class="mt-1 text-muted">' + escapeHtml(en.comments) + '</div>';
                        html += '</div>';
                    });
                    html += '</div>';
                } else {
                    html += '<p class="text-muted mt-2">No entries for this date.</p>';
                }
                phResult.innerHTML = html;
            } else if (xhr.status === 403) {
                phResult.innerHTML = '<p class="text-danger">Access denied.</p>';
            } else {
                phResult.innerHTML = '<p class="text-danger">Error loading data.</p>';
            }
        };
        xhr.send();
    });

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&"'<>]/g, function (s) {
            return ({ '&': '&amp;', '"': '&quot;', "'": '&#39;', '<': '&lt;', '>': '&gt;' })[s];
        });
    }
});

// 2FA Functions
function start2FASetup() {
    var cfg = window.ProfileConfig || {};
    $.ajax({
        url: cfg.baseDir + '/api/profile_2fa.php',
        method: 'POST',
        data: { action: 'generate_secret' },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                // Clear and render QR locally
                const qrContainer = document.getElementById('qrCodeContainer');
                qrContainer.innerHTML = ''; 
                new QRCode(qrContainer, {
                    text: res.otpauth_uri,
                    width: 200,
                    height: 200,
                    colorDark : "#000000",
                    colorLight : "#ffffff",
                    correctLevel : QRCode.CorrectLevel.H
                });
                
                // Add some styling to center the canvas if it's generated
                $(qrContainer).find('canvas, img').addClass('mx-auto shadow-sm border rounded p-1 p-2 bg-white');

                $('#secretText').text(res.secret);
                $('#verificationCode').val('');
                var modal = new bootstrap.Modal(document.getElementById('modal2FASetup'));
                modal.show();
            } else {
                showToast(res.message || 'Failed to generate 2FA secret.', 'danger');
            }
        },
        error: function() {
            showToast('A network error occurred while setting up 2FA.', 'danger');
        }
    });
}

function verifyAndEnable2FA() {
    var code = $('#verificationCode').val();
    if (code.length !== 6) {
        showToast('Please enter a 6-digit code.', 'warning');
        return;
    }
    
    var btn = $('#btnVerify2FA');
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Verifying...');
    
    var cfg = window.ProfileConfig || {};
    $.ajax({
        url: cfg.baseDir + '/api/profile_2fa.php',
        method: 'POST',
        data: { action: 'verify_and_enable', code: code },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                showToast(res.message, 'success');
                setTimeout(function() { window.location.reload(); }, 1500);
            } else {
                showToast(res.message || 'Verification failed. Try again.', 'danger');
                btn.prop('disabled', false).text('Verify & Enable');
            }
        },
        error: function() {
            showToast('A network error occurred.', 'danger');
            btn.prop('disabled', false).text('Verify & Enable');
        }
    });
}

function disable2FA() {
    if (!confirm('Are you sure you want to disable Two-Factor Authentication? Your account will be less secure.')) return;
    
    var cfg = window.ProfileConfig || {};
    $.ajax({
        url: cfg.baseDir + '/api/profile_2fa.php',
        method: 'POST',
        data: { action: 'disable_2fa' },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                showToast(res.message, 'success');
                setTimeout(function() { window.location.reload(); }, 1500);
            } else {
                showToast(res.message || 'Failed to disable 2FA.', 'danger');
            }
        },
        error: function() {
            showToast('A network error occurred.', 'danger');
        }
    });
}
