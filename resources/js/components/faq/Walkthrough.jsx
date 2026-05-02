import { useEffect } from 'react';
import { useLocation } from 'react-router-dom';
import { driver } from 'driver.js';
import 'driver.js/dist/driver.css';

const STORAGE_KEY = 'exoticcrm.faq.walkthrough';

function normalizeSteps(rawSteps = []) {
    return rawSteps.map((step) => ({
        element: step.element_selector,
        popover: {
            title: step.title,
            description: step.body,
            side: step.side || 'bottom',
            align: step.align || 'start',
        },
    }));
}

export function queueWalkthrough(payload) {
    if (typeof window === 'undefined') {
        return;
    }

    window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
}

export default function Walkthrough() {
    const location = useLocation();

    useEffect(() => {
        const raw = window.sessionStorage.getItem(STORAGE_KEY);
        if (!raw) {
            return;
        }

        try {
            const payload = JSON.parse(raw);
            if (payload?.targetPath && payload.targetPath !== `${location.pathname}${location.search}`) {
                return;
            }

            const steps = normalizeSteps(payload?.steps || []);
            if (!steps.length) {
                window.sessionStorage.removeItem(STORAGE_KEY);
                return;
            }

            const tour = driver({
                showProgress: true,
                allowClose: true,
                steps,
            });

            window.sessionStorage.removeItem(STORAGE_KEY);
            window.setTimeout(() => tour.drive(), 200);
        } catch {
            window.sessionStorage.removeItem(STORAGE_KEY);
        }
    }, [location.pathname, location.search]);

    return null;
}
