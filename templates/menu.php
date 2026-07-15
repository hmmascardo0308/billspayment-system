<?php 
// Dynamic base path detection
function getBasePath() {
    // Get the protocol (http or https)
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    
    // Get the host
    $host = $_SERVER['HTTP_HOST'];
    
    // Get project folder name from PHP_SELF
    $phpSelf = $_SERVER['PHP_SELF'];
    $pathParts = explode('/', trim($phpSelf, '/'));
    $projectFolder = $pathParts[0]; // First directory is the project folder
    
    // Check if we're in a subfolder (like dashboard)
    $subFolder = '';
    if (count($pathParts) > 1 && $pathParts[1] === 'dashboard') {
        $subFolder = 'dashboard/';
    }
    
    // Use DOCUMENT_ROOT for sub folders
    $documentRoot = $_SERVER['DOCUMENT_ROOT'];
    
    // Get filename from SCRIPT_NAME
    $scriptName = $_SERVER['SCRIPT_NAME'];
    $filename = basename($scriptName);
    
    // Build the base path
    $basePath = str_replace('\\', '/', $documentRoot);
    
    // Normalize the base path
    if ($basePath === '/') {
        $basePath = '';
    }
    
    // Return the complete base URL with subfolder if present
    return $protocol . $host . '/' . $projectFolder . '/' . $subFolder;
}

// Function for logout URL (without dashboard subfolder)
function getAuthPath() {
    // Get the protocol (http or https)
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    
    // Get the host
    $host = $_SERVER['HTTP_HOST'];
    
    // Get project folder name from PHP_SELF
    $phpSelf = $_SERVER['PHP_SELF'];
    $pathParts = explode('/', trim($phpSelf, '/'));
    $projectFolder = $pathParts[0]; // First directory is the project folder
    
    // Return base URL without any subfolder for authentication
    return $protocol . $host . '/' . $projectFolder . '/';
}

// Get dynamic paths
$base_url = getBasePath();
$auth_url = getAuthPath();

// include permission middleware helpers
include_once __DIR__ . '/middleware.php';

// Temporary debug helper: show resolved access level and permissions when ?debug_access=1
if (isset($_GET['debug_access']) && $_GET['debug_access']) {
    $__dbg_level = intval(get_user_access_level());
    $__dbg_perms = get_current_user_permissions();
    // extra debug: inspect loaded map for this level
    $__dbg_map = load_access_map();
    if (function_exists('error_log')) {
        error_log('[debug_access] menu: level=' . $__dbg_level . ' map_has_level=' . (isset($__dbg_map[$__dbg_level]) ? '1' : '0') . ' map_item=' . substr(var_export($__dbg_map[$__dbg_level] ?? null, true), 0, 300));
    }
    $__dbg_html = '<div style="position:fixed;right:12px;top:12px;background:#fff;border:1px solid #ccc;padding:8px;z-index:99999;font-size:12px;color:#111;max-width:320px;word-wrap:break-word;">'
        . '<strong>Access Debug</strong><br>'
        . 'Level: ' . $__dbg_level . '<br>'
        . 'Permissions: ' . htmlspecialchars((string) json_encode($__dbg_perms, JSON_UNESCAPED_SLASHES));
    if (function_exists('access_map_debug')) {
        $__dbg_info = access_map_debug();
        $__dbg_html .= '<br><hr style="border:none;border-top:1px solid #ddd;margin:6px 0;">';
        $__dbg_html .= 'Map file exists: ' . ($__dbg_info['file_exists'] ? 'yes' : 'no') . '<br>';
        $__dbg_html .= 'Raw length: ' . intval($__dbg_info['raw_len']) . '<br>';
        $__dbg_html .= 'JSON error: ' . htmlspecialchars((string) ($__dbg_info['json_err'] ?? '')) . '<br>';
        $__dbg_html .= 'Loaded keys: ' . htmlspecialchars((string) json_encode($__dbg_info['keys'], JSON_UNESCAPED_SLASHES)) . '<br>';
    }
    $__dbg_html .= '</div>';
    
    echo $__dbg_html;
    if (function_exists('error_log')) {
        error_log('[debug_access] level=' . $__dbg_level . ' perms=' . json_encode($__dbg_perms));
    }
}

