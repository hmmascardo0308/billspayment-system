<?php
require_once __DIR__ . '/database-connection.php';

if (isset($_GET['action']) && $_GET['action'] === 'branch-ids') {
    header('Content-Type: application/json');

    $sql = "SELECT branch_id, branch_name FROM masterdata.kpx_branch_masterfile ORDER BY CAST(branch_id AS UNSIGNED)";
    $result = $conn->query($sql);

    if (!$result) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to fetch branch IDs.']);
        exit;
    }

    $branchIds = [];
    while ($row = $result->fetch_assoc()) {
        $branchIds[] = [
            'id' => (int)$row['branch_id'],
            'name' => $row['branch_name']
        ];
    }

    echo json_encode(['success' => true, 'branch_ids' => $branchIds]);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'check-new-branches') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $sql = "SELECT branch_id, branch_name FROM masterdata.kpx_branch_masterfile";
    $result = $conn->query($sql);

    if (!$result) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to fetch DB branch IDs.']);
        exit;
    }

    $dbBranches = [];
    while ($row = $result->fetch_assoc()) {
        $id = (int)$row['branch_id'];
        if ($id > 0) {
            $dbBranches[$id] = (string)($row['branch_name'] ?? '');
        }
    }

    $jsonPath = __DIR__ . '/../../branch.json';
    if (!file_exists($jsonPath)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'branch.json not found.']);
        exit;
    }

    $jsonContent = file_get_contents($jsonPath);
    $jsonBranches = json_decode($jsonContent, true);

    if (!is_array($jsonBranches)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Invalid branch.json format.']);
        exit;
    }

    $jsonBranchSet = [];

    foreach ($jsonBranches as $branch) {
        $branchId = (int)($branch['branch_id'] ?? 0);
        if ($branchId <= 0) {
            continue;
        }
        $jsonBranchSet[$branchId] = true;
    }

    // DB-first comparison: only return IDs present in DB but missing in JSON.
    // IDs that exist only in JSON are skipped by design.
    $newBranches = [];
    foreach ($dbBranches as $dbId => $dbName) {
        if (!isset($jsonBranchSet[$dbId])) {
            $newBranches[] = [
                'branch_id' => (int)$dbId,
                'branch_name' => $dbName
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'new_branches' => $newBranches
    ]);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'sync-branch-json') {
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    $payload = json_decode(file_get_contents('php://input'), true);
    $branchesToSave = $payload['branches'] ?? [];
    if (!is_array($branchesToSave) || count($branchesToSave) === 0) {
        echo json_encode(['success' => false, 'message' => 'No branches provided.']);
        exit;
    }

    $jsonPath = __DIR__ . '/../../branch.json';
    if (!file_exists($jsonPath)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'branch.json not found.']);
        exit;
    }

    $jsonContent = file_get_contents($jsonPath);
    $jsonBranches = json_decode($jsonContent, true);
    if (!is_array($jsonBranches)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Invalid branch.json format.']);
        exit;
    }

    $jsonBranchSet = [];
    foreach ($jsonBranches as $branch) {
        $id = (int)($branch['branch_id'] ?? 0);
        if ($id > 0) {
            $jsonBranchSet[$id] = true;
        }
    }

    $added = [];
    foreach ($branchesToSave as $branch) {
        $dbId = (int)($branch['branch_id'] ?? 0);
        $dbName = (string)($branch['branch_name'] ?? '');

        if ($dbId > 0 && !isset($jsonBranchSet[$dbId])) {
            $newRow = [
                'branch_id' => (int)$dbId,
                'branch_name' => $dbName
            ];
            $jsonBranches[] = $newRow;
            $added[] = $newRow;
        }
    }

    if (count($added) === 0) {
        echo json_encode(['success' => true, 'updated' => 0, 'message' => 'No changes needed.']);
        exit;
    }

    $encoded = json_encode($jsonBranches, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to encode JSON content.']);
        exit;
    }

    if (file_put_contents($jsonPath, $encoded . PHP_EOL, LOCK_EX) === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update branch.json.']);
        exit;
    }

    echo json_encode(['success' => true, 'updated' => count($added)]);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branch List</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .table-scroll {
            max-height: 70vh;
            overflow-y: auto;
        }

        .table-scroll thead th {
            position: sticky;
            top: 0;
            z-index: 2;
        }

        .loading-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.35);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
        }

        .btn-notify {
            position: relative;
        }

        .notify-dot {
            position: absolute;
            top: 6px;
            right: 6px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #dc3545;
            display: none;
            box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.9);
        }
    </style>
