<?php
include '../../../config/config.php';
session_start();
include '../../../templates/middleware.php';
// canonical auth guard
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }
// page-level permission enforcement (allow existing 'Bills Payment' holders too)
if (!function_exists('has_any_permission') || !has_any_permission(['TRL Report','Bills Payment'])) { header('Location: ../../home.php'); exit; }

$mode = isset($_GET['mode']) ? strtolower(trim((string) $_GET['mode'])) : 'summary';
if ($mode !== 'summary' && $mode !== 'refunded' && $mode !== 'subbillers') {
    $mode = 'summary';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Transaction Request Log - Report</title>
    <link rel="icon" href="../../../images/MLW%20logo.png" type="image/png">
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="trl-report.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="components/trl-report-summary.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="components/trl-report-refunded.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="components/trl-report-subbillers.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
    <div class="main-container">
        <?php include '../../../templates/header_ui.php'; ?>
        <?php include '../../../templates/sidebar.php'; ?>

        <?php bp_section_header_html('fa-solid fa-chart-column', 'Transaction Request Log - Report'); ?>

        <div class="bp-card container-fluid mt-3 p-4 trl-report-wrap">
            <div class="mode-cards" id="modeCards">
                <label class="mode-card <?php echo $mode === 'summary' ? 'selected' : ''; ?>" data-mode="summary">
                    <input type="radio" name="reportMode" value="summary" <?php echo $mode === 'summary' ? 'checked' : ''; ?>>
                    <div class="mode-icon"><i class="fa-solid fa-list"></i></div>
                    <div class="mode-text">
                        <p class="mode-label">SUMMARY</p>
                        <small>Aggregate yearly totals</small>
                    </div>
                </label>

                <label class="mode-card <?php echo $mode === 'refunded' ? 'selected' : ''; ?>" data-mode="refunded">
                    <input type="radio" name="reportMode" value="refunded" <?php echo $mode === 'refunded' ? 'checked' : ''; ?>>
                    <div class="mode-icon"><i class="fa-solid fa-arrow-rotate-left"></i></div>
                    <div class="mode-text">
                        <p class="mode-label">REFUNDED</p>
                        <small>Refunded transactions</small>
                    </div>
                </label>

                <label class="mode-card <?php echo $mode === 'subbillers' ? 'selected' : ''; ?>" data-mode="subbillers">
                    <input type="radio" name="reportMode" value="subbillers" <?php echo $mode === 'subbillers' ? 'checked' : ''; ?>>
                    <div class="mode-icon"><i class="fa-solid fa-layer-group"></i></div>
                    <div class="mode-text">
                        <p class="mode-label">SUB BILLERS</p>
                        <small>Specific sub-biller report</small>
                    </div>
                </label>
            </div>

            <div id="summaryPanel" class="mode-panel <?php echo $mode === 'summary' ? '' : 'hidden'; ?>">
                <?php include __DIR__ . '/components/trl-report-summary.php'; ?>
            </div>

            <div id="refundedPanel" class="mode-panel <?php echo $mode === 'refunded' ? '' : 'hidden'; ?>">
                <?php include __DIR__ . '/components/trl-report-refunded.php'; ?>
            </div>

            <div id="subbillersPanel" class="mode-panel <?php echo $mode === 'subbillers' ? '' : 'hidden'; ?>">
                <?php include __DIR__ . '/components/trl-report-subbillers.php'; ?>
            </div>
        </div>

        <script>
        (function() {
            var modeInputs = document.querySelectorAll('input[name="reportMode"]');
            var modeCards = document.querySelectorAll('.mode-card');
            var summaryPanel = document.getElementById('summaryPanel');
            var refundedPanel = document.getElementById('refundedPanel');
            var subbillersPanel = document.getElementById('subbillersPanel');

            function activeMode() {
                var checked = document.querySelector('input[name="reportMode"]:checked');
                return checked ? checked.value : 'summary';
            }

            function setMode(mode) {
                modeCards.forEach(function(card) {
                    card.classList.toggle('selected', card.getAttribute('data-mode') === mode);
                });
                if (summaryPanel) summaryPanel.classList.toggle('hidden', mode !== 'summary');
                if (refundedPanel) refundedPanel.classList.toggle('hidden', mode !== 'refunded');
                if (subbillersPanel) subbillersPanel.classList.toggle('hidden', mode !== 'subbillers');

                var params = new URLSearchParams(window.location.search);
                params.set('mode', mode);
                history.replaceState(null, '', window.location.pathname + '?' + params.toString());
            }

            modeInputs.forEach(function(input) {
                input.addEventListener('change', function() {
                    setMode(input.value);
                });
            });

            // initialize
            try { setMode(activeMode()); } catch (e) { /* ignore */ }
        })();
        </script>

        <?php include '../../../templates/footer.php'; ?>
    </div>
</body>
</html>
