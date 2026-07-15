<?php
$searchTicket = trim((string) ($_GET['search_ticket'] ?? ''));
$ticketFound = null;
$ticketWrongBiller = null;
$ticketOverstated = null;
$ticketCancelled = null;
$ticketError = '';
$ticketGuardToast = '';

if ($searchTicket !== '') {
    $sql = "SELECT
                t.ticket_number,
                t.reference_number,
                t.vpo_owner,
                ti.reason,
                ti.transfer_datetime,
                ti.wrong_biller_id,
                ti.biller_name,
                ti.account_no,
                ti.account_name,
                ti.payment_branch_id,
                ti.payment_branch_name,
                ti.amount,
                ti.type_of_request
            FROM support_ticket.tickets t
            LEFT JOIN support_ticket.ticket_info ti ON ti.ticket_number = t.ticket_number
            WHERE t.ticket_number = ?
            ORDER BY t.id DESC
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $searchTicket);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $ticketFound = $res->fetch_assoc();

                $vpoOwner = isset($ticketFound['vpo_owner']) && is_numeric($ticketFound['vpo_owner']) ? (int) $ticketFound['vpo_owner'] : 0;
                if ($vpoOwner <= 0) {
                    $ticketFound = null;
                    $ticketGuardToast = 'Error: This ticket has not yet been invistaged by VPO';
                } else {
                    $wbSql = "SELECT correct_biller_id, correct_biller_name FROM support_ticket.ticket_info_wrongbiller WHERE ticket_number = ? ORDER BY id DESC LIMIT 1";
                    $wbStmt = $conn->prepare($wbSql);
                    if ($wbStmt) {
                        $wbStmt->bind_param('s', $searchTicket);
                        if ($wbStmt->execute()) {
                            $wbRes = $wbStmt->get_result();
                            $ticketWrongBiller = $wbRes ? $wbRes->fetch_assoc() : null;
                        }
                        $wbStmt->close();
                    }

                    $oaSql = "SELECT wrong_amount, correct_amount, difference FROM support_ticket.ticket_info_overstatedamount WHERE ticket_number = ? ORDER BY id DESC LIMIT 1";
                    $oaStmt = $conn->prepare($oaSql);
                    if ($oaStmt) {
                        $oaStmt->bind_param('s', $searchTicket);
                        if ($oaStmt->execute()) {
                            $oaRes = $oaStmt->get_result();
                            $ticketOverstated = $oaRes ? $oaRes->fetch_assoc() : null;
                        }
                        $oaStmt->close();
                    }

                    $ctSql = "SELECT wrong_amount, correct_amount FROM support_ticket.ticket_info_cancelledtransaction WHERE ticket_number = ? ORDER BY id DESC LIMIT 1";
                    $ctStmt = $conn->prepare($ctSql);
                    if ($ctStmt) {
                        $ctStmt->bind_param('s', $searchTicket);
                        if ($ctStmt->execute()) {
                            $ctRes = $ctStmt->get_result();
                            $ticketCancelled = $ctRes ? $ctRes->fetch_assoc() : null;
                        }
                        $ctStmt->close();
                    }
                }
            } else {
                $ticketError = 'Ticket number not found';
            }
        } else {
            $ticketError = 'Failed to fetch ticket details';
        }
        $stmt->close();
    } else {
        $ticketError = 'Unable to prepare ticket query';
    }
}

$typeOfRequest = strtoupper(trim((string) ($ticketFound['type_of_request'] ?? '')));
$isWrongBiller = ($typeOfRequest === 'WRONG BILLER');
$isOverstated = ($typeOfRequest === 'OVERSTATED AMOUNT');
$isCancelled = ($typeOfRequest === 'CANCELLED TRANSACTION');

$wrongAmount = '';
$correctAmount = '';
$differenceValue = '';

if ($isOverstated && !empty($ticketOverstated)) {
    $wrongAmount = (string) ($ticketOverstated['wrong_amount'] ?? '');
    $correctAmount = (string) ($ticketOverstated['correct_amount'] ?? '');
    $differenceValue = (string) ($ticketOverstated['difference'] ?? '');
} elseif ($isCancelled && !empty($ticketCancelled)) {
    $wrongAmount = (string) ($ticketCancelled['wrong_amount'] ?? '');
    $correctAmount = (string) ($ticketCancelled['correct_amount'] ?? '');
}
?>

