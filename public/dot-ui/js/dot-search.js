/*
 * dot-ui search, optional behaviour. Only what InfoDot's bar does around the dropdown:
 * open on focus, close on outside click or Escape (Escape also blurs), Ctrl/Cmd+K focuses the box when it shows a hint.
 * No keyboard list navigation and no fetching: those are host logic. CSP-safe: no inline script, no eval.
 * Without this file the CSS still opens the panel with :focus-within.
 */
(function () {
    'use strict';

    function setup(root) {
        var input = root.querySelector('.dsr-input');
        if (!input || root.__dsr) { return; }
        root.__dsr = true;

        function open() {
            root.removeAttribute('data-closed');
            root.classList.add('is-open');
            input.setAttribute('aria-expanded', 'true');
        }
        function close() {
            root.classList.remove('is-open');
            input.setAttribute('aria-expanded', 'false');
        }

        input.addEventListener('focus', open);
        root.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                root.setAttribute('data-closed', '');
                close();
                input.blur();
            }
        });
        root.addEventListener('focusout', function (e) {
            if (e.relatedTarget && !root.contains(e.relatedTarget)) { close(); }
        });
        document.addEventListener('click', function (e) {
            if (!root.contains(e.target)) { close(); }
        });

        if (root.hasAttribute('data-dsr-shortcut')) {
            window.addEventListener('keydown', function (e) {
                if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 'k' || e.key === 'K')) {
                    e.preventDefault();
                    input.focus();
                }
            });
        }
    }

    function init() {
        var roots = document.querySelectorAll('.dsr[data-dsr]');
        for (var i = 0; i < roots.length; i++) { setup(roots[i]); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    window.DotSearch = { init: init };
})();
