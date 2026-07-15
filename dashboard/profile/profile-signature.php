<?php
require_once __DIR__ . '/../../config/config.php';
session_start();
include '../../templates/middleware.php';

$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../login_form.php'); exit; }

// fetch signature
if (!function_exists('has_any_permission') || !has_any_permission(['Profile Signature','Profile','Profile View'])) { header('Location: ../home.php'); exit; }
$sig = null;
$stmt = $conn->prepare("SELECT signature FROM mldb.user_sig WHERE id_number = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param('s', $id);
    $stmt->execute();
    $stmt->bind_result($sig_blob);
    if ($stmt->fetch()) $sig = $sig_blob;
    $stmt->close();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Signature</title>
        <link rel="icon" href="../../images/MLW%20logo.png" type="image/png">

    <link rel="stylesheet" href="../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
     
    <style>
        .file-upload-area {
            border: 3px dashed #dee2e6;
            border-radius: 10px;
            padding: 28px;
            text-align: center;
            background-color: #f8f9fa;
            transition: all 0.25s ease;
            cursor: pointer;
            margin-bottom: 12px;
        }
        .file-upload-area:hover { background-color:#fff; border-color:#dc3545; }
        .file-upload-area.drag-over {
            border-color: #dc3545;
            background-color: #fff5f5;
            transform: translateY(-4px);
            box-shadow: 0 10px 20px rgba(220,53,69,0.06);
        }
        .file-upload-icon { font-size: 36px; color: #dc3545; margin-bottom: 10px; }
        #sig-preview{
            display:block;
            margin:0 auto 12px;
            max-width:360px;
            max-height:140px;
            width:auto;
            height:auto;
            object-fit:contain;
            border:1px solid #eee;
            padding:8px;
            border-radius:6px;
        }
        .file-upload-area h5 { margin:6px 0 4px; font-weight:700; }
        .file-upload-area p { margin:0; color:#6c757d; }

        /* Mode cards */
        .mode-cards { display:flex; gap:8px; margin-bottom:12px; }
        .mode-card { border:1px solid #e9ecef; padding:8px 10px; border-radius:8px; cursor:pointer; display:flex; gap:8px; align-items:center; background:#fff; }
        .mode-card .mode-icon { font-size:18px; color:#6c757d; width:28px; text-align:center; }
        .mode-card .mode-label { font-weight:700; font-size:13px; }
        .mode-card.selected { border-color:#dc3545; box-shadow:0 8px 24px rgba(220,53,69,0.06); }

        /* Draw canvas styles */
        #sigCanvas { background: #fff; touch-action: none; }

        /* Signature preview wrapper and delete button positioning */
        #sig-wrap { position: relative; padding-bottom: 48px; }
        #deleteSigBtn { position: absolute; right: 12px; bottom: 12px; }
        @media (max-width: 576px) {
            #sig-wrap { padding-bottom: 0; }
            #deleteSigBtn { position: static; display: block; margin: 8px auto 0; }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <?php include '../../templates/header_ui.php'; ?>
        <?php include '../../templates/sidebar.php'; ?>

        <?php bp_section_header_html('fa-solid fa-pen-nib', 'Signature', 'Manage your signature image'); ?>

        <div class="bp-card container-fluid mt-3 p-4">
            <div class="row">
                <div class="col-md-12">
                    <div class="card mb-3">
                        <div class="card-body">
                            <h5>Signature</h5>
                            <?php if (!empty($sig)): ?>
                                <div id="sig-wrap">
                                    <img id="sig-preview" src="data:image/png;base64,<?php echo base64_encode($sig); ?>" alt="Signature">
                                    <div class="mt-2">
                                        <button id="deleteSigBtn" class="btn btn-outline-danger">Delete signature</button>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="bp-empty">No Signature detected. Please add your signature.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (empty($sig)): ?>
                    <div class="mb-3">
                        <div class="mode-cards" id="modeCards">
                            <div class="mode-card selected" data-mode="upload" id="modeUpload">
                                <div class="mode-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                                <div class="mode-text"><div class="mode-label">Upload</div><small>Drag & drop or browse</small></div>
                            </div>
                            <div class="mode-card" data-mode="draw" id="modeDraw">
                                <div class="mode-icon"><i class="fa-solid fa-pen-nib"></i></div>
                                <div class="mode-text"><div class="mode-label">Draw</div><small>Draw your signature</small></div>
                            </div>
                        </div>
                    </div>

                    <div class="card" id="uploadCard">
                        <div class="card-body">
                            <h5>Upload Signature (PNG)</h5>
                            <div class="file-upload-area" id="fileUploadArea" tabindex="0">
                                <div class="file-upload-icon"><i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i></div>
                                <h5>Drag &amp; drop your PNG signature here</h5>
                                <p>or <label style="color:#dc3545;cursor:pointer;">browse<input id="fileInput" type="file" accept="image/png" style="display:none"></label></p>
                                <small class="text-muted d-block mt-2">Only PNG images are accepted. Max file size: 2MB.</small>
                            </div>
                        </div>
                    </div>
                    <div class="card" id="drawCard" style="display:none;">
                        <div class="card-body">
                            <h5>Draw Signature</h5>
                            <canvas id="sigCanvas" width="800" height="240" style="border:1px solid #eee; width:90%; max-width:900px; height:160px; display:block; margin:0 auto 8px;"></canvas>
                            <div class="d-flex justify-content-end" style="gap:8px;">
                                <button id="clearCanvasBtn" class="btn btn-outline-secondary">Clear</button>
                                <button id="saveCanvasBtn" class="btn btn-danger">Submit</button>
                            </div>
                            <small class="text-muted d-block mt-2">Use mouse or touch to draw. When you save, the drawing is converted to PNG and uploaded.</small>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<script>
$(function(){
    // Upload area initializer so we can re-bind after injecting HTML
    function initUploadArea() {
        const area = $('#fileUploadArea');
        const fileInput = $('#fileInput');
        if (!area.length || !fileInput.length) return;

        // remove previous handlers to avoid duplicates
        area.off('click dragover dragleave drop');
        fileInput.off('change');

        function handleUploadFile(file){
        if (!file) return;
        if (file.type !== 'image/png') { Swal.fire('Invalid file','Please upload a PNG image','error'); return; }
        if (file.size > 2 * 1024 * 1024) { Swal.fire('Too large','File must be <= 2MB','error'); return; }

        const fd = new FormData();
        fd.append('id_number', '<?php echo addslashes($id); ?>');
        fd.append('signature', file);

        // Validate PNG transparency before uploading
        isPngTransparent(file).then(function(isTransparent){
            console.log('profile-signature: png transparency', isTransparent);
            if (!isTransparent) {
                Swal.fire('Invalid PNG','Please upload a PNG with transparency (no opaque-only PNG).','error');
                return;
            }
            // Delegate to shared uploader
            uploadBlob(file);
        }).catch(function(err){
            console.error('transparency check error', err);
            // fallback to upload
            uploadBlob(file);
        });
    }

        // Click opens file picker
        area.on('click', function(){ fileInput.trigger('click'); });

        // Drag events
        area.on('dragover', function(e){ e.preventDefault(); e.stopPropagation(); $(this).addClass('drag-over'); });
        area.on('dragleave', function(e){ e.preventDefault(); e.stopPropagation(); $(this).removeClass('drag-over'); });
        area.on('drop', function(e){ e.preventDefault(); e.stopPropagation(); $(this).removeClass('drag-over'); const dt = e.originalEvent.dataTransfer; if (dt && dt.files && dt.files.length) handleUploadFile(dt.files[0]); });

        // file input change
        fileInput.on('change', function(e){ if (this.files && this.files[0]) handleUploadFile(this.files[0]); });
    }

    // shared uploader that handles a File/Blob and posts to the server
    function uploadBlob(file) {
        if (!file) return;
        console.log('profile-signature: uploading file', { name: file.name, type: file.type, size: file.size });
        const fd = new FormData();
        fd.append('id_number', '<?php echo addslashes($id); ?>');
        fd.append('signature', file, (file.name || 'signature.png'));
        for (let pair of fd.entries()) { console.log('formdata entry', pair[0], pair[1] && pair[1].name ? pair[1].name : pair[1]); }

        Swal.fire({ title:'Uploading...', allowOutsideClick:false, didOpen: ()=> Swal.showLoading() });

        $.ajax({
            url: '../../models/updated/upload-user-signature.php',
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json'
        }).done(function(resp){
            console.log('profile-signature: upload response', resp);
            Swal.close();
            if (resp && resp.success) {
                // Ensure only one preview container exists
                var sigBody = $('.card .card-body').first();
                // remove existing preview wrapper or preview image
                $('#sig-wrap').remove();
                sigBody.find('#sig-preview').remove();
                // remove any empty placeholders inside signature card
                sigBody.find('.bp-empty').remove();

                // build new preview wrapper
                var img = $('<img id="sig-preview" alt="Signature">');
                img.attr('src', 'data:image/png;base64,' + resp.sig_b64);
                var wrap = $('<div id="sig-wrap"></div>');
                wrap.append(img);
                wrap.append('<div class="mt-2"><button id="deleteSigBtn" class="btn btn-outline-danger">Delete signature</button></div>');

                // remove upload/draw/mode cards then insert the preview wrapper into the signature card
                $('#uploadCard').remove();
                $('#drawCard').remove();
                $('#modeCards').remove();
                sigBody.append(wrap);

                Swal.fire('Uploaded','Signature uploaded successfully','success');
            } else {
                console.error('profile-signature: upload failed response', resp);
                Swal.fire('Error', resp && resp.message ? resp.message : 'Upload failed', 'error');
            }
        }).fail(function(jqXHR, textStatus, errorThrown){
            console.error('profile-signature: ajax error', { textStatus: textStatus, errorThrown: errorThrown, responseText: jqXHR && jqXHR.responseText });
            try { Swal.close(); } catch(e){}
            Swal.fire('Error','Upload failed — check console for details','error');
        });
    }

    // Check whether a PNG File has any transparent pixels (approx by downscaling)
    function isPngTransparent(file) {
        return new Promise(function(resolve, reject){
            if (!file || file.type !== 'image/png') return resolve(false);
            var url = URL.createObjectURL(file);
            var img = new Image();
            img.onload = function(){
                try {
                    var sw = img.naturalWidth, sh = img.naturalHeight;
                    // downscale for performance
                    var max = 200;
                    var dw = sw > max ? max : sw;
                    var dh = sh > max ? Math.round(sh * (dw / sw)) : sh;
                    var c = document.createElement('canvas');
                    c.width = dw; c.height = dh;
                    var cx = c.getContext('2d');
                    cx.drawImage(img, 0, 0, sw, sh, 0, 0, dw, dh);
                    var imgd = cx.getImageData(0,0,dw,dh).data;
                    for (var i = 3; i < imgd.length; i += 4) {
                        if (imgd[i] < 255) { URL.revokeObjectURL(url); resolve(true); return; }
                    }
                    URL.revokeObjectURL(url);
                    resolve(false);
                } catch(e) {
                    URL.revokeObjectURL(url);
                    console.error('isPngTransparent error', e);
                    resolve(false);
                }
            };
            img.onerror = function(){ URL.revokeObjectURL(url); resolve(false); };
            img.src = url;
        });
    }

    // initialize on page load
    initUploadArea();
    // Draw area initializer
    function initDrawArea() {
        // prepare canvas/context early so mode toggle can resize it when shown
        var canvas = document.getElementById('sigCanvas');
        var ctx = canvas ? canvas.getContext('2d') : null;

        // mode toggle
        $('#modeCards .mode-card').off('click').on('click', function(){
            $('#modeCards .mode-card').removeClass('selected');
            $(this).addClass('selected');
            var mode = $(this).data('mode');
            if (mode === 'upload') {
                $('#drawCard').hide();
                $('#uploadCard').show();
            } else {
                $('#uploadCard').hide();
                $('#drawCard').show();
                // ensure canvas is sized and cleared when revealed
                setTimeout(function(){
                    try {
                        if (canvas && ctx && typeof resizeCanvasToDisplaySize === 'function') {
                            resizeCanvasToDisplaySize(canvas);
                            // ensure canvas is cleared (transparent) for immediate visibility
                            var w = canvas.clientWidth, h = canvas.clientHeight;
                            ctx.clearRect(0,0,w,h);
                        }
                    } catch(e){ console.error('sigCanvas: resize error', e); }
                }, 50);
            }
        });

        if (!canvas) return;
        var drawing = false;
        var lastX = 0, lastY = 0;

        function resizeCanvasToDisplaySize(canvas) {
            var ratio = window.devicePixelRatio || 1;
            var w = canvas.clientWidth;
            var h = canvas.clientHeight;
            if (canvas.width !== w * ratio || canvas.height !== h * ratio) {
                // Resize backing store
                canvas.width = Math.round(w * ratio);
                canvas.height = Math.round(h * ratio);
            }
            // Reset transform and scale once to map CSS pixels to backing store
            ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        }

        function startDraw(x,y){ drawing = true; lastX = x; lastY = y; }
        function stopDraw(){ drawing = false; }
        function drawLine(x,y){ if (!drawing) return; ctx.lineJoin = 'round'; ctx.lineCap = 'round'; ctx.strokeStyle = '#000'; ctx.lineWidth = 2.5; ctx.beginPath(); ctx.moveTo(lastX, lastY); ctx.lineTo(x, y); ctx.stroke(); lastX = x; lastY = y; }

        // pointer/mouse events with logging and touch fallback
        $(canvas).off('pointerdown pointermove pointerup pointerleave mousedown mousemove mouseup touchstart touchmove touchend touchcancel');
        $(canvas).on('pointerdown mousedown touchstart', function(e){
            try { e.preventDefault(); } catch(e){}
            var ev = e.originalEvent.touches ? e.originalEvent.touches[0] : e;
            var rect = canvas.getBoundingClientRect();
            var x = ev.clientX - rect.left, y = ev.clientY - rect.top;
            console.log('sigCanvas:start', x, y, e.type);
            // ensure stroke settings
            ctx.strokeStyle = '#000'; ctx.lineWidth = 2.5; ctx.lineJoin = 'round'; ctx.lineCap = 'round';
            ctx.beginPath();
            ctx.moveTo(x, y);
            startDraw(x,y);
        });

        $(canvas).on('pointermove mousemove touchmove', function(e){
            if (!drawing) return;
            try { e.preventDefault(); } catch(e){}
            var ev = e.originalEvent.touches ? e.originalEvent.touches[0] : e;
            var rect = canvas.getBoundingClientRect();
            var x = ev.clientX - rect.left, y = ev.clientY - rect.top;
            drawLine(x,y);
        });

        $(canvas).on('pointerup pointerleave mouseup touchend touchcancel', function(e){
            try { e.preventDefault(); } catch(e){}
            stopDraw();
        });

        // set crosshair cursor
        canvas.style.cursor = 'crosshair';

        // clear button
        $('#clearCanvasBtn').off('click').on('click', function(){
            // reset backing store and clear (transparent)
            resizeCanvasToDisplaySize(canvas);
            var w = canvas.clientWidth, h = canvas.clientHeight;
            ctx.clearRect(0,0,w,h);
        });

        // save button -> convert to blob/file and upload
        $('#saveCanvasBtn').off('click').on('click', function(){
            // ensure canvas is sized correctly and preserve transparency
            var tmpCanvas = document.createElement('canvas');
            tmpCanvas.width = canvas.width;
            tmpCanvas.height = canvas.height;
            var tctx = tmpCanvas.getContext('2d');
            // draw the user's canvas onto the tmp canvas without adding a white background
            tctx.drawImage(canvas, 0, 0, tmpCanvas.width, tmpCanvas.height);
            tmpCanvas.toBlob(function(blob){
                if (!blob) { Swal.fire('Error','Failed to create image','error'); return; }
                // create File so server receives a filename/type
                var file = new File([blob], 'signature.png', { type: 'image/png' });
                uploadBlob(file);
            }, 'image/png');
        });

        // ensure canvas is cleared / reset size on load
        $(window).off('resize.sigcanvas').on('resize.sigcanvas', function(){ try { resizeCanvasToDisplaySize(canvas); } catch(e){} });
        resizeCanvasToDisplaySize(canvas);
    }
    initDrawArea();

    // Delete signature handler
    const modeCardsHtml = `
        <div class="mb-3">
            <div class="mode-cards" id="modeCards">
                <div class="mode-card selected" data-mode="upload" id="modeUpload">
                    <div class="mode-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                    <div class="mode-text"><div class="mode-label">Upload</div><small>Drag & drop or browse</small></div>
                </div>
                <div class="mode-card" data-mode="draw" id="modeDraw">
                    <div class="mode-icon"><i class="fa-solid fa-pen-nib"></i></div>
                    <div class="mode-text"><div class="mode-label">Draw</div><small>Draw your signature</small></div>
                </div>
            </div>
        </div>`;

    const uploadCardHtml = `
        <div class="card" id="uploadCard">
            <div class="card-body">
                <h5>Upload Signature (PNG)</h5>
                <div class="file-upload-area" id="fileUploadArea" tabindex="0">
                    <div class="file-upload-icon"><i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i></div>
                    <h5>Drag &amp; drop your PNG signature here</h5>
                    <p>or <label style="color:#dc3545;cursor:pointer;">browse<input id="fileInput" type="file" accept="image/png" style="display:none"></label></p>
                    <small class="text-muted d-block mt-2">Only PNG images are accepted. Max file size: 2MB.</small>
                </div>
            </div>
        </div>`;

    const drawCardHtml = `
        <div class="card" id="drawCard" style="display:none;">
            <div class="card-body">
                <h5>Draw Signature</h5>
                <canvas id="sigCanvas" width="800" height="240" style="border:1px solid #eee; width:90%; max-width:900px; height:160px; display:block; margin:0 auto 8px;"></canvas>
                        <div class="d-flex" style="gap:8px;">
                    <div class="d-flex justify-content-end" style="gap:8px; width:100%;">
                        <button id="clearCanvasBtn" class="btn btn-outline-secondary">Clear</button>
                        <button id="saveCanvasBtn" class="btn btn-danger">Submit</button>
                    </div>
                </div>
                <small class="text-muted d-block mt-2">Use mouse or touch to draw. When you save, the drawing is converted to PNG and uploaded.</small>
            </div>
        </div>`;

    $(document).on('click', '#deleteSigBtn', function(e){
        e.preventDefault();
        const id = '<?php echo addslashes($id); ?>';
        Swal.fire({
            title: 'Delete signature?',
            text: 'This will permanently remove your stored signature.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, delete it',
            cancelButtonText: 'Cancel'
        }).then(function(result){
            if (!result.isConfirmed) return;

            console.log('profile-signature: deleting signature for', id);
            Swal.fire({ title:'Deleting...', allowOutsideClick:false, didOpen: ()=> Swal.showLoading() });

            $.ajax({
                url: '../../models/updated/delete-user-signature.php',
                method: 'POST',
                data: { id_number: id },
                dataType: 'json'
            }).done(function(resp){
                console.log('profile-signature: delete response', resp);
                Swal.close();
                if (resp && resp.success) {
                    // replace signature preview with upload card
                    $('#sig-wrap').replaceWith('<div class="bp-empty">No Signature detected. Please add your signature.</div>');
                    // insert upload card after the empty placeholder if not present
                    if (!$('#uploadCard').length) {
                        // remove any leftovers then insert mode selector + upload + draw cards
                        $('#modeCards').remove();
                        $('#uploadCard').remove();
                        $('#drawCard').remove();
                        $('.card .card-body').first().parent().after($(modeCardsHtml + uploadCardHtml + drawCardHtml));
                    }
                    initUploadArea();
                    initDrawArea();
                    Swal.fire('Deleted','Signature removed','success');
                } else {
                    console.error('profile-signature: delete failed', resp);
                    Swal.fire('Error', resp && resp.message ? resp.message : 'Delete failed', 'error');
                }
            }).fail(function(jqXHR, textStatus, errorThrown){
                console.error('profile-signature: delete ajax error', { textStatus: textStatus, errorThrown: errorThrown, responseText: jqXHR && jqXHR.responseText });
                try { Swal.close(); } catch(e){}
                Swal.fire('Error','Delete failed — check console for details','error');
            });
        });
    });
});
</script>

<?php include '../../templates/footer.php'; ?>
</body>
</html>
