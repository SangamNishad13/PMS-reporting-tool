/* Register page JS - extracted from modules/auth/register.php */
$(document).ready(function() {
    // Password strength indicator
    $('#password').on('input', function() {
        const password = $(this).val();
        let strength = 0;
        if (password.length >= 6) strength++;
        if (password.match(/[a-z]/)) strength++;
        if (password.match(/[A-Z]/)) strength++;
        if (password.match(/[0-9]/)) strength++;
        if (password.match(/[^a-zA-Z0-9]/)) strength++;
        const indicator = $('#password-strength');
        if (!indicator.length) { $(this).after('<div id="password-strength" class="mt-1"></div>'); }
        const strengthText = ['Very Weak', 'Weak', 'Fair', 'Good', 'Strong', 'Very Strong'];
        const strengthClass = ['danger', 'danger', 'warning', 'info', 'success', 'success'];
        $('#password-strength').html(`
            <div class="progress" style="height: 5px;">
                <div class="progress-bar bg-${strengthClass[strength]}" style="width: ${(strength / 5) * 100}%"></div>
            </div>
            <small class="text-${strengthClass[strength]}">${strengthText[strength]}</small>
        `);
    });

    // Confirm password match
    $('#confirm_password').on('input', function() {
        const password = $('#password').val();
        const confirm = $(this).val();
        if (confirm && password !== confirm) {
            $(this).addClass('is-invalid');
            $('#confirm-feedback').remove();
            $(this).after('<div id="confirm-feedback" class="invalid-feedback">Passwords do not match</div>');
        } else {
            $(this).removeClass('is-invalid');
            $('#confirm-feedback').remove();
        }
    });
});
