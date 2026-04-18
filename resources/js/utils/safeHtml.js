import DOMPurify from 'dompurify';

export function safeHtml(raw) {
    if (raw == null) return '';
    return DOMPurify.sanitize(String(raw), {
        ALLOWED_TAGS: ['b', 'i', 'em', 'strong', 'br', 'p', 'ul', 'ol', 'li', 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'code', 'span'],
        ALLOWED_ATTR: ['href'],
    });
}
