import { useEffect, useRef } from 'react';

export default function useKycDialog(open, onClose) {
    const ref = useRef(null);
    const close = useRef(onClose);
    close.current = onClose;
    useEffect(() => {
        if (!open) return;
        const previous = document.activeElement;
        const dialog = ref.current;
        const elements = () => [...(dialog?.querySelectorAll('button:not(:disabled), a[href], input:not(:disabled), textarea:not(:disabled), select:not(:disabled)') || [])].filter(el => el.offsetParent !== null);
        elements()[0]?.focus();
        const handle = (event) => {
            if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close.current?.(); }
            if (event.key !== 'Tab') return;
            const items = elements(); const first = items[0]; const last = items[items.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
        };
        dialog?.addEventListener('keydown', handle);
        return () => { dialog?.removeEventListener('keydown', handle); previous?.focus(); };
    }, [open]);
    return ref;
}
