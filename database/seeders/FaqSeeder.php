<?php

namespace Database\Seeders;

use App\Models\Faq\Article;
use App\Models\Faq\Category;
use App\Models\Faq\Walkthrough;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class FaqSeeder extends Seeder
{
    public function run(): void
    {
        $authorId = User::query()->whereIn('role', ['admin', 'sub_admin'])->orderBy('id')->value('id')
            ?? User::query()->orderBy('id')->value('id');

        $walkthroughDefinitions = [
            'smart-delete-safely' => ['Smart delete safely', '[data-tour="clients-smart-delete"]'],
            'untracked-payment-reconciliation' => ['Reconcile an untracked payment', '[data-tour="payments-untracked-queue"]'],
            'record-shared-manual-payment' => ['Record a shared manual payment', '[data-tour="deals-shared-manual-payment"]'],
            'deactivate-subscription-reason-code' => ['Deactivate a subscription with the right reason', '[data-tour="client-detail-subscription-actions"]'],
            'dashboard-impersonation-banner' => ['Read the impersonation banner', '[data-tour="dashboard-impersonation-banner"]'],
            'clients-filter-cohorts' => ['Use client filters to build cohorts', '[data-tour="clients-filters"]'],
            'auto-match-queue-triage' => ['Triage the auto-match queue', '[data-tour="payments-auto-match-queue"]'],
            'payments-csv-import' => ['Import payments from CSV', '[data-tour="payments-import-panel"]'],
        ];

        foreach ($walkthroughDefinitions as $slug => [$name, $selector]) {
            Walkthrough::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'steps' => [
                        [
                            'element_selector' => $selector,
                            'title' => $name,
                            'body' => 'Start here, confirm the current filters, and follow the prompts in the article CTA.',
                            'position' => 1,
                            'side' => 'bottom',
                            'align' => 'start',
                        ],
                        [
                            'element_selector' => $selector,
                            'title' => 'Check the next action',
                            'body' => 'Review the queue item carefully before changing state or dispatching a follow-up action.',
                            'position' => 2,
                            'side' => 'bottom',
                            'align' => 'start',
                        ],
                    ],
                ]
            );
        }

        $categories = [
            [
                'slug' => 'cross-cutting',
                'name' => 'Cross-cutting',
                'description' => 'Foundational CRM concepts, glossary terms, and shared workflows.',
                'crm_page' => 'cross_cutting',
                'articles' => [
                    'Welcome to Exotic CRM',
                    'Glossary: WP State Conflict, Unified Non-Lapsed, Forever plan, and FX normalization',
                    'Reading retention bands',
                    'How View As / impersonation works',
                    'How to read the dashboard banner',
                ],
            ],
            [
                'slug' => 'dashboard',
                'name' => 'Dashboard',
                'description' => 'Understand the dashboard, scope selectors, and workload queues.',
                'crm_page' => 'dashboard',
                'articles' => [
                    'Customer Service Center anatomy',
                    'Market scope and time window',
                    'Reading the metric cards',
                    'Recovery queue and renewal workload',
                ],
            ],
            [
                'slug' => 'clients',
                'name' => 'Clients',
                'description' => 'Filter logic, status meanings, and safe bulk actions.',
                'crm_page' => 'clients',
                'articles' => [
                    'Search modes: exact, exact_missing, and fallback',
                    'Combining filters to build cohorts',
                    'Every client filter explained',
                    'The metric-card-as-segment pattern',
                    'Client status taxonomy',
                    'Resolving WP State Conflict',
                    'Retention bands and behavior tags',
                    'Smart delete rules of thumb',
                ],
            ],
            [
                'slug' => 'client-detail',
                'name' => 'Client Detail',
                'description' => 'Operational actions on a single client profile.',
                'crm_page' => 'client_detail',
                'articles' => [
                    'Tour of the client tabs',
                    'Sync from WordPress: when it works and when it does not',
                    'Generating a payment link',
                    'Adding a tour',
                    'Marking a client as verified',
                    'NEW badge modes',
                    'Creating a new subscription',
                    'Deactivating a subscription with the right reason',
                    'Wallet adjustments',
                    'Profile Health and what it surfaces',
                ],
            ],
            [
                'slug' => 'payments-subscriptions',
                'name' => 'Payments & Subscriptions',
                'description' => 'Payment queue workflows, matching, and subscription operations.',
                'crm_page' => 'payments',
                'articles' => [
                    'Flat vs Native and the FX banner',
                    'The five payment metric cards',
                    'Customer revenue mix segments',
                    'Auto-match queue triage',
                    'Reconciling untracked payments',
                    'Importing payments from CSV',
                    'Deal buckets reference',
                    'Recording a shared manual payment',
                    'Deactivation reason codes',
                    'Renewal pipeline vs Recently Expired vs Untracked Active',
                ],
            ],
        ];

        $deepLinks = [
            'dashboard' => '/?view=dashboard',
            'cross_cutting' => '/?help=overview',
            'clients' => '/clients?status=manual_review',
            'client_detail' => '/clients?highlight=profile-actions',
            'payments' => '/payments?tab=queue',
        ];

        $walkthroughMap = [
            'How to read the dashboard banner' => 'dashboard-impersonation-banner',
            'Combining filters to build cohorts' => 'clients-filter-cohorts',
            'Smart delete rules of thumb' => 'smart-delete-safely',
            'Deactivating a subscription with the right reason' => 'deactivate-subscription-reason-code',
            'Auto-match queue triage' => 'auto-match-queue-triage',
            'Reconciling untracked payments' => 'untracked-payment-reconciliation',
            'Importing payments from CSV' => 'payments-csv-import',
            'Recording a shared manual payment' => 'record-shared-manual-payment',
        ];

        foreach (array_values($categories) as $categoryIndex => $categoryDefinition) {
            $category = Category::query()->updateOrCreate(
                ['slug' => $categoryDefinition['slug']],
                [
                    'name' => $categoryDefinition['name'],
                    'description' => $categoryDefinition['description'],
                    'crm_page' => $categoryDefinition['crm_page'],
                    'position' => $categoryIndex + 1,
                ]
            );

            foreach (array_values($categoryDefinition['articles']) as $articleIndex => $title) {
                $slug = Str::slug($title);
                $article = Article::query()->updateOrCreate(
                    ['slug' => $slug],
                    [
                        'category_id' => $category->id,
                        'title' => $title,
                        'summary' => 'A practical guide for the ' . strtolower($category->name) . ' workflow in Exotic CRM.',
                        'body' => $this->articleBody($title, $category->name),
                        'body_draft' => null,
                        'status' => 'published',
                        'author_id' => $authorId,
                        'last_editor_id' => $authorId,
                        'position' => $articleIndex + 1,
                        'published_at' => now(),
                    ]
                );

                $article->ctas()->delete();
                $article->ctas()->create([
                    'position' => 1,
                    'kind' => 'deep_link',
                    'label' => 'Open related CRM view',
                    'target_path' => $deepLinks[$category->crm_page ?? 'cross_cutting'] ?? '/faq',
                    'prefill_payload' => null,
                    'walkthrough_id' => null,
                ]);

                if (isset($walkthroughMap[$title])) {
                    $article->ctas()->create([
                        'position' => 2,
                        'kind' => 'walkthrough',
                        'label' => 'Start walkthrough',
                        'target_path' => $deepLinks[$category->crm_page ?? 'cross_cutting'] ?? '/faq',
                        'prefill_payload' => null,
                        'walkthrough_id' => $walkthroughMap[$title],
                    ]);
                }
            }
        }
    }

    private function articleBody(string $title, string $categoryName): string
    {
        return <<<MD
# {$title}

## Why this matters
This article explains a high-frequency {$categoryName} workflow so agents can move quickly without guessing what the CRM is trying to tell them.

## What to check first
- Confirm the market scope, date range, and any active filters.
- Read badges and warning banners before changing a status.
- Prefer additive actions first: inspect, verify, then update.

## Common working pattern
1. Open the related CRM screen from the CTA.
2. Reproduce the state using the same filters or row context.
3. Confirm whether you are fixing data, triaging a queue item, or communicating with a client.
4. Save the smallest correct change and re-check the result.

## When to escalate
Escalate if the state conflicts with WordPress, a payment record looks duplicated, or the action would change live subscription status without enough evidence.
MD;
    }
}
