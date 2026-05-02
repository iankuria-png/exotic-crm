import React from 'react';
import { useNavigate } from 'react-router-dom';
import { queueWalkthrough } from './Walkthrough';

export default function ArticleCtaButton({ cta }) {
    const navigate = useNavigate();

    const handleClick = () => {
        const targetPath = cta?.target_path || '/faq';

        if (cta?.kind === 'walkthrough' && cta?.walkthrough?.steps?.length) {
            queueWalkthrough({
                targetPath,
                steps: cta.walkthrough.steps,
            });
        }

        navigate(targetPath);
    };

    return (
        <button
            type="button"
            onClick={handleClick}
            className="crm-btn-secondary px-3 py-2 text-sm"
        >
            {cta?.label || 'Open'}
        </button>
    );
}