</head>
<body class="bg-light">
    <div id="loadingOverlay" class="loading-overlay">
        <div class="bg-white px-4 py-3 rounded shadow d-flex align-items-center gap-2">
            <div class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></div>
            <span>Checking branch updates...</span>
        </div>
    </div>

    <div class="container py-4">
        <h1 class="h3 mb-3">Branch List</h1>
        <button id="checkUpdatesBtn" class="btn btn-secondary mb-3 btn-notify">
            Check for Branch Updates
            <span id="checkUpdatesDot" class="notify-dot" aria-hidden="true"></span>
        </button>
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="table-responsive table-scroll">
                    <table class="table table-striped table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th scope="col">Branch ID</th>
                                <th scope="col">Branch Name</th>
                            </tr>
                        </thead>
                        <tbody id="branchTableBody">
                            <tr>
                                <td colspan="2" class="text-center text-muted">Loading branches...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="newBranchesModal" tabindex="-1" aria-labelledby="newBranchesModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="newBranchesModalLabel">New Branches Found</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover align-middle mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>Branch ID</th>
                                    <th>Branch Name</th>
                                </tr>
                            </thead>
                            <tbody id="newBranchesTableBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const branchTableBody = document.getElementById('branchTableBody');
        const checkUpdatesBtn = document.getElementById('checkUpdatesBtn');
        const checkUpdatesDot = document.getElementById('checkUpdatesDot');
        const loadingOverlay = document.getElementById('loadingOverlay');
        const newBranchesTableBody = document.getElementById('newBranchesTableBody');
        const newBranchesModalElement = document.getElementById('newBranchesModal');
        const newBranchesModal = new bootstrap.Modal(newBranchesModalElement);
        let jsonBranches = [];
        let pendingNewBranches = [];

        async function loadBranches() {
            try {
                const response = await fetch(`../../branch.json?_=${Date.now()}`, {
                    cache: 'no-store'
                });

                if (!response.ok) {
                    throw new Error('Failed to load branch.json');
                }

                const branches = await response.json();
                jsonBranches = branches;

                if (!Array.isArray(branches) || branches.length === 0) {
                    branchTableBody.innerHTML = '<tr><td colspan="2" class="text-center text-muted">No branch data found.</td></tr>';
                    return;
                }

                branchTableBody.innerHTML = branches.map(branch => `
                    <tr>
                        <td>${branch.branch_id ?? ''}</td>
                        <td>${branch.branch_name ?? ''}</td>
                    </tr>
                `).join('');
            } catch (error) {
                branchTableBody.innerHTML = '<tr><td colspan="2" class="text-center text-danger">Unable to load branch data.</td></tr>';
                console.error(error);
            }
        }

        function setLoading(isLoading) {
            loadingOverlay.style.display = isLoading ? 'flex' : 'none';
            checkUpdatesBtn.disabled = isLoading;
        }

        function setUpdateDot(hasPending) {
            checkUpdatesDot.style.display = hasPending ? 'inline-block' : 'none';
        }

        async function checkPendingIndicator() {
            try {
                const response = await fetch(`branch-list.php?action=check-new-branches&_=${Date.now()}`, {
                    cache: 'no-store'
                });
                if (!response.ok) {
                    setUpdateDot(false);
                    return;
                }
                const data = await response.json();
                const hasPending = Boolean(data.success && Array.isArray(data.new_branches) && data.new_branches.length > 0);
                setUpdateDot(hasPending);
            } catch (error) {
                setUpdateDot(false);
            }
        }

        function showNewBranchesModal(newBranches) {
            pendingNewBranches = newBranches;
            newBranchesTableBody.innerHTML = newBranches.map(branch => `
                <tr>
                    <td>${branch.branch_id ?? ''}</td>
                    <td>${branch.branch_name ?? ''}</td>
                </tr>
            `).join('');
            newBranchesModal.show();
        }

        async function saveBranchesToJson(newBranches) {
            setLoading(true);
            const syncResponse = await fetch(`branch-list.php?action=sync-branch-json&_=${Date.now()}`, {
                method: 'POST',
                cache: 'no-store',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ branches: newBranches })
            });
            setLoading(false);

            if (!syncResponse.ok) {
                throw new Error('Failed to update branch.json');
            }

            const syncData = await syncResponse.json();
            if (!syncData.success) {
                throw new Error(syncData.message || 'Failed to update branch.json');
            }

            pendingNewBranches = [];
            await Swal.fire({
                icon: 'success',
                title: 'branch.json Updated',
                text: `${syncData.updated ?? 0} branch(es) added to branch.json.`
            });
            await loadBranches();
            await checkPendingIndicator();
        }

        async function promptNewBranchesFound(newBranches) {
            const confirmResult = await Swal.fire({
                icon: 'question',
                title: 'New Branches Found',
                text: `${newBranches.length} new branch(es) detected. Proceed submission?`,
                showCancelButton: true,
                showDenyButton: true,
                allowOutsideClick: false,
                allowEnterKey: false,
                allowEscapeKey: false,
                confirmButtonText: 'Proceed',
                denyButtonText: '<i class="bi bi-eye me-1"></i> View New Branches',
                cancelButtonText: 'Cancel'
            });

            if (confirmResult.isDenied) {
                showNewBranchesModal(newBranches);
                return false;
            }

            return confirmResult.isConfirmed;
        }

        async function checkBranchUpdates() {
            setLoading(true);

            try {
                const response = await fetch(`branch-list.php?action=check-new-branches&_=${Date.now()}`, {
                    cache: 'no-store'
                });

                if (!response.ok) {
                    throw new Error('Failed to check new branches.');
                }

                const data = await response.json();
                if (!data.success || !Array.isArray(data.new_branches)) {
                    throw new Error('Invalid new branch payload.');
                }

                const newBranches = data.new_branches;

                if (newBranches.length === 0) {
                    setLoading(false);
                    await Swal.fire({
                        icon: 'info',
                        title: 'No New Branch',
                        text: 'All branch IDs already exist in the database.'
                    });
                    return;
                }

                setLoading(false);
                const shouldProceed = await promptNewBranchesFound(newBranches);
                if (!shouldProceed) {
                    return;
                }

                await saveBranchesToJson(newBranches);
            } catch (error) {
                setLoading(false);
                await Swal.fire({
                    icon: 'error',
                    title: 'Update Check Failed',
                    text: 'Unable to complete branch update check.'
                });
            }
        }

        newBranchesModalElement.addEventListener('hidden.bs.modal', () => {
            if (pendingNewBranches.length > 0) {
                promptNewBranchesFound(pendingNewBranches).then(async (shouldProceed) => {
                    if (shouldProceed) {
                        await saveBranchesToJson(pendingNewBranches);
                    }
                }).catch(async () => {
                    await Swal.fire({
                        icon: 'error',
                        title: 'Save Failed',
                        text: 'Unable to save new branches to branch.json.'
                    });
                });
            }
        });

        checkUpdatesBtn.addEventListener('click', checkBranchUpdates);
        loadBranches();
        checkPendingIndicator();
    </script>
</body>
</html>
