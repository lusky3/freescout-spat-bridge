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

    /**
     * Called by FreeScout once the preview partial is in the pop-up.
     * Confirm stays disabled until every confirmation is ticked and while a
     * run is in flight; the idempotency key makes a repeated run harmless.
     */
    window.spatbridgeActionInit = function (modal) {
        var root = element(modal);
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
