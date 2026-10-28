(function () {
    'use strict';

    var STORAGE_KEY = 'spatbridge.layout';

    function readChoice() {
        try {
            return window.localStorage.getItem(STORAGE_KEY);
        } catch (e) {
            return null;
        }
    }

    function storeChoice(value) {
        try {
            window.localStorage.setItem(STORAGE_KEY, value);
        } catch (e) {
            // Storage blocked: the choice just won't persist.
        }
    }

    function show(root, layout) {
        var stacked = root.querySelector('.spatbridge-stacked');
        var tabbed = root.querySelector('.spatbridge-tabbed');
        if (!stacked || !tabbed) {
            return;
        }
        stacked.hidden = layout !== 'stacked';
        tabbed.hidden = layout !== 'tabbed';
        root.setAttribute('data-layout', layout);
    }

    function init(root) {
        var allowSwitch = root.getAttribute('data-switch') === '1';
        var fallback = root.getAttribute('data-default') === 'tabbed' ? 'tabbed' : 'stacked';
        var stored = allowSwitch ? readChoice() : null;

        show(root, stored === 'tabbed' || stored === 'stacked' ? stored : fallback);

        root.addEventListener('click', function (event) {
            var target = event.target;
            if (!target || !target.closest) {
                return;
            }

            var toggle = target.closest('.spatbridge-switch');
            if (toggle && allowSwitch) {
                event.preventDefault();
                event.stopPropagation();
                var next = root.getAttribute('data-layout') === 'tabbed' ? 'stacked' : 'tabbed';
                show(root, next);
                storeChoice(next);
                return;
            }

            var tab = target.closest('.spatbridge-tab');
            if (tab) {
                event.preventDefault();
                var key = tab.getAttribute('data-tab');
                var tabs = root.querySelectorAll('.spatbridge-tab');
                var panes = root.querySelectorAll('.spatbridge-tabpane');
                Array.prototype.forEach.call(tabs, function (el) {
                    var item = el.parentNode;
                    item.classList.toggle('active', el.getAttribute('data-tab') === key);
                });
                Array.prototype.forEach.call(panes, function (el) {
                    el.hidden = el.getAttribute('data-tab') !== key;
                });
            }
        });
    }

    function boot() {
        Array.prototype.forEach.call(document.querySelectorAll('.spatbridge'), init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
