<?php
// Global no-signature modal — include this file where needed.
// It checks whether the current user has an uploaded signature and
// prints a modal that forces them to go to the signature page when empty.

// Do not call session_start() here; including pages already start the session.
@include_once __DIR__ . '/../../templates/middleware.php';

// IDs that are allowed to proceed without a signature
$exemptIds = [
    '1013333',
    '94005055'
];

$showModal = false;
if (function_exists('resolve_user_identifier')) {
    $current_user = resolve_user_identifier();
    if (!empty($current_user) && isset($GLOBALS['conn'])) {
        // if current user is exempt, do not show the modal
        if (in_array((string)$current_user, $exemptIds, true)) {
            $showModal = false;
        } else {
        $sig = null;
        $stmt = $GLOBALS['conn']->prepare("SELECT signature FROM mldb.user_sig WHERE id_number = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $current_user);
            $stmt->execute();
            $stmt->bind_result($sig_blob);
            if ($stmt->fetch()) $sig = $sig_blob;
            $stmt->close();
        }
        if (empty($sig)) {
            $showModal = true;
        }
        }
    }
}

if ($showModal):
?>
<!-- No Signature Modal (global) -->
<style>
/* Ensure modal shows even on pages without modal styles */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(10,10,10,.45); backdrop-filter: blur(2px); align-items: center; justify-content: center; z-index: 2000; }
.modal-overlay.active { display: flex; }
.modal-card { background: #fff; border-radius: 10px; box-shadow: 0 18px 50px rgba(0,0,0,0.18); width: 100%; max-width: 520px; animation: mcSlide .18s ease both; }
.modal-card .modal-card-header { padding: 18px 22px; border-bottom: 1px solid rgba(0,0,0,0.04); }
.modal-card .modal-card-header h3 { margin:0; font-size:20px; }
.modal-card .modal-card-body { padding: 16px 22px; color: var(--n-700, #333); }
.modal-card .modal-card-footer { padding: 12px 22px 20px; }
@keyframes mcSlide { from { transform: translateY(8px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
</style>
<script>
// Render modal via JS and append to document.body to avoid placement/css cascade issues
document.addEventListener('DOMContentLoaded', function(){
    try { document.body.style.overflow = 'hidden'; } catch(e) {}

    // inject CSS into head if not present
    if (!document.getElementById('noSigModalStyles')) {
        var s = document.createElement('style');
        s.id = 'noSigModalStyles';
        s.innerHTML = '\n/* modal styles copied from site theme for consistent look */\n.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(10,10,10,.5); backdrop-filter: blur(3px); -webkit-backdrop-filter: blur(3px); z-index: 2000; align-items: center; justify-content: center; padding: 16px; }\n.modal-overlay.active { display: flex; }\n.modal-card { background: #fff; border-radius: 14px; box-shadow: 0 25px 50px rgba(0,0,0,.15); width: 100%; max-width: 520px; animation: mcSlide .2s cubic-bezier(.4,0,.2,1); overflow: hidden; }\n.modal-card-wide { max-width: 880px; }\n@keyframes mcSlide { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }\n.modal-card-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; background: var(--brand, #C62828); color: #fff; }\n.modal-card-header h3 { margin: 0; font-size: 15px; font-weight: 600; color: #fff; display: flex; align-items: center; gap: 8px; }\n.modal-close-btn { background: none; border: none; cursor: pointer; color: rgba(255,255,255,.8); font-size: 22px; line-height: 1; padding: 2px 6px; border-radius: 4px; transition: color .12s, background .12s; }\n.modal-close-btn:hover { color: #fff; background: rgba(255,255,255,.15); }\n.modal-card-body { padding: 20px 22px; }\n.modal-card-footer { padding: 12px 20px 16px; display: flex; align-items: center; justify-content: flex-end; gap: 8px; flex-wrap: wrap; border-top: 1px solid var(--n-200, #eee); }\n.btn-modal { display: inline-flex; align-items: center; gap: 6px; padding: 8px 18px; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; transition: background .14s, transform .1s, box-shadow .14s; box-shadow: 0 1px 3px rgba(0,0,0,.10); }\n.btn-modal:hover { transform: translateY(-1px); box-shadow: 0 4px 6px rgba(0,0,0,.08); }\n.btn-green { background: var(--success, #2E7D32); color: #fff; }\n';
        document.head.appendChild(s);
    }

    // create overlay
    var overlay = document.createElement('div');
    overlay.id = 'noSigModal';
    overlay.className = 'modal-overlay active';
    overlay.setAttribute('role','dialog');
    overlay.setAttribute('aria-modal','true');

    overlay.innerHTML = '\n        <div class="modal-card modal-card-wide" role="document">\n            <div class="modal-card-header">\n                <h3>Warning</h3>\n            </div>\n            <div class="modal-card-body">\n                <p>Upload or Draw your signature first in order to proceed.</p>\n            </div>\n            <div class="modal-card-footer" style="text-align:right;">\n                <button id="noSigOkBtn" class="btn btn-green">Ok</button>\n            </div>\n        </div>\n';
    overlay.innerHTML = '\n        <div class="modal-card modal-card-wide" role="document">\n            <div class="modal-card-header">\n                <h3>Warning</h3>\n            </div>\n            <div class="modal-card-body">\n                <p>Upload or Draw your signature first in order to proceed.</p>\n            </div>\n            <div class="modal-card-footer" style="text-align:right;">\n                <button id="noSigOkBtn" class="btn-modal btn-green">Ok</button>\n            </div>\n        </div>\n';

    document.body.appendChild(overlay);

    // Prevent clicks on overlay background (no close)
    overlay.addEventListener('click', function(e){ e.stopPropagation(); e.preventDefault(); });

    window.addEventListener('keydown', function(e){ if (e.key === 'Escape' || e.key === 'Esc') { e.preventDefault(); e.stopPropagation(); } }, true);

    var btn = document.getElementById('noSigOkBtn');
    if (btn) {
        btn.addEventListener('click', function(){
            try { document.body.style.overflow = ''; } catch(e) {}
            window.location.href = '/billspayment/dashboard/profile/profile-signature.php';
        });
    }
});
</script>
<?php
endif;

?>
