import { useEffect, useEffectEvent } from 'react';
import api from '../services/api';
import { ensureSessionToken } from '../utils/authStorage';

const HEARTBEAT_INTERVAL_MS = 60_000;

export function useHeartbeat(user) {
    const userId = user?.id;

    const sendHeartbeat = useEffectEvent(async (sessionToken) => {
        if (!userId || !sessionToken) {
            return;
        }

        try {
            await api.post('/crm/heartbeat', {
                session_token: sessionToken,
            });
        } catch {
            // Intentionally silent. Auth failures are handled globally and stale cleanup will close abandoned sessions.
        }
    });

    useEffect(() => {
        if (!userId || typeof document === 'undefined') {
            return undefined;
        }

        const sessionToken = ensureSessionToken();
        let intervalId = null;

        const clearHeartbeatInterval = () => {
            if (intervalId !== null) {
                window.clearInterval(intervalId);
                intervalId = null;
            }
        };

        const startHeartbeat = () => {
            clearHeartbeatInterval();

            if (document.visibilityState !== 'visible') {
                return;
            }

            void sendHeartbeat(sessionToken);
            intervalId = window.setInterval(() => {
                void sendHeartbeat(sessionToken);
            }, HEARTBEAT_INTERVAL_MS);
        };

        const handleVisibilityChange = () => {
            if (document.visibilityState === 'visible') {
                startHeartbeat();
                return;
            }

            clearHeartbeatInterval();
        };

        startHeartbeat();
        document.addEventListener('visibilitychange', handleVisibilityChange);

        return () => {
            clearHeartbeatInterval();
            document.removeEventListener('visibilitychange', handleVisibilityChange);
        };
    }, [userId]);
}
