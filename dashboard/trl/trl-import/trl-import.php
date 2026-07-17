<?php
include '../../../config/config.php';
session_start();
include '../../../templates/middleware.php';
// canonical auth guard
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }
// quick debug endpoint: show middleware info when requested by a superuser or
// a user with the appropriate maintenance permission. Do not rely on role.
if (isset($_GET['__showperms']) && ((isset($_SESSION['access_level']) && intval($_SESSION['access_level']) === -1) || (function_exists('has_permission') && has_permission('Access Levels')))) {
    header('Content-Type: application/json');
    echo json_encode(middleware_debug_info());
    exit;
}
// page-level permission enforcement (allow existing 'Bills Payment' holders too)
if (!function_exists('has_any_permission') || !has_any_permission(['TRL Import','Bills Payment'])) { header('Location: ../../home.php'); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Transaction Request Log - Import</title>
    <link rel="icon" href="../../../images/MLW%20logo.png" type="image/png">
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="trl-import.css?v=<?php echo time(); ?>">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
    <div class="main-container">
        <?php include '../../../templates/header_ui.php'; ?>
        <?php include '../../../templates/sidebar.php'; ?>

        <?php bp_section_header_html('fa-solid fa-file-import', 'Transaction Request Log - Import'); ?>

        <div id="loading-overlay" style="display:none;">
            <div class="loading-spinner"></div>
        </div>

        <div class="bp-card container-fluid mt-3 p-4 trl-import-wrap">
            <div class="trl-toolbar">
                <div>
                    <h3 class="trl-title">Import Files</h3>
                    <p class="trl-subtitle">Upload TRL Excel files and run duplicate pre-check before processing.</p>
                </div>
                <div class="trl-toolbar-actions">
                    <button id="resetBtn" type="button" class="btn btn-outline-secondary">Reset</button>
                    <button id="proceedBtn" type="button" class="btn btn-danger" disabled>
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        Proceed Import
                    </button>
                </div>
            </div>

            <div class="file-upload-area" id="fileUploadArea">
                <div class="file-upload-icon"><i class="fa-solid fa-file-arrow-up"></i></div>
                <h5>Drag and Drop TRL Files Here</h5>
                <p class="text-muted">or click to browse</p>
                <p class="text-muted"><small>Accepted: .xls, .xlsx</small></p>
                <input type="file" id="fileInput" accept=".xls,.xlsx" multiple style="display:none;">
            </div>

            <div id="emptyState" class="bp-empty">No files selected yet.</div>

            <div id="filesContainer" class="files-container"></div>

            <div id="precheckSummary" class="precheck-summary" style="display:none;">
                <div class="summary-item"><span>Total Rows</span><strong id="sumTotal">0</strong></div>
                <div class="summary-item"><span>Duplicate Rows</span><strong id="sumDuplicates">0</strong></div>
                <div class="summary-item"><span>Posted Matches</span><strong id="sumPosted">0</strong></div>
                <div class="summary-item"><span>Unposted Matches</span><strong id="sumUnposted">0</strong></div>
                <div class="summary-item"><span>New Rows</span><strong id="sumNew">0</strong></div>
            </div>
        </div>

        <script>
        (function() {
            var uploadedFiles = [];
            var fileCounter = 0;
            var fetchEndpoint = 'controllers/trl-import-fetch.php';

            var fileUploadArea = document.getElementById('fileUploadArea');
            var fileInput = document.getElementById('fileInput');
            var filesContainer = document.getElementById('filesContainer');
            var emptyState = document.getElementById('emptyState');
            var proceedBtn = document.getElementById('proceedBtn');
            var resetBtn = document.getElementById('resetBtn');
            var loadingOverlay = document.getElementById('loading-overlay');

            function setLoading(show) {
                loadingOverlay.style.display = show ? 'flex' : 'none';
            }

            function parseFile(file) {
                return Promise.resolve({
                    id: 'trl_file_' + (++fileCounter),
                    file: file,
                    name: file.name,
                    size: file.size
                });
            }

            function formatBytes(bytes) {
                if (!bytes) return '0 B';
                var sizes = ['B', 'KB', 'MB', 'GB'];
                var i = Math.floor(Math.log(bytes) / Math.log(1024));
                return (bytes / Math.pow(1024, i)).toFixed(2) + ' ' + sizes[i];
            }

            function renderCards() {
                filesContainer.innerHTML = '';
                emptyState.style.display = uploadedFiles.length ? 'none' : 'block';
                proceedBtn.disabled = uploadedFiles.length === 0;

                uploadedFiles.forEach(function(item) {
                    var statusHtml = '';
                    if (item.precheck) {
                        if (item.precheck.error) {
                            statusHtml = '<span class="chip chip-error">Error</span>';
                        } else if (item.precheck.isUnique === true) {
                            statusHtml = '<span class="chip chip-ok">Duplicate check passed</span>';
                        } else {
                            statusHtml = '<span class="chip chip-warn">Needs review</span>';
                        }
                    } else {
                        statusHtml = '<span class="chip chip-pending">Pending Check</span>';
                    }

                    var card = document.createElement('div');
                    card.className = 'file-card';
                    card.innerHTML =
                        '<div class="file-card-header">' +
                            '<div class="file-card-title">' +
                                '<div class="file-name">' + item.name + '</div>' +
                                '<div class="file-meta">' + formatBytes(item.size) + '</div>' +
                            '</div>' +
                            '<button class="file-remove" data-id="' + item.id + '" type="button" aria-label="Remove"><i class="fa-solid fa-xmark"></i></button>' +
                        '</div>' +
                        '<div class="file-card-footer">' + statusHtml + '</div>';

                    filesContainer.appendChild(card);
                });

                var removes = filesContainer.querySelectorAll('.file-remove');
                removes.forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        removeFile(btn.getAttribute('data-id'));
                    });
                });

                renderSummary();
            }

            function renderSummary() {
                var summaryBox = document.getElementById('precheckSummary');
                var filesWithCheck = uploadedFiles.filter(function(f) { return !!f.precheck; });

                if (filesWithCheck.length === 0) {
                    summaryBox.style.display = 'none';
                    return;
                }

                var totals = filesWithCheck.reduce(function(acc, f) {
                    acc.total += Number(f.precheck.totalRows || 0);
                    acc.dupes += Number(f.precheck.duplicateRows || 0);
                    acc.posted += 0;
                    acc.unposted += 0;
                    acc.newRows += Number(f.precheck.totalRows || 0);
                    return acc;
                }, { total: 0, dupes: 0, posted: 0, unposted: 0, newRows: 0 });

                document.getElementById('sumTotal').textContent = totals.total;
                document.getElementById('sumDuplicates').textContent = totals.dupes;
                document.getElementById('sumPosted').textContent = totals.posted;
                document.getElementById('sumUnposted').textContent = totals.unposted;
                document.getElementById('sumNew').textContent = totals.newRows;

                summaryBox.style.display = 'grid';
            }

            function removeFile(id) {
                uploadedFiles = uploadedFiles.filter(function(f) { return f.id !== id; });
                renderCards();
            }

            async function showDuplicateModal(duplicates) {
                var MAX_DUP_DISPLAY = 100;
                var PAGE_SIZE = 10;
                var PAGE_WINDOW_SIZE = 10;

                var all = (duplicates || []).map(function(v) { return String(v); });
                var limited = all.slice(0, MAX_DUP_DISPLAY);
                var truncated = all.length > MAX_DUP_DISPLAY;

                // Per requirement: if under 100 rows, do not paginate.
                var usePagination = limited.length >= MAX_DUP_DISPLAY;

                var totalPages = Math.max(1, Math.ceil(limited.length / PAGE_SIZE));
                var currentPage = 1;
                var pageWindowStart = 1;

                function pageItems(page) {
                    var start = (page - 1) * PAGE_SIZE;
                    return limited.slice(start, start + PAGE_SIZE);
                }

                function normalizeWindow() {
                    if (!usePagination) {
                        pageWindowStart = 1;
                        return;
                    }
                    // Lazy page-window behavior: show 1-10, then shift to next 10 when at pages 8-10.
                    if (currentPage >= pageWindowStart + 7 && (pageWindowStart + PAGE_WINDOW_SIZE) <= totalPages) {
                        pageWindowStart += PAGE_WINDOW_SIZE;
                    } else if (currentPage <= pageWindowStart + 2 && pageWindowStart > 1) {
                        pageWindowStart = Math.max(1, pageWindowStart - PAGE_WINDOW_SIZE);
                    }
                }

                function renderList(container) {
                    container.innerHTML = '';
                    var ul = document.createElement('ul');
                    ul.style.textAlign = 'left';
                    ul.style.margin = '0';
                    ul.style.paddingLeft = '1.2em';

                    var items = usePagination ? pageItems(currentPage) : limited;
                    items.forEach(function(item) {
                        var li = document.createElement('li');
                        li.textContent = item;
                        ul.appendChild(li);
                    });
                    container.appendChild(ul);
                }

                function renderPager(container, listContainer, statusContainer) {
                    container.innerHTML = '';
                    if (!usePagination || totalPages <= 1) {
                        statusContainer.textContent = 'Showing ' + limited.length + ' duplicate reference(s).';
                        return;
                    }

                    normalizeWindow();

                    var prev = document.createElement('button');
                    prev.type = 'button';
                    prev.className = 'swal2-styled';
                    prev.style.background = '#6c757d';
                    prev.style.marginRight = '6px';
                    prev.textContent = 'Prev';
                    prev.disabled = currentPage === 1;
                    prev.addEventListener('click', function() {
                        if (currentPage > 1) {
                            currentPage--;
                            renderList(listContainer);
                            renderPager(container, listContainer, statusContainer);
                        }
                    });
                    container.appendChild(prev);

                    var endPage = Math.min(totalPages, pageWindowStart + PAGE_WINDOW_SIZE - 1);
                    for (var p = pageWindowStart; p <= endPage; p++) {
                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'swal2-styled';
                        btn.style.marginRight = '4px';
                        btn.style.background = p === currentPage ? '#dc3545' : '#adb5bd';
                        btn.textContent = String(p);
                        (function(page) {
                            btn.addEventListener('click', function() {
                                currentPage = page;
                                renderList(listContainer);
                                renderPager(container, listContainer, statusContainer);
                            });
                        })(p);
                        container.appendChild(btn);
                    }

                    var next = document.createElement('button');
                    next.type = 'button';
                    next.className = 'swal2-styled';
                    next.style.background = '#6c757d';
                    next.textContent = 'Next';
                    next.disabled = currentPage === totalPages;
                    next.addEventListener('click', function() {
                        if (currentPage < totalPages) {
                            currentPage++;
                            renderList(listContainer);
                            renderPager(container, listContainer, statusContainer);
                        }
                    });
                    container.appendChild(next);

                    statusContainer.textContent = 'Showing page ' + currentPage + ' of ' + totalPages + ' (' + limited.length + ' max displayed).';
                }

                return Swal.fire({
                    title: 'Duplicate reference(s) found',
                    html:
                        '<p>The following Ref No(s) already exist in the database:</p>' +
                        (truncated ? '<p><small>Showing first 100 duplicates only.</small></p>' : '') +
                        '<div id="dupList" style="max-height:320px;overflow:auto;border:1px solid #eee;border-radius:6px;padding:8px;margin-bottom:10px;"></div>' +
                        '<div id="dupPager" style="margin-bottom:8px;"></div>' +
                        '<div id="dupPagerStatus" style="font-size:12px;color:#666;"></div>' +
                        '<p>Choose an action:</p>',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Remove All',
                    cancelButtonText: 'Cancel',
                    focusCancel: true,
                    width: 760,
                    didOpen: function() {
                        var listContainer = document.getElementById('dupList');
                        var pagerContainer = document.getElementById('dupPager');
                        var statusContainer = document.getElementById('dupPagerStatus');
                        if (!listContainer || !pagerContainer || !statusContainer) return;
                        renderList(listContainer);
                        renderPager(pagerContainer, listContainer, statusContainer);
                    }
                });
            }

            async function addFiles(fileList) {
                if (!fileList || fileList.length === 0) return;

                var files = Array.prototype.slice.call(fileList);
                var excelFiles = files.filter(function(file) {
                    var lower = file.name.toLowerCase();
                    return lower.endsWith('.xlsx') || lower.endsWith('.xls');
                });

                if (excelFiles.length === 0) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Invalid file type',
                        text: 'Please upload Excel files (.xls, .xlsx) only.'
                    });
                    return;
                }

                setLoading(true);
                try {
                    for (var i = 0; i < excelFiles.length; i++) {
                        var parsed = await parseFile(excelFiles[i]);
                        uploadedFiles.push(parsed);
                    }
                    renderCards();
                } finally {
                    setLoading(false);
                }
            }

            async function handleProceed() {
                if (uploadedFiles.length === 0) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'No files selected',
                        text: 'Please add at least one file to continue.'
                    });
                    return;
                }

                setLoading(true);
                proceedBtn.disabled = true;
                try {
                    var fd = new FormData();
                    uploadedFiles.forEach(function(fileItem) {
                        fd.append('files[]', fileItem.file, fileItem.name);
                    });

                    var res = await fetch(fetchEndpoint, {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin'
                    });
                    var data = await res.json();

                    if (!data || !data.success) {
                        throw new Error((data && data.message) ? data.message : 'Unable to fetch TRL data.');
                    }

                    if (Array.isArray(data.files)) {
                        uploadedFiles.forEach(function(fileItem, idx) {
                            var match = data.files[idx] || null;
                            if (match) fileItem.precheck = match;
                        });
                        renderCards();
                    }

                    // If server reports duplicates, prompt the user with modal
                    if (data.duplicates && Array.isArray(data.duplicates) && data.duplicates.length > 0) {
                        // remove blocking overlay while user reviews duplicate list
                        setLoading(false);
                        var result = await showDuplicateModal(data.duplicates);

                        if (result.isConfirmed) {
                            // send request to remove duplicates from session and proceed to preview
                            setLoading(true);
                            var fd2 = new FormData();
                            fd2.append('action', 'remove_duplicates');
                            data.duplicates.forEach(function(d) { fd2.append('duplicates[]', d); });

                            var res2 = await fetch(fetchEndpoint, { method: 'POST', body: fd2, credentials: 'same-origin' });
                            var data2 = await res2.json();
                            if (data2 && data2.success) {
                                if (data2.no_new_rows === true || Number(data2.total_rows || 0) === 0) {
                                    setLoading(false);
                                    await Swal.fire({
                                        icon: 'info',
                                        title: 'No new rows detected',
                                        text: 'All selected rows are duplicates. Please upload another file.'
                                    });
                                    // clear selected files from the UI since nothing remains to import
                                    uploadedFiles = [];
                                    renderCards();
                                    proceedBtn.disabled = true;
                                    return;
                                }
                                window.location.href = data2.redirect || 'trl-import-preview.php';
                                return;
                            }

                            throw new Error((data2 && data2.message) ? data2.message : 'Failed to remove duplicates.');
                        } else {
                            // User cancelled — do nothing so they can review files
                            return;
                        }
                    }

                    // Normal path: no duplicates — redirect to preview
                    window.location.href = data.redirect || 'trl-import-preview.php';
                } catch (err) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Fetch failed',
                        text: err && err.message ? err.message : 'Unable to process selected files.'
                    });
                } finally {
                    setLoading(false);
                    proceedBtn.disabled = uploadedFiles.length === 0;
                }
            }

            fileUploadArea.addEventListener('click', function() {
                fileInput.click();
            });

            fileInput.addEventListener('change', function(e) {
                addFiles(e.target.files);
                fileInput.value = '';
            });

            fileUploadArea.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.stopPropagation();
                fileUploadArea.classList.add('drag-over');
            });

            fileUploadArea.addEventListener('dragleave', function(e) {
                e.preventDefault();
                e.stopPropagation();
                fileUploadArea.classList.remove('drag-over');
            });

            fileUploadArea.addEventListener('drop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                fileUploadArea.classList.remove('drag-over');
                addFiles(e.dataTransfer.files);
            });

            proceedBtn.addEventListener('click', handleProceed);

            resetBtn.addEventListener('click', function() {
                uploadedFiles = [];
                renderCards();
            });
        })();
        </script>

        <?php include '../../../templates/footer.php'; ?>
    </div>
</body>
</html>
 