<?php
include '../../config/config.php';
session_start();
include '../../templates/middleware.php';

$id = resolve_user_identifier();
if (empty($id)) {
    header('Location: ../../login_form.php');
    exit;
}

if (!function_exists('has_any_permission') || !has_any_permission(['Profile','Profile View','Profile Signature'])) { header('Location: ../home.php'); exit; }

$row = get_user_row($id);
$perms = get_current_user_permissions();
$level = get_user_access_level();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Profile</title>
        <link rel="icon" href="../../images/MLW%20logo.png" type="image/png">
    <link rel="stylesheet" href="../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        /* Profile revamp styles */
        .profile-row { display:flex; gap:16px; align-items:flex-start; }
        .profile-card { border-radius:10px; box-shadow:0 10px 24px rgba(16,24,40,0.04); border:1px solid #f1f1f1; background:#fff; }
        .profile-card .card-body { padding:18px; }
        .info-table th { width:160px; font-weight:700; color:#374151; }
        .info-icon { width:28px; text-align:center; margin-right:8px; color:#dc3545; }

        /* Access level card */
        .access-level-badge { display:inline-block; padding:10px 14px; background:#fee2e2; color:#b91c1c; font-weight:700; border-radius:8px; font-size:18px; }
        .access-card-title { display:flex; align-items:center; gap:10px; }
        .access-card-title i { font-size:22px; color:#dc3545; }

        /* Permission preview styles (compact) */
        #profilePermissions { display:block; gap:8px; margin-top:6px; }
        #profilePermissions .preview-item { display:flex; align-items:center; justify-content:space-between; padding:8px 10px; border-radius:8px; border:1px solid #eef2f7; background:#ffffff; margin-bottom:6px; }
        #profilePermissions .preview-item .label { color:#374151; font-weight:600; }
        #profilePermissions .preview-item .check i { color:#dc3545; }
    </style>
</head>
<body>
    <div class="main-container">
        <?php include '../../templates/header_ui.php'; ?>
        <?php include '../../templates/sidebar.php'; ?>

        <?php bp_section_header_html('fa-solid fa-user', 'My Profile', 'View and manage your account'); ?>

        <div class="bp-card container-fluid mt-3 p-4">
            <div class="profile-row">
                <div style="flex:1 1 65%;">
                    <div class="profile-card mb-3 card">
                        <div class="card-body">
                            <h5><i class="fa-solid fa-user" style="color:#dc3545;margin-right:8px"></i> User Info</h5>
                            <table class="table table-borderless info-table">
                                <tr><th><span class="info-icon"><i class="fa-solid fa-id-card"></i></span>ID Number</th><td><?php echo htmlspecialchars($row['id_number'] ?? ''); ?></td></tr>
                                <tr><th><span class="info-icon"><i class="fa-solid fa-signature"></i></span>First Name</th><td><?php echo htmlspecialchars($row['first_name'] ?? ''); ?></td></tr>
                                <tr><th><span class="info-icon"><i class="fa-solid fa-user-pen"></i></span>Middle Name</th><td><?php echo htmlspecialchars($row['middle_name'] ?? ''); ?></td></tr>
                                <tr><th><span class="info-icon"><i class="fa-solid fa-user"></i></span>Last Name</th><td><?php echo htmlspecialchars($row['last_name'] ?? ''); ?></td></tr>
                                <tr><th><span class="info-icon"><i class="fa-solid fa-users"></i></span>User Type</th><td><?php echo htmlspecialchars($row['user_type'] ?? ''); ?></td></tr>
                            </table>
                        </div>
                    </div>

                    <div class="profile-card card">
                        <div class="card-body">
                            <h5><i class="fa-solid fa-key" style="color:#dc3545;margin-right:8px"></i> User Login</h5>
                            <table class="table table-borderless info-table">
                                <tr><th><span class="info-icon"><i class="fa-solid fa-at"></i></span>Username</th><td><?php echo htmlspecialchars($row['email'] ?? ''); ?></td></tr>
                                <tr><th><span class="info-icon"><i class="fa-solid fa-lock"></i></span>Password</th><td>******** <button id="resetPasswordBtn" class="btn btn-danger btn-sm ms-2">Reset</button></td></tr>
                            </table>
                            <small class="text-muted">Resetting password will set it to the default and log you out.</small>
                        </div>
                    </div>
                </div>

                <div style="flex:0 0 320px;">
                    <div class="profile-card card mb-3">
                        <div class="card-body">
                            <div class="access-card-title"><i class="fa-solid fa-shield-halved"></i><div>
                                <div style="font-weight:700;">Access Level</div>
                                <div class="access-level-badge"><?php echo htmlspecialchars((string)$level); ?></div>
                            </div></div>
                        </div>
                    </div>

                    <div class="profile-card card">
                        <div class="card-body">
                            <h6 style="display:flex;align-items:center;gap:8px;"><i class="fa-solid fa-list-check" style="color:#dc3545"></i> Permissions</h6>
                            <?php if (!empty($perms)): ?>
                                <div id="profilePermissions">
                                    <?php foreach ($perms as $p): ?>
                                        <div class="preview-item"><div class="label"><?php echo htmlspecialchars($p); ?></div><div class="check"><i class="fa-solid fa-check-circle"></i></div></div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="bp-empty">No explicit permissions assigned.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<script>
$(function(){
    $('#resetPasswordBtn').on('click', function(){
        Swal.fire({
            title: 'Reset password?',
            text: 'Your password will be reset to the default and you will be logged out.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, reset',
            cancelButtonText: 'Cancel'
        }).then(function(res){
            if (res.isConfirmed) {
                $.ajax({
                    url: '../../models/updated/updated-reset-user-password.php',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({ id_number: '<?php echo addslashes($row['id_number'] ?? ''); ?>', username: '<?php echo addslashes($row['email'] ?? ''); ?>' }),
                    dataType: 'json'
                }).done(function(resp){
                    if (resp.success) {
                        Swal.fire({ icon: 'success', title: 'Password reset', text: resp.message }).then(function(){
                            window.location = '../../logout.php';
                        });
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: resp.message || 'Reset failed' });
                    }
                }).fail(function(){
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Request failed' });
                });
            }
        });
    });
});
</script>

<?php include '../../templates/footer.php'; ?>
</body>
</html>
