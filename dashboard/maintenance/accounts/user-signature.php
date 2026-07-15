<?php
// Connect to the database
require_once __DIR__ . '/../../../config/config.php';
require '../../../vendor/autoload.php';

// Start the session
session_start();


if (isset($_SESSION['user_type'])) {
    $current_user_email = '';
    if ($_SESSION['user_type'] === 'admin' && isset($_SESSION['admin_email'])) {
        $current_user_email = $_SESSION['admin_email'];
    } elseif ($_SESSION['user_type'] === 'user' && isset($_SESSION['user_email'])) {
        $current_user_email = $_SESSION['user_email'];
    }
}

// Fetch users from database using MySQLi


$users = [];
try {
    $query = "WITH user_list AS (
                    SELECT
                        muf.id_number,
                        CONCAT_WS(' ',
                            muf.first_name,
                            muf.middle_name,
                            muf.last_name
                        ) AS full_name,
                        mus.signature
                    FROM 
                        mldb.user_form muf
                    LEFT JOIN 
                        mldb.user_sig mus
                        ON muf.id_number = mus.id_number
                    WHERE muf.status = 'Active'
                    ORDER BY muf.date_created DESC
                )
                SELECT * FROM user_list";
    $result = mysqli_query($conn, $query);
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $users[] = $row;
        }
        mysqli_free_result($result);
    } else {
        error_log("Database query error: " . mysqli_error($conn));
    }
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Signature | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <!-- custom CSS file link  -->
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>

    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">