if (isset($_SESSION['user_type']) && ($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user')): ?>
    <div id="sidemenu" class="sidemenu" style="display: none;">
        <!-- Home Button -->
        <div class="onetab" onclick="parent.location='<?php echo $base_url; ?>home.php'">
        <a href="<?php echo $base_url; ?>home.php"><i class="fa-solid fa-house"></i> Home</a>
        </div>

        <!-- Profile Menu -->
            <!-- Profile Menu (visibility controlled by permissions) -->
            <?php if (has_any_permission(['Profile View','Profile Signature'])): ?>
            <div class="onetab" id="profile-btn">
                <h6><i class="fa-solid fa-user"></i> Profile</h6>
                <i class="fa-solid fa-chevron-right" id="closed-profile" style="display: block"></i>
                <i class="fa-solid fa-chevron-down" id="open-profile" style="display: none"></i>
            </div>
            <div class="onetab-sub" id="profile-nav" style="display: none;">
                <?php if (has_permission('Profile View')): ?>
                <div class="sub" onclick="parent.location='<?php echo $auth_url; ?>dashboard/profile/profile.php'">
                    <a href="<?php echo $auth_url; ?>dashboard/profile/profile.php">Profile</a>
                </div>
                <?php endif; ?>

                <?php if (has_permission('Profile Signature')): ?>
                <div class="sub" onclick="parent.location='<?php echo $auth_url; ?>dashboard/profile/profile-signature.php'">
                    <a href="<?php echo $auth_url; ?>dashboard/profile/profile-signature.php">Signature</a>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        <?php if (has_any_permission(['BP Import Transaction','BP Import Cancellation','BP Import Partner Data','BP Post Transaction','BP Settlement Adjustment Entry','BP Settlement Per Bank','BP Report Volume','BP Report EDI','BP Report Transaction Details','BP Report Transaction Summary','BP Report Cancellation','BP Report Balance Sheet'])): ?>
        <!-- Show/Hide Paramount -->
        <div class="onetab" id="para-btn">
            <h6><i class="fa-solid fa-money-bill-wave"></i> Bills Payment Transaction</h6>
            <i class="fa-solid fa-chevron-right" id="closed-para" style="display: block"></i>
            <i class="fa-solid fa-chevron-down" id="open-para" style="display: none"></i>
        </div>

        <?php if (has_any_permission(['BP Import Transaction','BP Import Cancellation','BP Import Partner Data'])): ?>
        <div class="tabcat" id="para-import-btn" style="display: none;">
            <h6><i class="fa-solid fa-file-import"></i> Import</h6>
            <i class="fa-solid fa-chevron-right" id="closed-para-import" style="display: block"></i>
            <i class="fa-solid fa-chevron-down" id="open-para-import" style="display: none"></i>
        </div>
        <?php endif; ?>

        <!-- Paramount Import Buttons -->
        <?php if (has_any_permission(['BP Import Transaction','BP Import Cancellation','BP Import Partner Data'])): ?>
        <div class="onetab-sub" id="para-import-nav" style="display: none;">
            <?php if (has_permission('BP Import Transaction')): ?>
            <div class="sub" onclick="parent.location='<?php echo $base_url; ?>billspayment/import/billspay-transaction.php'">
                <a href="<?php echo $base_url; ?>billspayment/import/billspay-transaction.php"><i class="fa-solid fa-receipt"></i> Transaction</a>
            </div>
            <?php endif; ?>
            <?php if (has_permission('BP Import Cancellation')): ?>
            <div class="sub" onclick="parent.location='<?php echo $base_url; ?>billspayment/import/billspay-cancellation.php'">
                <a href="<?php echo $base_url; ?>billspayment/import/billspay-cancellation.php">Cancellation</a>
            </div>
            <?php endif; ?>
            <?php if (has_permission('BP Import Partner Data')): ?>
            <div class="sub" onclick="parent.location='<?php echo $base_url; ?>billspayment/import/billspay-partner-data.php'">
                <a href="<?php echo $base_url; ?>billspayment/import/billspay-partner-data.php">Partner Data</a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (has_permission('BP Post Transaction')):?>
        <!-- Show/Hide Paramount Post -->
        <div class="tabcat" id="para-post-btn" style="display: none;">
            <h6><i class="fa-solid fa-paper-plane"></i> Post</h6>
            <i class="fa-solid fa-chevron-right" id="closed-para-post" style="display: block"></i>
            <i class="fa-solid fa-chevron-down" id="open-para-post" style="display: none"></i>
        </div>
        <div class="onetab-sub" id="para-post-nav" style="display: none;">
            <div class="sub" onclick="parent.location='<?php echo $base_url; ?>billspayment/post/billspay-post-transaction.php'">
                <a href="<?php echo $base_url; ?>billspayment/post/billspay-post-transaction.php"><i class="fa-solid fa-check-to-slot"></i> Transaction</a>
            </div>
        </div>
        <?php endif; ?>

        <?php if (has_any_permission(['BP Settlement Adjustment Entry','BP Settlement Per Bank'])): ?>
        <div class="tabcat" id="para-settlement-btn" style="display: none;">
            <h6><i class="fa-solid fa-chart-line"></i> Settlement</h6>
            <i class="fa-solid fa-chevron-right" id="closed-para-settlement" style="display: block"></i>
            <i class="fa-solid fa-chevron-down" id="open-para-settlement" style="display: none"></i>
        </div>

        <div class="onetab-sub" id="para-settlement-nav" style="display: none;">
            <?php if (has_permission('BP Settlement Adjustment Entry')): ?>
            <div class="sub" onclick="parent.location='<?php echo $base_url; ?>billspayment/settlement/adjustment-entry-per-branch.php'">
                <a href="<?php echo $base_url; ?>billspayment/settlement/adjustment-entry-per-branch.php"><i class="fa-solid fa-chart-column"></i> Adjustment Entry</a>
            </div>
            <?php endif; ?>
            <?php if (has_permission('BP Settlement Per Bank')): ?>
            <div class="sub" onclick="parent.location='<?php echo $base_url; ?>billspayment/settlement/settlement-per-bank.php'">
                <a href="<?php echo $base_url; ?>billspayment/settlement/settlement-per-bank.php"><i class="fa-solid fa-chart-column"></i> Per Bank</a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (has_any_permission(['BP Report Volume','BP Report EDI','BP Report Transaction Details','BP Report Transaction Summary','BP Report Cancellation','BP Report Balance Sheet'])): ?>
        <div class="tabcat" id="para-report-btn" style="display: none;">
            <h6><i class="fa-solid fa-chart-line"></i> Report</h6>
            <i class="fa-solid fa-chevron-right" id="closed-para-report" style="display: block"></i>
            <i class="fa-solid fa-chevron-down" id="open-para-report" style="display: none"></i>
        </div>
        <div class="onetab-sub" id="para-report-nav" style="display: none;">
            <!-- <div class="sub" onclick="parent.location='<?php //echo $base_url; ?>billspayment/report/daily-volume.php'">
                <a href="<?php //echo $base_url; ?>billspayment/report/daily-volume.php">Volume Report</a>
            </div> -->
            <?php if (has_permission('BP Report Volume')): ?>
            <div class="sub" onclick="parent.location='<?php echo $base_url; ?>billspayment/report/volume-report.php'">
                <a href="<?php echo $base_url; ?>billspayment/report/volume-report.php"><i class="fa-solid fa-chart-column"></i> Volume Report</a>
            </div>
            <?php endif; ?>
            <?php if (has_permission('BP Report EDI')): ?>
            <div class="sub" onclick="parent.location='<?php echo $base_url; ?>billspayment/report/edi-report.php'">
                <a href="<?php echo $base_url; ?>billspayment/report/edi-report.php"><i class="fa-solid fa-file-lines"></i> EDI Report</a>
            </div>
            <?php endif; ?>
            <?php if (has_permission('BP Report Billers')): ?>
            <div class="sub" onclick="parent.location='<?php echo $base_url; ?>billspayment/report/billers-report.php'">
                <a href="<?php echo $base_url; ?>billspayment/report/billers-report.php"><i class="fa-solid fa-file-invoice"></i> Billers Report</a>
            </div>
            <?php endif; ?>
            <?php if (has_permission('BP Report Transaction Details')): ?>
            <div class="sub" onclick="parent.location='<?php echo $base_url; ?>billspayment/report/transaction-report.php'">
                <a href="<?php echo $base_url; ?>billspayment/report/transaction-report.php"><i class="fa-solid fa-list-check"></i> Transaction Report (Details)</a>
            </div>
            <?php endif; ?>
            <?php if (has_permission('BP Report Transaction Summary')): ?>
            <div class="sub" onclick="parent.location='<?php echo $base_url; ?>billspayment/report/transaction-summary.php'">
                <a href="<?php echo $base_url; ?>billspayment/report/transaction-summary.php"><i class="fa-solid fa-table"></i> Transaction Report (Summary)</a>
            </div>
            <?php endif; ?>
            <!-- <div class="sub">
                <a href="#" id="transaction-report-summary-link">Transaction Report (Summary)</a>
            </div> -->
            
            <?php if (has_permission('BP Report Cancellation')): ?>
            <div class="sub" onclick="parent.location='<?php echo $base_url; ?>billspayment/report/cancellation-report.php'">
                <a href="<?php echo $base_url; ?>billspayment/report/cancellation-report.php" id="cancellation-report-link"><i class="fa-solid fa-circle-xmark"></i> Cancellation Report</a>
            </div>
            <?php endif; ?>
            <?php if (has_permission('BP Report Balance Sheet')):?>
                <div class="sub" onclick="parent.location='<?php echo $base_url; ?>billspayment/report/balance-sheet-report.php'">
                    <a href="<?php echo $base_url; ?>billspayment/report/balance-sheet-report.php" id="balance-sheet-report-link"><i class="fa-solid fa-chart-bar"></i> Balance Sheet Report</a>
                </div>
            <?php endif;?>
            <?php if (has_permission('BP Report Recon')): ?>
                <div class="sub" onclick="parent.location='<?php echo $base_url; ?>billspayment/report/recon-report.php'">
                    <a href="<?php echo $base_url; ?>billspayment/report/recon-report.php" id="recon-report-link"><i class="fa-solid fa-file-chart-line"></i> Recon Report</a>
                </div>
            <?php endif; ?>
            <!-- <div class="sub" onclick="parent.location='<?php //echo $base_url; ?>billspayment/report/monthly-volume.php'">
                <a href="<?php //echo $base_url; ?>billspayment/report/monthly-volume.php">Monthly Volume Report</a>
            </div> -->
            <!-- <div class="sub" onclick="parent.location='<?php //echo $base_url; ?>date/date-filter-billsPayment.php'">
                <a href="<?php //echo $base_url; ?>date/date-filter-billsPayment.php">BP Transaction (Cancelled and Good)</a>
            </div>
            <div class="sub" onclick="parent.location='<?php //echo $base_url; ?>date/date-good-only.php'">
                <a href="<?php //echo $base_url; ?>date/date-good-only.php">BP Transaction (Good Only)</a>
            </div>
            <div class="sub" onclick="parent.location='<?php //echo $base_url; ?>date/date-cancelled-only.php'">
                <a href="<?php //echo $base_url; ?>date/date-cancelled-only.php">BP Transaction (Cancelled Only)</a>
            </div>
            <div class="sub" onclick="parent.location='<?php //echo $base_url; ?>date/date-duplicate-report.php'">
                <a href="<?php //echo $base_url; ?>date/date-duplicate-report.php">BP Transaction (Duplicate/Split Transaction)</a>
            </div> -->
        </div>

        <?php endif; ?>
        <?php endif; ?>

        <?php if (has_any_permission(['TRL Import','TRL Entry','TRL Review','TRL Report','TRL Ticket Entry'])): ?>
        <!-- Billspayment - TRL (Transaction Request Log) - top-level menu -->
        <div class="onetab" id="bp-trl-btn">
            <h6><i class="fa-solid fa-list"></i> Billspayment - TRL</h6>
            <i class="fa-solid fa-chevron-right" id="closed-bp-trl" style="display: block"></i>
            <i class="fa-solid fa-chevron-down" id="open-bp-trl" style="display: none"></i>
        </div>

        <div class="onetab-sub" id="bp-trl-nav" style="display: none;">
            <?php if (has_permission('TRL Import')): ?>
            <div class="sub" onclick="parent.location='<?php echo $auth_url; ?>dashboard/trl/trl-import/trl-import.php'">
                <a href="<?php echo $auth_url; ?>dashboard/trl/trl-import/trl-import.php"><i class="fa-solid fa-file-import"></i> TRL - Import</a>
            </div>
            <?php endif; ?>

            <?php if (has_permission('TRL Entry')): ?>
            <div class="sub" onclick="parent.location='<?php echo $auth_url; ?>dashboard/trl/trl-entry/trl-entry.php'">
                <a href="<?php echo $auth_url; ?>dashboard/trl/trl-entry/trl-entry.php"><i class="fa-solid fa-pen-to-square"></i> TRL - Entry</a>
            </div>
            <?php endif; ?>

            <?php if (has_permission('TRL Ticket Entry')): ?>
            <div class="sub" onclick="parent.location='<?php echo $auth_url; ?>dashboard/trl/trl-ticket_entry/trl-entry_ticket.php'">
                <a href="<?php echo $auth_url; ?>dashboard/trl/trl-ticket_entry/trl-entry_ticket.php"><i class="fa-solid fa-ticket"></i> TRL - Ticket Entry</a>
            </div>
            <?php endif; ?>

            <?php if (has_permission('TRL Review')): ?>
            <div class="sub" onclick="parent.location='<?php echo $auth_url; ?>dashboard/trl/trl-review/trl-review.php'">
                <a href="<?php echo $auth_url; ?>dashboard/trl/trl-review/trl-review.php"><i class="fa-solid fa-clipboard-check"></i> TRL - Review</a>
            </div>
            <?php endif; ?>

            <?php if (has_permission('TRL Report')): ?>
            <div class="sub" onclick="parent.location='<?php echo $auth_url; ?>dashboard/trl/trl-report/trl-report.php'">
                <a href="<?php echo $auth_url; ?>dashboard/trl/trl-report/trl-report.php"><i class="fa-solid fa-chart-column"></i> TRL - Report</a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- <div class="tabcat" id="action-report-btn" style="display: none;">
            <i class="fa-solid fa-chevron-right" id="closed-action-report" style="display: block"></i>
            <i class="fa-solid fa-chevron-down" id="open-action-report" style="display: none"></i>
            <h6>Action Taken / Log Files</h6>
        </div> -->

        <!-- Action Log submenu removed as requested -->

    

        
        <!-- Show/Hide Billing Invoice (main) -->
        <?php if (has_any_permission(['BI Create Manual', 'BI Create Automated', 'Invoice Review', 'Invoice Approval', 'BI Report Billing Invoice'])): ?>
        <div class="onetab" id="soa-btn">
            <h6><i class="fa-solid fa-file-invoice-dollar"></i> Billing Invoice</h6>
            <i class="fa-solid fa-chevron-right" id="closed-soa" style="display: block"></i>
            <i class="fa-solid fa-chevron-down" id="open-soa" style="display: none"></i>
        </div>
        <?php endif; ?>
        <!-- Show/Hide soa create Sub-menu -->
        <?php if (has_any_permission(['BI Create Manual', 'BI Create Automated'])): ?>
            <div class="tabcat" id="soa-create-btn" style="display: none;">
                <h6><i class="fa-solid fa-plus-circle"></i> Create</h6>
                <i class="fa-solid fa-chevron-right" id="closed-soa-create" style="display: block"></i>
                <i class="fa-solid fa-chevron-down" id="open-soa-create" style="display: none"></i>
            </div>
        <?php endif; ?>

        <!-- soa create Buttons -->
        <?php if (has_any_permission(['BI Create Manual', 'BI Create Automated'])): ?>
        <div class="onetab-sub" id="soa-create-nav" style="display: none;">
            <?php if (has_permission('BI Create Manual')): ?>
            <div class="sub" onclick="parent.location='<?php echo $base_url; ?>billspayment-soa/create/billing-service-charge.php'">
                <a href="<?php echo $base_url; ?>billspayment-soa/create/billing-service-charge.php"><i class="fa-solid fa-hand-holding-dollar"></i> Service Charge (MANUAL)</a>
            </div>
            <?php endif; ?>
            <!-- recycle if needed -->
            <!-- <div class="sub">
                <a href="#" id="service-charge-automate-link">Service Charge (AUTOMATED)</a>
            </div> -->
			
            <?php if (has_permission('BI Create Automated')): ?>
            <div class="sub" onclick="parent.location='<?php echo $base_url; ?>billspayment-soa/create/billing-invoice-service-charge_automated.php'">
                <a href="<?php echo $base_url; ?>billspayment-soa/create/billing-invoice-service-charge_automated.php"><i class="fa-solid fa-gears"></i> Service Charge (AUTOMATED)</a>
            </div>
            <?php endif; ?>

        </div>
        <?php endif; ?>

        <?php if (has_permission('Invoice Review')): ?>
            <!-- Show/Hide soa review Sub-menu -->
            <div class="tabcat" id="soa-review-btn" style="display: none;">
                <h6><i class="fa-solid fa-clipboard-check"></i> Review</h6>
                <i class="fa-solid fa-chevron-right" id="closed-soa-review" style="display: block"></i>
                <i class="fa-solid fa-chevron-down" id="open-soa-review" style="display: none"></i>
            </div>
        <?php endif; ?>

        <!-- soa review Buttons -->
        <?php if (has_permission('Invoice Review')): ?>
        <div class="onetab-sub" id="soa-review-nav" style="display: none;">
            <div class="sub" onclick="parent.location='<?php echo $base_url; ?>billspayment-soa/review/for-checking-review.php'">
                <a href="<?php echo $base_url; ?>billspayment-soa/review/for-checking-review.php"><i class="fa-solid fa-magnifying-glass-chart"></i> For Checking / Review</a>
            </div>
        </div>
        <?php endif; ?>

        <?php if (has_permission('Invoice Approval')): ?>
            <!-- Show/Hide soa approval Sub-menu -->
            <div class="tabcat" id="soa-approval-btn" style="display: none;">
                <h6><i class="fa-solid fa-certificate"></i> Approval</h6>
                <i class="fa-solid fa-chevron-right" id="closed-soa-approval" style="display: block"></i>
                <i class="fa-solid fa-chevron-down" id="open-soa-approval" style="display: none"></i>
            </div>

            <!-- soa approval Buttons -->
            <div class="onetab-sub" id="soa-approval-nav" style="display: none;">
                <div class="sub" onclick="parent.location='<?php echo $base_url; ?>billspayment-soa/approval/soa-approval.php'">
                    <a href="<?php echo $base_url; ?>billspayment-soa/approval/soa-approval.php"><i class="fa-solid fa-check-double"></i> Billing Invoice Approval</a>
                </div>
            </div>
        <?php endif; ?>

        <?php if (has_permission('BI Report Billing Invoice')): ?>
        <div class="tabcat" id="soa-report-btn" style="display: none;">
            <h6><i class="fa-solid fa-chart-pie"></i> Report</h6>
            <i class="fa-solid fa-chevron-right" id="closed-soa-report" style="display: block"></i>
            <i class="fa-solid fa-chevron-down" id="open-soa-report" style="display: none"></i>
        </div>

        <!-- soa report Buttons -->
        <div class="onetab-sub" id="soa-report-nav" style="display: none;">
            <div class="sub" onclick="parent.location='<?php echo $base_url; ?>billspayment-soa/report/soa-report.php'">
                <a href="<?php echo $base_url; ?>billspayment-soa/report/soa-report.php"><i class="fa-solid fa-file-contract"></i> Billing Invoice Report</a>
            </div>
        </div>
        <?php endif; ?>

        <?php if (has_any_permission(['Masterfiles View Partner List','Masterfiles View Bank List'])): ?>
        <!-- Show/Hide Set Masterfiles Main-menu -->
        <div class="onetab" id="masterfiles-btn">
            <h6><i class="fa-solid fa-layer-group"></i> Masterfiles</h6>
            <i class="fa-solid fa-chevron-right" id="closed-masterfiles" style="display: block"></i>
            <i class="fa-solid fa-chevron-down" id="open-masterfiles" style="display: none"></i>
        </div>

        <!-- Show/Hide Set Masterfiles Sub-menu -->
        <div class="tabcat" id="set-masterfiles-btn" style="display: none;">
            <h6><i class="fa-solid fa-eye"></i> View</h6>
            <i class="fa-solid fa-chevron-right" id="closed-set-masterfiles" style="display: block"></i>
            <i class="fa-solid fa-chevron-down" id="open-set-masterfiles" style="display: none"></i>
        </div>

        <!-- Set Masterfiles Partner List Buttons -->
        <!-- <div class="onetab-sub" id="set-masterfile-partner-nav" style="display: none;">
            <div class="sub" onclick="parent.location='<?php //echo $base_url; ?>masterfiles/masterfiles/masterfile-partner-list.php'">
                <a href="<?php //echo $base_url; ?>masterfiles/masterfiles/masterfile-partner-list.php"><i class="fa-solid fa-receipt"></i> Partner List</a>
            </div>
        </div> -->
        <!-- Set Masterfiles Bank List Buttons -->
        <div class="onetab-sub" id="set-masterfile-bank-nav" style="display: none;">
            <?php if (has_permission('Masterfiles View Partner List')): ?>
            <div class="sub" onclick="parent.location='<?php echo $base_url; ?>masterfiles/view/view-partner-list.php'">
                <a href="<?php echo $base_url; ?>masterfiles/view/view-partner-list.php" id="partner-list-link"><i class="fa-solid fa-receipt"></i> Partner List</a>
            </div>
            <?php endif; ?>
            <?php if (has_permission('Masterfiles View Bank List')): ?>
            <div class="sub" onclick="parent.location='<?php echo $base_url; ?>masterfiles/view/view-bank-list.php'">
                <a href="<?php echo $base_url; ?>masterfiles/view/view-bank-list.php"><i class="fa-solid fa-receipt"></i> Bank List</a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (has_any_permission(['Support Ticket Create','Support Ticket VPO','Support Ticket CAD','Support Ticket Report'])): ?>
            <div class="onetab" id="support-ticket-btn">
                <h6><i class="fa-solid fa-ticket-simple"></i> Support Ticket</h6>
                <i class="fa-solid fa-chevron-right" id="closed-support-ticket" style="display: block"></i>
                <i class="fa-solid fa-chevron-down" id="open-support-ticket" style="display: none"></i>
            </div>

            <div class="onetab-sub" id="support-ticket-nav" style="display: none;">
                <?php if (has_permission('Support Ticket Create')): ?>
                <div class="sub" onclick="parent.location='<?php echo $base_url; ?>support_ticket/create-ticket.php'">
                    <a href="<?php echo $base_url; ?>support_ticket/create-ticket.php"><i class="fa-solid fa-plus"></i> Create Ticket</a>
                </div>
                <?php endif; ?>
                <?php if (has_permission('Support Ticket VPO')): ?>
                <div class="sub" onclick="parent.location='<?php echo $base_url; ?>support_ticket/bpo-ticket.php'">
                    <a href="<?php echo $base_url; ?>support_ticket/bpo-ticket.php"><i class="fa-solid fa-headset"></i> VPO Ticket</a>
                </div>
                <?php endif; ?>
                <?php if (has_permission('Support Ticket CAD')): ?>
                <div class="sub" onclick="parent.location='<?php echo $base_url; ?>support_ticket/cad-ticket.php'">
                    <a href="<?php echo $base_url; ?>support_ticket/cad-ticket.php"><i class="fa-solid fa-tools"></i> CAD Ticket</a>
                </div>
                <?php endif; ?>

                <?php if (has_permission('Support Ticket Report')): ?>
                <div class="sub" onclick="parent.location='<?php echo $base_url; ?>support_ticket/ticket-report.php'">
                    <a href="<?php echo $base_url; ?>support_ticket/ticket-report.php"><i class="fa-solid fa-chart-line"></i> Ticket Report</a>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (has_any_permission(['Accounts','Maintenance Accounts User Management','Maintenance Accounts Access Levels','Maintenance Duplicate Transaction','Maintenance Masterfiles Partner List','Maintenance Masterfiles Bank List','Support Ticket Report','Maintenance Support Ticket'])): ?>
            <!-- Show/Hide Set Maintenance Main-menu -->
            <div class="onetab" id="set-btn">
            <h6><i class="fa-solid fa-wrench"></i> Maintenance</h6>
            <i class="fa-solid fa-chevron-right" id="closed-set" style="display: block"></i>
            <i class="fa-solid fa-chevron-down" id="open-set" style="display: none"></i>
            </div>

            <!-- Show/Hide Set maintenance Sub-menu -->
            <?php if (has_any_permission(['Accounts','Maintenance Accounts User Management','Maintenance Accounts Access Levels'])): ?>
            <div class="tabcat" id="set-maintenance-btn" style="display: none;">
                <h6><i class="fa-solid fa-users-gear"></i> Accounts</h6>
                <i class="fa-solid fa-chevron-right" id="closed-set-maintenance" style="display: block"></i>
                <i class="fa-solid fa-chevron-down" id="open-set-maintenance" style="display: none"></i>
            </div>
            <?php endif; ?>

            <!-- Set Maintenance Buttons -->
            <div class="onetab-sub" id="set-maintenance-nav" style="display: none;">
                <?php if (has_permission('Maintenance Accounts User Management')): ?>
                <div class="sub" onclick="parent.location='<?php echo $base_url; ?>maintenance/accounts/user-management.php'">
                    <a href="<?php echo $base_url; ?>maintenance/accounts/user-management.php"><i class="fa-solid fa-user-cog"></i> User Management</a>
                </div>
                <?php endif; ?>
                <?php if (has_permission('Maintenance Accounts User Signature')): ?>
                <div class="sub" onclick="parent.location='<?php echo $base_url; ?>maintenance/accounts/user-signature.php'">
                    <a href="<?php echo $base_url; ?>maintenance/accounts/user-signature.php"><i class="fa-solid fa-signature"></i> User Signature</a>
                </div>
                <?php endif; ?>
                <?php if (has_permission('Maintenance Accounts Access Levels')): ?>
                <div class="sub" onclick="parent.location='<?php echo $base_url; ?>maintenance/accounts/accesslevels.php'">
                    <a href="<?php echo $base_url; ?>maintenance/accounts/accesslevels.php"><i class="fa-solid fa-key"></i> Access Levels</a>
                </div>
                <?php endif; ?>
            </div>

            <?php if (has_permission('Maintenance Duplicate Transaction')): ?>
            <!-- Show/Hide Set duplicates Sub-menu -->
            <div class="tabcat" id="set-duplicate-btn" style="display: none;">
                <h6><i class="fa-solid fa-code-compare"></i> Duplicate</h6>
                <i class="fa-solid fa-chevron-right" id="closed-set-duplicate" style="display: block"></i>
                <i class="fa-solid fa-chevron-down" id="open-set-duplicate" style="display: none"></i>
            </div>

            <!-- Set Duplicate Buttons -->
            <div class="onetab-sub" id="set-duplicate-nav" style="display: none;">
                <div class="sub" onclick="parent.location='<?php echo $base_url; ?>billspayment/import/duplicate-transaction.php'">
                    <a href="<?php echo $base_url; ?>billspayment/import/duplicate-transaction.php"><i class="fa-solid fa-receipt"></i> Transaction</a>
                </div>
            </div>
            <?php endif; ?>

            <?php if (has_any_permission(['Maintenance Masterfiles Partner List','Maintenance Masterfiles Bank List'])): ?>
            <!-- Show/Hide Set masterfiles Sub-menu -->
            <div class="tabcat" id="set-masterfile-btn" style="display: none;">
                <h6><i class="fa-solid fa-code-compare"></i> Masterfiles</h6>
                <i class="fa-solid fa-chevron-right" id="closed-set-masterfile" style="display: block"></i>
                <i class="fa-solid fa-chevron-down" id="open-set-masterfile" style="display: none"></i>
            </div>

            <!-- Set Masterfiles submenu items -->
            <div class="onetab-sub" id="set-masterfile-nav" style="display: none;">
                <?php if (has_permission('Maintenance Masterfiles Partner List')): ?>
                <div class="sub" onclick="parent.location='<?php echo $base_url; ?>maintenance/masterfiles/masterfile-partner-list.php'">
                    <a href="<?php echo $base_url; ?>maintenance/masterfiles/masterfile-partner-list.php"><i class="fa-solid fa-receipt"></i> Partner List</a>
                </div>
                <?php endif; ?>
                <?php if (has_permission('Maintenance Masterfiles Bank List')): ?>
                <div class="sub" onclick="parent.location='<?php echo $base_url; ?>maintenance/masterfiles/masterfile-bank-list.php'">
                    <a href="<?php echo $base_url; ?>maintenance/masterfiles/masterfile-bank-list.php"><i class="fa-solid fa-receipt"></i> Bank List</a>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <?php if (has_any_permission(['Support Ticket Report','Maintenance Support Ticket'])): ?>
            <!-- Show/Hide Set support ticket Sub-menu -->
            <div class="tabcat" id="set-support-ticket-btn" style="display: none;">
                <h6><i class="fa-solid fa-ticket-simple"></i> Support Ticket</h6>
                <i class="fa-solid fa-chevron-right" id="closed-set-support-ticket" style="display: block"></i>
                <i class="fa-solid fa-chevron-down" id="open-set-support-ticket" style="display: none"></i>
            </div>

            <!-- Set Support Ticket Buttons -->
            <div class="onetab-sub" id="set-support-ticket-nav" style="display: none;">
                <div class="sub" onclick="parent.location='<?php echo $base_url; ?>maintenance/ticket/ticket-managment.php'">
                    <a href="<?php echo $base_url; ?>maintenance/ticket/ticket-managment.php"><i class="fa-solid fa-ticket"></i> Tickets</a>
                </div>
            </div>
            <?php endif; ?>

        <!-- Tools Menu -->
        <?php if (has_any_permission(['Tools KPX Generator','Tools Branch Maker','Tools File Fetch'])): ?>
        <div class="onetab" id="tools-btn">
            <h6><i class="fa-solid fa-tools"></i> Tools</h6>
            <i class="fa-solid fa-chevron-right" id="closed-tools" style="display: block"></i>
            <i class="fa-solid fa-chevron-down" id="open-tools" style="display: none"></i>
        </div>

        <!-- Tools Submenu -->
        <div class="onetab-sub" id="tools-nav" style="display: none;">
                <?php if (has_permission('Tools KPX Generator')): ?>
                <div class="sub">
                    <a href="<?php echo $auth_url; ?>mlauto/index.html" target="_blank" rel="noopener noreferrer">KPX/KP7 Generator</a>
            </div>
                <?php endif; ?>
                <?php if (has_permission('Tools Branch Maker')): ?>
                <div class="sub">
                    <a href="<?php echo $auth_url; ?>mlbranchmaker/convert.html" target="_blank" rel="noopener noreferrer">Branch Maker</a>
            </div>
                <?php endif; ?>
                <?php if (has_permission('Tools File Fetch')): ?>
                <div class="sub">
                    <a href="<?php echo $auth_url; ?>recontool/sample.html" target="_blank" rel="noopener noreferrer">File Fetch</a>
            </div>
                <?php endif; ?>
                <?php if (has_permission('Tools Excel Unlock Password')): ?>
                <div class="sub">
                    <a href="<?php echo $auth_url; ?>exceldecryptpassword/index.html" target="_blank" rel="noopener noreferrer">Excel Unlock Password Generator</a>
            </div>
                <?php endif; ?>
                
        </div>
        <?php endif; ?>

        <!-- Logout Button -->
        <div class="onetab" onclick="parent.location='<?php echo $auth_url; ?>logout.php'">
        <a href="<?php echo $auth_url; ?>logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        </div>
    </div>
<?php else: ?>
    <?php header("Location:" . $auth_url); session_destroy(); exit(); ?>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// Function to show under construction alert
function showUnderConstructionAlert() {
    Swal.fire({
        icon: 'info',
        title: 'Feature Under Development',
        text: 'This feature is currently under construction. Please check back later!',
        confirmButtonText: 'Got it!',
        confirmButtonColor: '#3085d6'
    });
}

// Array of IDs for features under construction
const underConstructionIds = [
    'cancellation-link',
    'post-transaction-link',
    'settle-transaction-link',
    'transaction-report-summary-link',
    'service-charge-automate-link'
];

// Add event listeners to all under construction features
(function onReady(handler){
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', handler);
    } else {
        handler();
    }
})(function() {
    underConstructionIds.forEach(function(id) {
        const element = document.getElementById(id);
        if (element) {
            // Add event listener to both the link and its parent div
            element.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation(); // Prevent parent onclick from firing
                showUnderConstructionAlert();
            });
            
            // Also add to parent div if it exists
            const parentDiv = element.closest('.sub');
            if (parentDiv) {
                parentDiv.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    showUnderConstructionAlert();
                });
            }
        }
    });

    // Active state management based on current URL
    const currentPath = window.location.pathname;

    function normalizePath(path) {
        if (!path) return '/';
        return path
            .replace(/\/+/g, '/')
            .replace(/\/$/, '')
            .toLowerCase() || '/';
    }

    function setArrowExpanded(menuElement, expanded) {
        if (!menuElement) {
            return;
        }
        const openIcon = menuElement.querySelector('[id^="open-"]');
        const closedIcon = menuElement.querySelector('[id^="closed-"]');
        if (openIcon) openIcon.style.display = expanded ? 'block' : 'none';
        if (closedIcon) closedIcon.style.display = expanded ? 'none' : 'block';
    }

    function findPreviousByClass(startNode, className) {
        let pointer = startNode ? startNode.previousElementSibling : null;
        while (pointer) {
            if (pointer.classList && pointer.classList.contains(className)) {
                return pointer;
            }
            pointer = pointer.previousElementSibling;
        }
        return null;
    }

    function clearAllActiveStates() {
        document.querySelectorAll('.sub.active, .tabcat.active, .onetab.active').forEach(function(node) {
            node.classList.remove('active');
        });
    }

    function activateMatchedSubLink(link) {
        if (!link) {
            return;
        }

        const subItem = link.closest('.sub');
        const parentNav = link.closest('.onetab-sub');

        if (subItem) {
            subItem.classList.add('active');
        }

        if (parentNav) {
            parentNav.style.display = 'block';
        }

        // Only bind to the nearest direct sibling tab category.
        // Using a broad previous search can accidentally pick a tabcat
        // from another top-level menu (e.g., Bills Payment Report while in TRL).
        const immediatePrev = parentNav ? parentNav.previousElementSibling : null;
        const parentTab = (immediatePrev && immediatePrev.classList && immediatePrev.classList.contains('tabcat'))
            ? immediatePrev
            : null;
        if (parentTab) {
            parentTab.style.display = 'flex';
            parentTab.classList.add('active');
            setArrowExpanded(parentTab, true);
        }

        // Resolve top-level parent from local structure first.
        let mainParent = null;
        if (parentTab) {
            mainParent = findPreviousByClass(parentTab, 'onetab');
        } else if (immediatePrev && immediatePrev.classList && immediatePrev.classList.contains('onetab')) {
            mainParent = immediatePrev;
        } else {
            mainParent = findPreviousByClass(parentNav, 'onetab');
        }
        if (mainParent) {
            mainParent.classList.add('active');
            setArrowExpanded(mainParent, true);
        }
    }

    const normalizedCurrentPath = normalizePath(currentPath);

    setTimeout(function() {
        clearAllActiveStates();

        const subMatches = [];

        document.querySelectorAll('.sub a').forEach(function(link) {
            const rawHref = (link.getAttribute('href') || '').trim();
            if (!rawHref || rawHref === '#' || rawHref.toLowerCase().startsWith('javascript:')) {
                return;
            }

            let normalizedLinkPath = '';
            try {
                normalizedLinkPath = normalizePath(new URL(link.href, window.location.origin).pathname);
            } catch (e) {
                return;
            }

            if (normalizedLinkPath === normalizedCurrentPath) {
                subMatches.push({ link: link, path: normalizedLinkPath });
            }
        });

        if (subMatches.length > 0) {
            subMatches.sort(function(a, b) {
                return b.path.length - a.path.length;
            });
            activateMatchedSubLink(subMatches[0].link);
            return;
        }

        // Check for direct onetab links (like Home) only when no sub-link matched
        let directMatchFound = false;
        document.querySelectorAll('.onetab a').forEach(function(link) {
            if (directMatchFound) {
                return;
            }

            const rawHref = (link.getAttribute('href') || '').trim();
            if (!rawHref || rawHref === '#' || rawHref.toLowerCase().startsWith('javascript:')) {
                return;
            }

            let normalizedLinkPath = '';
            try {
                normalizedLinkPath = normalizePath(new URL(link.href, window.location.origin).pathname);
            } catch (e) {
                return;
            }

            if (normalizedLinkPath === normalizedCurrentPath) {
                const onetab = link.closest('.onetab');
                if (onetab) {
                    onetab.classList.add('active');
                    setArrowExpanded(onetab, true);
                    directMatchFound = true;
                }
            }
        });

        // Special case for home.php
        if (!directMatchFound && (normalizedCurrentPath.endsWith('/home.php') || normalizedCurrentPath === '/')) {
            const homeBtn = document.querySelector('.onetab a[href*="home.php"]');
            if (homeBtn && homeBtn.closest('.onetab')) {
                homeBtn.closest('.onetab').classList.add('active');
            }
        }
    }, 0);
});
</script>
