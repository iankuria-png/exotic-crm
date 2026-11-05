import React, { useEffect, useId, useRef } from 'react';
import { createPortal } from 'react-dom';

export default function ReviewDialog({ title, eyebrow, children, footer, onClose, wide = false }) {
    const root = useRef(null);
    const titleId = useId();
    const closeRef = useRef(onClose); closeRef.current = onClose;
    useEffect(() => {
        const previous = document.activeElement;
        const el = root.current;
        const appRoot = document.getElementById('app');
        if (appRoot) appRoot.inert = true;
        document.querySelectorAll('[data-review-dialog]').forEach(node => { node.inert = node !== el; });
        const selector = 'button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex="0"]';
        (el.querySelector(selector) || el).focus();
        const key = (event) => {
            const layers = document.querySelectorAll('[data-review-dialog]');
            if (layers[layers.length - 1] !== el) return;
            if (event.key === 'Escape') { event.preventDefault(); closeRef.current(); }
            if (event.key === 'Tab') {
                const items = [...el.querySelectorAll(selector)].filter((n) => n.getClientRects().length);
                if (!items.length) { event.preventDefault(); el.focus(); return; }
                if (event.shiftKey && (document.activeElement === items[0] || document.activeElement === el)) { event.preventDefault(); items[items.length - 1].focus(); }
                else if (!event.shiftKey && document.activeElement === items[items.length - 1]) { event.preventDefault(); items[0].focus(); }
            }
        };
        document.addEventListener('keydown', key);
        return () => { document.removeEventListener('keydown', key); const layers = [...document.querySelectorAll('[data-review-dialog]')].filter(node => node !== el); const top = layers[layers.length - 1]; if (top) top.inert = false; else if (appRoot) appRoot.inert = false; if (previous?.isConnected) previous.focus(); };
    }, []);
    return createPortal(<div className="rec-overlay"><section data-review-dialog ref={root} className={`rec-dialog ${wide ? 'rec-dialog-wide' : ''}`} role="dialog" aria-modal="true" aria-labelledby={titleId} tabIndex={-1}>
        <header className="rec-dialog-head"><div>{eyebrow && <p className="rec-eyebrow">{eyebrow}</p>}<h2 id={titleId}>{title}</h2></div><button className="rec-icon-button" type="button" aria-label="Close dialog" onClick={onClose}>×</button></header>
        <div className="rec-dialog-body">{children}</div>{footer && <footer className="rec-dialog-foot">{footer}</footer>}
    </section></div>, document.body);
}
