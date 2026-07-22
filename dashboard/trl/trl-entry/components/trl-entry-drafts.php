<?php
$draftRows = [];
if (isset($conn)) {
    $draftSql = "SELECT t.trl_no, t.transfer_datetime, t.ref_no, t.account_no, t.name, t.type_of_request, t.reason
                 FROM mldb.trl t
                 WHERE t.status = 'DRAFT'
                 ORDER BY t.trl_no DESC";
    $draftResult = $conn->query($draftSql);
    if ($draftResult) {
        while ($draftRow = $draftResult->fetch_assoc()) {
            $draftRows[] = $draftRow;
        }
    }
}
?>

<section class="entry-block trl-drafts-block">
    <div class="trl-drafts-header">
        <div>
            <h3><i class="fa-solid fa-file-pen" aria-hidden="true"></i> Draft Transactions</h3>
            <p>Transactions saved without attachments remain here until a supporting file is added.</p>
        </div>
        <span class="trl-draft-count"><?php echo count($draftRows); ?> draft<?php echo count($draftRows) === 1 ? '' : 's'; ?></span>
    </div>

    <?php if (empty($draftRows)): ?>
        <div class="trl-drafts-empty">No draft transactions found.</div>
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
                        <th>Attachment and Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($draftRows as $draft): ?>
                        <tr>
                            <td><?php echo (int) ($draft['trl_no'] ?? 0); ?></td>
                            <td><?php echo htmlspecialchars((string) ($draft['ref_no'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($draft['transfer_datetime'] ?? '')); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars((string) ($draft['name'] ?? '')); ?></strong>
                                <small><?php echo htmlspecialchars((string) ($draft['account_no'] ?? '')); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars((string) ($draft['type_of_request'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($draft['reason'] ?? '')); ?></td>
                            <td>
                                <form class="trl-draft-submit-form" action="controllers/trl-draft-submit.php" method="post" enctype="multipart/form-data">
                                    <input type="hidden" name="trl_no" value="<?php echo (int) ($draft['trl_no'] ?? 0); ?>">
                                    <div class="trl-draft-file-actions">
                                        <input class="trl-draft-file" type="file" name="attachments[]" accept=".png,.jpeg,.jpg,.gif,.webp,.pdf,.docx,.txt,.xlsx,.csv,.ods" multiple required>
                                        <button class="btn btn-outline-secondary trl-draft-view" type="button" hidden>
                                            <i class="fa-solid fa-eye" aria-hidden="true"></i> View File
                                        </button>
                                    </div>
                                    <button class="btn btn-danger trl-draft-submit" type="submit">
                                        <i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Submit for Review
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
    document.querySelectorAll('.trl-draft-submit-form').forEach(function (form) {
        var fileInput = form.querySelector('.trl-draft-file');
        var viewButton = form.querySelector('.trl-draft-view');

        function openSelectedFile(file) {
            if (!file) return;
            var objectUrl = URL.createObjectURL(file);
            var fileName = String(file.name || 'Attachment');
            var isImage = /^image\//i.test(file.type || '') || /\.(png|jpe?g|gif|webp)$/i.test(fileName);
            var isPdf = String(file.type || '').toLowerCase() === 'application/pdf' || /\.pdf$/i.test(fileName);

            if (isImage) {
                var image = document.createElement('img');
                image.className = 'trl-draft-image-preview';
                image.src = objectUrl;
                image.alt = fileName;
                Swal.fire({
                    title: fileName,
                    html: image,
                    width: '900px',
                    showCloseButton: true,
                    confirmButtonText: 'Close',
                    didClose: function () { URL.revokeObjectURL(objectUrl); }
                });
                return;
            }

            if (isPdf) {
                var frame = document.createElement('iframe');
                frame.className = 'trl-draft-pdf-preview';
                frame.src = objectUrl;
                frame.title = fileName;
                Swal.fire({
                    title: fileName,
                    html: frame,
                    width: '1000px',
                    showCloseButton: true,
                    confirmButtonText: 'Close',
                    didClose: function () { URL.revokeObjectURL(objectUrl); }
                });
                return;
            }

            var previewWindow = window.open(objectUrl, '_blank');
            if (previewWindow) previewWindow.opener = null;
            if (!previewWindow) {
                Swal.fire({ icon: 'warning', title: 'Preview Blocked', text: 'Allow pop-ups in your browser to view the selected file.' });
            }
            window.setTimeout(function () { URL.revokeObjectURL(objectUrl); }, 60000);
        }

        if (fileInput && viewButton) {
            fileInput.addEventListener('change', function () {
                var count = fileInput.files ? fileInput.files.length : 0;
                viewButton.hidden = count === 0;
                viewButton.innerHTML = '<i class="fa-solid fa-eye" aria-hidden="true"></i> ' +
                    (count > 1 ? 'View Files (' + count + ')' : 'View File');
            });

            viewButton.addEventListener('click', function () {
                var files = Array.prototype.slice.call(fileInput.files || []);
                if (!files.length) return;
                if (files.length === 1) {
                    openSelectedFile(files[0]);
                    return;
                }

                var list = document.createElement('div');
                list.className = 'trl-draft-preview-list';
                files.forEach(function (file) {
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'trl-draft-preview-item';
                    button.innerHTML = '<i class="fa-solid fa-eye" aria-hidden="true"></i><span></span>';
                    button.querySelector('span').textContent = file.name;
                    button.addEventListener('click', function () { openSelectedFile(file); });
                    list.appendChild(button);
                });

                Swal.fire({
                    title: 'Selected Attachments',
                    html: list,
                    showConfirmButton: false,
                    showCloseButton: true
                });
            });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!fileInput || !fileInput.files || !fileInput.files.length) {
                Swal.fire({ icon: 'warning', title: 'Attachment Required', text: 'Add at least one attachment before submitting this draft.' });
                return;
            }

            Swal.fire({
                title: 'Submitting draft...',
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
                        Swal.fire({ icon: 'error', title: 'Submission Failed', text: data.message || 'Unable to submit the draft.' });
                        return;
                    }
                    Swal.fire({ icon: 'success', title: 'Submitted for Review', text: data.message, confirmButtonText: 'Acknowledged' })
                        .then(function () { window.location.href = data.redirect || '../trl-review/trl-review.php'; });
                })
                .catch(function () {
                    Swal.close();
                    Swal.fire({ icon: 'error', title: 'Submission Failed', text: 'An error occurred while submitting the draft.' });
                });
        });
    });
})();
</script>
