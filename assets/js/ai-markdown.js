/* Ecollab Phase 4.4 — safe Markdown rendering for AI responses. */
(function () {
    'use strict';

    function configureMarked() {
        if (typeof window.marked === 'undefined') {
            return false;
        }

        window.marked.setOptions({
            gfm: true,
            breaks: true
        });

        return true;
    }

    function sanitize(html) {
        if (typeof window.DOMPurify === 'undefined') {
            return html;
        }

        return window.DOMPurify.sanitize(html, {
            USE_PROFILES: {
                html: true
            },
            FORBID_TAGS: [
                'script',
                'iframe',
                'object',
                'embed',
                'form',
                'style'
            ],
            FORBID_ATTR: [
                'onerror',
                'onclick',
                'onload',
                'onmouseover',
                'onfocus',
                'onmouseenter',
                'onmouseleave'
            ],
            ALLOW_UNKNOWN_PROTOCOLS: false
        });
    }

    window.renderAiMarkdown = function (markdown) {
        const source = String(markdown ?? '');

        if (typeof window.marked === 'undefined') {
            return escapeHtml(source).replace(/\n/g, '<br>');
        }

        configureMarked();

        const html = window.marked.parse(source);

        return sanitize(html);
    };

    window.renderAiMarkdownInto = function (element, markdown) {
        if (!(element instanceof HTMLElement)) {
            return;
        }

        element.innerHTML = window.renderAiMarkdown(markdown);
    };

    function escapeHtml(value) {
        return value.replace(/[&<>"']/g, function (character) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[character];
        });
    }
})();