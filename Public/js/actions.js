/*
 * Action pop-up behaviour. The pop-up's HTML is loaded over AJAX, where
 * FreeScout's CSP blocks inline scripts, so everything lives here and is
 * wired through data-modal-on-show="spatbridgeActionInit".
 *
 * Text from WordPress is only ever written with textContent.
 */
(function () {
    'use strict';

    var GENERIC_ERROR = "Couldn't reach FreeScout. Try again.";

    function element(modal) {
        if (modal && modal.jquery) {
            return modal.get(0);
        }
        return modal && modal.querySelector ? modal : null;
    }

    function messageFrom(response, fallback) {
        if (response && typeof response.msg === 'string' && response.msg !== '') {
            return response.msg;
        }
        return fallback;
    }

    function hideModal(root) {
        if (window.jQuery && root) {
            window.jQuery(root).modal('hide');
        }
    }

    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    function formatDate(iso) {
        var date = new Date(iso);
        if (typeof iso !== 'string' || isNaN(date.getTime())) {
            return '';
        }
        return MONTHS[date.getUTCMonth()] + ' ' + date.getUTCDate();
    }

    function formatAmount(value) {
        var number = parseFloat(value);
        return isNaN(number) ? '' : '$' + number.toFixed(2);
    }

    function joined(parts) {
        return parts.filter(function (part) {
            return part !== '';
        }).join(' · ');
    }

    function text(value) {
        return typeof value === 'string' || typeof value === 'number' ? String(value) : '';
    }

    /**
     * Loads the preview for the given params into the pop-up in place of what
     * it shows now (the picker), then wires the new content up.
     */
    function loadPreview(root, pick, params, onError) {
        var body = root.querySelector('.modal-body') || pick.parentNode;
        window.jQuery.ajax({
            url: pick.getAttribute('data-preview-url'),
            method: 'get',
            dataType: 'html',
            data: {
                feature: pick.getAttribute('data-feature'),
                action: pick.getAttribute('data-action'),
                params: JSON.stringify(params),
                conversation_id: pick.getAttribute('data-conversation-id')
            },
            success: function (html) {
                body.innerHTML = html; // The server's escaped partial, as FreeScout loads its own.
                window.spatbridgeActionInit(root);
            },
            error: function () {
                onError(GENERIC_ERROR);
            }
        });
    }

    /**
     * The picker shown when a match still lacks an order or a transfer. Find
     * runs a query; "Use" requests the preview again with both ids.
     */
    function initPick(root, pick) {
        if (pick.getAttribute('data-bound') === '1' || !window.jQuery) {
            return;
        }
        pick.setAttribute('data-bound', '1');

        var mode = pick.getAttribute('data-mode');
        var input = pick.querySelector('.spatbridge-pick-input');
        var find = pick.querySelector('.spatbridge-pick-find');
        var errorBox = pick.querySelector('.spatbridge-pick-error');
        var known = {};
        try {
            known = JSON.parse(pick.getAttribute('data-params') || '{}') || {};
        } catch (e) {
            known = {};
        }

        function showError(message) {
            if (errorBox) {
                errorBox.textContent = message;
                errorBox.hidden = message === '';
            }
        }

        function render(result) {
            var wrap = document.createElement('div');
            var label = document.createElement('span');
            var use = document.createElement('button');
            var id;
            if (mode === 'order') {
                id = result.order_id;
                label.textContent = joined([
                    'Order #' + text(id),
                    formatAmount(result.total),
                    text(result.billing_name),
                    result.on_hold ? '' : 'not on hold (' + text(result.status) + ')'
                ]);
                use.textContent = 'Use this order';
            } else {
                id = result.log_id;
                var from = text(result.from_name);
                var reference = text(result.reference);
                label.textContent = joined([
                    formatDate(result.at),
                    formatAmount(result.amount),
                    from,
                    reference === '' ? '' : 'ref ' + reference
                ]);
                use.textContent = 'Use';
            }
            use.type = 'button';
            use.className = 'btn btn-default btn-xs pull-right';
            use.addEventListener('click', function () {
                var params = {};
                Object.keys(known).forEach(function (name) {
                    params[name] = known[name];
                });
                params[mode === 'order' ? 'order_id' : 'log_id'] = id;
                use.disabled = true;
                showError('');
                loadPreview(root, pick, params, function (message) {
                    use.disabled = false;
                    showError(message);
                });
            });
            wrap.appendChild(use);
            wrap.appendChild(label);
            return wrap;
        }

        function run() {
            window.spatbridgeSearch(input, find.getAttribute('data-url'), render);
        }

        if (find) {
            find.addEventListener('click', function (event) {
                event.preventDefault();
                run();
            });
        }
        if (input) {
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    run();
                }
            });
        }
    }

    /**
     * The offer form shown before a waitlist offer is previewed. Preview
     * requests the dry run again with the hours and message added.
     */
    function initOfferForm(root, form) {
        if (form.getAttribute('data-bound') === '1' || !window.jQuery) {
            return;
        }
        form.setAttribute('data-bound', '1');

        var hours = form.querySelector('.spatbridge-offer-hours');
        var message = form.querySelector('.spatbridge-offer-message');
        var button = form.querySelector('.spatbridge-offer-preview');
        var errorBox = form.querySelector('.spatbridge-offer-error');

        function showError(problem) {
            if (errorBox) {
                errorBox.textContent = problem;
                errorBox.hidden = problem === '';
            }
        }

        if (!button) {
            return;
        }

        function submit(event) {
            event.preventDefault();
            if (button.disabled) {
                return;
            }
            var raw = hours ? hours.value.trim() : '';
            var count = raw === '' ? NaN : Number(raw);
            if (!Number.isInteger(count) || count < 1 || count > 720) {
                showError('Enter a number of hours from 1 to 720.');
                return;
            }
            var params = {};
            try {
                params = JSON.parse(form.getAttribute('data-params') || '{}') || {};
            } catch (e) {
                params = {};
            }
            params.hours = count;
            var note = message ? message.value.trim() : '';
            if (note !== '') {
                params.message = note.substring(0, 500);
            }
            button.disabled = true;
            showError('');
            loadPreview(root, form, params, function (failure) {
                button.disabled = false;
                showError(failure);
            });
        }

        button.addEventListener('click', submit);
        if (hours) {
            hours.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    submit(event);
                }
            });
        }
    }

    /**
     * Called by FreeScout once the preview partial is in the pop-up.
     * Confirm stays disabled until every confirmation is ticked and while a
     * run is in flight; the idempotency key makes a repeated run harmless.
     */
    window.spatbridgeActionInit = function (modal) {
        var root = element(modal);
        var pick = root ? root.querySelector('.spatbridge-pick') : null;
        if (pick) {
            initPick(root, pick);
            return;
        }
        var offerForm = root ? root.querySelector('.spatbridge-offer-form') : null;
        if (offerForm) {
            initOfferForm(root, offerForm);
            return;
        }
        var box = root ? root.querySelector('.spatbridge-action-preview') : null;
        if (!box || box.getAttribute('data-bound') === '1') {
            return;
        }
        box.setAttribute('data-bound', '1');

        var button = box.querySelector('.spatbridge-action-run');
        var errorBox = box.querySelector('.spatbridge-action-error');
        var label = button ? button.textContent : '';
        var busy = false;

        function confirmBoxes() {
            return box.querySelectorAll('.spatbridge-action-confirm');
        }

        function allTicked() {
            return Array.prototype.every.call(confirmBoxes(), function (input) {
                return input.checked;
            });
        }

        function sync() {
            if (button) {
                button.disabled = busy || !allTicked();
            }
        }

        function showError(text) {
            if (!errorBox) {
                return;
            }
            errorBox.textContent = text;
            errorBox.hidden = text === '';
        }

        function finish(errorText) {
            busy = false;
            button.textContent = label;
            showError(errorText);
            sync();
        }

        box.addEventListener('change', function (event) {
            if (event.target && event.target.classList.contains('spatbridge-action-confirm')) {
                sync();
            }
        });

        if (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                if (busy || !allTicked()) {
                    return;
                }
                busy = true;
                sync();
                showError('');
                button.textContent = button.getAttribute('data-running-text') || label;

                var codes = [];
                Array.prototype.forEach.call(confirmBoxes(), function (input) {
                    codes.push(input.value);
                });

                window.fsAjax(
                    {
                        feature: box.getAttribute('data-feature'),
                        action: box.getAttribute('data-action'),
                        params: box.getAttribute('data-params'),
                        conversation_id: box.getAttribute('data-conversation-id'),
                        idempotency_key: box.getAttribute('data-idempotency-key'),
                        confirm: codes
                    },
                    box.getAttribute('data-run-url'),
                    function (response) {
                        if (response && response.status === 'success') {
                            hideModal(root);
                            window.location.reload();
                            return;
                        }
                        finish(messageFrom(response, GENERIC_ERROR));
                    },
                    true,
                    function (xhr) {
                        finish(messageFrom(xhr && xhr.responseJSON, GENERIC_ERROR));
                    }
                );
            });
        }

        sync();
    };

    /**
     * Runs one read-only query for a search box in a pop-up and lists the
     * results under it.
     *
     * input:  the text input. data-param names the param its value is sent
     *         as (default "q"); data-params may hold extra fixed params as
     *         JSON.
     * url:    the query endpoint, with feature, query and conversation_id.
     * render: function (result) returning a string (written as text) or a
     *         DOM node built by the caller.
     */
    window.spatbridgeSearch = function (input, url, render) {
        if (!input || !url || !window.jQuery) {
            return;
        }

        var holder = input.closest ? input.closest('.spatbridge-search') : null;
        var list = holder ? holder.querySelector('.spatbridge-search-results') : null;
        if (!list) {
            list = document.createElement('ul');
            list.className = 'list-unstyled spatbridge-search-results';
            input.parentNode.insertBefore(list, input.nextSibling);
        }

        var params = {};
        try {
            params = JSON.parse(input.getAttribute('data-params') || '{}') || {};
        } catch (e) {
            params = {};
        }
        params[input.getAttribute('data-param') || 'q'] = input.value.trim();

        var seq = (parseInt(list.getAttribute('data-seq') || '0', 10) || 0) + 1;
        list.setAttribute('data-seq', String(seq));

        function line(text) {
            var item = document.createElement('li');
            item.textContent = text;
            list.appendChild(item);
        }

        function show(response) {
            if (list.getAttribute('data-seq') !== String(seq)) {
                return; // A newer search has started.
            }
            list.textContent = '';
            if (!response || response.status !== 'success') {
                line(messageFrom(response, GENERIC_ERROR));
                return;
            }
            var results = Array.isArray(response.results) ? response.results : [];
            if (results.length === 0) {
                line('No results.');
                return;
            }
            results.forEach(function (result) {
                var rendered = render(result);
                if (rendered && rendered.nodeType) {
                    var item = document.createElement('li');
                    item.appendChild(rendered);
                    list.appendChild(item);
                } else {
                    line(String(rendered));
                }
            });
        }

        window.jQuery.ajax({
            url: url + (url.indexOf('?') === -1 ? '?' : '&') + 'params=' + encodeURIComponent(JSON.stringify(params)),
            method: 'get',
            dataType: 'json',
            success: show,
            error: function (xhr) {
                show(xhr && xhr.responseJSON ? xhr.responseJSON : null);
            }
        });
    };
})();
