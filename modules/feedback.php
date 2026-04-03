<?php
/**
 * Global User Feedback Form
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();

$baseDir = getBaseDir();
$pageTitle = 'Send Feedback';

$successMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
    // In a fully built backend, we would write this to a `feedback` table or send an email.
    // For now, we simulate success.
    $successMsg = "Thank you! Your feedback has been received and our team will review it shortly.";
}

include __DIR__ . '/../includes/header.php';
?>

<div class="container mt-4 mb-5" style="max-width: 800px;">
    <div class="row mb-4">
        <div class="col-12 text-center">
            <h2><i class="fas fa-comment-dots text-primary"></i> Send Feedback</h2>
            <p class="text-muted">We value your input. Let us know how we can improve your portal experience or report any issues you are facing.</p>
        </div>
    </div>

    <?php if ($successMsg): ?>
        <div class="alert alert-success d-flex align-items-center mb-4" role="alert">
            <i class="fas fa-check-circle fs-4 me-3"></i>
            <div><?php echo $successMsg; ?></div>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <form method="POST" action="">
                <div class="mb-3">
                    <label for="feedback_type" class="form-label fw-bold">Feedback Category</label>
                    <select class="form-select" id="feedback_type" name="feedback_type" required>
                        <option value="" disabled selected>Select a category...</option>
                        <option value="bug">Report a Bug/Issue</option>
                        <option value="feature">Feature Request</option>
                        <option value="dashboard_data">Question about Dashboard Data</option>
                        <option value="other">Other Feedback</option>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label for="subject" class="form-label fw-bold">Subject</label>
                    <input type="text" class="form-control" id="subject" name="subject" placeholder="Brief summary of your feedback" required>
                </div>

                <div class="mb-4">
                    <label for="message" class="form-label fw-bold">Message</label>
                    <textarea class="form-control" id="message" name="message" rows="6" placeholder="Provide as much detail as possible..." required></textarea>
                </div>

                <div class="d-flex justify-content-end">
                    <a href="javascript:history.back()" class="btn btn-outline-secondary me-2">Cancel</a>
                    <button type="submit" name="submit_feedback" class="btn btn-primary px-4">
                        <i class="fas fa-paper-plane me-1"></i> Submit Feedback
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>