</head>
<body>
    <div class="main-container">
        <?php include '../../../templates/header_ui.php'; ?>
        <!-- Show and Hide Side Nav Menu -->
        <?php include '../../../templates/sidebar.php'; ?>
        <div id="loading-overlay">
            <div class="loading-spinner"></div>
        </div>
        <div class="bp-section-header" role="region" aria-label="Page title">
            <div class="bp-section-title">
                <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                <div>
                    <h2>User Signature</h2>
                    <p class="bp-section-sub">Manage user signatures and related settings.</p>
                </div>
            </div>
        </div>
        <div class="bp-card container-fluid mt-3 p-4">
            <!-- Your content goes here -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                    <div class="card-header">
                        <div class="input-group" style="display: flex; justify-content: space-between; align-items: center;">
                            <div class="input-group-append" style="display: flex; align-items: center; gap: 10px;">
                                <form action="" style="display: flex; align-items: center; gap: 10px;">
                                <input type="text" id="searchInput" class="form-control" placeholder="Search by any field..." style="width: 250px;" autocomplete="off">
                                <button type="button" id="clearFilters" class="btn btn-secondary">Clear</button>
                                </form>
                            </div>
                            <div class="input-group-append" style="display: flex; align-items: center; gap: 5px;">
                                <button type="button" id="uploadSignatureBtn" class="btn btn-danger" data-bs-target="#addUserModal" disabled><i class="fa fa-upload"></i> Upload Signature</button>
                                <button type="button" id="removeSignatureBtn" class="btn btn-danger" data-bs-target="#editUserModal" disabled><i class="fa fa-trash"></i> Remove Signature</button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <table class="table table-hover" id="users-table">
                            <thead>
                                <tr>
                                    <th>No.</th>
                                    <th>ID Number</th>
                                    <th>Full Name</th>
                                    <th>Signature Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($users)): ?>
                                <?php foreach ($users as $index => $user): ?>
                                    <tr data-user-id="<?php echo htmlspecialchars($user['id_number'] ?? ''); ?>"
                                        data-user-data='<?php echo json_encode($user); ?>'
                                        data-has-signature="<?php echo !empty($user['signature']) ? '1' : '0'; ?>"
                                        style="cursor: pointer;">
                                        <td><?php echo $index + 1; ?></td>
                                        <td><?php echo htmlspecialchars($user['id_number'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars(trim(implode(' ', array_filter([
                                            $user['full_name'] ?? ''
                                        ], static fn($value) => $value !== null && trim((string)$value) !== '')))); ?></td>
                                        <td>
                                            <?php if (!empty($user['signature'])): ?>
                                                <?php $imgData = base64_encode($user['signature']); ?>
                                                <img src="data:image/png;base64,<?php echo $imgData; ?>" alt="Signature of <?php echo htmlspecialchars($user['full_name'] ?? 'User'); ?>" style="max-height:40px;" />
                                            <?php else: ?>
                                                <?php echo 'No Signature'; ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">
                                        <i class="fa fa-users fa-2x mb-2"></i><br>
                                        No users found in the database
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                            <tfoot>
                            </tfoot>
                        </table>
                    </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
<?php include '../../../templates/footer.php'; ?>
<script>
    (function(){
        const searchInput = document.getElementById('searchInput');
        const table = document.getElementById('users-table');
        const tbody = table.querySelector('tbody');
        const uploadBtn = document.getElementById('uploadSignatureBtn');
        const removeBtn = document.getElementById('removeSignatureBtn');
        let selectedRow = null;

        // Live search: filter rows by any cell text
        searchInput.addEventListener('input', function(e){
            const q = this.value.trim().toLowerCase();
            const rows = tbody.querySelectorAll('tr');
            rows.forEach(row => {
                const text = row.innerText.toLowerCase();
                row.style.display = q === '' || text.includes(q) ? '' : 'none';
            });
            // clear selection if filtered out
            if (selectedRow && selectedRow.style.display === 'none') {
                clearSelection();
            }
        });

        // Clear filters button
        const clearFilters = document.getElementById('clearFilters');
        if (clearFilters) {
            clearFilters.addEventListener('click', function(){
                searchInput.value = '';
                const rows = tbody.querySelectorAll('tr');
                rows.forEach(row => row.style.display = '');
                clearSelection();
            });
        }

        // Row click selection and toggle buttons based on signature presence
        tbody.addEventListener('click', function(e){
            let tr = e.target.closest('tr');
            if (!tr) return;
            // ignore if it's the empty 'no users' row
            if (tr.querySelector('td') && tr.querySelector('td').hasAttribute('colspan')) return;

            if (selectedRow) selectedRow.classList.remove('table-primary');
            selectedRow = tr;
            selectedRow.classList.add('table-primary');

            // read data-user-data JSON
            // Prefer explicit attribute for signature presence (avoids parsing binary blob in JSON)
            const hasSignatureAttr = selectedRow.getAttribute('data-has-signature');
            const hasSignature = hasSignatureAttr === '1';

            if (hasSignature) {
                uploadBtn.disabled = true;
                removeBtn.disabled = false;
            } else {
                uploadBtn.disabled = false;
                removeBtn.disabled = true;
            }

            // store selected id on buttons (for later actions)
            const id = selectedRow.getAttribute('data-user-id') || '';
            uploadBtn.dataset.userId = id;
            removeBtn.dataset.userId = id;
        });

        function clearSelection(){
            if (selectedRow) selectedRow.classList.remove('table-primary');
            selectedRow = null;
            uploadBtn.disabled = true;
            removeBtn.disabled = true;
            delete uploadBtn.dataset.userId;
            delete removeBtn.dataset.userId;
        }

        // initialize buttons disabled
        clearSelection();
    })();
</script>
<script>
// Upload modal and processing for admin user-signature page
(function(){
    const uploadBtn = document.getElementById('uploadSignatureBtn');
    const removeBtn = document.getElementById('removeSignatureBtn');

    function isPngTransparentFile(file){
        return new Promise((resolve)=>{
            if (!file || file.type !== 'image/png') return resolve(false);
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = function(){
                try{
                    const c = document.createElement('canvas');
                    const dw = Math.min(200, img.naturalWidth);
                    const dh = Math.round(img.naturalHeight * (dw / img.naturalWidth));
                    c.width = dw; c.height = dh;
                    const cx = c.getContext('2d');
                    cx.drawImage(img, 0, 0, img.naturalWidth, img.naturalHeight, 0, 0, dw, dh);
                    const d = cx.getImageData(0,0,dw,dh).data;
                    for (let i=3;i<d.length;i+=4) if (d[i] < 255) { URL.revokeObjectURL(url); return resolve(true); }
                    URL.revokeObjectURL(url); return resolve(false);
                } catch(e){ URL.revokeObjectURL(url); return resolve(false); }
            };
            img.onerror = function(){ URL.revokeObjectURL(url); return resolve(false); };
            img.src = url;
        });
    }

    // remove background by replacing pixels close to sampled bg color with transparency
    function removeBackgroundPng(file){
        return new Promise((resolve,reject)=>{
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = function(){
                try{
                    const w = img.naturalWidth, h = img.naturalHeight;
                    const canvas = document.createElement('canvas');
                    canvas.width = w; canvas.height = h;
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(img,0,0);
                    const imgd = ctx.getImageData(0,0,w,h);
                    const d = imgd.data;
                    // sample top-left pixel as background color
                    const bgR = d[0], bgG = d[1], bgB = d[2];
                    const threshold = 30; // color distance threshold
                    for (let i=0;i<d.length;i+=4){
                        const dr = d[i]-bgR, dg = d[i+1]-bgG, db = d[i+2]-bgB;
                        const dist = Math.sqrt(dr*dr+dg*dg+db*db);
                        if (dist < threshold) d[i+3] = 0; // make transparent
                    }
                    ctx.putImageData(imgd,0,0);
                    canvas.toBlob(function(blob){ URL.revokeObjectURL(url); if (blob) resolve(blob); else reject(new Error('toBlob failed')); }, 'image/png');
                } catch(e){ URL.revokeObjectURL(url); reject(e); }
            };
            img.onerror = function(){ URL.revokeObjectURL(url); reject(new Error('image load error')); };
            img.src = url;
        });
    }

    async function handleFileAndUpload(file, id_number){
        if (!file) return Swal.fire('No file','Please provide a PNG file','error');
        if (file.type !== 'image/png') return Swal.fire('Invalid file','Only PNG is accepted','error');
        if (file.size > 5*1024*1024) return Swal.fire('Too large','Max 5MB allowed','error');

        const hasTrans = await isPngTransparentFile(file);
        let uploadBlob = file;
        if (!hasTrans) {
            // remove background client-side
            try{
                const processed = await removeBackgroundPng(file);
                uploadBlob = new File([processed], 'signature.png', { type: 'image/png' });
            } catch(err){ console.error('bg removal failed', err); /* fall back to original */ }
        }

        // send to server
        const fd = new FormData();
        fd.append('id_number', id_number);
        fd.append('signature', uploadBlob, 'signature.png');

        Swal.fire({ title:'Uploading...', allowOutsideClick:false, didOpen: ()=> Swal.showLoading() });
        try{
            const resp = await fetch('../../../models/updated/upload-user-signature.php', { method:'POST', body: fd });
            const json = await resp.json();
            Swal.close();
            if (json && json.success) {
                Swal.fire('Uploaded','Signature uploaded','success');
                // update row attribute and visible cell to indicate signature present
                const sigB64 = json.sig_b64 || '';
                updateRowSignature(id_number, true, sigB64);
            } else {
                Swal.fire('Error', json && json.message ? json.message : 'Upload failed', 'error');
            }
        } catch(e){ Swal.close(); console.error(e); Swal.fire('Error','Upload failed','error'); }
    }

    function openUploadModal(id_number){
        const html = `
            <div id="swal-upload-area" style="text-align:center; padding:12px;">
                <div id="swal-drop" style="border:2px dashed #dee2e6; border-radius:8px; padding:18px; cursor:pointer;">
                    <div style="font-size:28px;color:#dc3545;margin-bottom:6px;"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                    <div>Drag & drop PNG here or click to browse</div>
                    <input type="file" id="swal-file-input" accept="image/png" style="display:none;" />
                    <div style="margin-top:8px;color:#6c757d;font-size:12px;">PNG will be converted to transparent background if needed. Max file size: 2MB.</div>
                </div>
            </div>`;

        Swal.fire({ title: 'Upload signature', html: html, showCancelButton:true, showConfirmButton:false , allowOutsideClick:false, allowEscapeKey:false, allowEnterKey:false, width:600, didOpen: ()=>{
            const drop = document.getElementById('swal-drop');
            const fin = document.getElementById('swal-file-input');
            drop.addEventListener('click', ()=> fin.click());
            drop.addEventListener('dragover', (e)=>{ e.preventDefault(); drop.classList.add('drag-over'); });
            drop.addEventListener('dragleave', ()=> drop.classList.remove('drag-over'));
            drop.addEventListener('drop', (e)=>{ e.preventDefault(); drop.classList.remove('drag-over'); const f = e.dataTransfer.files && e.dataTransfer.files[0]; if (f) handleFileAndUpload(f, id_number); });
            fin.addEventListener('change', ()=>{ if (fin.files && fin.files[0]) handleFileAndUpload(fin.files[0], id_number); });
        }});
    }

    uploadBtn && uploadBtn.addEventListener('click', function(){
        const id = this.dataset.userId;
        if (!id) return Swal.fire('No user selected','Please select a user row first','info');
        openUploadModal(id);
    });

    // remove button: show confirm then post to delete endpoint
    removeBtn && removeBtn.addEventListener('click', function(){
        const id = this.dataset.userId;
        if (!id) return Swal.fire('No user selected','Please select a user row first','info');
        Swal.fire({ title:'Remove signature?', text:'This will permanently remove your stored signature.', icon:'warning', showCancelButton:true, allowOutsideClick:false, allowEscapeKey:false, allowEnterKey:false, confirmButtonText: 'Yes, remove it', cancelButtonText: 'Cancel' }).then(function(res){
            if (!res.isConfirmed) return;
            Swal.fire({ title:'Deleting...', allowOutsideClick:false, didOpen: ()=> Swal.showLoading() });
            fetch('../../../models/updated/delete-user-signature.php', { method:'POST', headers:{ 'Content-Type':'application/x-www-form-urlencoded' }, body: 'id_number='+encodeURIComponent(id) })
            .then(r=>r.json()).then(json=>{ Swal.close(); if (json && json.success) {
                Swal.fire('Deleted','Signature removed','success');
                updateRowSignature(id, false);
            } else Swal.fire('Error', json && json.message ? json.message : 'Delete failed','error'); })
            .catch(e=>{ Swal.close(); console.error(e); Swal.fire('Error','Delete failed','error'); });
        });
    });
})();
// Update table row UI when signature added/removed
function updateRowSignature(id, hasSignature, base64Data = ''){
    const row = document.querySelector('tr[data-user-id="'+id+'"]');
    if (!row) return;
    row.setAttribute('data-has-signature', hasSignature ? '1' : '0');
    // signature cell is the 4th cell (index 3)
    const cell = row.cells[3];
    if (!cell) return;
    if (hasSignature){
        // prefer server-provided base64 if available
        if (base64Data){
            cell.innerHTML = '<img src="data:image/png;base64,'+base64Data+'" alt="Signature" style="max-height:40px;" />';
        } else {
            cell.innerHTML = '<span class="text-muted">Has Signature</span>';
        }
    } else {
        cell.textContent = 'No Signature';
    }
    // if this row is selected update buttons state
    if (row.classList.contains('table-primary')){
        const uploadBtn = document.getElementById('uploadSignatureBtn');
        const removeBtn = document.getElementById('removeSignatureBtn');
        if (uploadBtn && removeBtn){
            uploadBtn.disabled = hasSignature;
            removeBtn.disabled = !hasSignature;
        }
    }
}
</script>
</html>