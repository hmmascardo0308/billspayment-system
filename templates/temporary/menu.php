<?php //if (isset($_SESSION['user_type']) && ($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user')): ?>
<?php
include_once __DIR__ . '/../middleware.php';
?>
<?php if (isset($_SESSION['user_type']) && ($_SESSION['user_type'] === 'admin')): ?>
    <div class="btn-nav">
        <ul class="nav-list">
            <li><a href="admin_page.php">HOME</a></li>
            <?php if (has_permission('Bills Payment')): ?>
            <li class="dropdown">
                <button class="dropdown-btn">Import File</button>
                <div class="dropdown-content">
                    <a id="user" href="billspaymentImportFile.php">BILLSPAYMENT TRANSACTION</a>
                    <a id="user" href="billspaymentImportFileCancellation.php">BILLSPAYMENT CANCELLATION</a>
                    <a id="user" href="import_billspaymentfeedback.php">BILLSPAYMENT FEEDBACK</a>
                </div>
            </li>
            <?php endif; ?>
            <!-- <li class="dropdown">
                <button class="dropdown-btn">Transaction</button>
                <div class="dropdown-content">
                <a id="user" href="billspaymentSettlement.php">SETTLEMENT</a>
                <a id="user" href="#">RECONCILIATION</a>
                </div>
            </li> -->
            <?php if (has_permission('Bills Payment')): ?>
            <li class="dropdown">
                <button class="dropdown-btn">Post Transaction</button>
                <div class="dropdown-content">
                <a id="user" href="post_billspayment-transaction.php">BILLSPAYMENT TRANSACTION</a>
                </div>
            </li>
            <?php endif; ?>
            <?php if (has_permission('Bills Payment')): ?>
            <li class="dropdown">
                <button class="dropdown-btn">Reports</button>
                <div class="dropdown-content">
                    <!-- <a id="user" href="billspaymentReport.php">BILLS PAYMENT</a> -->
                    <a id="user" href="dailyVolume.php">BILLSPAY DAILY VOLUME</a>
                    <a id="user" href="billspay_recon.php">BILLSPAY RECON & VARIANCE</a>
                    <a id="user" href="billspay_transaction.php">BILLSPAY TRANSACTION</a>
                    <a id="user" href="#">BILLSPAY FEEDBACK</a>
                    <a id="user" href="billspay_cancellation.php">BILLSPAY CANCELLATION</a>
                </div>
            </li>
            <?php endif; ?>
            <!-- <li class="dropdown">
                <button class="dropdown-btn">MAINTENANCE</button>
                <div class="dropdown-content">
                <a id="user" href="userLog.php">USER</a>
                <a id="user" href="partnerLog.php">PARTNER</a>
                <a id="user" href="natureOfBusinessLog.php">NATURE OF BUSINESS</a>
                <a id="user" href="bankLog.php">BANK</a>
                </div>
            </li> -->
            <li>
                <a href="../login_form.php">LOGOUT</a>
            </li>
        </ul>
    </div>
<?php elseif (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'user'):?>
    <div id="sidemenu" class="sidemenu" style="display: none;">

        <!-- Home Button -->
        <div class="onetab" onclick="parent.location='user_page.php'">
        <a href="user_page.php">Home</a>
        </div>

        <!-- Show/Hide Paramount -->
        <div class="onetab" id="para-btn">
        <i class="fa-solid fa-caret-right" id="closed-para" style="display: block"></i>
        <i class="fa-solid fa-caret-down" id="open-para" style="display: none"></i>
        <h4>Billspayment</h4>
        </div>

        <!-- Show/Hide Paramount Import -->
        <div class="tabcat" id="para-import-btn" style="display: none;">
            <i class="fa-solid fa-chevron-right" id="closed-para-import" style="display: block"></i>
            <i class="fa-solid fa-chevron-down" id="open-para-import" style="display: none"></i>
            <h4>Billspayment Import</h4>
        </div>

        <!-- Paramount Import Buttons -->
        <div class="onetab-sub" id="para-import-nav" style="display: none;">
            <div class="sub" onclick="parent.location='billsPayment.php'">
                <a href="billsPayment.php">Import</a>
            </div>
            <div class="sub" onclick="parent.location='billsFeedback.php'">
                <a href="billsFeedback.php">Feedback</a>
            </div>
        </div>

        <!-- Show/Hide Paramount Report -->
        <div class="tabcat" id="para-report-btn" style="display: none;">
            <i class="fa-solid fa-chevron-right" id="closed-para-report" style="display: block"></i>
            <i class="fa-solid fa-chevron-down" id="open-para-report" style="display: none"></i>
            <h4>Billspayment Report</button>
        </div>

        <!-- Paramount Report Buttons -->
        <div class="onetab-sub" id="para-report-nav" style="display: none;">
        <div class="sub" onclick="parent.location='daily_report.php'">
            <a href="daily_report.php">Daily Report</a>
        </div>
        <div class="sub" onclick="parent.location='#'">
            <a href="#">Monthly Report</a>
        </div>
        <div class="sub" onclick="parent.location='date/date-filter-billsPayment.php'">
            <a href="date/date-filter-billsPayment.php">BP Transaction (Cancelled and Good)</a>
        </div>
        <div class="sub" onclick="parent.location='date/date-good-only.php'">
            <a href="date/date-good-only.php">BP Transaction (Good Only)</a>
        </div>
        <div class="sub" onclick="parent.location='date/date-cancelled-only.php'">
            <a href="date/date-cancelled-only.php">BP Transaction (Cancelled Only)</a>
        </div>
        <div class="sub" onclick="parent.location='date/date-duplicate-report.php'">
            <a href="date/date-duplicate-report.php">BP Transaction (Duplicate/Split Transaction)</a>
        </div>
        </div>

        <div class="tabcat" id="action-report-btn" style="display: none;">
            <i class="fa-solid fa-chevron-right" id="closed-action-report" style="display: block"></i>
            <i class="fa-solid fa-chevron-down" id="open-action-report" style="display: none"></i>
            <h4>Action Taken / Log Files</button>
        </div>

        <div class="onetab-sub" id="action-report-nav" style="display: none;">
        <div class="sub" onclick="parent.location='ActionLog.php'">
            <a href="ActionLog.php">Add Logs</a>
        </div>
        <div class="sub" onclick="parent.location='actionLogReport.php'">
            <a href="actionLogReport.php">Action Log Reports</a>
        </div>
        </div>

        <!-- Show/Hide MAA -->
        <div class="onetab" id="maa-btn">
        <i class="fa-solid fa-caret-right" id="closed-maa" style="display: block"></i>
        <i class="fa-solid fa-caret-down" id="open-maa" style="display: none"></i>
        <h4>Bookkeeper</h4>
        </div>

        <div class="onetab-sub" id="maa-nav" style="display: none;">
        <div class="sub" onclick="parent.location='#'">
            <a href="#">Bookkeeper Import</a>
        </div>
        <div class="sub" onclick="parent.location='#'">
            <a href="#">Book keeper Report</a>
        </div>
        </div>

        <div class="onetab" onclick="parent.location='../logout.php'">
        <a href="../login_form.php">Logout</a>
        </div>

    </div>
<?php else: ?>
    <?php include '../login_form.php'; ?>
<?php endif; ?>