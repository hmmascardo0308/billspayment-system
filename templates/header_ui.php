<?php
// Header UI include: pure CSS header with burger, centered logo, and user info on right
?>
<style>
/* Header UI (pure CSS, no Bootstrap) */
.bp-header {
    background-color: #dc3545; /* solid red */
    color: #ffffff;
    height: 64px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 16px;
    position: relative;
    box-shadow: 0 1px 0 rgba(0,0,0,0.06);
    z-index: 1050;
}
.bp-left, .bp-right {
    display: flex;
    align-items: center;
    min-width: 120px;
}
.bp-center {
    position: absolute;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    align-items: center;
    justify-content: center;
}
.bp-logo {
    height: 300px;
    max-width: 400px;
    object-fit: contain;
}
.bp-menu {
    background: transparent;
    border: none;
    color: #fff;
    cursor: pointer;
    padding: 8px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-weight: 700;
}
.bp-menu:focus { outline: 2px solid rgba(255,255,255,0.18); outline-offset: 2px; }
.bp-burger {
    width: 20px;
    height: 2px;
    background: #fff;
    display: block;
    position: relative;
}
.bp-burger:before, .bp-burger:after {
    content: '';
    position: absolute;
    left: 0;
    width: 20px;
    height: 2px;
    background: #fff;
}
.bp-burger:before { top: -6px; }
.bp-burger:after { top: 6px; }
.bp-user {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    font-size: 13px;
}
.bp-user .name { font-weight: 700; }
.bp-user .email { font-size: 12px; opacity: 0.9; }
/* Responsive adjustments */
@media (max-width: 640px) {
    .bp-center { left: 50%; transform: translateX(-50%); }
    .bp-logo { height: 34px; }
    .bp-right { min-width: 90px; }
}

</style>

<!-- Shared BP UI styles applied globally when header is included -->
<style>
/* Core BP UI */
.bp-section-header { background:#fff; border-left:4px solid #dc3545; padding:12px 16px; margin:18px 0 12px; border-radius:8px; box-shadow:0 6px 18px rgba(16,24,40,0.04); }
.bp-section-header h2{ margin:0; font-size:1.25rem; color:#212529; font-weight:700; }
.bp-section-sub{ color:#6c757d; font-size:0.95rem; margin-top:6px }
.bp-section-title { display:flex; align-items:center; gap:12px; }
.bp-section-title i { font-size:28px; color:#dc3545; }
.bp-card{ background:#ffffff; border-radius:10px; box-shadow:0 10px 24px rgba(16,24,40,0.06); border:1px solid #f1f1f1; padding:20px; }
.proceed-container{ display:flex; justify-content:flex-end; gap:12px; }
.btn-proceed{ background:#dc3545; color:#fff; border:none; padding:10px 18px; border-radius:8px; font-weight:700; }

/* Responsive helpers */
@media (max-width:768px){ .bp-card{ padding:12px; } .bp-section-header{ padding:10px 12px } }
</style>

<style>
/* Layout helpers used across pages */
.bp-card-body { padding: 8px 0 0 0; }
.bp-grid { display: grid; grid-template-columns: 1fr 320px; gap: 20px; align-items: start; }
@media (max-width: 900px) { .bp-grid { grid-template-columns: 1fr; } }

/* File card compact variant used by import pages */
.bp-file-card { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px; border-radius:8px; border:1px solid #eef0f2; background:#fff; }
.bp-file-card .meta { display:flex; gap:12px; align-items:center; }
.bp-file-card .meta .name { font-weight:600; color:#212529; }
.bp-file-card .actions { display:flex; gap:8px; align-items:center; }

.bp-empty { text-align:center; padding:28px; color:#6c757d; }
</style>

<header class="bp-header" role="banner">
    <div class="bp-left">
        <button id="menu-btn" class="bp-menu" aria-label="Toggle menu">
            <span class="bp-burger" aria-hidden="true"></span>
            <span class="visually-hidden">Menu</span>
        </button>
    </div>

    <div class="bp-center">
        <img src="/BillsPayment/images/mlwhite.png" alt="Logo" class="bp-logo">
    </div>

    <div class="bp-right">
        <?php
        $display_name = 'GUEST';
        $display_email = '';
        if (isset($_SESSION['user_type'])) {
            if ($_SESSION['user_type'] === 'admin') {
                $display_name = $_SESSION['admin_name'] ?? 'ADMIN';
                $display_email = $_SESSION['admin_email'] ?? '';
            } elseif ($_SESSION['user_type'] === 'user') {
                $display_name = $_SESSION['user_name'] ?? 'USER';
                $display_email = $_SESSION['user_email'] ?? '';
            }
        }
        ?>
        <div class="bp-user">
            <span class="name"><?php echo htmlspecialchars($display_name); ?></span>
            <span class="email"><?php echo htmlspecialchars($display_email); ?></span>
        </div>
    </div>
</header>

<?php
// Helper to render a branded section header used across pages
function bp_section_header_html($icon = 'fa-solid fa-file-import', $title = '', $subtitle = '') {
    $icon_html = $icon ? '<i class="' . htmlspecialchars($icon) . '"></i>' : '';
    $title_html = htmlspecialchars($title);
    $subtitle_html = $subtitle !== '' ? '<div class="bp-section-sub">' . htmlspecialchars($subtitle) . '</div>' : '';
    echo '<div class="bp-section-header"><div class="bp-section-title">' . $icon_html . '<div><h2>' . $title_html . '</h2>' . $subtitle_html . '</div></div></div>';
}

?>

