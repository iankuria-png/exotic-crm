import React from 'react';

export default function KillSwitchToggle({ disabled, onToggle, profile }) {
    const enabled = Boolean(profile.kill_switch_enabled);

    return (
        <button
            type="button"
            disabled={disabled}
            onClick={() => {
                if (!enabled) {
                    const confirmation = window.prompt(`Type KILL SWITCH to block sends for ${profile.profile_name}.`);
                    if (confirmation !== 'KILL SWITCH') {
                        return;
                    }

                    onToggle(profile, !enabled);
                    return;
                }

                if (window.confirm(`Disable the kill switch for ${profile.profile_name}? Sends may resume immediately if routing points to this profile.`)) {
                    onToggle(profile, !enabled);
                }
            }}
            className={`inline-flex items-center rounded-md px-2.5 py-1.5 text-xs font-semibold ring-1 ring-inset disabled:cursor-not-allowed disabled:opacity-60 ${
                enabled
                    ? 'bg-rose-50 text-rose-700 ring-rose-200 hover:bg-rose-100'
                    : 'bg-emerald-50 text-emerald-700 ring-emerald-200 hover:bg-emerald-100'
            }`}
        >
            {enabled ? 'Kill switch on' : 'Kill switch off'}
        </button>
    );
}