<section class="entry-block" id="ticketModeBlock">
    <div class="ticket-search-section">
        <form method="get" class="ticket-search-form" autocomplete="off">
            <input type="hidden" name="mode" value="ticket">
            <div class="search-input-wrapper">
                <span class="material-icons search-icon">search</span>
                <input id="searchTicketNo" name="search_ticket" class="auto-search-input" type="text" placeholder="Enter ticket number" value="<?php echo htmlspecialchars($searchTicket); ?>" required>
            </div>
            <button type="submit" class="btn btn-search">
                <span class="material-icons">check_circle</span>
                Search
            </button>
        </form>
    </div>

    <?php if ($ticketError !== ''): ?>
        <div class="entry-alert alert-error">
            <span class="material-icons">error</span>
            <div class="alert-content">
                <strong>Not Found</strong>
                <p><?php echo htmlspecialchars($ticketError); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($ticketGuardToast !== ''): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                if (typeof Swal === 'undefined') {
                    return;
                }
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'error',
                    title: <?php echo json_encode($ticketGuardToast); ?>,
                    showConfirmButton: false,
                    timer: 3200,
                    timerProgressBar: true
                });
            });
        </script>
    <?php endif; ?>

    <?php if ($ticketFound): ?>
        <form id="ticketEntryForm" method="post" action="controllers/trl-entry-insert.php" class="entry-form auto-entry-form ticket-entry-form" novalidate>
            <input type="hidden" name="source_mode" value="ticket">
            <input type="hidden" name="include_ref_no" value="1">
            <input type="hidden" name="ref_no" class="required-field" required value="<?php echo htmlspecialchars((string) ($ticketFound['reference_number'] ?? '')); ?>">
            <input type="hidden" name="transfer_datetime" class="required-field" required value="<?php echo htmlspecialchars((string) ($ticketFound['transfer_datetime'] ?? '')); ?>">
            <input type="hidden" name="account_no" class="required-field" required value="<?php echo htmlspecialchars((string) ($ticketFound['account_no'] ?? '')); ?>">
            <input type="hidden" name="name" class="required-field" required value="<?php echo htmlspecialchars((string) ($ticketFound['account_name'] ?? '')); ?>">
            <input type="hidden" name="payment_branch_id" class="required-field" required value="<?php echo htmlspecialchars((string) ($ticketFound['payment_branch_id'] ?? '')); ?>">
            <input type="hidden" name="payment_branch_name" class="required-field" required value="<?php echo htmlspecialchars((string) ($ticketFound['payment_branch_name'] ?? '')); ?>">
            <input type="hidden" name="wrong_biller_id" class="required-field" required value="<?php echo htmlspecialchars((string) ($ticketFound['wrong_biller_id'] ?? '')); ?>">
            <input type="hidden" name="biller_name" class="required-field" required value="<?php echo htmlspecialchars((string) ($ticketFound['biller_name'] ?? '')); ?>">
            <input type="hidden" name="amount" class="required-field" required value="<?php echo htmlspecialchars((string) ($ticketFound['amount'] ?? '0')); ?>">
            <input type="hidden" name="type_of_request" class="required-field" required value="<?php echo htmlspecialchars((string) ($ticketFound['type_of_request'] ?? '')); ?>">
            <input type="hidden" name="reason" class="required-field" required value="<?php echo htmlspecialchars((string) ($ticketFound['reason'] ?? '')); ?>">
            <input type="hidden" name="correct_biller_id" <?php echo $isWrongBiller ? 'class="required-field" required' : ''; ?> value="<?php echo htmlspecialchars((string) ($ticketWrongBiller['correct_biller_id'] ?? '')); ?>">
            <input type="hidden" name="correct_biller_name" <?php echo $isWrongBiller ? 'class="required-field" required' : ''; ?> value="<?php echo htmlspecialchars((string) ($ticketWrongBiller['correct_biller_name'] ?? '')); ?>">
            <input type="hidden" name="wrong_amount" <?php echo ($isOverstated || $isCancelled) ? 'class="required-field" required' : ''; ?> value="<?php echo htmlspecialchars($wrongAmount); ?>">
            <input type="hidden" name="correct_amount" <?php echo ($isOverstated || $isCancelled) ? 'class="required-field" required' : ''; ?> value="<?php echo htmlspecialchars($correctAmount); ?>">
            <input type="hidden" name="difference_value" value="<?php echo htmlspecialchars($differenceValue); ?>">

            <div class="auto-content-grid">
                <div class="auto-data-column">
                    <div class="auto-data-header">
                        <span class="material-icons">folder_open</span>
                        <h3>Ticket Transaction Details</h3>
                    </div>
                    <div class="auto-data-card">
                        <div class="data-item">
                            <div class="data-icon"><span class="material-icons">sell</span></div>
                            <div class="data-content">
                                <span class="data-label">Ticket No.</span>
                                <span class="data-value"><?php echo htmlspecialchars((string) ($ticketFound['ticket_number'] ?? '')); ?></span>
                            </div>
                        </div>

                        <div class="data-item">
                            <div class="data-icon"><span class="material-icons">confirmation_number</span></div>
                            <div class="data-content">
                                <span class="data-label">Reference No.</span>
                                <span class="data-value"><?php echo htmlspecialchars((string) ($ticketFound['reference_number'] ?? '')); ?></span>
                            </div>
                        </div>

                        <div class="data-item">
                            <div class="data-icon"><span class="material-icons">schedule</span></div>
                            <div class="data-content">
                                <span class="data-label">Transaction Date/Time</span>
                                <span class="data-value"><?php echo htmlspecialchars((string) ($ticketFound['transfer_datetime'] ?? '')); ?></span>
                            </div>
                        </div>

                        <div class="data-item">
                            <div class="data-icon"><span class="material-icons">account_balance</span></div>
                            <div class="data-content">
                                <span class="data-label">Account Number</span>
                                <span class="data-value"><?php echo htmlspecialchars((string) ($ticketFound['account_no'] ?? '')); ?></span>
                            </div>
                        </div>

                        <div class="data-item">
                            <div class="data-icon"><span class="material-icons">person</span></div>
                            <div class="data-content">
                                <span class="data-label">Account Name</span>
                                <span class="data-value"><?php echo htmlspecialchars((string) ($ticketFound['account_name'] ?? '')); ?></span>
                            </div>
                        </div>

                        <div class="data-item">
                            <div class="data-icon"><span class="material-icons">business</span></div>
                            <div class="data-content">
                                <span class="data-label">Branch ID</span>
                                <span class="data-value"><?php echo htmlspecialchars((string) ($ticketFound['payment_branch_id'] ?? '')); ?></span>
                            </div>
                        </div>

                        <div class="data-item">
                            <div class="data-icon"><span class="material-icons">store</span></div>
                            <div class="data-content">
                                <span class="data-label">Payment Branch</span>
                                <span class="data-value"><?php echo htmlspecialchars((string) ($ticketFound['payment_branch_name'] ?? '')); ?></span>
                            </div>
                        </div>

                        <div class="data-item">
                            <div class="data-icon"><span class="material-icons">warning</span></div>
                            <div class="data-content">
                                <span class="data-label">Biller ID</span>
                                <span class="data-value"><?php echo htmlspecialchars((string) ($ticketFound['wrong_biller_id'] ?? '')); ?></span>
                            </div>
                        </div>

                        <div class="data-item">
                            <div class="data-icon"><span class="material-icons">business</span></div>
                            <div class="data-content">
                                <span class="data-label">Biller Name</span>
                                <span class="data-value"><?php echo htmlspecialchars((string) ($ticketFound['biller_name'] ?? '')); ?></span>
                            </div>
                        </div>

                        <div class="data-item">
                            <div class="data-icon"><span class="material-icons">attach_money</span></div>
                            <div class="data-content">
                                <span class="data-label">Amount</span>
                                <span class="data-value">PHP <?php echo number_format((float) ($ticketFound['amount'] ?? 0), 2); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="auto-input-column">
                    <div class="auto-input-header">
                        <span class="material-icons">edit_note</span>
                        <h3>Request Information</h3>
                    </div>
                    <div class="auto-input-card">
                        <div class="field-group">
                            <label><span class="material-icons">category</span> Type of Request</label>
                            <div class="field-input ticket-readonly"><?php echo htmlspecialchars((string) ($ticketFound['type_of_request'] ?? '')); ?></div>
                        </div>

                        <?php if ($isOverstated || $isCancelled): ?>
                            <div class="field-group">
                                <label><span class="material-icons">payments</span> Wrong Amount</label>
                                <div class="field-input ticket-readonly">PHP <?php echo number_format((float) ($wrongAmount !== '' ? $wrongAmount : 0), 2); ?></div>
                            </div>

                            <div class="field-group">
                                <label><span class="material-icons">payments</span> Correct Amount</label>
                                <div class="field-input ticket-readonly">PHP <?php echo number_format((float) ($correctAmount !== '' ? $correctAmount : 0), 2); ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if ($isOverstated): ?>
                            <div class="field-group">
                                <label><span class="material-icons">calculate</span> Difference</label>
                                <div class="field-input ticket-readonly">PHP <?php echo number_format((float) ($differenceValue !== '' ? $differenceValue : 0), 2); ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if ($isWrongBiller): ?>
                            <div class="field-group">
                                <label><span class="material-icons">check_circle</span> Correct Biller ID</label>
                                <div class="field-input ticket-readonly"><?php echo htmlspecialchars((string) ($ticketWrongBiller['correct_biller_id'] ?? '')); ?></div>
                            </div>

                            <div class="field-group">
                                <label><span class="material-icons">business</span> Correct Biller Name</label>
                                <div class="field-input ticket-readonly"><?php echo htmlspecialchars((string) ($ticketWrongBiller['correct_biller_name'] ?? '')); ?></div>
                            </div>
                        <?php endif; ?>

                        <div class="field-group field-fullwidth">
                            <label><span class="material-icons">description</span> Reason</label>
                            <div class="field-input ticket-readonly ticket-readonly--multiline"><?php echo nl2br(htmlspecialchars((string) ($ticketFound['reason'] ?? ''))); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    <?php endif; ?>
</section>
