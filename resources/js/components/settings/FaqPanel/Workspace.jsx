import React from 'react';
import { useQueries } from '@tanstack/react-query';
import faqApi from '../../../services/faqApi';
import Articles from './Articles';
import Categories from './Categories';
import Walkthroughs from './Walkthroughs';
import Feedback from './Feedback';
import FeedbackHubAdmin from './FeedbackHubAdmin';

export default function FaqWorkspace() {
    const results = useQueries({
        queries: [
            { queryKey: ['settings-faq-categories'], queryFn: () => faqApi.listCategories({ include_articles: 1 }) },
            { queryKey: ['settings-faq-articles'], queryFn: () => faqApi.listArticles({ per_page: 30 }) },
            { queryKey: ['settings-faq-walkthroughs'], queryFn: () => faqApi.listWalkthroughs() },
            { queryKey: ['settings-faq-feedback'], queryFn: () => faqApi.listFeedback({ per_page: 30 }) },
        ],
    });

    const [categoriesQuery, articlesQuery, walkthroughsQuery, feedbackQuery] = results;

    return (
        <div className="space-y-4">
            <Articles articles={articlesQuery.data?.articles || []} />
            <Categories categories={categoriesQuery.data?.categories || []} />
            <Walkthroughs walkthroughs={walkthroughsQuery.data?.walkthroughs || []} />
            <Feedback items={(feedbackQuery.data?.feedback || []).filter((item) => ['helpful', 'unhelpful', 'article_suggestion'].includes(item.kind))} />
            <FeedbackHubAdmin items={feedbackQuery.data?.feedback || []} />
        </div>
    );
}
