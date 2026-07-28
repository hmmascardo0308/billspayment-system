<?php
$pendingRows = [];
if (isset($conn)) {
    $pendingSql = "SELECT
                      t.trl_no,
                      t.transfer_datetime,
                      t.ref_no,
                      t.account_no,
                      t.name,
                      t.type_of_request,
                      t.reason,
                      (SELECT COUNT(*) FROM mldb.trl_attachments a WHERE a.trl_no = t.trl_no) AS attachment_count
                   FROM mldb.trl t
                   WHERE t.status = 'PENDING_APPROVAL'
                     AND EXISTS (
                         SELECT 1 FROM mldb.trl_attachments a
                         WHERE a.trl_no = t.trl_no
                     )
                   ORDER BY t.trl_no DESC";
    $pendingResult = $conn->query($pendingSql);
    if ($pendingResult) {
        while ($pendingRow = $pendingResult->fetch_assoc()) {
            $pendingRows[] = $pendingRow;
        }
    }
}

$pendingAttachments = [];
if (isset($conn) && !empty($pendingRows)) {
    $attachmentSql = "SELECT a.id, a.trl_no, a.file_name
                      FROM mldb.trl_attachments a
                      INNER JOIN mldb.trl t ON t.trl_no = a.trl_no
                      WHERE t.status = 'PENDING_APPROVAL'
                      ORDER BY a.id ASC";
    $attachmentResult = $conn->query($attachmentSql);
    if ($attachmentResult) {
        while ($attachment = $attachmentResult->fetch_assoc()) {
            $attachmentTrlNo = (int) ($attachment['trl_no'] ?? 0);
            if (!isset($pendingAttachments[$attachmentTrlNo])) {
                $pendingAttachments[$attachmentTrlNo] = [];
            }
            $pendingAttachments[$attachmentTrlNo][] = $attachment;
        }
    }
}
?>

<section class="entry-block trl-drafts-block">
    <div class="trl-drafts-header">
        <div>
            <h3><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Pending Approvals</h3>
            <p>Submitted transactions with supporting attachments remain here while waiting for review.</p>
        </div>
        <span class="trl-draft-count trl-pending-count"><?php echo count($pendingRows); ?> pending</span>
    </div>

    <?php if (empty($pendingRows)): ?>
        <div class="trl-drafts-empty">No transactions are pending approval.</div>
    <?php else: ?>
        <div class="trl-drafts-table-wrap">
            <table class="trl-drafts-table">
                <thead>
                    <tr>
                        <th>TRL No.</th>
                        <th>Reference No.</th>
                        <th>Transaction Date/Time</th>
                        <th>Account</th>
                        <th>Request Type</th>
                        <th>Reason</th>
                        <th>Attachments</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingRows as $pending): ?>
                        <tr>
                            <td><?php echo (int) ($pending['trl_no'] ?? 0); ?></td>
                            <td><?php echo htmlspecialchars((string) ($pending['ref_no'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($pending['transfer_datetime'] ?? '')); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars((string) ($pending['name'] ?? '')); ?></strong>
                                <small><?php echo htmlspecialchars((string) ($pending['account_no'] ?? '')); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars((string) ($pending['type_of_request'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($pending['reason'] ?? '')); ?></td>
                            <td>
                                <div class="trl-pending-attachments">
                                    <?php foreach (($pendingAttachments[(int) ($pending['trl_no'] ?? 0)] ?? []) as $attachment): ?>
                                        <a class="trl-pending-attachment-link"
                                           href="controllers/trl-pending-attachment.php?id=<?php echo (int) ($attachment['id'] ?? 0); ?>"
                                           target="_blank" rel="noopener">
                                            <i class="fa-solid fa-paperclip" aria-hidden="true"></i>
                                            <?php echo htmlspecialchars((string) ($attachment['file_name'] ?? 'Attachment')); ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td><span class="trl-pending-status">Pending Approval</span></td>
                            <td>
                                <form class="trl-pending-approve-form" action="controllers/trl-pending-approve.php" method="post">
                                    <input type="hidden" name="trl_no" value="<?php echo (int) ($pending['trl_no'] ?? 0); ?>">
                                    <button class="btn btn-danger trl-pending-approve" type="submit">
                                        <i class="fa-solid fa-check" aria-hidden="true"></i> Confirm
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<script>
(function () {
    document.querySelectorAll('.trl-pending-approve-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            Swal.fire({
                icon: 'question',
                title: 'Confirm transaction?',
                text: 'After confirmation, this transaction will be sent to Transaction Request Log - Review.',
                showCancelButton: true,
                confirmButtonText: 'Confirm & Send to Review',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#dc3545'
            }).then(function (result) {
                if (!result.isConfirmed) return;

                Swal.fire({
                    title: 'Confirming transaction...',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    didOpen: function () { Swal.showLoading(); }
                });

                fetch(form.action, { method: 'POST', body: new FormData(form) })
                    .then(function (response) {
                        if (!response.ok) throw new Error('Network response was not ok');
                        return response.json();
                    })
                    .then(function (data) {
                        Swal.close();
                        if (!data.success) {
                            Swal.fire({ icon: 'error', title: 'Confirmation Failed', text: data.message || 'Unable to confirm the transaction.' });
                            return;
                        }
                        Swal.fire({ icon: 'success', title: 'Sent to Review', text: data.message, confirmButtonText: 'Acknowledged' })
                            .then(function () { window.location.href = data.redirect || 'trl-entry.php?mode=pending'; });
                    })
                    .catch(function () {
                        Swal.close();
                        Swal.fire({ icon: 'error', title: 'Confirmation Failed', text: 'An error occurred while confirming the transaction.' });
                    });
            });
        });
    });
})();
</script>
