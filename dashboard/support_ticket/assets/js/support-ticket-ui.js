(function () {
    function initModeCards() {
        var groups = document.querySelectorAll('[data-st-mode-group]');
        groups.forEach(function (group) {
            var paramName = group.getAttribute('data-st-param') || 'mode';
            var radios = group.querySelectorAll('input[type="radio"][name]');
            var cards = group.querySelectorAll('.mode-card');

            function setMode(mode) {
                cards.forEach(function (card) {
                    card.classList.toggle('selected', card.getAttribute('data-mode') === mode);
                });

                document.querySelectorAll('[data-st-panel]').forEach(function (panel) {
                    panel.classList.toggle('hidden', panel.getAttribute('data-st-panel') !== mode);
                });

                var params = new URLSearchParams(window.location.search);
                params.set(paramName, mode);
                history.replaceState(null, '', window.location.pathname + '?' + params.toString());
            }

            radios.forEach(function (radio) {
                radio.addEventListener('change', function () {
                    if (radio.checked) {
                        setMode(radio.value);
                    }
                });
            });

            var checked = group.querySelector('input[type="radio"]:checked');
            if (checked) {
                setMode(checked.value);
            }
        });
    }

    function initCreateModal() {
        var openBtn = document.getElementById('stOpenCreateModal');
        var closeBtn = document.getElementById('stCloseCreateModal');
        var modal = document.getElementById('createTicketModal');

        if (!modal) {
            return;
        }

        function openModal() {
            modal.classList.add('open');
        }

        function closeModal() {
            modal.classList.remove('open');
        }

        if (openBtn) {
            openBtn.addEventListener('click', function (e) {
                e.preventDefault();
                openModal();
            });
        }

        if (closeBtn) {
            closeBtn.addEventListener('click', function (e) {
                e.preventDefault();
                closeModal();
            });
        }

        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                closeModal();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
    }

    function adjustTrailCardHeights(root) {
        var container = root || document;
        var cards = container.querySelectorAll('.tm-trail-card');
        cards.forEach(function (card) {
            var body = card.querySelector('.tm-trail-card-body');
            if (!body) return;
            if (card.classList.contains('tm-expanded')) {
                // for the latest card keep fully expanded (no max-height cap)
                if (card.hasAttribute('data-tm-latest')) {
                    body.style.maxHeight = 'none';
                } else {
                    // ensure measurement happens when element is visible
                    body.style.maxHeight = body.scrollHeight + 'px';
                }
            } else {
                body.style.maxHeight = '0px';
            }
        });
    }

    function toggleTrailCard(card) {
        if (!card) return;
        if (card.hasAttribute('data-tm-latest')) return; // cannot toggle latest
        var body = card.querySelector('.tm-trail-card-body');
        if (!body) return;
        var header = card.querySelector('.tm-trail-card-header');
        var isExpanded = card.classList.contains('tm-expanded');

        if (isExpanded) {
            // collapse: measure current height then animate to 0
            var start = body.scrollHeight;
            body.style.maxHeight = start + 'px';
            // force reflow
            /* eslint-disable no-unused-expressions */ void body.offsetHeight;
            requestAnimationFrame(function () {
                body.style.maxHeight = '0px';
                card.classList.remove('tm-expanded');
                if (header) header.setAttribute('aria-expanded', 'false');
            });
        } else {
            // expand: add class, measure, animate to measured height, then remove cap
            card.classList.add('tm-expanded');
            // ensure any layout changes from the class apply
            /* eslint-disable no-unused-expressions */ void body.offsetHeight;
            var target = body.scrollHeight;
            // start from 0 to ensure transition
            body.style.maxHeight = '0px';
            // animate to measured height
            requestAnimationFrame(function () {
                body.style.maxHeight = target + 'px';
                if (header) header.setAttribute('aria-expanded', 'true');
            });

            // after expand transition ends, remove maxHeight cap so content can grow naturally
            var onEnd = function (e) {
                if (e.propertyName !== 'max-height') return;
                body.removeEventListener('transitionend', onEnd);
                body.style.maxHeight = 'none';
            };
            body.addEventListener('transitionend', onEnd);
        }
    }

    function prepareTrailCardHeader(header) {
        if (!header) return;
        var card = header.closest('.tm-trail-card');
        if (!card) return;
        header.setAttribute('role', 'button');
        header.setAttribute('tabindex', '0');
        header.setAttribute('aria-expanded', card.classList.contains('tm-expanded') ? 'true' : 'false');
    }

    function initTrailCardToggles() {
        var headers = document.querySelectorAll('.tm-trail-card-header');
        headers.forEach(function (header) {
            prepareTrailCardHeader(header);
        });

        if (document.documentElement.getAttribute('data-st-trail-toggle-bound') === '1') {
            return;
        }
        document.documentElement.setAttribute('data-st-trail-toggle-bound', '1');

        document.addEventListener('click', function (e) {
            var header = e.target && e.target.closest ? e.target.closest('.tm-trail-card-header') : null;
            if (!header) return;
            prepareTrailCardHeader(header);
            toggleTrailCard(header.closest('.tm-trail-card'));
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            var header = e.target && e.target.closest ? e.target.closest('.tm-trail-card-header') : null;
            if (!header) return;
            e.preventDefault();
            prepareTrailCardHeader(header);
            toggleTrailCard(header.closest('.tm-trail-card'));
        });
    }

    function initTicketTrailModals() {
        var modalLoadPromises = {};
        var trailSyncPromises = {};

        function closeModalById(id) {
            var m = document.getElementById(id);
            if (m) {
                m.classList.remove('open');
            }
        }

        function fetchAndAttachModal(modalId) {
            var id = String(modalId || '').trim();
            if (!id) {
                return Promise.resolve(null);
            }

            var existing = document.getElementById(id);
            if (existing) {
                return Promise.resolve(existing);
            }

            if (modalLoadPromises[id]) {
                return modalLoadPromises[id];
            }

            modalLoadPromises[id] = fetch(window.location.href, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(function (res) {
                if (!res || !res.ok) return null;
                return res.text();
            }).then(function (html) {
                if (!html) return null;
                var parser = new DOMParser();
                var doc = parser.parseFromString(html, 'text/html');
                var fetchedModal = doc.getElementById(id);
                if (!fetchedModal) return null;

                var host = document.body || document.documentElement;
                if (!host) return null;

                var imported = document.importNode(fetchedModal, true);
                host.appendChild(imported);
                return imported;
            }).catch(function () {
                return null;
            }).finally(function () {
                delete modalLoadPromises[id];
            });

            return modalLoadPromises[id];
        }

        function getTrailSyncEndpoint() {
            var cfg = window.supportTicketLiveUpdates || null;
            if (!cfg || !cfg.endpoint) return '';

            if (cfg.trailEndpoint) {
                return String(cfg.trailEndpoint);
            }

            var liveEndpoint = String(cfg.endpoint);
            if (liveEndpoint.indexOf('live-updates.php') === -1) {
                return '';
            }

            return liveEndpoint.replace('live-updates.php', 'trail-deltas.php');
        }

        function syncModalTrails(modalEl) {
            if (!modalEl) return;

            var cfg = window.supportTicketLiveUpdates || null;
            if (!cfg || !cfg.scope) return;

            var endpoint = getTrailSyncEndpoint();
            if (!endpoint) return;

            var modalId = String(modalEl.id || '').trim();
            var m = modalId.match(/-(\d+)$/);
            if (!m) return;

            var ticketId = parseInt(m[1], 10) || 0;
            if (!ticketId) return;

            var syncKey = modalId;
            if (trailSyncPromises[syncKey]) {
                return;
            }

            var trailWrap = modalEl.querySelector('.tm-trail');
            var lastTrailId = 0;
            if (trailWrap) {
                trailWrap.querySelectorAll('.tm-trail-item[data-trail-id]').forEach(function (item) {
                    var tid = parseInt(item.getAttribute('data-trail-id') || '0', 10) || 0;
                    if (tid > lastTrailId) lastTrailId = tid;
                });
            }

            var params = new URLSearchParams();
            params.set('scope', String(cfg.scope));
            params.set('ticket_id', String(ticketId));
            params.set('since_trail_id', String(lastTrailId));

            trailSyncPromises[syncKey] = fetch(endpoint + '?' + params.toString(), {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(function (res) {
                if (!res || !res.ok) return null;
                return res.json();
            }).then(function (json) {
                if (!json || !json.success || !json.data) return;
                stAppendTrailDeltas(json.data.trail_deltas || {});
            }).catch(function () {
                // no-op
            }).finally(function () {
                delete trailSyncPromises[syncKey];
            });
        }

        function openModalFromRow(btn) {
            if (!btn) return;

            var targetId = btn.getAttribute('data-ticket-modal');
            if (!targetId) return;
            var targetModal = document.getElementById(targetId);
            if (!targetModal) {
                fetchAndAttachModal(targetId).then(function (loadedModal) {
                    if (loadedModal) {
                        openModalFromRow(btn);
                        return;
                    }
                    stShowToast('Ticket details are still loading. Please try again.', 'danger');
                });
                return;
            }

            if (targetModal) {
                targetModal.classList.add('open');
                syncModalTrails(targetModal);

                // Mark ticket badge as seen for the current page role.
                var seenTicketId = btn.getAttribute('data-ticket-id');
                var seenRole = btn.getAttribute('data-seen-role');
                if (seenTicketId && seenRole) {
                    var fd = new FormData();
                    fd.append('ticket_id', seenTicketId);
                    fd.append('role', seenRole);
                    fetch('controllers/badges/mark-seen.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: fd
                    }).then(function (res) {
                        if (!res || !res.ok) return null;
                        return res.json();
                    }).then(function (json) {
                        if (!json || !json.success) return;

                        // Remove the per-ticket unread badge in the row we clicked
                        try {
                            var unread = btn.querySelector('.st-ticket-unread-badge');
                            if (unread && unread.parentNode) unread.parentNode.removeChild(unread);
                        } catch (e) {
                            // ignore DOM update errors
                        }

                        // Decrement the mode-level active badge if present
                        try {
                            var modeCard = document.querySelector('.mode-card[data-mode="active"]');
                            if (modeCard) {
                                var modeBadge = modeCard.querySelector('.st-mode-count-badge');
                                if (modeBadge) {
                                    var n = parseInt(modeBadge.textContent.trim(), 10) || 0;
                                    n = Math.max(0, n - 1);
                                    if (n === 0) {
                                        modeBadge.parentNode && modeBadge.parentNode.removeChild(modeBadge);
                                    } else {
                                        modeBadge.textContent = n;
                                    }
                                }
                            }
                        } catch (e) {
                            // ignore DOM update errors
                        }
                    }).catch(function () {
                        // no-op: badge sync failure should not block modal open
                    });
                }

                // Ensure trail card bodies have correct heights when modal becomes visible
                requestAnimationFrame(function () {
                    adjustTrailCardHeights(targetModal);
                    scrollModalToLatest(targetModal);
                });
            }
        }

        function scrollModalToLatest(modalEl) {
            if (!modalEl) return;
            var body = modalEl.querySelector('.tm-body');
            if (!body) return;

            var latestCard = modalEl.querySelector('.tm-trail-card[data-tm-latest]');
            if (latestCard) {
                latestCard.classList.add('tm-expanded');
            }

            // Wait one more frame so expanded heights are applied before scrolling.
            requestAnimationFrame(function () {
                body.scrollTop = body.scrollHeight;
            });
        }

        // Delegated click so newly-polled rows can open modals without rebinding.
        document.addEventListener('click', function (e) {
            var row = e.target && e.target.closest ? e.target.closest('[data-ticket-modal]') : null;
            if (!row) return;
            openModalFromRow(row);
        });

        // Delegated close handling so dynamically attached modals also work.
        document.addEventListener('click', function (e) {
            var closeBtn = e.target && e.target.closest ? e.target.closest('[data-st-close-modal]') : null;
            if (!closeBtn) return;
            var targetId = closeBtn.getAttribute('data-st-close-modal');
            if (!targetId) return;
            closeModalById(targetId);
        });

        // Delegated backdrop close for static and dynamically loaded overlays.
        document.addEventListener('click', function (e) {
            var target = e.target;
            if (!target || !target.classList) return;
            if (!target.classList.contains('tm-overlay') && !target.classList.contains('st-ticket-trail-backdrop')) return;
            target.classList.remove('open');
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            var backdrops = document.querySelectorAll('.st-ticket-trail-backdrop, .tm-overlay');
            backdrops.forEach(function (backdrop) {
                backdrop.classList.remove('open');
            });
        });
    }

    function initTransferConfirmModals() {
        var openButtons = document.querySelectorAll('[data-confirm-transfer-open]');
        openButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var modalId = btn.getAttribute('data-confirm-transfer-open');
                if (!modalId) return;

                // Determine ticket status from the parent modal to prevent actions on resolved tickets
                var parent = (btn.closest && (btn.closest('.tm-overlay') || btn.closest('.tm-modal'))) || document;
                var statusEl = parent ? parent.querySelector('.tm-status') : null;
                var statusText = statusEl ? String(statusEl.textContent || '').trim().toLowerCase() : '';

                // If ticket is resolved, show a toast and do not open the transfer confirm modal
                if (statusText.indexOf('resolved') !== -1) {
                    stShowToast('Ticket Cannot be transferred, Status is already Resolved', 'danger');
                    return;
                }

                var modal = document.getElementById(modalId);
                if (modal) {
                    modal.style.display = 'flex';
                    modal.setAttribute('aria-hidden', 'false');
                }
            });
        });

        var cancelButtons = document.querySelectorAll('[data-confirm-transfer-cancel]');
        cancelButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var modalId = btn.getAttribute('data-confirm-transfer-cancel');
                if (!modalId) return;
                var modal = document.getElementById(modalId);
                if (modal) {
                    modal.style.display = 'none';
                    modal.setAttribute('aria-hidden', 'true');
                }
            });
        });

        var submitButtons = document.querySelectorAll('[data-confirm-transfer-submit]');
        submitButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var formId = btn.getAttribute('data-transfer-form');
                if (!formId) return;

                // Prevent submitting transfer if the parent ticket is resolved
                var parent = (btn.closest && (btn.closest('.tm-overlay') || btn.closest('.tm-modal'))) || document;
                var statusEl = parent ? parent.querySelector('.tm-status') : null;
                var statusText = statusEl ? String(statusEl.textContent || '').trim().toLowerCase() : '';
                if (statusText.indexOf('resolved') !== -1) {
                    stShowToast('Ticket Cannot be transferred, Status is already Resolved', 'danger');
                    return;
                }

                var form = document.getElementById(formId);
                if (form) {
                    form.submit();
                }
            });
        });

        var transferModals = document.querySelectorAll('.tm-submodal-overlay[id^="stTransferTo"]');
        transferModals.forEach(function (overlay) {
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) {
                    overlay.style.display = 'none';
                    overlay.setAttribute('aria-hidden', 'true');
                }
            });
        });
    }

    function initClosePickerModals() {
        // Delegated handlers ensure dynamically imported or refreshed modal markup still responds.
        if (document.documentElement.getAttribute('data-st-close-picker-delegation-bound') !== '1') {
            document.documentElement.setAttribute('data-st-close-picker-delegation-bound', '1');

            document.addEventListener('click', function (e) {
                var btn = e.target && e.target.closest ? e.target.closest('[data-close-picker-open]') : null;
                if (!btn) return;
                var modalId = btn.getAttribute('data-close-picker-open');
                if (!modalId) return;

                var modalIdLower = String(modalId || '').toLowerCase();
                var isDeleteModal = modalIdLower.indexOf('delete') !== -1;

                if (!isDeleteModal) {
                    var parent = (btn.closest && (btn.closest('.tm-overlay') || btn.closest('.tm-modal'))) || document;
                    var statusEl = parent ? parent.querySelector('.tm-status') : null;
                    var statusText = statusEl ? String(statusEl.textContent || '').trim().toLowerCase() : '';
                    if (statusText.indexOf('resolved') !== -1 || statusText.indexOf('auto') !== -1) {
                        stShowToast('Ticket has already been resolved and will Close within 24 hours.', 'danger');
                        return;
                    }
                }

                var modal = document.getElementById(modalId);
                if (modal) {
                    modal.style.display = 'flex';
                    modal.setAttribute('aria-hidden', 'false');
                }
            });

            document.addEventListener('click', function (e) {
                var btn = e.target && e.target.closest ? e.target.closest('[data-close-picker-cancel]') : null;
                if (!btn) return;
                var modalId = btn.getAttribute('data-close-picker-cancel');
                if (!modalId) return;
                var modal = document.getElementById(modalId);
                if (modal) {
                    modal.style.display = 'none';
                    modal.setAttribute('aria-hidden', 'true');
                }
            });

            document.addEventListener('click', function (e) {
                var target = e.target;
                if (!target || !target.classList) return;
                if (!target.classList.contains('tm-submodal-overlay')) return;
                var id = String(target.id || '');
                if (id.indexOf('stClosePicker') !== 0) return;
                if (e.target === target) {
                    target.style.display = 'none';
                    target.setAttribute('aria-hidden', 'true');
                }
            });
        }

        // Also bind existing elements for immediate responsiveness on initial load.
        var openButtons = document.querySelectorAll('[data-close-picker-open]');
        openButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var modalId = btn.getAttribute('data-close-picker-open');
                if (!modalId) return;

                var modalIdLower = String(modalId || '').toLowerCase();
                var isDeleteModal = modalIdLower.indexOf('delete') !== -1;

                if (!isDeleteModal) {
                    var parent = (btn.closest && (btn.closest('.tm-overlay') || btn.closest('.tm-modal'))) || document;
                    var statusEl = parent ? parent.querySelector('.tm-status') : null;
                    var statusText = statusEl ? String(statusEl.textContent || '').trim().toLowerCase() : '';
                    if (statusText.indexOf('resolved') !== -1 || statusText.indexOf('auto') !== -1) {
                        stShowToast('Ticket has already been resolved and will Close within 24 hours.', 'danger');
                        return;
                    }
                }

                var modal = document.getElementById(modalId);
                if (modal) {
                    modal.style.display = 'flex';
                    modal.setAttribute('aria-hidden', 'false');
                }
            });
        });

        var cancelButtons = document.querySelectorAll('[data-close-picker-cancel]');
        cancelButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var modalId = btn.getAttribute('data-close-picker-cancel');
                if (!modalId) return;
                var modal = document.getElementById(modalId);
                if (modal) {
                    modal.style.display = 'none';
                    modal.setAttribute('aria-hidden', 'true');
                }
            });
        });

        var closePickers = document.querySelectorAll('.tm-submodal-overlay[id^="stClosePicker"]');
        closePickers.forEach(function (overlay) {
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) {
                    overlay.style.display = 'none';
                    overlay.setAttribute('aria-hidden', 'true');
                }
            });
        });
        
        // Reopen picker handlers (maintenance page uses data-reopen-picker-* attributes)
        var reopenOpenBtns = document.querySelectorAll('[data-reopen-picker-open]');
        reopenOpenBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var modalId = btn.getAttribute('data-reopen-picker-open');
                if (!modalId) return;
                var modal = document.getElementById(modalId);
                if (modal) {
                    modal.style.display = 'flex';
                    modal.setAttribute('aria-hidden', 'false');
                }
            });
        });

        var reopenCancelBtns = document.querySelectorAll('[data-reopen-picker-cancel]');
        reopenCancelBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var modalId = btn.getAttribute('data-reopen-picker-cancel');
                if (!modalId) return;
                var modal = document.getElementById(modalId);
                if (modal) {
                    modal.style.display = 'none';
                    modal.setAttribute('aria-hidden', 'true');
                }
            });
        });

        var reopenPickers = document.querySelectorAll('.tm-submodal-overlay[id^="stReopenPicker"]');
        reopenPickers.forEach(function (overlay) {
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) {
                    overlay.style.display = 'none';
                    overlay.setAttribute('aria-hidden', 'true');
                }
            });
        });
        
        // Buttons inside the reopen picker open a confirmation overlay per-target
        var reopenTargetBtns = document.querySelectorAll('[data-reopen-target]');
        reopenTargetBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var ticketId = btn.getAttribute('data-reopen-ticket-id');
                var target = btn.getAttribute('data-reopen-target');
                if (!ticketId || !target) return;

                var confirmId = 'stReopenConfirmMaint-' + ticketId + '-' + target;
                var confirmModal = document.getElementById(confirmId);
                if (!confirmModal) return;

                // hide the picker modal that contains this button
                var parentPicker = btn.closest('.tm-submodal-overlay');
                if (parentPicker) {
                    parentPicker.style.display = 'none';
                    parentPicker.setAttribute('aria-hidden', 'true');
                    confirmModal.dataset.reopenParent = parentPicker.id || '';
                }

                confirmModal.style.display = 'flex';
                confirmModal.setAttribute('aria-hidden', 'false');
            });
        });

        // Delegated fallback for reopen target buttons (handles dynamic buttons or missed bindings)
        document.addEventListener('click', function (e) {
            try {
                var delegatedBtn = e.target.closest ? e.target.closest('[data-reopen-target]') : null;
                if (!delegatedBtn) return;

                var ticketId = delegatedBtn.getAttribute('data-reopen-ticket-id');
                var target = delegatedBtn.getAttribute('data-reopen-target');
                if (!ticketId || !target) return;

                var confirmId = 'stReopenConfirmMaint-' + ticketId + '-' + target;
                var confirmModal = document.getElementById(confirmId);
                if (!confirmModal) return;

                // if already visible, do nothing (prevents double-handling)
                if (confirmModal.style.display === 'flex' || confirmModal.getAttribute('aria-hidden') === 'false') return;

                var parentPicker = delegatedBtn.closest('.tm-submodal-overlay');
                if (parentPicker) {
                    parentPicker.style.display = 'none';
                    parentPicker.setAttribute('aria-hidden', 'true');
                    confirmModal.dataset.reopenParent = parentPicker.id || '';
                }

                confirmModal.style.display = 'flex';
                confirmModal.setAttribute('aria-hidden', 'false');
            } catch (err) {
                // fail silently — fallback handler should not break other UI
            }
        });

        var reopenConfirmCancelBtns = document.querySelectorAll('[data-reopen-confirm-cancel]');
        reopenConfirmCancelBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var modalId = btn.getAttribute('data-reopen-confirm-cancel');
                if (!modalId) return;
                var modal = document.getElementById(modalId);
                if (!modal) return;
                modal.style.display = 'none';
                modal.setAttribute('aria-hidden', 'true');

                var parentId = modal.dataset.reopenParent;
                if (parentId) {
                    var parent = document.getElementById(parentId);
                    if (parent) {
                        parent.style.display = 'flex';
                        parent.setAttribute('aria-hidden', 'false');
                    }
                }
            });
        });

        var reopenConfirmPickers = document.querySelectorAll('.tm-submodal-overlay[id^="stReopenConfirmMaint-"]');
        reopenConfirmPickers.forEach(function (overlay) {
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) {
                    overlay.style.display = 'none';
                    overlay.setAttribute('aria-hidden', 'true');
                    var parentId = overlay.dataset.reopenParent;
                    if (parentId) {
                        var parent = document.getElementById(parentId);
                        if (parent) {
                            parent.style.display = 'flex';
                            parent.setAttribute('aria-hidden', 'false');
                        }
                    }
                }
            });
        });
    }

    function initAttachmentPreviews() {
        // Delegated handler so attachments inside modals are caught
        document.addEventListener('click', function (e) {
            var a = e.target.closest('a.tm-attachment');
            if (!a) return;
            // Only handle if href contains id= (attachment link)
            var href = a.getAttribute('href') || '';
            try {
                var u = new URL(href, window.location.href);
                var id = u.searchParams.get('id');
                if (!id) return; // fallback to default navigation
            } catch (err) {
                return;
            }

            e.preventDefault();

            // Remove any existing preview overlay
            var existing = document.getElementById('stImagePreviewOverlay');
            if (existing && existing.parentNode) existing.parentNode.removeChild(existing);

            // Fetch server snippet and insert
            fetch('image-preview.php?id=' + encodeURIComponent(id), { credentials: 'same-origin' })
                .then(function (res) { return res.text(); })
                .then(function (html) {
                    var wrapper = document.createElement('div');
                    wrapper.innerHTML = html;
                    // The snippet root has .ip-overlay — give it an ID to manage
                    var overlay = wrapper.querySelector('.ip-overlay');
                    if (!overlay) return;
                    // ensure unique id
                    overlay.id = 'stImagePreviewOverlay';
                    document.body.appendChild(overlay);

                    function close() {
                        overlay.parentNode && overlay.parentNode.removeChild(overlay);
                        document.removeEventListener('keydown', onKey);
                    }

                    function onKey(evt) {
                        if (evt.key === 'Escape') close();
                    }

                    // close button
                    var cb = overlay.querySelector('[data-ip-close]');
                    if (cb) cb.addEventListener('click', function (ev) { ev.preventDefault(); close(); });

                    // clicking backdrop closes
                    overlay.addEventListener('click', function (ev) {
                        if (ev.target === overlay) close();
                    });

                    document.addEventListener('keydown', onKey);
                })
                .catch(function (err) {
                    // if preview fails, fallback to download navigation
                    window.location.href = href;
                });
        });
    }

    function initReplyAttachmentPreviews() {
        function formatBytes(bytes) {
            if (!bytes) return '0 B';
            var sizes = ['B', 'KB', 'MB', 'GB'];
            var i = Math.floor(Math.log(bytes) / Math.log(1024));
            return (bytes / Math.pow(1024, i)).toFixed(2) + ' ' + sizes[i];
        }

        function openLocalFilePreview(file) {
            if (!file) return;

            var existing = document.getElementById('stImagePreviewOverlay');
            if (existing && existing.parentNode) existing.parentNode.removeChild(existing);

            var overlay = document.createElement('div');
            overlay.className = 'ip-overlay';
            overlay.id = 'stImagePreviewOverlay';

            var safeName = (file.name || 'attachment').replace(/[&<>\"]/g, function (ch) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[ch] || ch;
            });

            var isImage = (file.type || '').indexOf('image/') === 0;
            var bodyHtml = '';
            if (isImage) {
                var blobUrl = URL.createObjectURL(file);
                bodyHtml = '<img class="ip-image" src="' + blobUrl + '" alt="' + safeName + '">';
                overlay.dataset.blobUrl = blobUrl;
            } else {
                bodyHtml =
                    '<div class="ip-file-placeholder">' +
                        '<div class="ip-file-icon"><i class="fa-solid fa-file"></i></div>' +
                        '<div class="ip-file-name">' + safeName + '</div>' +
                        '<div class="ip-file-help">Preview unavailable for this file type.</div>' +
                    '</div>';
            }

            overlay.innerHTML =
                '<div class="ip-modal">' +
                    '<button type="button" class="ip-close" data-ip-close aria-label="Close">&times;</button>' +
                    '<div class="ip-body">' + bodyHtml + '</div>' +
                '</div>';

            document.body.appendChild(overlay);

            function close() {
                if (overlay.dataset.blobUrl) {
                    URL.revokeObjectURL(overlay.dataset.blobUrl);
                }
                if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
                document.removeEventListener('keydown', onKey);
            }

            function onKey(evt) {
                if (evt.key === 'Escape') close();
            }

            var cb = overlay.querySelector('[data-ip-close]');
            if (cb) cb.addEventListener('click', function (ev) { ev.preventDefault(); close(); });
            overlay.addEventListener('click', function (ev) {
                if (ev.target === overlay) close();
            });
            document.addEventListener('keydown', onKey);
        }

        var replyInputs = document.querySelectorAll('input[type="file"][id^="reply_attachments_"]');
        replyInputs.forEach(function (input) {
            var suffix = input.id.replace('reply_attachments_', '');
            var preview = document.getElementById('replyPreview_' + suffix);
            if (!preview) return;

            var selectedFiles = [];

            function syncInputFiles() {
                try {
                    var dt = new DataTransfer();
                    selectedFiles.forEach(function (f) { dt.items.add(f); });
                    input.files = dt.files;
                } catch (err) {
                    // ignore browser without DataTransfer write support
                }
            }

            function renderPreview() {
                preview.innerHTML = '';
                if (!selectedFiles.length) return;

                selectedFiles.forEach(function (file, index) {
                    var chip = document.createElement('div');
                    var isImage = (file.type || '').indexOf('image/') === 0;
                    chip.className = 'tm-attach-chip';
                    if (isImage) chip.setAttribute('data-previewable', '1');

                    var icon = isImage ? 'fa-image' : 'fa-file';
                    chip.innerHTML =
                        '<i class="fa-solid ' + icon + '" aria-hidden="true"></i>' +
                        '<span title="' + (file.name || '') + '">' + (file.name || 'Attachment') + ' (' + formatBytes(file.size || 0) + ')</span>' +
                        '<button type="button" class="tm-attach-chip-remove" data-remove-index="' + index + '" aria-label="Remove">&times;</button>';

                    chip.addEventListener('click', function (ev) {
                        if (ev.target && ev.target.closest('.tm-attach-chip-remove')) {
                            return;
                        }
                        if (isImage) {
                            openLocalFilePreview(file);
                        }
                    });

                    preview.appendChild(chip);
                });

                preview.querySelectorAll('[data-remove-index]').forEach(function (btn) {
                    btn.addEventListener('click', function (ev) {
                        ev.preventDefault();
                        ev.stopPropagation();
                        var idx = parseInt(btn.getAttribute('data-remove-index'), 10);
                        if (isNaN(idx)) return;
                        selectedFiles.splice(idx, 1);
                        syncInputFiles();
                        renderPreview();
                    });
                });
            }

            input.addEventListener('change', function () {
                var incoming = Array.prototype.slice.call(input.files || []);
                if (incoming.length) {
                    incoming.forEach(function (file) { selectedFiles.push(file); });
                }
                syncInputFiles();
                renderPreview();
            });
        });
    }

    function initTicketCopyButtons() {
        // Delegated click handler for copy buttons
        document.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.tm-copy-ticket') : null;
            if (!btn) return;
            e.preventDefault();

            // Block copying while ticket is still in open/accept state.
            var modal = btn.closest ? btn.closest('.tm-overlay') : null;
            var isOpenUnaccepted = false;
            if (modal) {
                var acceptForm = modal.querySelector('form[action*="accept-ticket.php"]');
                isOpenUnaccepted = !!acceptForm;
            }
            if (isOpenUnaccepted) {
                stShowToast('Error: You need to accept the ticket first.', 'danger');
                return;
            }

            var ticket = btn.getAttribute('data-ticket-number') || '';
            if (!ticket) return;

            function showTemp(iconHtml) {
                var orig = btn.innerHTML;
                btn.innerHTML = iconHtml;
                setTimeout(function () { btn.innerHTML = orig; }, 1400);
            }

            function fallbackCopy(text) {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                try {
                    var ok = document.execCommand('copy');
                    document.body.removeChild(ta);
                    if (ok) {
                        showTemp('<i class="fa-solid fa-check" aria-hidden="true"></i>');
                        showCopyToast('Ticket number copied to clipboard');
                    } else {
                        showTemp('<i class="fa-solid fa-ban" aria-hidden="true"></i>');
                        showCopyToast('Unable to copy ticket number');
                    }
                } catch (err) {
                    document.body.removeChild(ta);
                    showTemp('<i class="fa-solid fa-ban" aria-hidden="true"></i>');
                    showCopyToast('Unable to copy ticket number');
                }
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(ticket).then(function () {
                    showTemp('<i class="fa-solid fa-check" aria-hidden="true"></i>');
                    showCopyToast('Ticket number copied to clipboard');
                }).catch(function () {
                    fallbackCopy(ticket);
                });
            } else {
                fallbackCopy(ticket);
            }
        });
    }

    function initReferenceCopyHandler() {
        // Delegated click handler for Reference No. meta values
        document.addEventListener('click', function (e) {
            var el = e.target && e.target.closest ? e.target.closest('.tm-meta-value--ref') : null;
            if (!el) return;
            e.preventDefault();

            var refText = String(el.textContent || '').trim();
            if (!refText) {
                stShowToast('Reference number is empty', 'danger');
                return;
            }

            function fallbackCopy(text) {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                try {
                    var ok = document.execCommand('copy');
                    document.body.removeChild(ta);
                    if (ok) {
                        stShowToast('Reference No. ' + text + ' has been copied to clipboard', 'success');
                    } else {
                        stShowToast('Unable to copy reference number', 'danger');
                    }
                } catch (err) {
                    document.body.removeChild(ta);
                    stShowToast('Unable to copy reference number', 'danger');
                }
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(refText).then(function () {
                    stShowToast('Reference No. ' + refText + ' has been copied to clipboard', 'success');
                }).catch(function () {
                    fallbackCopy(refText);
                });
            } else {
                fallbackCopy(refText);
            }
        });
    }

    function stShowToast(message, type) {
        if (!message) return;
        var tone = (type || 'success').toLowerCase();
        var klass = tone === 'danger' ? 'st-copy-toast--danger' : 'st-copy-toast--success';
        var existing = document.getElementById('st-copy-toast');
        if (existing) {
            existing.textContent = message;
            existing.style.whiteSpace = 'pre-line';
            existing.classList.remove('st-copy-toast--hide', 'st-copy-toast--danger', 'st-copy-toast--success');
            existing.classList.add('st-copy-toast--show', klass);
            clearTimeout(existing._hideTimeout);
            existing._hideTimeout = setTimeout(function () {
                existing.classList.remove('st-copy-toast--show');
                existing.classList.add('st-copy-toast--hide');
                setTimeout(function () { try { existing.remove(); } catch (e) {} }, 260);
            }, 2200);
            return;
        }

        var toast = document.createElement('div');
        toast.id = 'st-copy-toast';
        toast.className = 'st-copy-toast st-copy-toast--show ' + klass;
        toast.style.whiteSpace = 'pre-line';
        toast.textContent = message;
        document.body.appendChild(toast);
        toast._hideTimeout = setTimeout(function () {
            toast.classList.remove('st-copy-toast--show');
            toast.classList.add('st-copy-toast--hide');
            setTimeout(function () { try { toast.remove(); } catch (e) {} }, 260);
        }, 2200);
    }

    function showCopyToast(message) {
        stShowToast(message || 'Ticket number copied to clipboard', 'success');
    }

    function initInitialFlashToast() {
        var flash = window.supportTicketInitialFlash;
        if (!flash || !flash.message) return;
        var type = String(flash.type || 'success').toLowerCase();
        stShowToast(String(flash.message), type === 'danger' ? 'danger' : 'success');
    }

    function initOpenTicketsPolling() {
        var cfg = window.supportTicketOpenPoll;
        if (!cfg || !cfg.endpoint) return;

        var panel = document.querySelector('[data-st-panel="open"]');
        if (!panel) return;

        var intervalMs = parseInt(cfg.intervalMs, 10);
        if (!intervalMs || intervalMs < 3000) {
            intervalMs = 7000;
        }

        var lastHash = null;
        var isPolling = false;

        function ensureRefreshHint() {
            var existing = panel.querySelector('[data-st-open-refresh-hint]');
            if (existing) return existing;

            var hint = document.createElement('div');
            hint.setAttribute('data-st-open-refresh-hint', '1');
            hint.style.display = 'none';
            hint.style.marginBottom = '8px';
            hint.style.fontSize = '11px';
            hint.style.color = '#6b7280';
            hint.style.textAlign = 'right';
            panel.insertBefore(hint, panel.firstChild);
            return hint;
        }

        function showRefreshHint() {
            var hint = ensureRefreshHint();
            var now = new Date();
            var hh = String(now.getHours()).padStart(2, '0');
            var mm = String(now.getMinutes()).padStart(2, '0');
            var ss = String(now.getSeconds()).padStart(2, '0');
            hint.textContent = 'Open list updated ' + hh + ':' + mm + ':' + ss;
            hint.style.display = 'block';
        }

        function shouldPollNow() {
            if (document.hidden) return false;

            var hasOpenOverlay = !!document.querySelector('.tm-overlay.open');
            if (hasOpenOverlay) return false;

            return true;
        }

        function patchOpenPanel(openHtml) {
            if (typeof openHtml !== 'string' || openHtml.trim() === '') return;
            panel.innerHTML = openHtml;
        }

        function pollOnce() {
            if (isPolling || !shouldPollNow()) return;
            isPolling = true;

            fetch(cfg.endpoint, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(function (res) {
                if (!res || !res.ok) return null;
                return res.json();
            }).then(function (json) {
                if (!json || !json.success || !json.data) return;

                var nextHash = String(json.data.hash || '');
                if (!nextHash) return;

                if (lastHash === null) {
                    lastHash = nextHash;
                    return;
                }

                if (nextHash !== lastHash) {
                    patchOpenPanel(String(json.data.open_html || ''));
                    lastHash = nextHash;
                    showRefreshHint();
                }
            }).catch(function () {
                // no-op: silent polling failure
            }).finally(function () {
                isPolling = false;
            });
        }

        pollOnce();
        setInterval(pollOnce, intervalMs);
    }

    function liveTypeLabel(type) {
        var t = String(type || '').toLowerCase();
        if (t === 'accept') return 'Accepted';
        if (t === 'transfer') return 'Transferred';
        if (t === 'resolve') return 'Resolved';
        if (t === 'close') return 'Closed';
        if (t === 'auto_close') return 'Auto Closed';
        return 'Message';
    }

    function stAppendTrailDeltas(trailDeltas) {
        if (!trailDeltas || typeof trailDeltas !== 'object') return;

        Object.keys(trailDeltas).forEach(function (ticketIdKey) {
            var deltas = trailDeltas[ticketIdKey];
            if (!Array.isArray(deltas) || deltas.length === 0) return;

            var overlay = document.querySelector('.tm-overlay[id$="-' + ticketIdKey + '"]');
            if (!overlay) return;

            var trailWrap = overlay.querySelector('.tm-trail');
            if (!trailWrap) return;

            var empty = trailWrap.querySelector('.tm-empty-trail');
            if (empty && empty.parentNode) {
                empty.parentNode.removeChild(empty);
            }

            deltas.forEach(function (delta) {
                var trailId = parseInt(delta.trail_id || 0, 10) || 0;
                if (!trailId) return;
                if (trailWrap.querySelector('.tm-trail-item[data-trail-id="' + trailId + '"]')) return;

                var senderRole = String(delta.sender_role || 'SYSTEM').toUpperCase();
                var type = String(delta.type || 'message').toLowerCase();
                var message = String(delta.message || '');
                var dtText = formatTrailDatetimeText(delta.created_at || '');

                var avatarClass = 'tm-trail-avatar--system';
                if (senderRole === 'BRANCH') {
                    avatarClass = 'tm-trail-avatar--branch';
                } else if (senderRole === 'VPO') {
                    avatarClass = 'tm-trail-avatar--vpo';
                } else if (senderRole === 'CAD') {
                    avatarClass = 'tm-trail-avatar--cad';
                }
                var avatarInner = buildTrailAvatarInner(senderRole);

                var attachments = Array.isArray(delta.attachments) ? delta.attachments : [];
                var attachmentsHtml = '';
                if (attachments.length > 0) {
                    var nodes = [];
                    attachments.forEach(function (att) {
                        var href = stEscapeHtml(String(att.download_url || '#'));
                        var name = stEscapeHtml(String(att.file_name || 'Attachment'));
                        var size = stEscapeHtml(String(att.file_size || ''));
                        nodes.push(
                            '<a class="tm-attachment" href="' + href + '">' +
                                '<span class="tm-attachment-icon"><i class="fa-solid fa-paperclip" aria-hidden="true"></i></span>' +
                                '<span class="tm-attachment-name">' + name + '</span>' +
                                '<span class="tm-attachment-size">' + size + '</span>' +
                            '</a>'
                        );
                    });
                    attachmentsHtml = '<div class="tm-attachments">' + nodes.join('') + '</div>';
                }

                var prevLatest = trailWrap.querySelector('.tm-trail-card[data-tm-latest]');
                if (prevLatest) {
                    prevLatest.removeAttribute('data-tm-latest');
                    prevLatest.classList.remove('tm-expanded');
                    var prevHeader = prevLatest.querySelector('.tm-trail-card-header');
                    if (prevHeader) {
                        prevHeader.setAttribute('aria-expanded', 'false');
                    }
                }

                var item = document.createElement('div');
                item.className = 'tm-trail-item';
                item.setAttribute('data-trail-id', String(trailId));
                item.innerHTML =
                    '<div class="tm-trail-dot-wrap">' +
                        '<div class="tm-trail-avatar ' + avatarClass + '">' + avatarInner + '</div>' +
                    '</div>' +
                    '<div class="tm-trail-card tm-expanded" data-tm-latest="1">' +
                        '<div class="tm-trail-card-header">' +
                            '<div class="tm-trail-avatar ' + avatarClass + '">' + avatarInner + '</div>' +
                            '<div class="tm-trail-meta">' +
                                '<div class="tm-trail-sender"><span>' + stEscapeHtml(senderRole) + '</span></div>' +
                                '<div class="tm-trail-datetime">' + stEscapeHtml(dtText) + '</div>' +
                            '</div>' +
                            '<div class="tm-trail-type-label tm-trail-type-label--' + stEscapeHtml(type) + '">' + stEscapeHtml(liveTypeLabel(type)) + '</div>' +
                            '<div class="tm-trail-chevron">›</div>' +
                        '</div>' +
                        '<div class="tm-trail-card-body">' +
                            '<div class="tm-trail-message">' + stEscapeHtml(message).replace(/\n/g, '<br>') + '</div>' +
                            attachmentsHtml +
                        '</div>' +
                    '</div>';

                trailWrap.appendChild(item);
                prepareTrailCardHeader(item.querySelector('.tm-trail-card-header'));
            });

            adjustTrailCardHeights(overlay);
            if (overlay.classList.contains('open')) {
                var body = overlay.querySelector('.tm-body');
                if (body) {
                    requestAnimationFrame(function () {
                        body.scrollTop = body.scrollHeight;
                    });
                }
            }
        });
    }

    function initLiveUpdatesPolling() {
        var cfg = window.supportTicketLiveUpdates;
        if (!cfg || !cfg.endpoint || !cfg.scope) return;

        var intervalMs = parseInt(cfg.intervalMs, 10);
        if (!intervalMs || intervalMs < 3000) {
            intervalMs = 5000;
        }

        var cursor = 0;
        var bootstrapped = false;
        var inFlight = false;
        var lastToastTrailId = 0;
        var requestedTicketSet = {};
        var modalRefreshPromises = {};

        function parseTicketIdFromModalId(id) {
            var m = String(id || '').match(/-(\d+)$/);
            if (!m) return 0;
            return parseInt(m[1], 10) || 0;
        }

        function getTicketNumberFromRow(row) {
            if (!row) return '';
            var explicit = String(row.getAttribute('data-ticket-number') || '').trim().toUpperCase();
            if (explicit) return explicit;

            var numberCell = row.querySelector('.st-col-number');
            var text = numberCell ? String(numberCell.textContent || '') : '';
            text = text.trim().toUpperCase();
            if (!text) return '';
            var m = text.match(/[A-Z0-9_.-]+/);
            return m ? m[0] : '';
        }

        function normalizeStatusText(status) {
            return String(status || '').trim().toLowerCase();
        }

        function statusPanelForPage(statusLower) {
            var s = normalizeStatusText(statusLower);
            var hasActivePanel = !!document.querySelector('[data-st-panel="active"]');

            if (hasActivePanel) {
                if (s === 'resolved' || s === 'closed') return 'closed';
                if (s === 'open' || s === 'transferred') return 'open';
                return 'active';
            }

            return (s === 'resolved' || s === 'closed') ? 'closed' : 'open';
        }

        function readRowStatus(row) {
            if (!row) return '';
            var explicit = String(row.getAttribute('data-status') || '').trim();
            if (explicit) return explicit;
            var node = row.querySelector('.st-col-status .st-status');
            return node ? String(node.textContent || '').trim() : '';
        }

        function readRowHandlerRole(row) {
            if (!row) return '';
            return String(row.getAttribute('data-handler-role') || '').trim().toUpperCase();
        }

        function readRowAssignedTo(row) {
            if (!row) return 0;
            return parseInt(row.getAttribute('data-assigned-to') || '0', 10) || 0;
        }

        function getPanelElement(panelName) {
            if (!panelName) return null;
            return document.querySelector('[data-st-panel="' + panelName + '"]');
        }

        function getOrCreatePanelTable(panelEl, sourceTable) {
            if (!panelEl) return null;

            var table = panelEl.querySelector('.st-ticket-table');
            if (table) return table;

            if (!sourceTable) return null;
            var head = sourceTable.querySelector('.st-ticket-row-head');
            if (!head) return null;

            table = document.createElement('div');
            table.className = 'st-ticket-table';
            table.setAttribute('role', sourceTable.getAttribute('role') || 'table');
            if (sourceTable.getAttribute('aria-label')) {
                table.setAttribute('aria-label', sourceTable.getAttribute('aria-label'));
            }
            table.appendChild(head.cloneNode(true));
            panelEl.appendChild(table);
            return table;
        }

        function updatePanelEmptyState(panelEl) {
            if (!panelEl) return;
            var table = panelEl.querySelector('.st-ticket-table');
            var rowCount = panelEl.querySelectorAll('.st-ticket-row[data-ticket-modal]').length;
            var empty = panelEl.querySelector('.st-empty');

            if (table) {
                table.style.display = rowCount > 0 ? '' : 'none';
            }

            if (empty) {
                empty.style.display = rowCount > 0 ? 'none' : '';
            }
        }

        function relocateRowByStatus(row, nextStatus) {
            if (!row) return;

            var currentPanel = row.closest('[data-st-panel]');
            var currentPanelName = currentPanel ? String(currentPanel.getAttribute('data-st-panel') || '').trim().toLowerCase() : '';
            var expectedPanel = statusPanelForPage(nextStatus);
            if (!expectedPanel || expectedPanel === currentPanelName) {
                return;
            }

            var sourceTable = currentPanel ? currentPanel.querySelector('.st-ticket-table') : null;
            var targetPanel = getPanelElement(expectedPanel);
            var targetTable = getOrCreatePanelTable(targetPanel, sourceTable);
            if (!targetTable) {
                // If there is no table container to receive rows yet, hide stale placement.
                row.style.display = 'none';
                return;
            }

            targetTable.appendChild(row);
            row.style.display = '';

            updatePanelEmptyState(currentPanel);
            updatePanelEmptyState(targetPanel);
        }

        function refreshModalMarkup(modalId) {
            var id = String(modalId || '').trim();
            if (!id) {
                return Promise.resolve(null);
            }

            if (modalRefreshPromises[id]) {
                return modalRefreshPromises[id];
            }

            var existing = document.getElementById(id);
            if (!existing) {
                return Promise.resolve(null);
            }

            var wasOpen = existing.classList.contains('open');
            var prevBody = existing.querySelector('.tm-body');
            var prevScrollTop = prevBody ? prevBody.scrollTop : 0;

            modalRefreshPromises[id] = fetch(window.location.href, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(function (res) {
                if (!res || !res.ok) return null;
                return res.text();
            }).then(function (html) {
                if (!html) return null;

                var parser = new DOMParser();
                var doc = parser.parseFromString(html, 'text/html');
                var fresh = doc.getElementById(id);
                if (!fresh || !existing.parentNode) return null;

                var imported = document.importNode(fresh, true);
                if (wasOpen) {
                    imported.classList.add('open');
                }

                existing.parentNode.replaceChild(imported, existing);
                adjustTrailCardHeights(imported);

                if (wasOpen) {
                    var newBody = imported.querySelector('.tm-body');
                    if (newBody) {
                        newBody.scrollTop = prevScrollTop;
                    }
                }

                // Rebind AJAX reply submit handlers for freshly imported forms.
                bindAjaxReplySubmitsInScope(imported);

                return imported;
            }).catch(function () {
                return null;
            }).finally(function () {
                delete modalRefreshPromises[id];
            });

            return modalRefreshPromises[id];
        }

        function syncRoleModalFooterState(overlay) {
            if (!overlay) return;

            var scope = String(cfg.scope || '').toUpperCase();
            if (scope !== 'VPO' && scope !== 'CAD') {
                return;
            }

            var modalId = String(overlay.id || '').trim();
            if (!modalId) return;

            refreshModalMarkup(modalId);
        }

        function applyRowStatus(row, nextStatus, state) {
            if (!row) return;
            var clean = String(nextStatus || '').trim();
            if (!clean) return;

            var lower = normalizeStatusText(clean);
            row.setAttribute('data-status', clean);
            if (state && typeof state === 'object') {
                row.setAttribute('data-handler-role', String(state.handler_role || '').toUpperCase());
                row.setAttribute('data-assigned-to', String(parseInt(state.assigned_to || 0, 10) || 0));
            }

            var statusCell = row.querySelector('.st-col-status');
            if (!statusCell) return;

            var statusNode = statusCell.querySelector('.st-status');
            if (!statusNode) {
                statusNode = document.createElement('span');
                statusCell.textContent = '';
                statusCell.appendChild(statusNode);
            }

            statusNode.className = 'st-status st-status-' + lower;
            statusNode.textContent = clean;

            var modalId = String(row.getAttribute('data-ticket-modal') || '').trim();
            if (!modalId) return;

            var overlay = document.getElementById(modalId);
            if (!overlay) return;

            var modalStatus = overlay.querySelector('.tm-status');
            if (!modalStatus) return;

            modalStatus.className = 'tm-status tm-status--' + lower;
            modalStatus.textContent = clean;

            syncBranchReplyFooterState(overlay, lower);
            syncRoleModalFooterState(overlay);
        }

        function syncBranchReplyFooterState(overlay, statusLower) {
            if (!overlay) return;

            var branchReplyForm = overlay.querySelector('form[action*="controllers/branch/reply-ticket.php"]');
            if (!branchReplyForm) return;

            var lower = normalizeStatusText(statusLower);
            var controls = branchReplyForm.querySelectorAll('textarea, input, select, button');
            var liveClosedNotice = overlay.querySelector('.tm-footer.tm-footer--closed[data-st-live-closed="1"]');

            if (lower === 'closed') {
                branchReplyForm.style.display = 'none';
                branchReplyForm.setAttribute('data-st-live-closed', '1');
                controls.forEach(function (node) {
                    node.disabled = true;
                });

                if (!liveClosedNotice) {
                    liveClosedNotice = document.createElement('div');
                    liveClosedNotice.className = 'tm-footer tm-footer--closed';
                    liveClosedNotice.setAttribute('data-st-live-closed', '1');
                    liveClosedNotice.textContent = 'This ticket is already closed!';
                    if (branchReplyForm.parentNode) {
                        branchReplyForm.parentNode.insertBefore(liveClosedNotice, branchReplyForm.nextSibling);
                    }
                }
                return;
            }

            if (branchReplyForm.getAttribute('data-st-live-closed') === '1') {
                branchReplyForm.style.display = '';
                branchReplyForm.removeAttribute('data-st-live-closed');
                controls.forEach(function (node) {
                    node.disabled = false;
                });
            }

            if (liveClosedNotice && liveClosedNotice.parentNode) {
                liveClosedNotice.parentNode.removeChild(liveClosedNotice);
            }
        }

        function applyTicketStatuses(ticketStatuses) {
            if (!ticketStatuses || typeof ticketStatuses !== 'object') {
                return;
            }
            var rows = document.querySelectorAll('.st-ticket-row[data-ticket-modal]');

            rows.forEach(function (row) {
                var ticketNo = getTicketNumberFromRow(row);
                if (!ticketNo) return;
                if (!requestedTicketSet[ticketNo]) return;

                var state = ticketStatuses[ticketNo];
                if (!state) {
                    return;
                }

                var nextStatus = (typeof state === 'object') ? String(state.status || '') : String(state || '');
                var nextHandler = (typeof state === 'object') ? String(state.handler_role || '').trim().toUpperCase() : '';
                var nextAssigned = (typeof state === 'object') ? (parseInt(state.assigned_to || 0, 10) || 0) : 0;
                var prevStatus = readRowStatus(row);
                var prevHandler = readRowHandlerRole(row);
                var prevAssigned = readRowAssignedTo(row);
                if (!nextStatus) return;

                var statusChanged = normalizeStatusText(prevStatus) !== normalizeStatusText(nextStatus);
                var hasRoutingSnapshot = row.hasAttribute('data-handler-role') || row.hasAttribute('data-assigned-to');
                if (!hasRoutingSnapshot && typeof state === 'object') {
                    row.setAttribute('data-handler-role', nextHandler);
                    row.setAttribute('data-assigned-to', String(nextAssigned));
                }
                var routingChanged = hasRoutingSnapshot && (prevHandler !== nextHandler || prevAssigned !== nextAssigned);
                if (statusChanged || routingChanged) {
                    applyRowStatus(row, nextStatus, (typeof state === 'object') ? state : null);
                }

                if (statusChanged) {
                    relocateRowByStatus(row, nextStatus);
                }
            });
        }

        function collectTicketNumbers() {
            var map = {};
            // Track all known rows (open/active/closed), not just the visible panel,
            // so live status changes can move tickets across panels without refresh.
            document.querySelectorAll('.st-ticket-row[data-ticket-modal]').forEach(function (row) {
                var tn = getTicketNumberFromRow(row);
                if (tn) map[tn] = true;
            });
            return Object.keys(map);
        }

        function collectOpenModalState() {
            var state = {};
            document.querySelectorAll('.tm-overlay.open[id]').forEach(function (overlay) {
                var ticketId = parseTicketIdFromModalId(overlay.id || '');
                if (!ticketId) return;
                var lastTrailId = 0;
                overlay.querySelectorAll('.tm-trail-item[data-trail-id]').forEach(function (item) {
                    var tid = parseInt(item.getAttribute('data-trail-id') || '0', 10) || 0;
                    if (tid > lastTrailId) lastTrailId = tid;
                });
                state[ticketId] = lastTrailId;
            });
            return state;
        }

        function applyBadgeCounts(badgeCounts) {
            if (!badgeCounts || typeof badgeCounts !== 'object') return;

            document.querySelectorAll('.st-ticket-row[data-ticket-modal]').forEach(function (row) {
                var ticketNo = getTicketNumberFromRow(row);
                if (!ticketNo) return;

                var count = parseInt(badgeCounts[ticketNo] || 0, 10) || 0;
                var numberCell = row.querySelector('.st-col-number');
                if (!numberCell) return;

                var existingBadge = numberCell.querySelector('.st-ticket-unread-badge');
                if (count > 0) {
                    if (!existingBadge) {
                        var badge = document.createElement('span');
                        badge.className = 'st-ticket-unread-badge';
                        badge.textContent = String(count);
                        numberCell.appendChild(document.createTextNode(' '));
                        numberCell.appendChild(badge);
                    } else {
                        existingBadge.textContent = String(count);
                    }
                } else if (existingBadge && existingBadge.parentNode) {
                    existingBadge.parentNode.removeChild(existingBadge);
                }
            });

            var activeCard = document.querySelector('.mode-card[data-mode="active"]');
            if (!activeCard) return;

            var activeRows = document.querySelectorAll('[data-st-panel="active"] .st-ticket-row[data-ticket-modal]');
            var total = 0;
            activeRows.forEach(function (row) {
                var ticketNo = getTicketNumberFromRow(row);
                if (!ticketNo) return;
                total += parseInt(badgeCounts[ticketNo] || 0, 10) || 0;
            });

            var existingModeBadge = activeCard.querySelector('.st-mode-count-badge');
            if (total > 0) {
                if (!existingModeBadge) {
                    var modeBadge = document.createElement('span');
                    modeBadge.className = 'st-mode-count-badge';
                    modeBadge.textContent = String(total);
                    activeCard.appendChild(modeBadge);
                } else {
                    existingModeBadge.textContent = String(total);
                }
            } else if (existingModeBadge && existingModeBadge.parentNode) {
                existingModeBadge.parentNode.removeChild(existingModeBadge);
            }
        }

        function appendTrailDeltas(trailDeltas) {
            stAppendTrailDeltas(trailDeltas);
        }

        function showNotifications(notifications) {
            if (!Array.isArray(notifications) || notifications.length === 0) return;

            notifications.forEach(function (n) {
                var trailId = parseInt(n.trail_id || 0, 10) || 0;
                if (!trailId || trailId <= lastToastTrailId) return;
                var ticketNo = String(n.ticket_number || '').trim();
                var text = String(n.text || '').trim();
                if (!text) return;

                var msg = ticketNo ? (ticketNo + '\n' + text) : text;
                stShowToast(msg, 'success');
                if (trailId > lastToastTrailId) {
                    lastToastTrailId = trailId;
                }
            });
        }

        function shouldPoll() {
            if (document.hidden) return false;
            return true;
        }

        function pollOnce() {
            if (inFlight || !shouldPoll()) return;
            inFlight = true;

            var params = new URLSearchParams();
            params.set('scope', String(cfg.scope));
            params.set('cursor', String(cursor));

            var ticketNumbers = collectTicketNumbers();
            requestedTicketSet = {};
            ticketNumbers.forEach(function (tn) {
                requestedTicketSet[tn] = true;
            });
            if (ticketNumbers.length > 0) {
                params.set('ticket_numbers', ticketNumbers.join(','));
            }

            var openState = collectOpenModalState();
            params.set('open_state', JSON.stringify(openState));

            if (!bootstrapped) {
                params.set('bootstrap', '1');
            }

            fetch(cfg.endpoint + '?' + params.toString(), {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(function (res) {
                if (!res || !res.ok) return null;
                return res.json();
            }).then(function (json) {
                if (!json || !json.success || !json.data) return;

                var data = json.data;
                var nextCursor = parseInt(data.next_cursor || cursor, 10) || cursor;
                if (nextCursor > cursor) {
                    cursor = nextCursor;
                }

                applyBadgeCounts(data.badge_counts || {});
                applyTicketStatuses(data.ticket_statuses || {});

                if (bootstrapped) {
                    appendTrailDeltas(data.trail_deltas || {});
                    showNotifications(data.notifications || []);
                }

                if (!bootstrapped) {
                    bootstrapped = true;
                }
            }).catch(function () {
                // no-op: silent polling failure
            }).finally(function () {
                inFlight = false;
            });
        }

        pollOnce();
        setInterval(pollOnce, intervalMs);
    }

    function clearReplyFormUI(form) {
        if (!form) return;
        var textarea = form.querySelector('textarea[name="message"]');
        if (textarea) textarea.value = '';

        var fileInput = form.querySelector('input[type="file"][id^="reply_attachments_"]');
        if (fileInput) {
            fileInput.value = '';
            var suffix = (fileInput.id || '').replace('reply_attachments_', '');
            var preview = document.getElementById('replyPreview_' + suffix);
            if (preview) preview.innerHTML = '';
        }
    }

    function shouldHandleAjaxReply(form) {
        if (!form) return false;
        var action = String(form.getAttribute('action') || '').toLowerCase();
        if (action.indexOf('controllers/branch/reply-ticket.php') !== -1) {
            return true;
        }

        if (action.indexOf('controllers/vpo/submit-ticket.php') !== -1 || action.indexOf('controllers/cad/submit-ticket.php') !== -1) {
            var actionInput = form.querySelector('input[name="action"]');
            return !!actionInput && String(actionInput.value || '').toLowerCase() === 'reply';
        }

        return false;
    }

    function stEscapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function resolveTrailIconSrc(role) {
        var roleLower = String(role || '').toLowerCase();
        if (roleLower !== 'branch' && roleLower !== 'vpo' && roleLower !== 'cad') {
            return '';
        }

        // Prefer source from existing rendered trail icons on page.
        var probe = document.querySelector('.tm-trail-avatar-icon--' + roleLower);
        if (probe) {
            var src = String(probe.getAttribute('src') || '').trim();
            if (src !== '') {
                return src;
            }
        }

        // Fallback to project-relative absolute path.
        var prefix = '';
        try {
            var pathname = String(window.location.pathname || '');
            var idx = pathname.toLowerCase().indexOf('/dashboard/');
            if (idx >= 0) {
                prefix = pathname.slice(0, idx);
            }
        } catch (e) {
            // ignore path parsing issues
        }

        if (roleLower === 'branch') return prefix + '/assets/images/icons/branch-icon.svg';
        if (roleLower === 'vpo') return prefix + '/assets/images/icons/vpo-icon.svg';
        if (roleLower === 'cad') return prefix + '/assets/images/icons/cad-icon.svg';
        return '';
    }

    function buildTrailAvatarInner(role) {
        var roleUpper = String(role || '').toUpperCase();
        var roleLower = roleUpper.toLowerCase();
        var src = resolveTrailIconSrc(roleLower);
        if (src !== '') {
            return '<img class="tm-trail-avatar-icon tm-trail-avatar-icon--' + stEscapeHtml(roleLower) + '" src="' + stEscapeHtml(src) + '" alt="" aria-hidden="true">';
        }
        return '<i class="fa-solid fa-gear" aria-hidden="true"></i>';
    }

    function formatTrailDatetimeText(raw) {
        var value = String(raw || '').trim();
        if (!value) return '';

        var d = new Date(value.replace(' ', 'T'));
        if (isNaN(d.getTime())) {
            return value;
        }

        try {
            return d.toLocaleString(undefined, {
                month: 'short',
                day: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                hour12: true
            }).replace(',', '');
        } catch (e) {
            return value;
        }
    }

    function getReplySenderRole(form) {
        if (!form) return 'SYSTEM';
        var action = String(form.getAttribute('action') || '').toLowerCase();
        if (action.indexOf('/branch/reply-ticket.php') !== -1) return 'BRANCH';
        if (action.indexOf('/vpo/submit-ticket.php') !== -1) return 'VPO';
        if (action.indexOf('/cad/submit-ticket.php') !== -1) return 'CAD';
        return 'SYSTEM';
    }

    function nowTrailDatetimeText() {
        try {
            return new Date().toLocaleString(undefined, {
                month: 'short',
                day: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                hour12: true
            }).replace(',', '');
        } catch (e) {
            return '';
        }
    }

    function formatBytesShort(bytes) {
        if (!bytes) return '0 B';
        var sizes = ['B', 'KB', 'MB', 'GB'];
        var i = Math.floor(Math.log(bytes) / Math.log(1024));
        return (bytes / Math.pow(1024, i)).toFixed(2) + ' ' + sizes[i];
    }

    function appendRealtimeReplyToTrail(form, messageText, attachments, serverTrailId) {
        if (!form || !messageText) return;

        var modal = form.closest('.tm-modal');
        if (!modal) return;

        var trail = modal.querySelector('.tm-trail');
        if (!trail) return;

        var realTrailId = parseInt(serverTrailId || 0, 10) || 0;
        if (realTrailId > 0 && trail.querySelector('.tm-trail-item[data-trail-id="' + realTrailId + '"]')) {
            return;
        }

        var empty = trail.querySelector('.tm-empty-trail');
        if (empty && empty.parentNode) {
            empty.parentNode.removeChild(empty);
        }

        var prevLatest = trail.querySelector('.tm-trail-card[data-tm-latest]');
        if (prevLatest) {
            prevLatest.removeAttribute('data-tm-latest');
            prevLatest.classList.remove('tm-expanded');
            var prevHeader = prevLatest.querySelector('.tm-trail-card-header');
            if (prevHeader) {
                prevHeader.setAttribute('aria-expanded', 'false');
            }
        }

        var role = getReplySenderRole(form);
        var avatarClass = 'tm-trail-avatar--system';
        if (role === 'BRANCH') {
            avatarClass = 'tm-trail-avatar--branch';
        } else if (role === 'VPO') {
            avatarClass = 'tm-trail-avatar--vpo';
        } else if (role === 'CAD') {
            avatarClass = 'tm-trail-avatar--cad';
        }
        var avatarInner = buildTrailAvatarInner(role);

        var dtText = nowTrailDatetimeText();
        var safeMessage = stEscapeHtml(messageText).replace(/\n/g, '<br>');
        var attachmentHtml = '';

        if (attachments && attachments.length) {
            var nodes = [];
            attachments.forEach(function (file) {
                if (!file) return;
                nodes.push(
                    '<div class="tm-attachment" title="Attachment uploaded">' +
                        '<span class="tm-attachment-icon"><i class="fa-solid fa-paperclip" aria-hidden="true"></i></span>' +
                        '<span class="tm-attachment-name">' + stEscapeHtml(file.name || 'Attachment') + '</span>' +
                        '<span class="tm-attachment-size">' + stEscapeHtml(formatBytesShort(file.size || 0)) + '</span>' +
                    '</div>'
                );
            });

            if (nodes.length) {
                attachmentHtml = '<div class="tm-attachments">' + nodes.join('') + '</div>';
            }
        }

        var item = document.createElement('div');
        item.className = 'tm-trail-item';
        if (realTrailId > 0) {
            item.setAttribute('data-trail-id', String(realTrailId));
        }
        item.innerHTML =
            '<div class="tm-trail-dot-wrap">' +
                '<div class="tm-trail-avatar ' + avatarClass + '">' + avatarInner + '</div>' +
            '</div>' +
            '<div class="tm-trail-card tm-expanded" data-tm-latest="1">' +
                '<div class="tm-trail-card-header">' +
                    '<div class="tm-trail-avatar ' + avatarClass + '">' + avatarInner + '</div>' +
                    '<div class="tm-trail-meta">' +
                        '<div class="tm-trail-sender"><span>' + stEscapeHtml(role) + '</span></div>' +
                        '<div class="tm-trail-datetime">' + stEscapeHtml(dtText) + '</div>' +
                    '</div>' +
                    '<div class="tm-trail-type-label tm-trail-type-label--message">Message</div>' +
                    '<div class="tm-trail-chevron">›</div>' +
                '</div>' +
                '<div class="tm-trail-card-body">' +
                    '<div class="tm-trail-message">' + safeMessage + '</div>' +
                    attachmentHtml +
                '</div>' +
            '</div>';

        trail.appendChild(item);
        prepareTrailCardHeader(item.querySelector('.tm-trail-card-header'));
        adjustTrailCardHeights(modal);

        var body = modal.querySelector('.tm-body');
        if (body) {
            requestAnimationFrame(function () {
                body.scrollTop = body.scrollHeight;
            });
        }
    }

    function initAjaxReplySubmits() {
        bindAjaxReplySubmitsInScope(document);
    }

    function bindAjaxReplySubmitsInScope(scopeRoot) {
        var root = scopeRoot || document;
        var forms = root.querySelectorAll('form[method="post"], form[method="POST"]');
        forms.forEach(function (form) {
            bindAjaxReplySubmitForm(form);
        });
    }

    function bindAjaxReplySubmitForm(form) {
        if (!form || !shouldHandleAjaxReply(form)) return;
        if (form.getAttribute('data-st-ajax-reply-bound') === '1') return;

        form.setAttribute('data-st-ajax-reply-bound', '1');

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var formData = new FormData(form);
            var submittedMessage = String(formData.get('message') || '').trim();
            var fileInput = form.querySelector('input[type="file"][id^="reply_attachments_"]');
            var submittedAttachments = fileInput && fileInput.files ? Array.prototype.slice.call(fileInput.files) : [];
            var submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;

            fetch(form.getAttribute('action') || window.location.href, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            }).then(function (res) {
                return res.json().catch(function () {
                    return { success: false, message: 'Unexpected server response.' };
                });
            }).then(function (json) {
                if (!json || !json.success) {
                    stShowToast((json && json.message) ? json.message : 'Unable to submit reply.', 'danger');
                    return;
                }

                var serverTrailId = json && json.data ? parseInt(json.data.trail_id || 0, 10) || 0 : 0;
                appendRealtimeReplyToTrail(form, submittedMessage, submittedAttachments, serverTrailId);
                clearReplyFormUI(form);
                stShowToast(json.message || 'Reply submitted successfully.', 'success');
            }).catch(function () {
                stShowToast('Network error while submitting reply.', 'danger');
            }).finally(function () {
                if (submitBtn) submitBtn.disabled = false;
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        window.stShowToast = stShowToast;
        initModeCards();
        initCreateModal();
        initTicketTrailModals();
        initTrailCardToggles();
        initTransferConfirmModals();
        initClosePickerModals();
        initAttachmentPreviews();
        initReplyAttachmentPreviews();
        initTicketCopyButtons();
        initReferenceCopyHandler();
        initAjaxReplySubmits();
        initInitialFlashToast();
        initOpenTicketsPolling();
        initLiveUpdatesPolling();
    });
})();
