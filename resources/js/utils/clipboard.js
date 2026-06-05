export async function copyToClipboard(text) {
    const value = String(text ?? '');

    if (!value) {
        throw new Error('Nothing to copy.');
    }

    if (typeof navigator !== 'undefined' && navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(value);
        return;
    }

    if (typeof document === 'undefined') {
        throw new Error('Clipboard is not available.');
    }

    const textarea = document.createElement('textarea');
    textarea.value = value;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    textarea.style.pointerEvents = 'none';
    document.body.appendChild(textarea);
    textarea.select();

    try {
        const copied = document.execCommand('copy');
        if (!copied) {
            throw new Error('Clipboard copy failed.');
        }
    } finally {
        document.body.removeChild(textarea);
    }
}
