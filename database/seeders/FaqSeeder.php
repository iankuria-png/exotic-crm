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
            'create-client-from-navigation' => ['Create a client record', '[data-tour="nav-new-client"]'],
            'smart-delete-safely' => ['Smart delete safely', '[data-tour="clients-smart-delete"]'],
            'client-access-tools' => ['Use client access tools', '[data-tour="client-detail-client-access"]'],
            'client-detail-payment-link' => ['Send a payment link from client detail', '[data-tour="client-detail-payment-link"]'],
            'activate-subscription-review' => ['Start a new subscription from client detail', '[data-tour="client-detail-new-subscription"]'],
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
                'description' => 'Shared operating rules, glossary terms, and habits that prevent avoidable mistakes.',
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
                'description' => 'Read the dashboard correctly, separate real work from noise, and enter the right queue.',
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
                'description' => 'Find the right profiles, read statuses correctly, and avoid risky cleanup actions.',
                'crm_page' => 'clients',
                'articles' => [
                    'Adding a client: CRM only vs WordPress provision',
                    'Editing a client safely',
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
                'slug' => 'leads',
                'name' => 'Leads',
                'description' => 'Work inbound prospects, assign ownership, and convert cleanly into client records.',
                'crm_page' => 'leads',
                'articles' => [
                    'When to create a lead instead of a client',
                    'Converting a lead to a client',
                    'Assigning and archiving leads with discipline',
                ],
            ],
            [
                'slug' => 'client-detail',
                'name' => 'Client Detail',
                'description' => 'Work one profile safely across tabs, subscriptions, notes, and verification actions.',
                'crm_page' => 'client_detail',
                'articles' => [
                    'Tour of the client tabs',
                    'Client access: setup links, passwords, and login as client',
                    'Sending a payment link from client detail',
                    'Activating a subscription after payment review',
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
                'description' => 'Resolve payment queues, match records cleanly, and handle subscription changes with evidence.',
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
            'leads' => '/leads',
            'client_detail' => '/clients?highlight=profile-actions',
            'payments' => '/payments?tab=queue',
        ];

        $walkthroughMap = [
            'Adding a client: CRM only vs WordPress provision' => 'create-client-from-navigation',
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
                        'summary' => $this->articleSummary($title, $category->name),
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

    private function articleSummary(string $title, string $categoryName): string
    {
        return match ($title) {
            'Welcome to Exotic CRM' => 'What this CRM is for, how to approach it, and where sales and customer service should look first.',
            'Glossary: WP State Conflict, Unified Non-Lapsed, Forever plan, and FX normalization' => 'A plain-English glossary for the CRM terms that most often confuse new agents.',
            'Reading retention bands' => 'How to read retention labels without overreacting to one datapoint.',
            'How View As / impersonation works' => 'What impersonation changes, what it does not, and how to exit safely.',
            'How to read the dashboard banner' => 'Use the top banner to understand scope, impersonation, and when you are not in your own operating context.',
            'Customer Service Center anatomy' => 'A fast tour of what the dashboard is trying to help you decide every day.',
            'Market scope and time window' => 'How scope selectors affect what you are seeing and why bad scope creates bad decisions.',
            'Reading the metric cards' => 'What the dashboard cards are signaling and how to click into them productively.',
            'Recovery queue and renewal workload' => 'How to separate urgent recovery work from normal renewal follow-up.',
            'Adding a client: CRM only vs WordPress provision' => 'Choose the right onboarding path when creating a profile so support, access, and subscription work start cleanly.',
            'Editing a client safely' => 'How to change profile data without creating WordPress conflicts or losing accountability.',
            'Search modes: exact, exact_missing, and fallback' => 'Choose the right search mode so you do not miss or misidentify a client.',
            'Combining filters to build cohorts' => 'Build useful client lists by stacking filters in a deliberate order.',
            'Every client filter explained' => 'A working reference for the most important filters on the Clients page.',
            'The metric-card-as-segment pattern' => 'Metric cards are not decoration; they are shortcuts into specific work queues.',
            'Client status taxonomy' => 'What each profile status usually means operationally and what action normally follows.',
            'Resolving WP State Conflict' => 'How to review a conflict before you change anything in CRM or WordPress.',
            'Retention bands and behavior tags' => 'How to combine retention and behavior signals when prioritizing outreach.',
            'Smart delete rules of thumb' => 'When Smart Delete is appropriate and when it is too risky.',
            'When to create a lead instead of a client' => 'Use the right record type so outreach, follow-up, and onboarding stay disciplined from the start.',
            'Converting a lead to a client' => 'Move a real prospect into the client workflow without duplicating records or losing sales context.',
            'Assigning and archiving leads with discipline' => 'Keep the pipeline trustworthy by using owner assignment and archive reasons consistently.',
            'Tour of the client tabs' => 'What each client tab is for so you stop hunting for the same data in the wrong place.',
            'Client access: setup links, passwords, and login as client' => 'How to help a client get into their account without weakening security or losing traceability.',
            'Sending a payment link from client detail' => 'Prepare and send the right payment link so the next subscription step is backed by a real payment trail.',
            'Activating a subscription after payment review' => 'What should be true before you activate a subscription and expose a profile publicly.',
            'Sync from WordPress: when it works and when it does not' => 'What a sync can fix, what it cannot fix, and when to stop retrying.',
            'Generating a payment link' => 'How to create the right payment link and what to verify before you send it.',
            'Adding a tour' => 'How to log a tour or visit correctly so other agents inherit clean context.',
            'Marking a client as verified' => 'What verified should mean before you apply it to a profile.',
            'NEW badge modes' => 'How the NEW badge behaves and when an override is justified.',
            'Creating a new subscription' => 'Start a new subscription without confusing it with a renewal or recovery action.',
            'Deactivating a subscription with the right reason' => 'Choose the correct deactivation reason because it affects reporting, risk, and follow-up.',
            'Wallet adjustments' => 'How to treat wallet changes carefully and leave an audit trail another agent can trust.',
            'Profile Health and what it surfaces' => 'Use Profile Health to spot mismatches, missing data, and operational risk early.',
            'Flat vs Native and the FX banner' => 'When to compare markets in normalized reporting and when to trust native currency values.',
            'The five payment metric cards' => 'What each payment card represents and which ones demand action first.',
            'Customer revenue mix segments' => 'Read the mix segments as workload and revenue shape, not just as pretty percentages.',
            'Auto-match queue triage' => 'A safe way to review suggested matches before you create downstream mistakes.',
            'Reconciling untracked payments' => 'How to work orphaned payments without inventing history.',
            'Importing payments from CSV' => 'How to prepare, preview, and commit imports without polluting live data.',
            'Deal buckets reference' => 'A practical guide to the subscription buckets you will see in renewals and payments.',
            'Recording a shared manual payment' => 'How to split one manual payment across multiple targets without breaking references.',
            'Deactivation reason codes' => 'What each reason code communicates to reporting and future triage.',
            'Renewal pipeline vs Recently Expired vs Untracked Active' => 'How to tell these three queues apart so you work the right one.',
            default => 'A practical guide for the ' . strtolower($categoryName) . ' workflow in Exotic CRM.',
        };
    }

    private function articleBody(string $title, string $categoryName): string
    {
        return match ($title) {
            'Welcome to Exotic CRM' => <<<'MD'
# Welcome to Exotic CRM

## What this workspace is actually for
Exotic CRM is where sales, support, and operations turn messy profile, subscription, and payment activity into safe next actions.

Use it to:

- find work that needs attention
- keep visibility on provider/client records across markets
- create or activate subscriptions from real payment evidence
- help users regain access, complete onboarding, and move forward without guesswork
- leave the next agent enough context to continue cleanly

If you rush to "fix" a record before you understand the current state, you usually create a second problem for the next agent.

## The first-minute checklist for sales and customer service
Before you click update, send a message, or change subscription state:

1. Check the market scope and date window.
2. Read any warning banner, badge, or conflict label.
3. Open the record behind the metric instead of guessing from the summary card.
4. Decide whether you are looking at a sales follow-up, a support issue, a subscription task, or a data-repair case.

## What good CRM discipline looks like
- Keep profile visibility, payment status, and subscription status aligned.
- Treat market scope as part of the truth, not as a cosmetic filter.
- Use notes, access tools, and follow-up actions so the next department does not restart the same case from zero.

## Three habits that prevent expensive mistakes
- Treat WordPress state and CRM state as related, but not automatically identical.
- Do not assume a completed payment already created the right subscription outcome.
- Do not overwrite odd-looking records until you understand why they look odd.

## When this knowledge base is worth opening
Use the FAQ when you need to answer:

- what this screen is for
- what a status means
- what action normally comes next
- when to escalate instead of forcing a fix

If the article does not help you make the next safe decision on a live case, report the gap. That is the standard this section should meet.
MD,
            'Glossary: WP State Conflict, Unified Non-Lapsed, Forever plan, and FX normalization' => <<<'MD'
# CRM glossary for the terms people ask about most

## WP State Conflict
Use this label when the WordPress profile state and the CRM subscription picture do not line up cleanly.

Typical examples:

- the profile is publicly active but CRM cannot find a matching active deal
- the CRM thinks a client should be active but WordPress is not in the expected state

This is a review signal, not automatic permission to edit either system.

## Unified Non-Lapsed
This is a management cohort used to group profiles that are still commercially "in play" even when their underlying path differs.

Think of it as:

- active
- effectively active
- not currently in a simple lapsed bucket

Read the underlying records before messaging a client from this cohort.

## Forever plan
This usually means a profile does not behave like a normal expiring subscription row.

Operationally:

- do not treat it like a standard monthly expiry
- confirm why it exists before deactivating or "correcting" it

## FX normalization
This is the reporting layer that converts values so cross-market comparisons are usable.

Important rule:

- **native currency stays authoritative for operations**
- **normalized currency helps comparison and management reporting**

Do not use normalized values to change what a client actually paid.
MD,
            'Reading retention bands' => <<<'MD'
# Reading retention bands

## What the band is telling you
Retention bands help you judge how durable or fragile a client relationship currently looks.

They are best used for:

- prioritizing follow-up
- spotting risk early
- deciding where to spend agent time

## What the band is not
It is not a final truth about the client.

Do not make a high-impact decision from the band alone. Always pair it with:

- recent payment history
- current subscription state
- profile status
- recent activity or engagement

## Practical reading guide
- **Strong / healthy band:** maintain service quality, do not assume upsell urgency.
- **Mixed / watch band:** review recent signals and look for friction before it becomes churn.
- **Weak / risk band:** check whether the issue is payment, inactivity, profile quality, or a data mismatch.

## Best habit
When you open a client because of retention risk, leave a note or complete the next action immediately. Retention insight is only useful if it changes workload.
MD,
            'How View As / impersonation works' => <<<'MD'
# How View As / impersonation works

## Why this exists
Admins use impersonation to see the CRM from another user's scope and permissions.

This is useful for:

- reproducing a reported issue
- checking what a sales or marketing user can actually see
- validating whether a route, filter, or action is blocked by role

## What changes in impersonation
- visible markets may change
- available actions may change
- some navigation items may disappear

## What does not change
You are still responsible for noticing that you are impersonating.

Before changing data, confirm:

1. whose scope you are seeing
2. whether you intend to act as that role
3. whether the action should wait until you return to admin context

## Safe habit
If you are just inspecting, do not mutate records. Reproduce the issue, note what you found, and return to admin once the check is complete.
MD,
            'How to read the dashboard banner' => <<<'MD'
# How to read the dashboard banner

## What the banner is for
The banner is an orientation signal. It tells you when you are **not operating from your normal context**.

Pay attention to it when:

- you are impersonating another user
- the scope feels narrower or broader than expected
- actions or numbers suddenly look "wrong"

## Why agents get confused
Most dashboard mistakes happen because someone trusts the numbers before checking the operating context.

If the banner is visible:

1. read who you are viewing as
2. confirm the role shown in the banner
3. remember that permissions and visible workload may differ from your own

## Good rule
Never debug a missing button, empty queue, or access complaint until you have checked the banner.
MD,
            'Customer Service Center anatomy' => <<<'MD'
# Customer Service Center anatomy

## What the dashboard should answer in under a minute
The dashboard is not there to look impressive. It should help an operator answer:

1. what needs attention now
2. what needs follow-up today
3. where volume or risk is accumulating

## How strong agents scan it
1. Start with the cards that signal unresolved work, not vanity totals.
2. Click into the queue behind the number.
3. Confirm scope and filters again once the list opens.
4. Work the rows, then return for the next queue.

## What this page is good for
Use the dashboard for:

- queue prioritization
- trend awareness
- spotting market-specific pressure

It is **not** the right place to make detailed client decisions from the summary alone.

## The mistake to avoid
Do not announce a "system problem" just because a number looks strange. Many dashboard surprises come from:

- a carried-over market scope
- a short date window
- a queue that has not been worked yet
- a card being read without opening the underlying rows
MD,
            'Market scope and time window' => <<<'MD'
# Market scope and time window

## Why agents get this wrong
Bad scope creates confident but wrong decisions.

Before trusting a metric, list, or queue, confirm:

- which market you are looking at
- whether the view is single-market or blended
- what date window the page is using

## The failure modes to watch for
- Comparing one market to all markets without realizing it.
- Treating a short date window like a full performance trend.
- Forgetting that a queue may be filtered to one market from a previous session.

## The safe routine
1. Read the market selector.
2. Read the active date range.
3. Re-check both after you click a card or apply a filter.

## Operating rule
Use narrow scope when you are taking action.
Use broader scope when you are explaining trend or performance.
Do not mix those two conversations.
MD,
            'Reading the metric cards' => <<<'MD'
# Reading the metric cards

## Treat cards as queue shortcuts
Each card is a compressed answer to: "How much work of this kind exists right now?"

The right workflow is:

1. read the label
2. read the supporting line
3. click through into the underlying segment

## Do not stop at the number
A large number can mean:

- genuine workload growth
- a temporary queue buildup
- a scope change
- delayed resolution from earlier periods

## What good usage looks like
- Use cards to enter work.
- Use the filtered result set to make decisions.
- Come back to the dashboard when you need the next queue.

## Bad usage
- quoting a card without checking scope
- making client-level decisions from a dashboard total
- assuming every card is equally urgent
MD,
            'Recovery queue and renewal workload' => <<<'MD'
# Recovery queue and renewal workload

## Do not treat these as the same queue
They may sit near each other in the CRM, but they represent different urgency and different operator posture.

## Recovery queue
Use this when something needs rescue now, for example:

- a payment is stuck
- a renewal should have happened but did not
- a profile is active-looking but commercially uncertain

## Renewal workload
Use this for predictable subscription follow-up:

- upcoming expiries
- expected reminders
- orderly renewal outreach

## The triage rule
- If revenue or access is already at risk, start with recovery.
- If the subscription is still on a normal path, work the renewal queue.

## What good handling looks like
- Recovery work is careful, evidence-led, and record-specific.
- Renewal work can be more systematic, but still needs the right scope and segment.

## What to avoid
Do not work recovery items with a campaign mindset. Recovery usually needs proof, not optimism.
MD,
            'Adding a client: CRM only vs WordPress provision' => <<<'MD'
# Adding a client: CRM only vs WordPress provision

## Start with the right onboarding mode
The Add Client modal supports two different jobs:

- **CRM only:** create a record for outreach, notes, deals, and tracking without provisioning WordPress access yet
- **Provision in WordPress:** create the CRM record and the live WordPress account together

Choosing the wrong mode creates cleanup work later.

## Use CRM only when
- the profile is still being qualified
- sales or support needs a record before account setup is ready
- you need to track communication, notes, or a pending payment first
- the user is not ready to receive live account access

## Use WordPress provision when
- the account should be usable now
- the client needs setup/login access immediately
- you have at least one valid contact path such as email or phone

## Required thinking before you save
1. Pick the correct market first.
2. Decide whether the profile should start as inactive, active, draft, or pending.
3. Add the owner if the case already belongs to a real agent.
4. Check whether a lead or client record already exists before creating another one.

## Safe sequence
1. Open `Clients` and click `Add Client`.
2. Select the market.
3. Choose `CRM only` or `Provision in WordPress`.
4. Enter name, phone, email, city, and the starting status.
5. Save the record.
6. Open the client detail immediately and decide the next operational step: access, payment link, note, or subscription.

## Do not do this
- Do not provision WordPress access with no reachable email or phone.
- Do not mark a profile active just because the record exists.
- Do not create a new client if the case should still live as a lead.
MD,
            'Editing a client safely' => <<<'MD'
# Editing a client safely

## Why this needs discipline
Editing a client is not just changing text on a screen. Some fields affect:

- WordPress profile state
- sales ownership
- support follow-up
- subscription interpretation

## Edit in the right place
- Use the client detail page for record-specific changes.
- Use the correct tab instead of forcing everything through one screen.
- Use `Sync from WP` only when the problem is stale WordPress data, not when the underlying business state is unclear.

## Before you change anything important
Check:

1. market
2. current profile status
3. recent payment or subscription activity
4. whether another agent already left context in notes or timeline

## Safe habits
- Make the smallest correct change.
- Re-check the overview and relevant tab after saving.
- Leave a note if the reason for the edit would not be obvious to the next person.

## Escalate instead of editing blindly when
- WordPress and CRM disagree on active state
- the client has an ambiguous payment trail
- the edit would change public visibility without enough evidence
MD,
            'Search modes: exact, exact_missing, and fallback' => <<<'MD'
# Search modes: exact, exact_missing, and fallback

## Exact
Use **exact** when you have a precise identifier and want a high-confidence result.

Best for:

- exact phone
- exact email
- exact known name or URL

## Exact missing
Use **exact_missing** when you suspect the main exact field may be incomplete and you still want a disciplined lookup path.

This helps when records are partially populated or imported oddly.

## Fallback
Use **fallback** when you need broader matching and are willing to inspect candidates.

Best for:

- incomplete names
- messy reference details
- uncertain spelling

## Practical rule
Start as strict as possible. Broaden only when strict search fails. Broad search first is how agents open the wrong client and create avoidable mistakes.
MD,
            'Combining filters to build cohorts' => <<<'MD'
# Combining filters to build cohorts

## Build filters in layers
The fastest way to get a useful client list is:

1. set the market
2. set the status or operational state
3. add retention or behavior signals
4. add date, owner, or risk refinement

## Good examples
- clients in manual review for one market
- high-risk clients with no recent positive signals
- profiles in a weak retention band that still have payment potential

## Bad habit
Throwing every filter on at once makes it hard to tell which condition is shrinking the list.

## Better habit
Add filters one by one and watch the list change. That tells you whether the cohort is:

- truly small
- badly scoped
- narrowed by the wrong condition
MD,
            'Every client filter explained' => <<<'MD'
# Every client filter explained

## Read filters as workload definitions
Filters are not cosmetic. They define **which clients you are about to act on**.

## The filters to understand first
- **Market:** controls which operational universe you are in.
- **Status:** narrows by current profile or workflow state.
- **Owner / assigned agent:** shows whose queue the work belongs to.
- **Risk / behavior / retention:** helps prioritize attention.
- **Date filters:** useful for trend windows, not just raw counts.

## When a result set looks wrong
Check in this order:
1. market
2. status
3. date range
4. search term
5. hidden advanced filters

## Good rule
If you cannot explain why a client is in the result set, do not act yet. Re-read the active filters first.
MD,
            'The metric-card-as-segment pattern' => <<<'MD'
# The metric-card-as-segment pattern

## What this pattern means
Many cards in CRM are clickable because they represent a live segment, not a static KPI.

When you click a card, you are usually asking:

- show me the rows behind this number
- let me work the queue that produced this total

## Why this matters
Agents often read the card and stop there. That leaves the most useful part unused.

## Right workflow
1. Click the card.
2. Review the filtered list it opens.
3. Work the rows from that segment.
4. Return to the top-level page when you need the next segment.

## Important
Do not compare card values and row values unless you are sure the same scope and filters are still active.
MD,
            'Client status taxonomy' => <<<'MD'
# Client status taxonomy

## Read status as operational meaning
Statuses should tell you what state the profile is in and what kind of follow-up is appropriate.

## Typical reading guide
- **publish:** profile is publicly active.
- **private / draft / pending:** visibility or readiness is constrained.
- **awaiting_payment:** commercial path exists, but money is not confirmed.
- **manual_review / conflict-type states:** do not automate judgment; inspect the record.

## What not to do
Do not assume a public profile always means a clean subscription picture. Some statuses need to be read together with payment and deal state.

## Good habit
Whenever you see a status that implies friction, open:

- the client detail
- the latest payment
- the relevant deal or renewal row

Status becomes useful when it leads you to the next record, not when it replaces the next record.
MD,
            'Resolving WP State Conflict' => <<<'MD'
# Resolving WP State Conflict

## What the conflict usually means
Something about WordPress visibility/state and CRM subscription reality does not line up.

## Review before you change anything
Check:

1. latest deal status
2. latest successful or failed payment history
3. current WordPress-linked profile state
4. whether this is a known legacy or special-case record

## Safe approach
- confirm the mismatch
- identify which system is stale
- choose the smallest correction path

## Unsafe approach
- forcing CRM to match WordPress immediately
- forcing WordPress to match CRM immediately
- deactivating or reactivating without understanding the payment trail

## Escalate when
Escalate if the client is commercially active, the payment history is ambiguous, or the record looks duplicated.
MD,
            'Retention bands and behavior tags' => <<<'MD'
# Retention bands and behavior tags

## Use them together
Retention tells you how stable the relationship looks. Behavior tags tell you what pattern may be driving that stability or risk.

## Example reading
- weak retention + low engagement: likely follow-up risk
- mixed retention + recent payment activity: investigate before escalating
- strong retention + dormant workflow tags: maintenance, not panic

## Best use
These signals are useful for:

- prioritizing outreach
- deciding which clients need manual review
- segmenting queues for follow-up

## Avoid this trap
Do not turn one weak tag into a hard narrative. The tags are hints, not verdicts.
MD,
            'Smart delete rules of thumb' => <<<'MD'
# Smart delete rules of thumb

## Use Smart Delete carefully
This action is for cleaning truly stale or low-value records, not for hiding confusing ones.

## Safer candidates
- inactive for a long time
- no meaningful engagement
- no subscription value to preserve
- no important linked history that another agent still needs

## Stop and review first if
- the client has active or recent payment history
- the profile is tied to an unresolved commercial question
- the record is high risk or conflict flagged

## Rule
If the record is annoying but still operationally relevant, **do not delete it**. Clean data is good. Missing evidence is worse.
MD,
            'When to create a lead instead of a client' => <<<'MD'
# When to create a lead instead of a client

## Lead and client are not interchangeable
Use the right record type from day one.

- **Lead:** a prospect still being qualified, followed up, or reconciled
- **Client:** an operational profile/account you intend to manage in the live CRM flow

## Create a lead when
- sales is still qualifying intent
- the person came in through conversation or outbound follow-up
- you are not ready to provision a live profile/account
- the next action is ownership, follow-up, or conversion, not profile management

## Create a client when
- support or sales is managing a real provider profile
- onboarding has started
- you need subscriptions, access tools, or profile-state workflows
- the case belongs in live operational support, not prospect tracking

## Why this matters
If you create a client too early:

- the clients workspace becomes noisy
- duplicate records become more likely
- support inherits a record that is not really operational yet

## Quick rule
If the next action is mostly qualification, keep it as a lead.
If the next action is mostly account/profile management, create or convert to client.
MD,
            'Converting a lead to a client' => <<<'MD'
# Converting a lead to a client

## Convert when the case becomes operational
Conversion is the point where a prospect stops being just pipeline and starts being an active onboarding or support case.

## Before converting
Check:

1. market is correct
2. phone and email are usable
3. there is not already a matching client record
4. the assigned owner and latest notes tell the same story

## What a good conversion preserves
- sales context
- ownership
- contact details
- auditability of why the move happened

## After converting
Open the new client record and decide the next concrete action:

- send access
- create a payment link
- start a subscription path
- leave an onboarding note

## Do not convert just to tidy the pipeline
Convert because the workflow genuinely changed, not because the lead list feels messy.
MD,
            'Assigning and archiving leads with discipline' => <<<'MD'
# Assigning and archiving leads with discipline

## Assignment is a promise
When you assign a lead, you are saying who owns the next action.

That means:

- the owner should be real, not placeholder
- the reason should make sense
- reassignment should happen for operational need, not convenience

## Archive when the lead is no longer active pipeline work
Archive is appropriate when:

- the lead is no longer actionable
- follow-up is complete and there is no live opportunity
- the record is being closed out with a clear reason

## Do not archive to hide weak follow-up
If the lead still needs a call, message, or decision, it should usually stay active with an owner.

## Best habit
Whenever you assign or archive, write the reason as if another agent will read it tomorrow and ask, "Would I understand what happened here?"
MD,
            'Tour of the client tabs' => <<<'MD'
# Tour of the client tabs

## Why the tabs matter
The client page is where you switch from queue thinking to record thinking.

## Use the tabs like this
- **overview / profile area:** quick state, badges, and context
- **subscriptions / deals:** commercial history and next commercial action
- **payments:** payment trail, confirmation state, and revenue clues
- **notes / activity / support areas:** what other agents already did
- **health / analytics areas:** risk, completeness, or quality checks

## Good habit
Do not stay in one tab too long. Good client review usually means moving between:

1. profile state
2. latest subscription
3. latest payment
4. notes or timeline

That is how you avoid acting on half the picture.
MD,
            'Client access: setup links, passwords, and login as client' => <<<'MD'
# Client access: setup links, passwords, and login as client

## What Client access is for
This drawer exists to help a user get into their account without leaving a weak security trail behind.

## The recommended default
Use:

- **Setup link**
- **Email + SMS**
- **Send now**

That is the safest normal onboarding path.

## What each quick action is for
- **Open profile:** confirm what public page the user should reach
- **Log in as client:** reproduce an account-side issue without asking the client to screen-record everything
- **Reset & copy credentials:** controlled fallback when setup-link flow is not enough

## Safe workflow
1. Confirm you are on the correct client record.
2. Open `Client access`.
3. Check the WordPress username, login URL, and setup link availability.
4. Send setup access first unless there is a strong reason to use a temporary password.
5. Log the reason clearly.

## Use temporary passwords carefully
Temporary passwords are a fallback, not the first choice.

If you use one:

- share it through the intended channel
- tell the user to reset it after first login
- do not leave the password sitting in chat notes or loose screenshots

## Use "log in as client" for diagnosis, not browsing
Open a client session when you need to reproduce:

- edit-profile issues
- access complaints
- account-state confusion

Do not make silent changes in the client session without noting why.
MD,
            'Sending a payment link from client detail' => <<<'MD'
# Sending a payment link from client detail

## What this action is for
Use the `Payment link` button when the next safe step is to collect money through a controlled payment flow.

## Confirm these four things first
1. correct client
2. correct market
3. whether this is a new sale, renewal, or recovery case
4. what should happen after payment succeeds

## Why this matters
The payment link is not just a message. It creates expectation for:

- the client
- support
- sales follow-up
- the subscription path that should happen next

## Safe sequence
1. Open the client detail page.
2. Click `Payment link`.
3. Review the intended amount and context.
4. Send or prepare the link.
5. Leave enough context so another agent knows why it was sent.

## Important rule
A sent payment link is **not** the same as a confirmed payment.
Do not activate a subscription or declare the case complete until the payment evidence is real.
MD,
            'Activating a subscription after payment review' => <<<'MD'
# Activating a subscription after payment review

## Activation should follow evidence
New subscriptions remain pending until someone verifies that the payment and subscription story match.

## Before you activate
Check:

1. the payment belongs to this client
2. the market and plan make sense
3. there is no duplicate or competing subscription path
4. the activation will not create a WordPress/CRM state conflict

## What good activation looks like
- the payment record is real
- the subscription dates and plan are intentional
- the next agent can understand why activation happened
- public profile state will not surprise support five minutes later

## Common mistake
Do not activate because the payment "probably" belongs here.
Activation is where uncertainty becomes live state. Treat it carefully.

## If the payment picture is weak
Do one of these instead:

- hold the subscription in pending
- reconcile the payment first
- escalate for review
MD,
            'Sync from WordPress: when it works and when it does not' => <<<'MD'
# Sync from WordPress: when it works and when it does not

## What sync is good for
Sync is useful when CRM needs a fresh pull of known WordPress-side data.

Typical wins:

- refreshed profile fields
- updated linkage details
- corrected missing or stale metadata

## What sync is not
Sync is not a magic repair button for every mismatch.

It will not safely solve:

- ambiguous payment history
- duplicate commercial paths
- unclear deactivation logic

## Before retrying sync
Check whether the issue is really:

- a data freshness problem
- a linkage problem
- a business-state problem

If it is the third one, more sync attempts usually waste time.
MD,
            'Generating a payment link' => <<<'MD'
# Generating a payment link

## Before you send the link
Confirm:

1. the correct client record
2. the correct market
3. the intended subscription or payment purpose
4. whether the link is for a new sale, renewal, or recovery case

## Why this matters
The wrong link creates downstream confusion:

- payment lands against the wrong expectation
- another agent sees an unclear queue state
- the client receives a message that does not match the case

## Practical habit
After generating the link, record enough context so another agent knows:

- why it was sent
- what it is meant to pay for
- what should happen after payment confirmation
MD,
            'Adding a tour' => <<<'MD'
# Adding a tour

## What this action is for
Use Add Tour to log the visit or tour activity in a way the next agent can trust.

## Good entry quality
A useful tour entry tells the next person:

- what happened
- when it happened
- what the outcome was
- whether follow-up is still needed

## Avoid weak entries
Avoid notes like:

- "tour done"
- "visited"
- "handled"

They add history without adding meaning.

## Better pattern
If a tour changes the commercial or service picture, pair the entry with the next operational step instead of leaving the record half-updated.
MD,
            'Marking a client as verified' => <<<'MD'
# Marking a client as verified

## What verified should mean
Verified should communicate that a key trust check has been satisfied, not simply that someone looked at the profile.

## Before applying it
Confirm the verification standard your team actually uses, for example:

- identity or legitimacy checks passed
- profile details are sufficiently confirmed
- there is no unresolved contradiction blocking that label

## Why caution matters
Other agents may treat verified as permission to move faster. Do not apply it casually.

## Good habit
If you change verification state, leave enough context in related notes or follow-up actions so the next person knows why.
MD,
            'NEW badge modes' => <<<'MD'
# NEW badge modes

## What the badge is trying to signal
The NEW badge helps distinguish genuinely new profiles from records that should no longer be treated as fresh.

## How to think about the modes
- **automatic behavior:** the system decides from state and timing
- **forced on:** you deliberately keep NEW visible
- **forced off:** you deliberately suppress NEW

## When an override is justified
Use an override when the automatic state would clearly mislead operators.

## When it is not justified
Do not use it as cosmetic cleanup. If the record feels wrong, first ask whether the underlying state is wrong.
MD,
            'Creating a new subscription' => <<<'MD'
# Creating a new subscription

## Confirm this is truly new
Before you create a new subscription, decide whether the case is:

- a true new commercial path
- a renewal
- a recovery of an already expected payment path

## Why this matters
Using "new subscription" for the wrong case distorts reporting and confuses the next agent reading the history.

## Safe sequence
1. confirm client and market
2. review recent deal and payment history
3. choose the right lifecycle
4. create the pending path cleanly

## Watch-out
If you had to tell yourself "this is basically a renewal," it probably should not be created as new without a clear reason.
MD,
            'Deactivating a subscription with the right reason' => <<<'MD'
# Deactivating a subscription with the right reason

## The reason code matters
Deactivation reason is not paperwork. It affects:

- future reporting
- risk interpretation
- how another agent reads the case

## Before deactivating
Check:

1. whether the profile is truly meant to be deactivated
2. whether the latest payment trail supports that action
3. whether a different correction path is safer

## Pick the reason honestly
Do not choose the easiest label. Choose the one that best explains what actually happened.

## Escalate when
Escalate if the payment picture is unclear, the profile is still active in WordPress, or the client may have been affected by a system mismatch rather than a genuine business outcome.
MD,
            'Wallet adjustments' => <<<'MD'
# Wallet adjustments

## Treat wallet changes as money changes
Any adjustment should be approached with the same seriousness as a payment correction.

## Before adjusting
Confirm:

- why the adjustment is needed
- what evidence supports it
- whether the issue is really wallet-related or a different state problem

## Good adjustment discipline
- change the smallest correct amount
- use the proper reason
- leave an audit trail another agent can understand later

## Bad adjustment discipline
- rounding casually
- fixing a reporting issue with a wallet adjustment
- changing balance because a linked record "looks off"
MD,
            'Profile Health and what it surfaces' => <<<'MD'
# Profile Health and what it surfaces

## What Profile Health is for
Profile Health is an early warning area. It helps you spot:

- missing or weak profile data
- inconsistencies across systems
- operational follow-up items that should not stay hidden

## Best use
Use it as a review aid, not as an automatic truth engine.

## What to do when something is flagged
1. read the flag
2. inspect the underlying record or linked area
3. resolve the actual issue, not just the symptom

## Why this matters
Healthy profiles are easier to sell, support, and renew. A health flag is usually a sign that some future queue pain can be prevented now.
MD,
            'Flat vs Native and the FX banner' => <<<'MD'
# Flat vs Native and the FX banner

## Native
Native values reflect the currency the transaction actually happened in. Use native values for:

- payment operations
- wallet reasoning
- client-level financial truth

## Flat / normalized
Flat or normalized reporting helps compare performance across markets without mentally converting currencies yourself.

Use it for:

- multi-market management views
- cross-market comparisons
- summary reporting

## Rule to remember
**Operate in native. Compare in normalized.**

If you forget that rule, you will eventually explain the wrong amount to the wrong person.
MD,
            'The five payment metric cards' => <<<'MD'
# The five payment metric cards

## What they are for
These cards summarize the shape of the payment workspace so you can enter the right queue faster.

## How to use them
- read the card label
- click into the underlying rows
- work the queue, not the summary number

## Typical interpretation pattern
- cards showing unresolved or pending volume usually need operational attention
- cards showing confirmed volume are useful for monitoring but not always the first queue to work
- exception or review-style cards deserve extra caution before action

## Best habit
After clicking a card, re-check the filters and row state before confirming, matching, or creating subscriptions.
MD,
            'Customer revenue mix segments' => <<<'MD'
# Customer revenue mix segments

## What the mix is telling you
Revenue mix helps you understand where revenue is coming from and how it is distributed across the customer base.

## Why it matters operationally
The mix can reveal:

- concentration risk
- overdependence on one segment
- changes in the quality of incoming revenue

## How to use it well
Use it to ask better questions, not to make blind conclusions.

Examples:

- Is one segment carrying too much of the period?
- Is the mix changing because of healthy growth or because other segments are weakening?
- Which queue would explain the shift?
MD,
            'Auto-match queue triage' => <<<'MD'
# Auto-match queue triage

## What auto-match is trying to do
Auto-match suggests likely relationships between payments and clients so you do not have to start from zero on every row.

## The agent's job
Your job is not to trust the suggestion blindly. Your job is to review whether it is commercially plausible.

## Check before confirming
- client identity clues
- amount logic
- market consistency
- timing consistency
- whether a different payment or client is a better fit

## Safe rule
When in doubt, leave it for review rather than forcing a match that creates a false subscription history.
MD,
            'Reconciling untracked payments' => <<<'MD'
# Reconciling untracked payments

## What an untracked payment means
The money signal exists, but the clean subscription or matching story does not.

## Review order
1. confirm the payment is real
2. confirm the likely client
3. review whether a subscription should already exist
4. decide whether the fix is a match, a subscription action, or escalation

## What to avoid
- creating history you cannot support
- attaching the payment to the first plausible client
- assuming "completed" always means "fully resolved"

## Good outcome
A good reconciliation leaves the record easier for the next agent to trust, not harder.
MD,
            'Importing payments from CSV' => <<<'MD'
# Importing payments from CSV

## Before you import
Clean imports begin before upload.

Check:

- file format
- field consistency
- duplicate risk
- whether the rows belong to the intended market and workflow

## Use the preview
The preview is where you catch:

- malformed rows
- duplicate references
- obvious candidate mismatches

## Good rule
If the preview is confusing, do not commit yet. Fix the file or reduce the batch until the result is explainable.

## Why this matters
Bulk mistakes are faster than manual mistakes. That is exactly why imports need more discipline, not less.
MD,
            'Deal buckets reference' => <<<'MD'
# Deal buckets reference

## Why buckets exist
Buckets group subscriptions by commercial state so the team can work the right queue instead of one giant list.

## Read the bucket as a workload label
Examples of bucket meaning:

- needs attention now
- still in normal renewal flow
- already expired
- commercially ambiguous and needs review

## Good usage
Move from:

1. bucket
2. filtered result set
3. record-level decision

## Bad usage
Treating all rows in a bucket as identical. Buckets are a starting point, not a substitute for review.
MD,
            'Recording a shared manual payment' => <<<'MD'
# Recording a shared manual payment

## What this is for
Use this when one real payment needs to be allocated across more than one target in a controlled way.

## Why caution matters
Shared manual payments are easy to damage if:

- the reference root is inconsistent
- the total is split badly
- the wrong market or targets are included

## Safe workflow
1. confirm the market
2. confirm the shared payment really belongs to all selected targets
3. preview the bundle
4. only commit once the allocation story makes sense end to end

## Important
Do not improvise reference roots. Shared-payment history must stay traceable later.
MD,
            'Deactivation reason codes' => <<<'MD'
# Deactivation reason codes

## Why the codes matter
These codes are how the business later understands why access ended.

They influence:

- churn interpretation
- fraud or invalid-reference handling
- what follow-up is still appropriate

## Good practice
Choose the code that best describes the real cause, not the fastest button to click.

## Before using a severe code
If the code implies fraud, reversal, or invalid commercial behavior, make sure the evidence supports it. Those labels shape future handling.
MD,
            'Renewal pipeline vs Recently Expired vs Untracked Active' => <<<'MD'
# Renewal pipeline vs Recently Expired vs Untracked Active

## Renewal pipeline
These are clients still on a normal commercial path. Work them proactively.

## Recently Expired
These clients are already on the wrong side of the line. Work them with recovery urgency, not with routine renewal assumptions.

## Untracked Active
These rows look operationally active but do not have a clean tracked subscription picture. They need investigation, not autopilot.

## Quick distinction
- **pipeline:** prepare and renew
- **recently expired:** recover
- **untracked active:** investigate before acting

Confusing these three queues leads to the wrong messaging and the wrong data fixes.
MD,
            default => <<<MD
# {$title}

## Why this matters
This article explains a high-frequency {$categoryName} workflow so agents can move quickly without guessing what the CRM is trying to tell them.

## What to check first
- Confirm the market scope, date range, and any active filters.
- Read badges and warning banners before changing a state.
- Prefer additive actions first: inspect, verify, then update.

## Common working pattern
1. Open the related CRM screen from the CTA.
2. Reproduce the state using the same filters or row context.
3. Confirm whether you are fixing data, triaging a queue item, or communicating with a client.
4. Save the smallest correct change and re-check the result.

## When to escalate
Escalate if the state conflicts with WordPress, a payment record looks duplicated, or the action would change live subscription status without enough evidence.
MD,
        };
    }
}
