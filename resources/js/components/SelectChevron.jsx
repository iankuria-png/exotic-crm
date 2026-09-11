import React from 'react';

export default function SelectChevron({ children, className = '' }) {
    return (
        <span className={`relative block ${className}`}>
            {children}
            <svg
                className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500"
                viewBox="0 0 20 20"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.75"
                aria-hidden="true"
            >
                <path strokeLinecap="round" strokeLinejoin="round" d="m5 7.5 5 5 5-5" />
            </svg>
        </span>
    );
}
