import { useCallback, useEffect, useState } from 'react';

/**
 * Reports `true` once the referenced element comes within `rootMargin` of the viewport,
 * and stays true. Used to hold back below-the-fold dashboard requests until they are
 * about to be seen, so the first screen's requests are not queued behind them.
 *
 * @returns {[(node: Element | null) => void, boolean]} a callback ref and the revealed flag
 */
export default function useRevealOnce(rootMargin = '600px 0px') {
    const [revealed, setRevealed] = useState(() => typeof window === 'undefined' || typeof window.IntersectionObserver === 'undefined');
    const [node, setNode] = useState(null);
    const ref = useCallback((element) => setNode(element), []);

    useEffect(() => {
        if (revealed || !node) {
            return undefined;
        }

        const observer = new window.IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                setRevealed(true);
                observer.disconnect();
            }
        }, { rootMargin });
        observer.observe(node);

        return () => observer.disconnect();
    }, [node, revealed, rootMargin]);

    return [ref, revealed];
}
