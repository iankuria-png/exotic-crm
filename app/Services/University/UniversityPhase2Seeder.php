<?php

namespace App\Services\University;

use App\Models\University\Badge;
use App\Models\University\Certification;
use App\Models\University\Course;
use App\Models\University\DailyDrill;
use App\Models\University\GlossaryTerm;
use App\Models\University\Module;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 2 deep-content seeder. Idempotent: rewrites bodies/questions/glossary/badges
 * every time it runs, so admins can re-seed without duplicating rows. Lesson bodies
 * use a markdown-with-directive syntax (`:::script`, `:::scenario`, `:::objection`,
 * `:::warning`, `:::tip`, `:::checklist`, `:::quickref`) that the React reader
 * parses into styled callout blocks.
 */
class UniversityPhase2Seeder
{
    public function run(): array
    {
        return DB::transaction(function () {
            $stats = [
                'courses' => 0,
                'modules' => 0,
                'lessons' => 0,
                'questions' => 0,
                'glossary' => 0,
                'badges' => 0,
                'drills' => 0,
            ];

            foreach ($this->courses() as $courseSpec) {
                $course = $this->upsertCourse($courseSpec, $stats);
                $this->upsertModulesAndLessons($course, $courseSpec['modules'], $stats);
            }

            $this->upsertCertification($stats);
            $this->upsertGlossary($stats);
            $this->upsertBadges($stats);
            $this->upsertDailyDrills($stats);

            return $stats;
        });
    }

    private function upsertCourse(array $spec, array &$stats): Course
    {
        $course = Course::firstOrNew(['slug' => $spec['slug']]);
        $course->fill([
            'title' => $spec['title'],
            'summary' => $spec['summary'],
            'difficulty' => $spec['difficulty'],
            'learning_outcomes' => $spec['learning_outcomes'],
            'instructor_name' => $spec['instructor_name'] ?? 'Exotic Online Sales Faculty',
            'accent_color' => $spec['accent_color'] ?? 'teal',
            'estimated_minutes' => array_sum(array_map(fn ($m) => array_sum(array_map(fn ($l) => $l['duration'] ?? 12, $m['lessons'])), $spec['modules'])),
            'status' => 'published',
            'visibility' => $spec['visibility'] ?? 'all',
            'required_for_roles' => $spec['required_for_roles'] ?? ['sales', 'sub_admin'],
            'order' => $spec['order'],
            'published_at' => $course->published_at ?: now(),
        ]);

        if (! $course->exists) {
            $stats['courses']++;
        }
        $course->save();

        return $course;
    }

    private function upsertModulesAndLessons(Course $course, array $modules, array &$stats): void
    {
        foreach ($modules as $moduleIndex => $moduleSpec) {
            $module = Module::firstOrNew([
                'course_id' => $course->id,
                'slug' => $moduleSpec['slug'],
            ]);
            $isNewModule = ! $module->exists;
            $module->fill([
                'title' => $moduleSpec['title'],
                'summary' => $moduleSpec['summary'] ?? null,
                'order' => $moduleIndex + 1,
            ])->save();
            if ($isNewModule) {
                $stats['modules']++;
            }

            foreach ($moduleSpec['lessons'] as $lessonIndex => $lessonSpec) {
                $lesson = $module->lessons()->firstOrNew(['slug' => $lessonSpec['slug']]);
                $isNewLesson = ! $lesson->exists;
                $lesson->fill([
                    'title' => $lessonSpec['title'],
                    'subtitle' => $lessonSpec['subtitle'] ?? null,
                    'body' => $lessonSpec['body'],
                    'body_draft' => $lessonSpec['body'],
                    'playbook_url' => $lessonSpec['playbook_url'] ?? null,
                    'quick_reference' => $lessonSpec['quick_reference'] ?? null,
                    'kind' => $lessonSpec['kind'] ?? 'lesson',
                    'duration_minutes' => $lessonSpec['duration'] ?? 12,
                    'order' => $lessonIndex + 1,
                    'status' => 'published',
                ])->save();
                if ($isNewLesson) {
                    $stats['lessons']++;
                }
            }
        }
    }

    private function upsertCertification(array &$stats): void
    {
        $course = Course::where('slug', 'sales-fundamentals')->first();
        if (! $course) {
            return;
        }

        $certification = Certification::firstOrNew(['slug' => 'core-sales-cs-certification']);
        $certification->fill([
            'course_id' => $course->id,
            'title' => 'Core Sales/CS Certification',
            'description' => 'Scenario-grounded certification covering discovery, package selection, failure recovery, and renewals. 25 questions, 80% to pass, 35 minutes, 3 attempts per 30 days, valid 12 months.',
            'pass_threshold' => 80,
            'time_limit_minutes' => 35,
            'question_count' => 25,
            'max_attempts_per_window' => 3,
            'attempt_window_days' => 30,
            'validity_months' => 12,
            'randomize_questions' => true,
            'randomize_options' => true,
            'show_explanations_on_fail' => true,
            'allow_review_before_submit' => true,
            'status' => 'published',
        ])->save();

        // Replace question bank with real content
        $certification->questions()->delete();
        foreach ($this->certQuestions() as $order => $q) {
            $question = $certification->questions()->create([
                'kind' => $q['kind'],
                'prompt' => $q['prompt'],
                'scenario_context' => $q['scenario'] ?? null,
                'explanation' => $q['explanation'],
                'topic_tag' => $q['topic'],
                'weight' => 1,
                'order' => $order + 1,
            ]);
            foreach ($q['options'] as $idx => $optionText) {
                $question->options()->create([
                    'text' => $optionText,
                    'is_correct' => $idx === $q['correct'],
                    'order' => $idx + 1,
                ]);
            }
            $stats['questions']++;
        }
    }

    private function upsertGlossary(array &$stats): void
    {
        foreach ($this->glossary() as $entry) {
            $slug = Str::slug($entry['term']);
            $term = GlossaryTerm::firstOrNew(['slug' => $slug]);
            $isNew = ! $term->exists;
            $term->fill([
                'term' => $entry['term'],
                'definition' => $entry['definition'],
                'aliases' => $entry['aliases'] ?? [],
                'topic_tag' => $entry['topic'] ?? null,
                'playbook_url' => $entry['playbook_url'] ?? null,
            ])->save();
            if ($isNew) {
                $stats['glossary']++;
            }
        }
    }

    private function upsertBadges(array &$stats): void
    {
        foreach ($this->badges() as $b) {
            $badge = Badge::firstOrNew(['code' => $b['code']]);
            $isNew = ! $badge->exists;
            $badge->fill([
                'title' => $b['title'],
                'description' => $b['description'],
                'icon' => $b['icon'],
                'color' => $b['color'],
                'criteria_kind' => $b['criteria_kind'],
                'criteria_config' => $b['criteria_config'] ?? [],
                'points' => $b['points'],
            ])->save();
            if ($isNew) {
                $stats['badges']++;
            }
        }
    }

    private function upsertDailyDrills(array &$stats): void
    {
        foreach ($this->dailyDrills() as $d) {
            $drill = DailyDrill::firstOrNew(['prompt' => $d['prompt']]);
            $isNew = ! $drill->exists;
            $drill->fill([
                'scenario_context' => $d['scenario'] ?? null,
                'explanation' => $d['explanation'],
                'options' => $d['options'],
                'correct_index' => $d['correct'],
                'topic_tag' => $d['topic'],
                'is_active' => true,
            ])->save();
            if ($isNew) {
                $stats['drills']++;
            }
        }
    }

    // ============================================================
    // CONTENT DEFINITIONS
    // ============================================================

    private function courses(): array
    {
        return [
            $this->courseEscalationTree(),
            $this->courseSalesFundamentals(),
            $this->courseProductMastery(),
            $this->courseObjectionHandling(),
            $this->courseFailureRecovery(),
        ];
    }

    private function courseEscalationTree(): array
    {
        return [
            'slug' => 'escalation-tree',
            'title' => 'Escalation Tree',
            'summary' => 'Who owns what when something breaks. The single most important map in the company — memorise it before you take your first call.',
            'difficulty' => 'beginner',
            'accent_color' => 'rose',
            'order' => 0,
            'learning_outcomes' => [
                'Identify the owner of any problem within 10 seconds',
                'Stop solo-rescues that should have been escalated',
                'Use the right escalation language so other teams take action quickly',
            ],
            'modules' => [
                [
                    'slug' => 'who-owns-what',
                    'title' => 'Who Owns What',
                    'summary' => 'The complete ownership matrix and the playbook for escalating each kind of problem.',
                    'lessons' => [$this->lessonEscalationTree()],
                ],
            ],
        ];
    }

    private function lessonEscalationTree(): array
    {
        return [
            'slug' => 'who-owns-what-guide',
            'title' => 'The Escalation Tree',
            'subtitle' => 'The single most important map in the company',
            'duration' => 9,
            'playbook_url' => 'https://exoticonline.mintlify.app/support/roles-permissions',
            'quick_reference' => "RULE OF THUMB:\n\nIf you're the agent on the call, you OWN getting it resolved — even if you don't own the FIX.\n\nEscalate when:\n- You don't know the owner.\n- You know the owner but they're unreachable.\n- The fix needs above-threshold authority (discount, refund, free trial).\n\nDON'T:\n- Promise something another team has to deliver without checking with them.\n- Sit on a problem because \"I'll figure it out\". 10 minutes is the limit.",
            'body' => <<<'MD'
## Why this matters

Most failed deals at Exotic Online are not failures of effort. They are **failures of routing**. An agent tries to solve a problem owned by another team, burns 40 minutes, and the customer walks away. The agent who knows the escalation tree resolves the same issue in 5 minutes by sending it to the right desk with the right framing.

This lesson is the single most important map in the company. Memorise it.

## The ownership matrix

:::escalation
Customer communication | Customer Service
Receipt confirmation | Head of Markets
Weekly payment review | Finance
CRM sync, callback, status issue | R&D / Product
Server, WordPress access, internet | IT
Policy approval (discounts above threshold, free trials, refunds) | Management
:::

## How to escalate well

:::checklist Always include these four things in an escalation
- [ ] **What happened** (specific, no opinion). "Customer paid Ksh 2,500 for Ksh 3,000 package via M-Pesa at 14:02."
- [ ] **What you've tried** (so the next person doesn't repeat your steps).
- [ ] **What you need** (action, not just attention). "Need Finance to confirm the payment is on the bank statement."
- [ ] **By when** (sets the urgency). "Customer is on hold — within 5 minutes if possible."
:::

## The "is this me?" decision tree

:::scenario A 30-second self-check before you escalate
1. **Is the problem about the conversation itself?** (tone, expectations, customer emotion) → That's CS. You probably handle it directly.
2. **Is the problem about a number on a receipt or statement?** → Finance, via Head of Markets.
3. **Is the problem about the CRM showing wrong data, missing webhooks, or a button not working?** → R&D / Product.
4. **Is the WordPress site or your computer not loading?** → IT.
5. **Does the fix require breaking the standard policy (a 40% discount, an overdue refund)?** → Management.

If none of the above, the default is: stay with it for 10 minutes, then escalate to your supervisor with the four-item checklist above.
:::

## When to NOT escalate

:::warning Things that look like escalations but aren't
- **Customer is upset.** Not an escalation by itself. CS-trained agents handle emotion in the call.
- **You don't know the answer to a product question.** Look it up first (Glossary, Playbook). Escalation is a last resort, not a first one.
- **The fix is slow but routine** (waiting for an STK Push to arrive, payment matching to complete). Don't escalate timing — manage expectations with the customer.
:::

## What "good" looks like

Three calls from real shifts, condensed:

:::scenario Good escalation
*Agent (in Slack to Finance):* "F8: customer Jane Mwangi paid via bank transfer Ksh 5,000 to account ending 4421 on 2026-05-12. Uploaded proof in CRM. Auto-match flagged amount mismatch — package is Ksh 4,500. Need verification this week so I can credit the Ksh 500 to wallet. Customer expecting confirmation by Friday."

*Finance:* (replies within an hour, confirms, agent processes wallet credit.)

**Why it worked:** Specific, dated, included next action, and respected Finance's review cadence (weekly).
:::

:::scenario Bad escalation
*Agent (in Slack to everyone):* "Hey can someone help, payment not going through, customer angry."

*Result:* Six people ask clarifying questions, 90 minutes pass, customer hangs up.

**Why it failed:** No specifics, no owner identified, no action requested. Escalation became a help-me-please broadcast.
:::

## Knowledge check

1. Who owns "receipt confirmation"?
2. The CRM is showing the wrong package tier after activation. Who owns the fix?
3. List the four things every good escalation contains.
MD,
        ];
    }

    private function courseSalesFundamentals(): array
    {
        return [
            'slug' => 'sales-fundamentals',
            'title' => 'Sales Fundamentals',
            'summary' => 'The Exotic Online sales operating playbook: how we qualify, sell, save, and renew. Five lessons grounded in real call scripts, the package matrix, and our most common objections.',
            'difficulty' => 'beginner',
            'accent_color' => 'teal',
            'order' => 1,
            'learning_outcomes' => [
                'Open a discovery call with a qualified opener and disqualifier questions',
                'Recommend the right package based on escort tier and market signals',
                'Recover stalled payments and unblock the customer in under 5 minutes',
                'Run a renewal call that surfaces churn risk before it becomes lapse',
            ],
            'modules' => [
                ['slug' => 'discover-stage-1', 'title' => 'Discover (Stage 1)', 'summary' => 'Qualify lead intent, market fit, and urgency.', 'lessons' => [$this->lessonDiscover()]],
                ['slug' => 'subscription-selection-stage-5a', 'title' => 'Subscription Selection (Stage 5a)', 'summary' => 'Match customer goals to the right package.', 'lessons' => [$this->lessonSubscriptionSelection()]],
                ['slug' => 'failures-and-recovery-stage-5e', 'title' => 'Failures & Recovery (Stage 5e)', 'summary' => 'Recover stalled payments, mismatches, and objections.', 'lessons' => [$this->lessonFailureOverview()]],
                ['slug' => 'renewals-stage-7', 'title' => 'Renewals (Stage 7)', 'summary' => 'Keep active clients renewing before expiry.', 'lessons' => [$this->lessonRenewals()]],
                ['slug' => 'product-overview', 'title' => 'Product Overview', 'summary' => 'Explain what Exotic Online sells and how success is measured.', 'lessons' => [$this->lessonProductOverview()]],
            ],
        ];
    }

    private function lessonDiscover(): array
    {
        return [
            'slug' => 'discover-stage-1-guide',
            'title' => 'Discover: Qualify the Lead',
            'subtitle' => 'Stage 1 of the Exotic Online customer lifecycle',
            'duration' => 14,
            'playbook_url' => 'https://exoticonline.mintlify.app/product/stage-1-discover',
            'quick_reference' => "Opener line: \"Hi, I'm [Name] from Exotic Online — Africa's leading adult advertising platform. Do you have 2 minutes?\"\n\nMUST-ASK qualifying questions:\n1. City + country?\n2. Currently listing anywhere else?\n3. Looking for new clients now or just exploring?\n\nDISQUALIFY immediately if: under 18, refuses verification, asks for guaranteed bookings.\n\nLog every call in CRM → Leads with disposition + next action.",
            'body' => <<<'MD'
## Why this matters

The Discover stage decides whether the rest of the funnel exists. A sloppy opener wastes the lead — and most agents lose deals here, not at payment. The two strongest predictors of a closed deal are **(1)** whether you confirmed the market fit in the first 90 seconds and **(2)** whether you logged a disposition before the call ended.

## What "good discovery" looks like

You leave the call with three concrete things written into the CRM:

1. **City + country** (drives package pricing and which currency to quote).
2. **Current listing status** (are they already on a competitor? brand new? returning after a break?).
3. **A specific next action with a date** (callback Thursday 2pm, send WhatsApp deck, escalate to supervisor).

If any of these three is missing when you hang up, the call did not happen.

:::script Opening line
"Hi, I'm [Name] calling from Exotic Online. We're Africa's leading adult advertising platform — you may have seen some of the profiles on [site URL]. I'm reaching out because we're currently accepting new listings in [city/country] and I'd love to tell you about what we offer. Do you have 2 minutes?"
:::

## Qualifying questions (in order)

These three questions, in this order, separate serious prospects from time-sinks:

1. **"Are you currently working in [city]?"** — confirms market and unlocks pricing.
2. **"Are you listed on any other platform right now?"** — tells you whether to lead with cross-listing or with "your first listing".
3. **"Are you actively looking for more clients this month, or is this more exploratory?"** — surfaces urgency. Exploratory leads go into nurture; urgent leads get a same-day package recommendation.

:::tip Why ask in this order
Market → competitor → urgency is least-to-most personal. Asking about urgency first feels pushy and gets evasive answers. Asking about market first feels procedural and earns honesty by the time you reach urgency.
:::

## The most common opening objection

:::objection "I already list on [competitor]"
**Customer:** *"I already list on Competitor X — why do I need you?"*

**You:** "That's actually great — a lot of our most successful clients cross-list. Exotic tends to attract clients who are willing to pay for a better experience, which means higher-quality enquiries. Would you be open to trying it alongside what you're already doing? You don't have to choose."

**Why it works:** It reframes the question from "either/or" to "both/and", validates their existing choice (so they don't feel attacked), and lowers the commitment ask to "try alongside" instead of "switch over".
:::

## Worked scenario

:::scenario A real discovery call
**Lead:** Inbound from the website. Phone number, first name "Joy", city "Nairobi", no other info.

**Step 1.** Open with the script above. Joy confirms she's in Nairobi.

**Step 2.** Ask Q1 → "Yes, Nairobi, working from Westlands." → log city = Nairobi, area = Westlands.

**Step 3.** Ask Q2 → "I tried a free site once but it didn't get me anything." → not on a competitor → lead with "first paid listing" framing, not cross-listing.

**Step 4.** Ask Q3 → "Yeah this week if possible, things have been quiet." → urgency confirmed → move directly to package recommendation.

**Step 5.** Quote KES pricing for Featured (mid-tier — see Subscription Selection lesson). Joy says she'll think and call back tomorrow.

**Step 6.** **Before you hang up**, set the disposition in the CRM: Lead → Status `Interested`, Next action `Callback Tomorrow 10am`, Note `Quoted Featured, considering. Returns to platform after free-site disappointment.`
:::

## Common mistakes

:::warning Three traps that kill discovery calls
1. **Pitching before qualifying.** If you describe packages before you've asked the three questions, you'll quote the wrong tier and burn credibility.
2. **Skipping the disposition.** "I'll log it later" means it never gets logged. The next agent who picks up this lead has no idea what was said.
3. **Trying to close the same call.** Discovery is about earning the next conversation, not the sale. Most paid leads need 2–3 touches.
:::

## Knowledge check

1. What three pieces of information must be in the CRM before you hang up?
2. Why do we ask about the market before urgency?
3. What's the recommended response to "I already list on Competitor X"?
MD,
        ];
    }

    private function lessonSubscriptionSelection(): array
    {
        return [
            'slug' => 'subscription-selection-stage-5a-guide',
            'title' => 'Subscription Selection: Recommend the Right Package',
            'subtitle' => 'Stage 5a — match the offer to the customer',
            'duration' => 16,
            'playbook_url' => 'https://exoticonline.mintlify.app/product/stage-5a-subscription-selection',
            'quick_reference' => "PACKAGE MATRIX (visibility low → high):\nBasic → Featured → Premium → VIP → VVIP\n\nDEFAULT RECOMMENDATIONS:\n- Brand-new escort, no prior platform: Featured\n- Returning after break: Featured or Premium\n- Established with strong portfolio: Premium or VIP\n- Top earner, wants top-of-page: VVIP\n\nFREE TRIAL: only if approved by supervisor PIN. Never offer unprompted.\n\nCURRENCY: always quote in the market's local currency (KES, NGN, GHS, etc.) — never USD.",
            'body' => <<<'MD'
## Why this matters

Package selection is where the conversation turns into revenue. Over- or under-quoting destroys the deal: quote too high and the lead drops; quote too low and they convert but churn in 30 days because the package never delivered.

The right package is **the lowest tier where they will actually feel the platform working** within the first two weeks. That number is rarely the cheapest one.

## The five tiers, in plain English

| Tier | Visibility | Best for |
|------|-----------|----------|
| **Basic** | Listed in category. No homepage exposure. | Cautious first-timers who want to test the platform with minimum spend. Conversion is slow — expect them to renew at Featured or churn. |
| **Featured** | Higher in category, occasional homepage rotation. | Default for new escorts. The package that "feels like the platform is working" within 7 days. |
| **Premium** | Top of category, persistent homepage banner. | Returning escorts and established workers. Strong renewal rate. |
| **VIP** | Persistent top placement + premium badge. | High earners optimising for fewer-but-better clients. |
| **VVIP** | Top-of-page, hero placement, VVIP badge. | Top 1% — limited slots per city. |

:::script Recommending a package
"Based on what you've told me — you're [new/returning/established] and you're [actively looking/exploring] — I'd suggest [tier]. That gives you [visibility outcome] and at [local price] it's the package that most [matching segment] are happy renewing after the first month. We can always upgrade you to [next tier] when you're ready."
:::

## The big three signals that drive the recommendation

1. **Prior platform experience.** Total newcomer → Featured. Returning after break → Featured or Premium depending on how long they're back for.
2. **Portfolio strength.** Strong photos, multiple, verified → can support Premium+. Weak portfolio → start at Featured and revisit at renewal.
3. **Stated goal.** "I want more bookings" → Featured/Premium. "I want fewer-but-higher-paying clients" → VIP. "I want top of the homepage" → VVIP.

:::tip The unfair upgrade
The single highest-leverage move in this stage is asking: *"Are you optimising for more bookings, or for better-paying bookings?"* The answer changes the recommendation by a tier in either direction and is the question most agents forget to ask.
:::

## Worked scenario

:::scenario Avoiding the price-anchoring trap
**Lead:** Sarah, Lagos. Worked on a competitor site for 18 months, taking a 3-month break, now coming back. Has a professional portfolio.

**Wrong move:** quote Basic because "she'll renew up later".

**Right move:** quote Premium with a one-line rationale: *"With your portfolio and previous experience, Basic would underuse what you've already built. Premium gets you visible in the first 48 hours, which matters more after a break because the algorithm needs to re-establish you."*

She paid Premium. Spending the next tier up *because the rationale was specific* converted faster than offering a discount on Basic.
:::

## Free trial — when and how

:::warning Free trial rules
- Free trial is approved by supervisor PIN only. Never offer it on your own authority.
- Only consider for high-value leads (strong portfolio, market shortage in their city, ex-competitor switcher).
- One free trial per phone number, ever. The CRM enforces this.
- If granted, log the approver and reason in the CRM. Audit reviews every free trial monthly.
:::

## Currency and pricing

Always quote in the local market currency. The CRM auto-detects from the platform/market, but **read it back to the customer** before you confirm. A confused customer who thinks "$10" actually means "USD 10" instead of "KES" is a refund and a complaint waiting to happen.

:::checklist Before you confirm the sale
- [ ] Package tier matches the three signals (history, portfolio, goal)
- [ ] Price quoted in local currency, read back to customer
- [ ] Free trial only if PIN-approved
- [ ] Discount only if within market threshold (see Discounts lesson in Objection Handling)
- [ ] Payment link sent OR M-Pesa STK initiated within 5 minutes of confirmation
:::

## Common mistakes

:::warning
- **Quoting USD by accident.** Always local currency.
- **Recommending Basic to anyone with a strong portfolio.** They will churn and blame the platform.
- **Mentioning all five tiers.** Anchor on one recommendation, mention one alternative. More than two options paralyses the buyer.
:::

## Knowledge check

1. What three signals drive the package recommendation?
2. Who is allowed to approve a free trial?
3. What's the question most agents forget to ask before recommending a tier?
MD,
        ];
    }

    private function lessonFailureOverview(): array
    {
        return [
            'slug' => 'failures-and-recovery-stage-5e-guide',
            'title' => 'Failures & Recovery: Escalation Tree',
            'subtitle' => 'Stage 5e overview — the deep-dive is in the Failure Recovery course',
            'duration' => 10,
            'playbook_url' => 'https://exoticonline.mintlify.app/product/stage-5e-failures',
            'quick_reference' => "FIRST RESPONSE (any failure):\n1. Acknowledge in 60 seconds\n2. Open CRM → Payments → find by phone or reference\n3. Read diagnostic field BEFORE asking customer to repeat info\n4. State next step in plain language\n\nESCALATE to supervisor if:\n- Payment showing >24h pending\n- Webhook backlog visible in CRM\n- Customer threatening refund/complaint\n- 3rd contact on the same issue",
            'body' => <<<'MD'
## Why this matters

Failure recovery is where retention is won or lost. 80% of churn is preceded by an unresolved failure event — a stuck payment, a profile not going live, a credential not delivered. The agent who handles the first failure call decides whether this customer renews next month.

The full failure taxonomy (F1 through F10) lives in the **Failure Recovery Deep Dive** course. This lesson covers the universal first-response protocol and the escalation tree.

## Universal first-response protocol

:::checklist Within 60 seconds of "I have a problem"
- [ ] Acknowledge specifically: "I can see there's been an issue with your payment — let me pull it up right now."
- [ ] Open CRM → Payments → search by phone or reference. **Do NOT ask the customer to repeat what they already entered into the form.**
- [ ] Read the diagnostic field on the payment record. It will tell you whether the issue is M-Pesa, webhook, mismatch, or activation.
- [ ] State the next step in one sentence: "Your payment landed but the auto-match flagged it for low confidence. I'm confirming it now and your profile will be live in 2 minutes."
:::

:::script Acknowledgement line that buys trust
"I can see [specific thing — your payment, your profile, your renewal] — let me sort this out now. Stay on the line for one minute."

The word "now" matters. "I'll look into it and get back to you" is the response that ends with a refund request.
:::

## The 10 failure modes (deep-dive course)

| Code | Symptom | Fix lives in |
|------|---------|--------------|
| **F1** | "I paid but profile isn't live" | Failure Recovery course |
| **F2** | STK Push timeout / no prompt received | Failure Recovery course |
| **F3** | Payment amount mismatch | Failure Recovery course |
| **F4** | Wrong package activated | Failure Recovery course |
| **F5** | Credentials not received | Failure Recovery course |
| **F6** | Webhook backlog (multi-customer) | Failure Recovery course |
| **F7** | Profile activated but not visible on site | Failure Recovery course |
| **F8** | Manual payment proof submitted but not approved | Failure Recovery course |
| **F9** | Duplicate payment | Failure Recovery course |
| **F10** | Renewal applied to wrong subscription | Failure Recovery course |

## When to escalate

:::warning Escalate immediately if any of these are true
- The payment shows **>24 hours pending** in the CRM.
- Multiple customers are reporting the same issue → likely a **webhook backlog (F6)**, not an individual problem.
- The customer uses the words **"refund"**, **"complaint"**, or **"reporting you"** — escalate before you respond, not after.
- This is the **third contact** the customer has had on the same issue — you don't have full context, and another agent's mistake may already be in the ticket.
:::

## Objection: "I want a refund"

:::objection "Give me my money back"
**Customer:** *"I want a refund — this isn't working."*

**You:** "I completely understand. Before I escalate the refund, can I give you 5 minutes to fix the underlying issue? If I can't get your profile live in that time, I'll process the refund myself — no questions asked. Fair?"

**Why it works:** Most refund requests are frustration with the failure, not a genuine desire for the money back. Offering a time-boxed fix with a guaranteed escape hatch reduces 70% of refund requests to a successful recovery.

**When NOT to use:** if the customer has already been promised a fix twice and it hasn't happened, do not offer a third "5 minutes". Escalate to supervisor immediately.
:::

## Knowledge check

1. What's the first thing you do when a customer reports a payment problem — before asking them anything?
2. List three triggers that mandate immediate escalation.
3. Why does the "5 minutes" reframe usually defuse a refund request?
MD,
        ];
    }

    private function lessonRenewals(): array
    {
        return [
            'slug' => 'renewals-stage-7-guide',
            'title' => 'Renewals: Save the Subscription',
            'subtitle' => 'Stage 7 — retention is cheaper than acquisition',
            'duration' => 15,
            'playbook_url' => 'https://exoticonline.mintlify.app/product/stage-7-renewal',
            'quick_reference' => "RENEWAL CALL ORDER:\n1. Open with date, not a question\n2. Surface positive proof (views, enquiries) if available\n3. Offer continuity at same tier\n4. Listen for objection — DON'T offer discount first\n5. Match objection to playbook response (this lesson)\n\nRETENTION WATCH:\n- 3 days before expiry = green\n- 0 to -7 days expired = amber\n- -8 to -29 days = red (still recoverable)\n- 30+ days = lapsed (cold lead, hand to onboarding)",
            'body' => <<<'MD'
## Why this matters

A renewed client is worth ~3x a new client over 12 months because acquisition cost is zero and the platform's value is already proven. But renewals don't happen by accident — they happen because an agent made a specific call at a specific moment in the customer's lifecycle.

The Retention Watch panel in the CRM tells you who to call and when. Your job is to know what to say.

## The renewal call opening

:::script The opener that gets to "yes" faster than asking
"Hi [Name], it's [Agent] from Exotic. I can see your listing is coming up for renewal on [specific date] — I wanted to make sure you're sorted so your profile stays live. Shall we get that renewed today?"

**Why it works:** The opener is a statement, not a question. The default action is "yes, renew" — the customer has to actively object to opt out. Compare with the weak version: *"Hi, are you interested in renewing?"* — which invites a no.
:::

## Surface proof before asking

If the CRM shows profile views, enquiries, or wallet activity in the last 30 days, mention it specifically in the second sentence:

*"I can also see you had 240 profile views and 18 enquiries this month — that's strong. Let's keep that going."*

If the numbers are weak, **do not lie about them**. Skip the proof line and move directly to the renewal offer. Inflated numbers are a trust-killer.

## The four objections (in frequency order)

:::objection "Not getting enough clients"
**Customer:** *"I haven't been getting enough enquiries."*

**Step 1 — diagnose, don't discount.** Pull up the profile in the CRM and check completeness: photos, description, contact info, package tier.

**You:** "Let me check your profile right now... I can see [specific gap — e.g., 'only 3 photos and no recent updates']. The clients who get the most enquiries on Featured usually have 6+ photos and refresh their profile weekly. Let's fix that during the renewal call and renew on the same tier — I'll bet you'll see different numbers next month."

**If the profile is complete and views are still low:** propose a tier upgrade with a specific rationale, not a discount. "You're maxing out what Featured can deliver in your market. Premium gets you visible to the next-tier client and is the natural step up."
:::

:::objection "I'm taking a break"
**Customer:** *"I'm taking some time off — I'll come back later."*

**You:** "Got it, that makes sense. Two quick things: first, your profile and data stay with us — nothing is deleted, so when you're back, you log straight in. Second, want me to set you up with a renewal reminder for [specific date 30/60/90 days out]? I'll be the one calling you back."

**Why it works:** It removes the fear of losing the work they've already done, and it earns the right to a follow-up call by being specific about timing.
:::

:::objection "Why pay when free sites exist?"
**Customer:** *"There are free sites — why am I paying you?"*

**You:** "Three reasons. **Lead quality** — our clients are paying clients, which filters out the time-wasters. **Visibility** — paid profiles rank both on our site and in search results. **Support** — you have me on the phone right now; free sites don't pick up. Which of those matters most to you?"

**Why it works:** Numbered structure makes it memorable. Ending with a question routes the rest of the conversation to their actual priority.
:::

:::objection "Give me a discount"
**Customer:** *"I'll renew if you give me 30% off."*

**You:** "I hear you. Let me check what I can do." *(Open CRM, check market discount threshold.)* "I can offer [X%] within my authority — that's a [specific value] saving. Beyond that I'd need supervisor approval, which adds a day. Want to renew at [X%] right now and stay live, or wait for the approval?"

**Why it works:** Specific number, specific timeframe, choice between two yeses. Both options keep them subscribed.
:::

:::warning Discount guardrails
- Every market has a discount threshold (check Settings → Discounts). Do not promise above it without supervisor PIN.
- Stacking discounts is not allowed. If they already used a discount last cycle, decline politely and escalate if pushed.
- Discounts on renewals are recorded in the deal record — three discounted renewals in a row triggers an account review.
:::

## At-risk cohorts

The Retention Watch panel highlights three colours:

- **Green** (3 days before expiry): standard renewal call.
- **Amber** (expired 0–7 days): use urgency language — "I want to keep you visible — your profile is paused but everything is preserved if we renew today."
- **Red** (expired 8–29 days): handle as a recovery call, not a renewal. Open with empathy: *"I noticed your listing went down — I wanted to check in personally and see what happened."*

Once a customer hits **lapsed (30+ days)**, hand off to the onboarding/reactivation queue — they need a different conversation, not a renewal one.

## Knowledge check

1. Why is the opening line a statement, not a question?
2. What's the diagnose-don't-discount move for "not getting enough clients"?
3. What's the maximum discount you can give without supervisor approval, and where do you check it?
MD,
        ];
    }

    private function lessonProductOverview(): array
    {
        return [
            'slug' => 'product-overview-guide',
            'title' => 'Product Overview: What We Actually Sell',
            'subtitle' => 'The 8-stage customer lifecycle, end to end',
            'duration' => 12,
            'playbook_url' => 'https://exoticonline.mintlify.app/product/overview',
            'quick_reference' => "8 STAGES:\n1. Discover (lead)\n2. Register (account)\n3. Profile (content)\n4. Verification\n5. Subscription/Payment\n6. Activation (profile live)\n7. Renewal\n8. Upgrades / Lapse / Reactivate\n\nWHAT WE SELL:\n- Visibility on a paid platform\n- Lead quality (paying clients vs. time-wasters)\n- Account support (you on the phone)\n\nWHAT WE DON'T SELL:\n- Guaranteed bookings\n- Adult content moderation overrides\n- Anonymity from law enforcement",
            'body' => <<<'MD'
## Why this matters

You cannot sell what you cannot describe. Every objection-handling lesson in this course is built on the assumption that you can answer "what does Exotic Online actually do?" in three sentences. This lesson is that foundation.

## The product, in one paragraph

**Exotic Online is a paid adult advertising platform operating across 54 African markets. Escorts (the clients you sell to) pay a subscription to list their profile, photos, contact info, and availability. The platform's customers (the people who view those profiles) are vetted by virtue of being willing to use a paid platform — that's the core value: paying clients filter out time-wasters.**

## The 8-stage customer lifecycle

| # | Stage | What happens | Who owns it |
|---|------|--------------|------|
| 1 | **Discover** | Lead enters via outbound, inbound, referral, or self-service signup. | Sales |
| 2 | **Register** | Account created in WordPress, synced to CRM as a Client record. | Sales / Self-service |
| 3 | **Profile** | Photos, description, contact info added. | Client / Sales assists |
| 4 | **Verification** | ID/age check. Required before activation. | Sales / Support |
| 5 | **Subscription** | Package picked, payment initiated (5a → 5e). | Sales |
| 6 | **Activation** | Profile goes live on the site. | Auto, with manual fallback |
| 7 | **Renewal** | Subscription extends before/after expiry. | Sales |
| 8 | **Lifecycle** | Upgrade / lapse / reactivate. | Sales / Retention |

:::tip Where most sales conversations actually happen
Stages 1, 5, and 7. The middle stages (Register, Profile, Verification, Activation) are mostly automated or done by the client themselves — your job there is to unblock, not to drive. Spending 40 minutes "helping" a customer build their profile is usually a sign you're avoiding the next discovery call.
:::

## What we sell — three pillars

:::checklist Memorise these three answers
1. **Visibility.** Paid profiles rank higher in our site, are featured on the homepage, and tend to surface in Google searches for relevant terms.
2. **Lead quality.** Our platform's visitors are willing to pay for a better experience, which filters out time-wasters and tyre-kickers.
3. **Support.** Every client has access to an account manager (you), renewal reminders, and dedicated customer service. Free sites don't.
:::

## What we do NOT sell

This list is just as important as the value pillars. Customers will sometimes ask for things we don't offer — being clear protects the brand and avoids refund disputes.

:::warning Things to never promise
- **Guaranteed bookings.** We sell visibility, not outcomes. "You'll get more clients" is a fair expectation-setter; "you'll get X clients per week" is a promise we cannot keep.
- **Moderation overrides.** Content policies (no minors, no illegal services) are non-negotiable. Don't promise exceptions.
- **Anonymity from law enforcement.** We comply with legal requests. Lying about this is a fireable offence.
- **Refunds for "I changed my mind".** Refund policy is documented per market. Stick to it.
:::

## The Sales / CS responsibility split

| You (Sales) own | Customer Service owns |
|-----------------|----------------------|
| Discover, Subscription, Renewal calls | Profile editing help, technical issues, complaints |
| Package recommendation, free trial requests | Verification ID review, content moderation |
| Outbound and warm-lead outreach | Inbound chat (support board), refund processing |
| Closing the sale | Keeping the customer happy after the sale |

You'll handle CS-style issues constantly because the customer doesn't care which team you're on. The Failure Recovery course teaches you how to do that without dropping your sales role.

## Knowledge check

1. Name the 8 stages of the customer lifecycle in order.
2. What are the three things Exotic Online actually sells?
3. Why is "you'll get 10 clients a week" a dangerous promise?
MD,
        ];
    }

    private function courseProductMastery(): array
    {
        return [
            'slug' => 'product-mastery',
            'title' => 'Product Mastery',
            'summary' => 'Everything about the Exotic Online platform itself: lifecycle, packages, pricing, visibility mechanics, and the payment paths. Required reading for anyone who quotes a price.',
            'difficulty' => 'beginner',
            'accent_color' => 'indigo',
            'order' => 2,
            'learning_outcomes' => [
                'Describe the 8-stage customer lifecycle to a new prospect',
                'Quote correct pricing per market without checking the spreadsheet',
                'Explain what drives visibility on the platform',
                'Pick the right payment path for any country we operate in',
            ],
            'modules' => [
                ['slug' => 'platform-lifecycle', 'title' => 'Platform & Lifecycle', 'lessons' => [$this->lessonPlatformLifecycle()]],
                ['slug' => 'package-tiers', 'title' => 'Package Tiers Deep Dive', 'lessons' => [$this->lessonPackageTiers()]],
                ['slug' => 'pricing-and-markets', 'title' => 'Pricing & Markets', 'lessons' => [$this->lessonPricingMarkets()]],
                ['slug' => 'visibility-mechanics', 'title' => 'Visibility & SEO', 'lessons' => [$this->lessonVisibility()]],
                ['slug' => 'payment-paths', 'title' => 'Payment Paths', 'lessons' => [$this->lessonPaymentPaths()]],
            ],
        ];
    }

    private function lessonPlatformLifecycle(): array
    {
        return [
            'slug' => 'platform-and-lifecycle',
            'title' => 'The Platform and the 8 Stages',
            'duration' => 12,
            'playbook_url' => 'https://exoticonline.mintlify.app/product/lifecycle',
            'quick_reference' => "Lifecycle stages own everything: Discover → Register → Profile → Verification → Subscription → Activation → Renewal → Lifecycle.\n\nSales touches 1, 5, 7 heavily. The middle is mostly automated.\n\nIf you don't know which stage a client is in, you'll quote the wrong thing.",
            'body' => <<<'MD'
## Why this matters

The lifecycle is the map of every customer interaction. When a customer says *"my profile isn't live"*, you need to know whether they're in Stage 5 (still paying) or Stage 6 (paid but not activated) or Stage 7 (lapsed and renewing). Same words, three different conversations.

## The full lifecycle

:::checklist The 8 stages in order
1. **Discover** — lead enters our world. Outbound, inbound, referral, self-service.
2. **Register** — account created in WordPress, synced to CRM.
3. **Profile** — photos, description, availability added.
4. **Verification** — ID and age verification before going live.
5. **Subscription** — package chosen, payment processed (sub-stages 5a–5e).
6. **Activation** — profile goes live on the website.
7. **Renewal** — subscription extends before expiry.
8. **Lifecycle** — upgrade, lapse, or reactivate.
:::

## Who owns what

The middle stages (2–4, 6) are mostly automated or self-service. Sales owns stages 1, 5, and 7. CS owns content moderation, verification review, and post-activation support.

:::tip The lifecycle-aware question
Before you respond to any customer issue, ask yourself silently: *"What stage are they in?"* If you don't know, open the CRM client record — it will tell you. Stage-correct responses save 5 minutes per call and avoid 80% of misunderstandings.
:::

## Knowledge check

1. Which lifecycle stages does Sales own?
2. What's the difference between "in Stage 5" and "in Stage 6"?
MD,
        ];
    }

    private function lessonPackageTiers(): array
    {
        return [
            'slug' => 'package-tiers-deep-dive',
            'title' => 'The Five Tiers, In Detail',
            'duration' => 14,
            'playbook_url' => 'https://exoticonline.mintlify.app/clients/subscription',
            'quick_reference' => "TIERS (low → high visibility):\nBasic | Featured | Premium | VIP | VVIP\n\nFEATURE DIFFERENCES:\n- Listing rank in category\n- Homepage rotation\n- Persistent banner\n- Badges (Premium, VIP, VVIP)\n- Slot scarcity (VVIP is capped per city)",
            'body' => <<<'MD'
## Why this matters

Every conversation about price is really a conversation about a tier. You can't sell a price you don't understand.

## What each tier actually delivers

### Basic
- Listed inside the relevant city/category.
- No homepage exposure, no badge.
- Lowest price point.
- Best for: extremely price-sensitive first-timers who want to test the platform.

### Featured
- Higher placement inside the category.
- Occasional homepage rotation.
- Most common "first paid" tier.
- Best for: brand-new escorts who want to feel the platform working.

### Premium
- Top of the category list.
- Persistent homepage banner during peak hours.
- Premium badge on profile.
- Best for: returning escorts, established workers, anyone with a strong portfolio.

### VIP
- Top placement + permanent badge.
- Featured in city-specific premium sections.
- Higher trust signal to customers.
- Best for: high-earners optimising for fewer-but-better bookings.

### VVIP
- Hero placement at top of every relevant page.
- VVIP badge — highest trust signal.
- **Capped per city** — limited slots, often a waitlist.
- Best for: top 1% earners. Treat as a status purchase, not just a visibility purchase.

:::tip The slot-scarcity move
VVIP being capped is a genuine sales asset. *"I've got two VVIP slots left in Nairobi this month — if you're seriously considering it, we should lock yours in today."* This is a true statement, not a pressure tactic. Use it when accurate.
:::

## Common confusion to clear up

:::warning "Featured" vs "Premium" sounds samey
Customers often think Featured = Premium. Be explicit: *"Featured gets you visible in the category. Premium pins you to the homepage. They're a meaningful step apart."*
:::

## Knowledge check

1. What's the actual capacity difference between VIP and VVIP?
2. Why is VVIP capped per city?
3. What's the easiest way to differentiate Featured from Premium in 10 seconds?
MD,
        ];
    }

    private function lessonPricingMarkets(): array
    {
        return [
            'slug' => 'pricing-and-markets',
            'title' => 'Pricing Per Market',
            'duration' => 12,
            'playbook_url' => 'https://exoticonline.mintlify.app/settings/billing',
            'quick_reference' => "PRICING SOURCES OF TRUTH:\n- CRM → Settings → Integrations → Platform → Packages\n- Each market has its own currency and tier prices\n- Never invent or remember prices — pull from CRM at quote time\n\nMARKET CURRENCY EXAMPLES:\n- Kenya: KES\n- Nigeria: NGN\n- Ghana: GHS\n- South Africa: ZAR\n- Côte d'Ivoire / regional: XOF / XAF (CFA — beware ambiguity)\n\nDISCOUNT THRESHOLDS: configured per market, surfaced in CRM when you initiate a deal. Above threshold → supervisor PIN required.",
            'body' => <<<'MD'
## Why this matters

Quoting the wrong currency or guessing a price is the fastest way to destroy trust. Every market in our platform has its own price table maintained by the local Head of CS / Sales — not by you. Your job is to pull the right number, not remember it.

## The single source of truth

:::checklist Always quote from the CRM
- [ ] Open the client record. Confirm market and currency.
- [ ] Open the deal flow. The package picker shows live prices for that market.
- [ ] Read the price back to the customer in their local currency. *"That's 4,500 Kenyan shillings — confirming?"*
- [ ] Never quote from memory or from a screenshot you took last month.
:::

:::warning CFA currency ambiguity
Côte d'Ivoire and several West/Central African markets use "CFA franc" — but there are **two different CFA francs**: XOF (West African) and XAF (Central African). They are **not interchangeable**. The CRM stores the correct one per market — trust it, and never just say "CFA" without the country context.
:::

## When prices change

Prices are set by the local Head of CS / Sales and updated in the CRM. If you see an old price quoted in a Slack thread or old PDF, it's wrong by default — go to the CRM.

## Discount thresholds

Each market has a discount threshold visible in Settings → Discounts. Below threshold = your authority. Above threshold = supervisor PIN.

:::tip The "I'll check what I can do" move
When a customer asks for a discount, say *"Let me check what I can do"*, open Settings → Discounts, and quote the actual market threshold. This earns trust because it sounds like authority being exercised, not made up.
:::

## Knowledge check

1. Where do you pull the actual price for a given market from?
2. What's the difference between XOF and XAF — and which markets use each?
3. Where is the discount threshold configured?
MD,
        ];
    }

    private function lessonVisibility(): array
    {
        return [
            'slug' => 'visibility-and-seo',
            'title' => 'What Drives Visibility',
            'duration' => 11,
            'playbook_url' => 'https://exoticonline.mintlify.app/product/visibility',
            'quick_reference' => "VISIBILITY DRIVERS (in order of impact):\n1. Subscription tier (the big one)\n2. Profile completeness (photos, description, contact info)\n3. Recency of updates\n4. Verified status\n5. City demand vs. supply\n\nWHAT DOESN'T HELP:\n- Asking us to 'boost' manually — we don't\n- Posting daily — only refreshes matter\n- Buying multiple profiles — flags as duplicate",
            'body' => <<<'MD'
## Why this matters

Customers ask "why aren't I getting clients?" constantly. If you don't understand what drives visibility, you'll either lie or panic-offer a discount. Both kill renewal.

## The visibility stack

1. **Tier.** The biggest lever. A Featured profile genuinely sees 3–5x the views of a Basic profile in the same city. Numbers vary, but the order of magnitude holds.
2. **Profile completeness.** Photos (6+ is the threshold), description with specifics, contact info, working hours. Incomplete profiles get deprioritised by the platform regardless of tier.
3. **Recency.** Profiles updated in the last 7 days are scored higher than profiles untouched for a month. "Updated" means edited content — not just logging in.
4. **Verified status.** Verified profiles get a trust badge that materially improves enquiry conversion.
5. **City demand.** Some cities have a glut of profiles; visibility is harder in saturated markets. This is honest information, not an excuse.

:::tip Coaching a low-traffic client
Walk through the stack with them on the phone. *"You're on Featured — that's the right tier. Let's check completeness. You have 4 photos — let's get to 6. Last update was 22 days ago — let's edit one thing now. Verified? Yes. Nairobi is competitive but you've got room to climb."* By the end they have specific actions, not a complaint.
:::

## What doesn't help

:::warning Don't promise these
- **"I'll boost your profile manually."** We don't have that lever. Don't fabricate one.
- **"Post once a day."** Logging in doesn't change anything. Editing content does.
- **"Create a second profile to double your exposure."** Duplicate detection flags it and may suspend both.
:::

## Knowledge check

1. List the visibility drivers in order of impact.
2. What counts as a "profile update" for ranking purposes?
3. Why is creating a second profile counterproductive?
MD,
        ];
    }

    private function lessonPaymentPaths(): array
    {
        return [
            'slug' => 'payment-paths',
            'title' => 'M-Pesa, Paystack, Pesapal — Pick the Right Path',
            'duration' => 12,
            'playbook_url' => 'https://exoticonline.mintlify.app/payments/overview',
            'quick_reference' => "PAYMENT PATHS:\n- M-Pesa STK Push: Kenya, primary. Phone-initiated, 90% success rate.\n- Paystack: Nigeria, Ghana, South Africa, card + bank.\n- Pesapal: regional fallback, hosted checkout.\n- KopoKopo: Kenya, paybill / till alternative.\n- Manual / cash: only with supervisor approval, recorded as manual_payment.\n- Wallet: top-ups + subsequent deductions.\n\nIF STK FAILS:\n- Auto-fallback to hosted checkout link (Paystack/Pesapal).\n- Or send payment link from CRM → Payment Link Sender.",
            'body' => <<<'MD'
## Why this matters

Different markets, different rails. Picking the right payment path on the first try saves the customer 3 retries and saves you a failure-recovery call.

## The defaults by market

| Market | Primary | Fallback |
|--------|---------|----------|
| Kenya | M-Pesa STK Push | KopoKopo paybill, hosted Pesapal link |
| Nigeria | Paystack | Manual bank transfer (with proof) |
| Ghana | Paystack | Manual bank transfer (with proof) |
| South Africa | Paystack | Manual EFT |
| Other markets | Hosted checkout link | Manual with supervisor approval |

## M-Pesa STK Push — how it actually works

1. You initiate the payment in CRM.
2. The system sends an STK Push to the customer's phone.
3. The customer enters their M-Pesa PIN.
4. Webhook returns success → profile activates automatically.

Typical timeline: 60–90 seconds.

:::warning STK Push gotchas
- Wrong phone number? The push goes nowhere — confirm the number before initiating.
- Customer didn't get the prompt? They may need to **dial *334#** to unlock prompts, or their SIM is M-Pesa-disabled.
- Webhook failure on our side? The payment may have succeeded on M-Pesa but not registered in CRM. Check the Payments → Reconcile screen.
:::

## When to switch to hosted checkout

If STK fails twice, switch to a hosted checkout link (Paystack or Pesapal). Send it via WhatsApp or SMS from the CRM. Customer pays via card or bank transfer.

## Wallet vs. one-shot

Wallet top-ups let customers pre-fund renewals — useful for high-spenders. Wallet is configured per market in Settings → Billing → Wallet Rules.

## Knowledge check

1. What's the primary path in Kenya, and what's the fallback?
2. How do you diagnose "STK Push not received"?
3. When does wallet make more sense than one-shot payment?
MD,
        ];
    }

    // ============================================================
    // Course: Objection Handling Masterclass
    // ============================================================

    private function courseObjectionHandling(): array
    {
        return [
            'slug' => 'objection-handling-masterclass',
            'title' => 'Objection Handling Masterclass',
            'summary' => 'The six objections you will hear every single day, with verbatim responses, the reasoning behind each, and what to do when the first response fails.',
            'difficulty' => 'intermediate',
            'accent_color' => 'amber',
            'order' => 3,
            'learning_outcomes' => [
                'Recognise an objection in the first sentence',
                'Respond with the playbook line in your own words',
                'Know when to escalate vs. when to keep talking',
            ],
            'modules' => [
                ['slug' => 'i-already-list', 'title' => 'I already list elsewhere', 'lessons' => [$this->lessonObjectionAlreadyList()]],
                ['slug' => 'why-pay', 'title' => 'Why pay when there are free sites?', 'lessons' => [$this->lessonObjectionFreeSites()]],
                ['slug' => 'not-enough-clients', 'title' => 'Not getting enough clients', 'lessons' => [$this->lessonObjectionNotEnough()]],
                ['slug' => 'taking-a-break', 'title' => "I'm taking a break", 'lessons' => [$this->lessonObjectionBreak()]],
                ['slug' => 'why-renew-early', 'title' => 'Why renew early?', 'lessons' => [$this->lessonObjectionRenewEarly()]],
                ['slug' => 'discount-asks', 'title' => 'Give me a discount', 'lessons' => [$this->lessonObjectionDiscount()]],
            ],
        ];
    }

    private function lessonObjectionAlreadyList(): array
    {
        return [
            'slug' => 'i-already-list',
            'title' => '"I already list on Competitor X"',
            'duration' => 8,
            'playbook_url' => 'https://exoticonline.mintlify.app/sales/objections/cross-listing',
            'quick_reference' => "THE MOVE: reframe from either/or → both/and.\n\nKEY LINE: 'A lot of our most successful clients cross-list.'\n\nVALUE PROP TO LEAD WITH: lead quality, not visibility (because they already have visibility somewhere).",
            'body' => <<<'MD'
## The objection

*"I already list on Competitor X — why do I need you?"*

## The response

:::script Cross-listing reframe
"That's actually great — a lot of our most successful clients cross-list. Exotic tends to attract clients who are willing to pay for a better experience, which means higher-quality enquiries. Would you be open to trying it alongside what you're already doing? You don't have to choose."
:::

## Why it works

- **"That's actually great"** — disarms the framing that listing elsewhere is a problem.
- **"A lot of our most successful clients cross-list"** — social proof, normalises the dual approach.
- **"Higher-quality enquiries"** — the value prop they don't already have. Visibility they have; quality they don't know about yet.
- **"You don't have to choose"** — eliminates the perceived risk of switching.

## When it doesn't work

If they say *"my current platform already brings the best clients"*, do NOT escalate the cross-listing argument. Switch to:

:::script Follow-up move when cross-listing fails
"Fair enough. Let me ask differently — what would actually need to change for it to be worth trying us alongside? Pricing? Visibility? Support quality?"
:::

This forces them to articulate the gap, which gives you something specific to address (or honestly disqualify if there's no fit).

## Knowledge check

1. What's the value prop you lead with when they already list elsewhere?
2. What's the follow-up move if cross-listing reframe fails?
MD,
        ];
    }

    private function lessonObjectionFreeSites(): array
    {
        return [
            'slug' => 'why-pay-free-sites',
            'title' => '"Why pay when there are free sites?"',
            'duration' => 8,
            'playbook_url' => 'https://exoticonline.mintlify.app/sales/objections/free-sites',
            'quick_reference' => "THREE-POINT ANSWER:\n1. Lead quality\n2. Visibility\n3. Support\n\nEND WITH A QUESTION: 'Which of those matters most to you?'",
            'body' => <<<'MD'
## The objection

*"There are free sites — why pay you?"*

## The response

:::script Three-point answer + redirect
"Three reasons. **Lead quality** — our clients are paying clients, which filters out time-wasters. **Visibility** — paid profiles rank on our site and in search results. **Support** — you have me on the phone right now; free sites don't pick up. Which of those matters most to you?"
:::

## Why it works

- **Numbered structure** is memorable and feels confident.
- **The question at the end** redirects the conversation to their actual priority.
- **The phone-call comparison** is concrete proof, not a claim.

## Variations

If they say "support doesn't matter to me", drop it and double down on lead quality + visibility. Don't keep selling support if they've already discounted it.

If they say "I just want lots of clients", lean into visibility and pricing tier. Don't argue them up.

## Knowledge check

1. What are the three pillars of value?
2. Why end with "Which of those matters most to you?"
MD,
        ];
    }

    private function lessonObjectionNotEnough(): array
    {
        return [
            'slug' => 'not-getting-enough-clients',
            'title' => '"I\'m not getting enough clients"',
            'duration' => 11,
            'playbook_url' => 'https://exoticonline.mintlify.app/sales/objections/not-enough-clients',
            'quick_reference' => "ORDER:\n1. Diagnose profile completeness — don't discount.\n2. Check views/enquiries data in CRM.\n3. If profile is weak: fix it, renew on same tier.\n4. If profile is strong: propose tier upgrade with rationale.\n5. NEVER offer a discount as first response.",
            'body' => <<<'MD'
## The objection

*"I haven't been getting enough enquiries."*

This is the most common renewal objection and the one most agents botch.

## The diagnose-don't-discount move

:::script First response — pull up data, don't apologise
"Let me check your profile right now and see what the data looks like."
*[Open client record. Check views, enquiries, completeness, last update date.]*
:::

Now respond based on what you actually find:

### If the profile is incomplete or stale
:::script
"I can see [specific gap — only 3 photos / no update in 6 weeks / no contact line]. The clients getting the most enquiries on your tier have 6+ photos and refresh weekly. Let's fix [specific gap] now and renew on the same tier — I'd bet you'll see different numbers next month."
:::

### If the profile is strong but views are low
:::script
"Your profile is in great shape — photos, description, all updated. What's happening is you're maxing out what Featured can deliver in your market. Premium pins you to the homepage and gets you to the next-tier customer. Want to step up for the next cycle?"
:::

### If the city is saturated
Be honest. *"Nairobi is competitive right now. You're doing the right things on your end. Premium would help; Basic would be a step backwards. Let's stay where you are and revisit if the numbers don't shift in 30 days."*

## What NOT to do

:::warning
- **Don't offer a discount as the first move.** It tells the customer that the platform price isn't worth it — which is the opposite of what you want them to believe.
- **Don't promise more clients next month.** Promise to fix specific gaps; outcomes follow.
- **Don't blame the customer.** Even if the profile is genuinely weak, frame it as fixable, not as their failure.
:::

## Knowledge check

1. What's the first thing you do — before responding — when you hear this objection?
2. When do you propose a tier upgrade vs. fix-and-renew?
3. Why is "I'll give you 20% off" the wrong first move?
MD,
        ];
    }

    private function lessonObjectionBreak(): array
    {
        return [
            'slug' => 'taking-a-break',
            'title' => '"I\'m taking a break"',
            'duration' => 8,
            'playbook_url' => 'https://exoticonline.mintlify.app/sales/objections/taking-a-break',
            'quick_reference' => "TWO MOVES:\n1. Reassure: profile/data preserved, not deleted.\n2. Book the comeback: set a follow-up date in the CRM.\n\nGOAL: keep the option open AND earn the next call.",
            'body' => <<<'MD'
## The objection

*"I'm taking some time off — I'll come back later."*

## The response

:::script
"Got it, that makes sense. Two quick things: first, your profile and data stay with us — nothing is deleted, so when you're back, you log straight in. Second, want me to set you up with a renewal reminder for [specific date 30/60/90 days out]? I'll be the one calling you back."
:::

## Why it works

- **Reassurance about preserved data** removes the silent worry that "if I don't renew, everything I built is gone".
- **Booking a specific callback** with a specific date earns the right to the next conversation. Vague "we'll be in touch" calls never connect.
- **"I'll be the one calling you back"** personalises the follow-up, which dramatically increases pickup rate.

## Set the callback in the CRM before you hang up

Open the Lead/Client record → set Next Action → pick the date → add yourself as owner. If you don't do this in real time, it won't happen.

## Knowledge check

1. What's the silent worry behind "I'm taking a break"?
2. Why does setting a specific date matter?
MD,
        ];
    }

    private function lessonObjectionRenewEarly(): array
    {
        return [
            'slug' => 'why-renew-early',
            'title' => '"Why renew early?"',
            'duration' => 7,
            'playbook_url' => 'https://exoticonline.mintlify.app/sales/objections/early-renewal',
            'quick_reference' => "EARLY RENEWAL VALUE:\n- Continuity (no gap in visibility)\n- Possible retention pricing\n- Locks in current tier price before adjustments\n\nDON'T pressure unless within 7 days of expiry — earlier feels predatory.",
            'body' => <<<'MD'
## The objection

*"Why are you calling about renewal — I still have time."*

## The response

:::script
"You're right — you've got [X] days left. The reason I'm calling early is so you don't end up with a gap in your listing. If your renewal slips past expiry by even a day, the algorithm has to re-rank you, and you lose some of the visibility you've built. Renewing today keeps everything continuous. Same tier, same price — want to lock it in now?"
:::

## When to use this — and when not to

:::tip Timing matters
This objection only deserves a hard reframe if the customer is within **7 days of expiry**. Earlier than that, accept it gracefully: *"Totally fair — let me put a reminder in to circle back closer to your renewal date."*

Calling 20 days early and pushing for "continuity" feels predatory and damages the relationship.
:::

## Knowledge check

1. What's the genuine business reason for early renewal?
2. When does pushing for early renewal hurt the relationship?
MD,
        ];
    }

    private function lessonObjectionDiscount(): array
    {
        return [
            'slug' => 'discount-asks',
            'title' => '"Give me a discount"',
            'duration' => 10,
            'playbook_url' => 'https://exoticonline.mintlify.app/sales/objections/discounts',
            'quick_reference' => "DISCOUNT FRAMEWORK:\n1. Check market threshold (Settings → Discounts).\n2. Offer your max authority with specific number.\n3. Frame as choice: 'X% now vs. waiting for supervisor approval'.\n4. NEVER stack discounts cycle-on-cycle.",
            'body' => <<<'MD'
## The objection

*"I'll renew if you give me 30% off."*

## The response

:::script
"I hear you. Let me check what I can do."
*[Open Settings → Discounts → check market threshold.]*
"I can offer [X%] within my authority — that's a [specific value] saving. Beyond that I'd need supervisor approval, which adds a day. Want to renew at [X%] right now and stay live, or wait for the approval?"
:::

## Why this works

- **"Let me check what I can do"** earns time and signals genuine effort.
- **A specific percentage with a specific value** feels concrete and authoritative.
- **The choice between two yeses** (now or supervisor) avoids the "no" entirely.

## The hard rules

:::warning Discount discipline
- **Market threshold is set in Settings → Discounts.** Above it needs PIN.
- **No stacking.** If they got a discount last cycle, this cycle is full-price unless there's exceptional justification.
- **No verbal-only promises.** Every discount is logged on the deal record.
- **Three discounted renewals in a row** triggers account review — this is the platform protecting itself.
:::

## When to walk away from the discount

If the customer is demanding 40% and you can only offer 10%, do not invent flexibility. Say:

:::script Honest no
"I genuinely cannot get to 40% — that's outside what we do. The most I can do is [X%]. If that doesn't work for you, I respect that, and your profile data stays with us if you want to come back later."
:::

A clean "no" preserves the relationship better than a desperate "let me see what I can do" that returns with the same number.

## Knowledge check

1. Where do you find the discount threshold for a given market?
2. What's the structure of the "choice between two yeses"?
3. Why does stacking discounts hurt long-term retention?
MD,
        ];
    }

    // ============================================================
    // Course: Failure Recovery Deep Dive (F1-F10)
    // ============================================================

    private function courseFailureRecovery(): array
    {
        $failures = $this->failureLessons();
        return [
            'slug' => 'failure-recovery-deep-dive',
            'title' => 'Failure Recovery Deep Dive',
            'summary' => 'Every documented failure mode (F1 through F10), with the symptom, diagnosis, recovery dialog, and the literal CRM clicks to fix it. The course every CS-leaning agent should know cold.',
            'difficulty' => 'intermediate',
            'accent_color' => 'rose',
            'order' => 4,
            'learning_outcomes' => [
                'Diagnose any of the 10 documented failure modes from a 30-second customer description',
                'Run the recovery dialog verbatim and resolve in under 5 minutes',
                'Know exactly when to escalate vs. when to fix in-line',
            ],
            'modules' => array_map(function ($f, $i) {
                return [
                    'slug' => 'f' . ($i + 1) . '-' . Str::slug($f['slug_part']),
                    'title' => 'F' . ($i + 1) . ': ' . $f['title'],
                    'summary' => $f['symptom'],
                    'lessons' => [[
                        'slug' => 'f' . ($i + 1) . '-' . Str::slug($f['slug_part']) . '-guide',
                        'title' => 'F' . ($i + 1) . ': ' . $f['title'],
                        'duration' => 8,
                        'playbook_url' => $f['playbook_url'] ?? null,
                        'quick_reference' => $f['quick_ref'],
                        'body' => $f['body'],
                    ]],
                ];
            }, $failures, array_keys($failures)),
        ];
    }

    private function failureLessons(): array
    {
        return [
            [
                'slug_part' => 'paid-but-not-live',
                'title' => '"I paid but my profile isn\'t live"',
                'symptom' => 'Customer paid; profile remains inactive.',
                'playbook_url' => 'https://exoticonline.mintlify.app/payments/failures/f1',
                'quick_ref' => "DIAGNOSE:\n1. Payment record exists? → check Payments → search phone\n2. Match confidence?\n3. Deal record created?\n4. Activation event fired?",
                'body' => <<<'MD'
## F1: Paid but not live

### Symptom
Customer says their payment went through but their profile is still showing as inactive.

### Diagnostic tree
1. **Does a payment record exist?** Open CRM → Payments → search by phone. If no record, the payment never reached us — ask for the M-Pesa confirmation message and check the recipient short code.
2. **What's the match confidence?** If low, the payment landed but couldn't be auto-linked to a deal. Confirm the match manually.
3. **Was a deal record created?** If the match worked but no deal was created, run "Create subscription from payment".
4. **Did activation fire?** If the deal exists but profile is inactive, trigger manual activation.

### Recovery dialog
:::script
"I can see your payment — it landed at [time] but our auto-match flagged it for review because [reason]. I'm confirming the match now and your profile will be live in 2 minutes. Stay on the line while I do this."
:::

### Escalate if
Payment record doesn't exist after 30 minutes — webhook may be down (see F6).
MD,
            ],
            [
                'slug_part' => 'stk-timeout',
                'title' => 'STK Push timeout / no prompt received',
                'symptom' => 'Customer never received the STK Push prompt.',
                'playbook_url' => 'https://exoticonline.mintlify.app/payments/failures/f2',
                'quick_ref' => "CAUSES:\n- Wrong phone number\n- M-Pesa prompts blocked (dial *334#)\n- SIM not M-Pesa enabled\n- Carrier delay\n\nFALLBACK: send hosted checkout link.",
                'body' => <<<'MD'
## F2: STK Push timeout

### Symptom
Customer was expecting the M-Pesa prompt but it never arrived, or arrived too late and they missed it.

### Diagnostic checklist
1. **Confirm the phone number** character-by-character. Off-by-one digits are the #1 cause.
2. **Ask if M-Pesa prompts are enabled.** Safaricom blocks prompts by default in some setups — dial *334# → My Account → Stop Bonga / Resume Prompts.
3. **Check whether their SIM is M-Pesa registered.** Non-registered SIMs cannot receive STK Push.
4. **Carrier delay.** Sometimes prompts take 30–60 seconds in Kenya. Don't retry immediately.

### Recovery dialog
:::script
"Let me confirm your number: [read it back digit by digit]. Have you ever gotten an M-Pesa prompt from anywhere else recently? If not, dial *334# and check that prompts are enabled. If that doesn't work, I'll send you a payment link you can click in WhatsApp instead — takes 30 seconds."
:::

### Fallback
Send hosted checkout link from CRM → Send Payment Link.

### Escalate if
Multiple customers simultaneously report no STK arriving → likely an M-Pesa outage. Verify with finance.
MD,
            ],
            [
                'slug_part' => 'amount-mismatch',
                'title' => 'Payment amount mismatch',
                'symptom' => 'Amount paid does not match package price.',
                'playbook_url' => 'https://exoticonline.mintlify.app/payments/failures/f3',
                'quick_ref' => "TOLERANCE: ±5%\nIF UNDER tolerance: auto-match, confirm with note\nIF OVER tolerance: manual decision — top-up, refund difference, or split.\nNEVER silently activate a different package.",
                'body' => <<<'MD'
## F3: Amount mismatch

### Symptom
Customer paid Ksh 2,500 but the package is Ksh 3,000 (or paid Ksh 3,500 for a Ksh 3,000 package).

### The ±5% rule
The CRM's auto-matcher allows ±5% drift to handle rounding. Outside that, the payment goes to manual review.

### Diagnostic + decision tree
- **Under by less than 5%.** Auto-match should handle it. Confirm and add a note "rounding tolerance".
- **Under by more than 5%.** Open the payment, contact the customer. Options: (1) they top up the difference, (2) you downgrade them to a cheaper package they can afford, (3) refund and restart.
- **Over by any amount.** Surplus goes to wallet by default. Confirm with customer: *"You paid X more than the package — I've credited it to your wallet for next renewal. Sound good?"*

### Recovery dialog (under-payment)
:::script
"I can see your payment, but it's [amount] short of the [package] price. Easiest fix: send the difference now via M-Pesa and I'll activate immediately. Alternative: I can move you to [cheaper tier] and activate that today. Which works better?"
:::

### What NEVER to do
:::warning
**Do not silently activate a cheaper package** without telling the customer. They will call back next month complaining about visibility and you'll have lost trust.
:::
MD,
            ],
            [
                'slug_part' => 'wrong-package',
                'title' => 'Wrong package activated',
                'symptom' => 'Profile activated with the wrong package tier.',
                'playbook_url' => 'https://exoticonline.mintlify.app/payments/failures/f4',
                'quick_ref' => "CAUSE: usually a deal record created with wrong package ID, or a payment matched to the wrong deal.\n\nFIX:\n1. Open Deal → Update Package\n2. Resync to WordPress\n3. Adjust expiry if the new tier has different duration",
                'body' => <<<'MD'
## F4: Wrong package activated

### Symptom
"I paid for Premium but my profile shows Featured."

### Diagnostic
Open the client's deals tab. The deal record will show which package was activated. Usually one of:
- Wrong package picked at deal creation time (agent error).
- Wallet auto-renewal applied the previous tier instead of the upgraded one.

### Fix
1. Open the deal → Update Package → select correct tier.
2. Confirm expiry date is correct (some tiers have different durations).
3. Resync to WordPress — this propagates the change to the live profile.
4. Verify on the live site that the new tier is showing.

### Recovery dialog
:::script
"You're right, I see Featured on your record but you paid for Premium. I'm correcting that now — your profile will reflect Premium within 5 minutes. I apologise for the mix-up."
:::

### Audit
Log the correction. If it was your error, own it. If it was system-driven, file a bug.
MD,
            ],
            [
                'slug_part' => 'credentials-not-received',
                'title' => 'Credentials not received',
                'symptom' => 'Customer never received their login credentials.',
                'playbook_url' => 'https://exoticonline.mintlify.app/clients/credentials',
                'quick_ref' => "CHECK:\n1. Client → Credential Dispatches tab\n2. Status of last dispatch (sent / failed / bounced)\n3. Channel (email / SMS / WhatsApp)\n\nFIX:\n- Resend via different channel\n- Manually share via WhatsApp\n- Reset password if needed",
                'body' => <<<'MD'
## F5: Credentials not received

### Symptom
"I never got my username and password."

### Diagnostic
Client record → Credential Dispatches tab. You'll see:
- Channel used (email / SMS / WhatsApp).
- Status (sent / failed / bounced).
- Timestamp of last attempt.

### Recovery
1. If status is **bounced**, the contact info is wrong — collect a working channel and resend.
2. If status is **sent** but customer says nothing arrived, try a different channel (often email lands in spam — WhatsApp is more reliable).
3. If multiple resends have failed, reset the password and share the new one manually over a verified channel.

### Recovery dialog
:::script
"Let me check what we sent and where... I see we sent to [channel] at [time]. Let me resend via WhatsApp now — what's the best WhatsApp number for you?"
:::
MD,
            ],
            [
                'slug_part' => 'webhook-backlog',
                'title' => 'Webhook backlog (multi-customer)',
                'symptom' => 'Many customers simultaneously reporting payment-not-recognised.',
                'playbook_url' => 'https://exoticonline.mintlify.app/payments/failures/f6',
                'quick_ref' => "DIAGNOSTIC:\n1. Settings → Webhook Logs — recent failures?\n2. Payments queue showing many unmatched in last hour?\n\nIF YES: escalate to engineering immediately.\nWHILE WAITING: triage by amount/recency, manually reconcile high-value customers first.",
                'body' => <<<'MD'
## F6: Webhook backlog

### Symptom
Two or more customers in the same hour all say "I paid but it's not showing". This is NOT an F1 — it's a system event.

### How to spot it fast
- **Settings → Webhook Logs** shows recent webhook failures.
- **Payments queue** has unusually many unmatched payments in the last 1–2 hours.

### Action
1. **Escalate to engineering immediately.** Do not waste 30 minutes trying to fix individual payments while the pipeline is broken.
2. **Triage during the wait.** Manually reconcile high-value customers (Premium+) first.
3. **Communicate to affected customers.** *"There's a temporary system delay — your payment is recorded and we're activating manually in batches. Your profile will be live within the hour."*

### Escalation criteria
Any time you see >5 unmatched payments in 60 minutes from the same payment provider, this is F6 until proven otherwise.

### Do NOT
:::warning
**Do not retry STK pushes** while the webhook backlog is happening — you'll create duplicate payments (F9). Wait for engineering to confirm the pipeline is processing again.
:::
MD,
            ],
            [
                'slug_part' => 'activated-but-not-visible',
                'title' => 'Activated but not visible',
                'symptom' => 'Deal shows active but the profile isn\'t showing on the site.',
                'playbook_url' => 'https://exoticonline.mintlify.app/clients/visibility',
                'quick_ref' => "CHECK:\n1. Live URL — is the profile actually missing?\n2. Verification status\n3. Profile completeness (sometimes hides incomplete profiles)\n4. WordPress sync status",
                'body' => <<<'MD'
## F7: Activated but not visible

### Symptom
CRM shows the deal as active but the profile is not appearing on the website.

### Diagnostic
1. **Check the live URL directly.** Sometimes the customer is looking at a cached version.
2. **Verification status.** Unverified profiles are hidden in some markets.
3. **Profile completeness.** Profiles below a completeness threshold are auto-hidden.
4. **WordPress sync.** Run "Resync to WP" from the client record.

### Recovery
- If verification is missing, walk the customer through the verification flow.
- If completeness is the issue, identify the missing fields and request them.
- If sync failed, retry. If sync keeps failing, escalate.

### Dialog
:::script
"Let me check why your profile isn't showing. *[Check verification.]* It looks like your ID verification hasn't been approved yet. Let me check our queue and get that fast-tracked — most reviews happen within 2 hours."
:::
MD,
            ],
            [
                'slug_part' => 'manual-proof-not-approved',
                'title' => 'Manual payment proof submitted but not approved',
                'symptom' => 'Customer uploaded payment proof; still pending.',
                'playbook_url' => 'https://exoticonline.mintlify.app/payments/failures/f8',
                'quick_ref' => "MANUAL PROOFS sit in: Payments → Manual Submissions\n\nReview, approve, verify against bank record.\n\nApproving = activating the customer — be sure.",
                'body' => <<<'MD'
## F8: Manual proof pending

### Symptom
Customer paid via bank transfer or cash, uploaded proof, but the activation hasn't happened.

### Diagnostic
Payments → Manual Submissions queue. Find the submission.

### Recovery
1. **Verify the proof** against the bank statement (Finance owns reconciliation; you confirm with them if you don't have direct access).
2. **Approve** the manual submission in the CRM.
3. Activation should fire automatically once approved.

### Dialog
:::script
"I can see your proof in our manual queue. Let me get it verified now — usually takes 5 minutes. Stay on the line."
:::

### When to push back
If the customer provides a screenshot that's clearly edited or doesn't match the package price, escalate to a supervisor. Don't approve under pressure.
MD,
            ],
            [
                'slug_part' => 'duplicate-payment',
                'title' => 'Duplicate payment',
                'symptom' => 'Customer paid twice for the same package.',
                'playbook_url' => 'https://exoticonline.mintlify.app/payments/failures/f9',
                'quick_ref' => "OPTIONS:\n1. Credit duplicate to wallet (preferred)\n2. Refund duplicate via original payment channel\n3. Apply duplicate to next month's renewal\n\nALWAYS confirm choice with customer before acting.",
                'body' => <<<'MD'
## F9: Duplicate payment

### Symptom
Two payments visible for the same customer, same amount, within minutes of each other.

### Cause
Usually the customer retried the STK after a delay, not realising the first one had succeeded. Sometimes a webhook delay made the first payment appear to fail.

### Recovery options (pick with customer)
1. **Wallet credit.** Add the duplicate amount to the customer's wallet for future use. Cleanest option.
2. **Refund.** Refund the duplicate via the original channel (M-Pesa reversal, Paystack refund, etc.).
3. **Apply to next renewal.** Push the next renewal date forward by one cycle.

### Dialog
:::script
"I can see you paid twice — easy fix. Three options: I can credit the second payment to your wallet for next time, refund it back to your M-Pesa, or apply it as next month's renewal so you don't have to think about it. Which works best?"
:::

### Don't
:::warning
Don't silently keep the duplicate. The customer will notice when they reconcile their M-Pesa statement and you'll have a refund-and-trust problem.
:::
MD,
            ],
            [
                'slug_part' => 'renewal-wrong-subscription',
                'title' => 'Renewal applied to wrong subscription',
                'symptom' => 'Customer has multiple subscriptions; renewal went to the wrong one.',
                'playbook_url' => 'https://exoticonline.mintlify.app/payments/failures/f10',
                'quick_ref' => "RARE BUT HIGH-STAKES.\n\nCAUSE: customer with multiple deals; payment matched by phone, not by deal.\n\nFIX: re-allocate the payment to the correct deal, adjust expiry on both.",
                'body' => <<<'MD'
## F10: Renewal on wrong subscription

### Symptom
Customer has two profiles or two deals; the renewal payment got applied to the wrong one.

### Diagnostic
1. Client record → Deals tab — confirm multiple active deals exist.
2. Find the recent payment and which deal it linked to.

### Fix
1. Re-allocate the payment to the correct deal.
2. Adjust expiry dates: extend the correct deal, retract the wrongly-extended one.
3. Resync to WordPress so both profiles reflect their actual state.

### Dialog
:::script
"You're right — that payment landed on the wrong subscription. I'm reallocating it now and adjusting the expiry on both profiles. Give me 3 minutes."
:::

### Prevent recurrence
When confirming renewal for a customer with multiple deals, **always state which deal you're renewing** by name (e.g., "renewing the Nairobi Premium profile, not the Mombasa Basic one"). The auto-matcher cannot tell them apart.
MD,
            ],
        ];
    }

    // ============================================================
    // Certification questions (25 total)
    // ============================================================

    private function certQuestions(): array
    {
        return [
            // Discovery (5)
            ['kind' => 'mcq', 'topic' => 'Discover',
                'prompt' => 'Which three pieces of information MUST be logged in the CRM before ending a discovery call?',
                'options' => [
                    'City/country, current listing status, and a specific next action with a date',
                    'Full name, age, and email address',
                    'Preferred package, payment method, and currency',
                    'Social media handles, ID number, and physical address',
                ],
                'correct' => 0,
                'explanation' => 'Discovery is about earning the next conversation. Without market, listing-status, and a dated next action, the lead is essentially anonymous to the next agent.',
            ],
            ['kind' => 'scenario', 'topic' => 'Discover',
                'scenario' => "An inbound lead from Nairobi says: \"I'm already on Competitor X — why should I switch to you?\"",
                'prompt' => 'What is the strongest response?',
                'options' => [
                    '"Competitor X is overpriced — we cost less."',
                    '"You don\'t have to switch. A lot of our most successful clients cross-list because we attract higher-quality enquiries. Open to trying us alongside?"',
                    '"They\'re not as good as us — trust me."',
                    '"OK, when you\'re unhappy with them, call us back."',
                ],
                'correct' => 1,
                'explanation' => 'Reframing from either/or to both/and validates their existing choice and lowers the commitment ask. Lead-quality is the value we offer that they don\'t already have.',
            ],
            ['kind' => 'mcq', 'topic' => 'Discover',
                'prompt' => 'Which of these is a disqualifier on a discovery call?',
                'options' => [
                    'Lead is currently working in another city',
                    'Lead asks about pricing in the first minute',
                    'Lead refuses age/ID verification',
                    'Lead is on a competitor platform already',
                ],
                'correct' => 2,
                'explanation' => 'Refusal to verify is a non-negotiable disqualifier. The other items are normal discovery-call signals, not disqualifiers.',
            ],
            ['kind' => 'mcq', 'topic' => 'Discover',
                'prompt' => 'What is the recommended order of the three qualifying questions?',
                'options' => [
                    'Urgency → competitor → market',
                    'Market → competitor → urgency',
                    'Competitor → urgency → market',
                    'Market → urgency → competitor',
                ],
                'correct' => 1,
                'explanation' => 'Least-personal to most-personal. Market is procedural and earns honesty; urgency is the most loaded and benefits from the trust built by the first two questions.',
            ],
            ['kind' => 'scenario', 'topic' => 'Discover',
                'scenario' => 'A lead says she is "just exploring" and not actively looking. She has a strong portfolio.',
                'prompt' => 'What is the right next step?',
                'options' => [
                    'Push her to commit to a package today',
                    'Move her to nurture and set a specific callback date',
                    'Mark the lead as lost and move on',
                    'Offer her a free trial without supervisor approval',
                ],
                'correct' => 1,
                'explanation' => 'Exploratory leads convert with patience, not pressure. Setting a specific callback earns the right to the next conversation. Free trials always require supervisor PIN.',
            ],
            // Subscription Selection (5)
            ['kind' => 'mcq', 'topic' => 'Subscription',
                'prompt' => 'What is the single question most agents forget to ask before recommending a package tier?',
                'options' => [
                    '"What is your budget?"',
                    '"Are you optimising for more bookings or for better-paying bookings?"',
                    '"What city are you in?"',
                    '"Have you used a paid platform before?"',
                ],
                'correct' => 1,
                'explanation' => 'The "more vs better" question changes the recommendation by a tier in either direction. It is the highest-leverage question in subscription selection.',
            ],
            ['kind' => 'scenario', 'topic' => 'Subscription',
                'scenario' => 'Sarah, Lagos, NGN market. Worked 18 months on a competitor, took a 3-month break, returning now. Strong portfolio.',
                'prompt' => 'Which package tier should you recommend?',
                'options' => [
                    'Basic — let her test the platform cheaply',
                    'Featured — safest default',
                    'Premium — strong portfolio + need to re-establish visibility after break',
                    'VVIP — top of page placement',
                ],
                'correct' => 2,
                'explanation' => 'After a break, the algorithm has to re-rank her. Premium gets her visible in 48 hours. Basic would waste her portfolio; VVIP is over-spending.',
            ],
            ['kind' => 'mcq', 'topic' => 'Subscription',
                'prompt' => 'Who can authorise a free trial?',
                'options' => [
                    'Any sales agent at their discretion',
                    'Any sales agent for first-time customers only',
                    'A supervisor via PIN approval',
                    'The customer, by requesting in writing',
                ],
                'correct' => 2,
                'explanation' => 'Free trials require supervisor PIN. No agent can grant one on their own authority. Every free trial is logged and audited monthly.',
            ],
            ['kind' => 'mcq', 'topic' => 'Subscription',
                'prompt' => 'A customer in Côte d\'Ivoire is being quoted "CFA 25,000". What is the risk?',
                'options' => [
                    'No risk — CFA is a single currency',
                    'CFA is ambiguous; Côte d\'Ivoire uses XOF and the quote could be confused with XAF',
                    'CFA is too small a unit; should always be quoted in USD',
                    'Côte d\'Ivoire only accepts EUR',
                ],
                'correct' => 1,
                'explanation' => 'XOF (West African CFA) and XAF (Central African CFA) are different currencies despite both being called "CFA franc". The CRM stores the correct one — always quote with the explicit currency code when in doubt.',
            ],
            ['kind' => 'scenario', 'topic' => 'Subscription',
                'scenario' => 'A customer paid Ksh 2,500 by M-Pesa. The package is Ksh 3,000. Auto-match flags it "low confidence".',
                'prompt' => 'What is the correct first action?',
                'options' => [
                    'Silently activate Basic instead of the package they intended',
                    'Refund and ask them to retry',
                    'Contact the customer with two options: top up the Ksh 500, or move to a cheaper package',
                    'Ignore the mismatch — it\'s within rounding',
                ],
                'correct' => 2,
                'explanation' => 'A Ksh 500 shortfall on a Ksh 3,000 package is 16%, outside the ±5% rounding tolerance. Silent activation of a different package destroys trust. Refunding skips the chance to recover.',
            ],
            // Failures (5)
            ['kind' => 'scenario', 'topic' => 'Failures',
                'scenario' => 'You see 15 unmatched payments in the last 60 minutes, all from M-Pesa.',
                'prompt' => 'What is the most likely cause and what do you do?',
                'options' => [
                    'F1 — fix each one individually',
                    'F6 — webhook backlog. Escalate to engineering and triage high-value customers manually while waiting',
                    'F9 — duplicate payments. Refund all and ask customers to retry',
                    'Customer error — retry STK Push for each one',
                ],
                'correct' => 1,
                'explanation' => 'Multiple simultaneous unmatched payments from one provider is the signature of a webhook backlog (F6). Retrying STK during a backlog creates duplicates (F9). Always escalate F6 first.',
            ],
            ['kind' => 'mcq', 'topic' => 'Failures',
                'prompt' => 'The amount-mismatch auto-match tolerance is:',
                'options' => [
                    '±1%',
                    '±5%',
                    '±10%',
                    '±20%',
                ],
                'correct' => 1,
                'explanation' => '±5% covers rounding while preventing silent activation of cheaper tiers. Outside this, manual review is required.',
            ],
            ['kind' => 'scenario', 'topic' => 'Failures',
                'scenario' => 'A customer says: "I paid twice by accident — give me a refund."',
                'prompt' => 'What is the best initial response?',
                'options' => [
                    'Refund immediately on the second payment',
                    'Tell them tough luck — they should have been more careful',
                    'Offer three options: wallet credit, refund, or apply to next renewal — let them choose',
                    'Silently keep the second payment',
                ],
                'correct' => 2,
                'explanation' => 'Customer choice respects their preference and often retains revenue (wallet credit, applied renewal). Silent retention is a trust-killer. Refusal damages relationship.',
            ],
            ['kind' => 'mcq', 'topic' => 'Failures',
                'prompt' => 'What is the FIRST action when a customer reports a payment problem?',
                'options' => [
                    'Ask them to repeat the M-Pesa code',
                    'Apologise and promise a callback',
                    'Open CRM → Payments → search by phone or reference, and read the diagnostic field before asking',
                    'Tell them to check with their bank',
                ],
                'correct' => 2,
                'explanation' => 'The universal first-response protocol: acknowledge specifically, pull up the record, read the diagnostic, state the next step. Never make the customer repeat themselves.',
            ],
            ['kind' => 'scenario', 'topic' => 'Failures',
                'scenario' => 'STK Push isn\'t arriving for a customer in Nairobi. You\'ve confirmed the phone number is correct twice.',
                'prompt' => 'What is the next diagnostic step?',
                'options' => [
                    'Retry STK 5 more times',
                    'Ask if M-Pesa prompts are enabled (dial *334#) and check if the SIM is M-Pesa registered',
                    'Refund and ask them to try a different bank',
                    'Tell them STK is broken across the country',
                ],
                'correct' => 1,
                'explanation' => 'Common causes (after wrong number) are blocked prompts and non-M-Pesa SIMs. Repeated retries often trigger anti-fraud blocks.',
            ],
            // Renewals (5)
            ['kind' => 'mcq', 'topic' => 'Renewals',
                'prompt' => 'Why is the renewal opener a STATEMENT and not a question?',
                'options' => [
                    'Because questions waste time',
                    'Because a statement makes "yes, renew" the default action — they have to actively opt out',
                    'Because customers prefer monologues',
                    'Because compliance requires statements',
                ],
                'correct' => 1,
                'explanation' => 'Default-yes framing dramatically outperforms "are you interested in renewing?" which invites a no.',
            ],
            ['kind' => 'scenario', 'topic' => 'Renewals',
                'scenario' => 'A client says "I\'m not getting enough clients."',
                'prompt' => 'What is the correct first move?',
                'options' => [
                    'Offer a 20% discount immediately',
                    'Pull up the profile and check completeness, views, and last update — diagnose before discounting',
                    'Promise more clients next month',
                    'Suggest they cancel and try later',
                ],
                'correct' => 1,
                'explanation' => 'Diagnose, don\'t discount. Offering a discount before checking the profile tells the customer the platform price isn\'t worth it — the opposite of what you want.',
            ],
            ['kind' => 'mcq', 'topic' => 'Renewals',
                'prompt' => 'At what point in the retention timeline does a customer become "lapsed" and should be handed to the reactivation queue?',
                'options' => [
                    'Day of expiry',
                    '7 days expired',
                    '30+ days expired',
                    'Never — sales always owns them',
                ],
                'correct' => 2,
                'explanation' => 'Lapsed = 30+ days expired. Reactivation requires a different conversation (empathy, fresh value-prop) than a renewal call.',
            ],
            ['kind' => 'scenario', 'topic' => 'Renewals',
                'scenario' => 'A client demands 40% discount on renewal. Market threshold is 15%.',
                'prompt' => 'What is the right response?',
                'options' => [
                    'Promise 40% to keep them, plan to "get approval later"',
                    'Refuse coldly and end the call',
                    'Offer the max you can authorise (15%) with specific value, and frame the choice as "renew at 15% now vs. wait for supervisor"',
                    'Stack a 15% discount on top of last month\'s 15% to make 30%',
                ],
                'correct' => 2,
                'explanation' => 'Offer your authority, name the number, give a clean choice. Never promise above threshold without PIN. Never stack discounts cycle-on-cycle.',
            ],
            ['kind' => 'mcq', 'topic' => 'Renewals',
                'prompt' => 'A customer says "I\'m taking a break". What is the highest-leverage move?',
                'options' => [
                    'Talk them out of the break',
                    'Reassure them their data is preserved AND book a specific callback date in the CRM',
                    'Cancel their account immediately',
                    'Give them 50% off to stay',
                ],
                'correct' => 1,
                'explanation' => 'Preserving the data removes the silent worry. Booking a specific callback earns the next conversation.',
            ],
            // Product Knowledge (5)
            ['kind' => 'mcq', 'topic' => 'Product',
                'prompt' => 'Which of the following is something Exotic Online does NOT sell?',
                'options' => [
                    'Profile visibility',
                    'Higher-quality enquiries via a paid platform',
                    'Account support',
                    'A guaranteed number of bookings per week',
                ],
                'correct' => 3,
                'explanation' => 'We sell visibility, lead quality, and support — not outcomes. Promising specific booking numbers is a trust risk and a refund risk.',
            ],
            ['kind' => 'mcq', 'topic' => 'Product',
                'prompt' => 'What is the #1 driver of visibility on the platform?',
                'options' => [
                    'Subscription tier',
                    'Daily logins',
                    'Owning multiple profiles',
                    'Asking the agent to "boost" the profile',
                ],
                'correct' => 0,
                'explanation' => 'Tier is the biggest lever. Daily logins do not affect ranking; multiple profiles get duplicate-flagged; manual boost is not a feature.',
            ],
            ['kind' => 'mcq', 'topic' => 'Product',
                'prompt' => 'Which payment path is the primary channel for Kenya?',
                'options' => [
                    'Paystack',
                    'Pesapal hosted checkout',
                    'M-Pesa STK Push',
                    'Bank wire transfer',
                ],
                'correct' => 2,
                'explanation' => 'M-Pesa STK is the primary path in Kenya. Pesapal and KopoKopo are fallbacks.',
            ],
            ['kind' => 'mcq', 'topic' => 'Product',
                'prompt' => 'What does "lapsed" mean in the retention taxonomy?',
                'options' => [
                    'Customer paid late this cycle',
                    'Customer\'s subscription is 30+ days expired',
                    'Customer requested a refund',
                    'Customer is on a free trial',
                ],
                'correct' => 1,
                'explanation' => '30+ days expired = lapsed. Lapsed customers go to the reactivation queue, not the renewal flow.',
            ],
            ['kind' => 'scenario', 'topic' => 'Product',
                'scenario' => 'A customer asks "if I let my subscription expire, will my photos and profile data be deleted?"',
                'prompt' => 'What is the correct answer?',
                'options' => [
                    'Yes, immediately',
                    'Yes, after 7 days',
                    'No — profile and data are preserved; the customer logs back in when they return',
                    'Only if they specifically request deletion',
                ],
                'correct' => 2,
                'explanation' => 'Data preservation is a deliberate retention asset — it removes the silent fear behind "I\'m taking a break" and makes reactivation frictionless.',
            ],
        ];
    }

    // ============================================================
    // Glossary (~40 terms)
    // ============================================================

    private function glossary(): array
    {
        return [
            ['term' => 'Basic', 'topic' => 'Packages', 'definition' => 'Lowest-tier subscription. Profile is listed within its category with no homepage exposure. Best for cautious first-timers.'],
            ['term' => 'Featured', 'topic' => 'Packages', 'definition' => 'Mid-low tier subscription. Higher in category + occasional homepage rotation. The default "first paid" tier for new escorts.'],
            ['term' => 'Premium', 'topic' => 'Packages', 'definition' => 'Mid-high tier. Top of category, persistent homepage banner, Premium badge. Best for returning or established escorts.'],
            ['term' => 'VIP', 'topic' => 'Packages', 'definition' => 'High tier. Permanent top placement and VIP badge. For high-earners optimising for fewer but better bookings.'],
            ['term' => 'VVIP', 'topic' => 'Packages', 'definition' => 'Top tier. Hero placement on every relevant page, VVIP badge, slot-capped per city. Top 1% of clients.'],
            ['term' => 'STK Push', 'topic' => 'Payments', 'definition' => 'M-Pesa "SIM Toolkit" Push — initiates a payment prompt directly on the customer\'s phone. Primary payment path in Kenya.', 'aliases' => ['M-Pesa Push']],
            ['term' => 'Webhook', 'topic' => 'Payments', 'definition' => 'A server-to-server callback that notifies the CRM when a payment succeeds. When webhooks fail in bulk, see Failure F6.'],
            ['term' => 'Free Trial', 'topic' => 'Sales', 'definition' => 'Short-term complimentary subscription, granted only via supervisor PIN. One per phone number ever. All free trials are logged and audited monthly.'],
            ['term' => 'Lapsed Escort', 'topic' => 'Retention', 'definition' => 'A customer whose subscription has been expired 30+ days. Handled by the reactivation queue, not the renewal flow.'],
            ['term' => 'Retention Watch', 'topic' => 'Retention', 'definition' => 'CRM panel that surfaces at-risk subscriptions colour-coded by proximity to expiry: green (pre-expiry), amber (0-7 days expired), red (8-29 days), lapsed (30+).'],
            ['term' => 'Deal', 'topic' => 'CRM', 'definition' => 'A subscription record in the CRM. Stores tier, expiry, payment reference, and renewal history. One deal per active subscription.'],
            ['term' => 'Subscription', 'topic' => 'CRM', 'definition' => 'The customer-facing name for what the CRM calls a "deal". The active period during which a profile is visible on the platform.'],
            ['term' => 'Renewal Window', 'topic' => 'Retention', 'definition' => 'The period 3 days before expiry through 7 days after, during which a standard renewal call should happen.'],
            ['term' => 'Manual Payment', 'topic' => 'Payments', 'definition' => 'A payment made outside the automated flow (bank transfer, cash) with proof uploaded by the customer for manual approval.'],
            ['term' => 'Auto-match', 'topic' => 'Payments', 'definition' => 'The CRM\'s logic that links incoming payments to deals by phone, reference, and amount. Allows ±5% rounding tolerance.'],
            ['term' => 'Match Confidence', 'topic' => 'Payments', 'definition' => 'Score on each incoming payment estimating how confidently the CRM linked it to a deal. Low confidence flags the payment for manual review.'],
            ['term' => 'Wallet', 'topic' => 'Payments', 'definition' => 'Customer-side pre-funded balance used for renewals and top-ups. Configured per market.'],
            ['term' => 'KopoKopo', 'topic' => 'Payments', 'definition' => 'Kenyan payment processor providing paybill/till alternatives to M-Pesa STK Push.'],
            ['term' => 'Paystack', 'topic' => 'Payments', 'definition' => 'Primary card/bank payment processor for Nigeria, Ghana, and South Africa.'],
            ['term' => 'Pesapal', 'topic' => 'Payments', 'definition' => 'Regional payment processor used as a fallback for hosted checkout when STK fails.'],
            ['term' => 'F1', 'topic' => 'Failures', 'definition' => '"Paid but not live" failure mode — customer paid but profile is still inactive. See Failure Recovery course.'],
            ['term' => 'F6', 'topic' => 'Failures', 'definition' => 'Webhook backlog — multiple unmatched payments in a short window. Always escalate to engineering before diagnosing individually.'],
            ['term' => 'F9', 'topic' => 'Failures', 'definition' => 'Duplicate payment — customer paid twice. Offer wallet credit, refund, or applied renewal as the three customer-choice options.'],
            ['term' => 'Activation', 'topic' => 'Lifecycle', 'definition' => 'Stage 6 of the customer lifecycle. The moment a paid profile goes live on the website.'],
            ['term' => 'Discover', 'topic' => 'Lifecycle', 'definition' => 'Stage 1 of the customer lifecycle. Lead enters via outbound, inbound, referral, or self-service.'],
            ['term' => 'Verification', 'topic' => 'Lifecycle', 'definition' => 'Stage 4. ID/age check required before activation. Refusal to verify is a non-negotiable disqualifier.'],
            ['term' => 'Cross-listing', 'topic' => 'Sales', 'definition' => 'A client listed on Exotic Online plus one or more competitor platforms. Often the most profitable customer segment.'],
            ['term' => 'Lead Quality', 'topic' => 'Sales', 'definition' => 'The value proposition that paying-customer platforms attract paying-customer users — filtering out time-wasters and tyre-kickers.'],
            ['term' => 'CFA Franc', 'topic' => 'Currency', 'definition' => 'Two distinct currencies share this informal name: XOF (West Africa) and XAF (Central Africa). They are not interchangeable. CRM stores the correct one per market.', 'aliases' => ['CFA']],
            ['term' => 'KES', 'topic' => 'Currency', 'definition' => 'Kenyan Shilling. Primary currency for Kenya market quotes.'],
            ['term' => 'NGN', 'topic' => 'Currency', 'definition' => 'Nigerian Naira. Primary currency for Nigeria market quotes.'],
            ['term' => 'GHS', 'topic' => 'Currency', 'definition' => 'Ghanaian Cedi. Primary currency for Ghana market quotes.'],
            ['term' => 'XOF', 'topic' => 'Currency', 'definition' => 'West African CFA Franc. Used by Côte d\'Ivoire, Senegal, Mali, and other UEMOA states.'],
            ['term' => 'XAF', 'topic' => 'Currency', 'definition' => 'Central African CFA Franc. Used by Cameroon, Chad, Gabon, and other CEMAC states.'],
            ['term' => 'Discount Threshold', 'topic' => 'Sales', 'definition' => 'Per-market cap on discounts that any agent can apply without supervisor approval. Configured in Settings → Discounts.'],
            ['term' => 'Supervisor PIN', 'topic' => 'Sales', 'definition' => 'Authentication required for above-threshold discounts, free trials, and other elevated actions. Issued to supervisors only.'],
            ['term' => 'Disposition', 'topic' => 'CRM', 'definition' => 'The recorded outcome of a call: Interested, Not Interested, Callback, Voicemail, Wrong Number, etc. Must be set before ending every call.'],
            ['term' => 'Profile Completeness', 'topic' => 'Product', 'definition' => 'A score on each profile based on photo count, description, contact info, and update recency. Below threshold = auto-hidden.'],
            ['term' => 'Reactivation Queue', 'topic' => 'Retention', 'definition' => 'Workflow for lapsed customers (30+ days expired). Different conversation script and goals from standard renewal.'],
            ['term' => 'Audit Log', 'topic' => 'CRM', 'definition' => 'Every state change (deal created, payment confirmed, discount applied, free trial granted) is recorded with the acting user. Reviewed monthly.'],
        ];
    }

    // ============================================================
    // Badges
    // ============================================================

    private function badges(): array
    {
        return [
            ['code' => 'first-lesson', 'title' => 'First Steps', 'description' => 'Completed your first University lesson.', 'icon' => 'sparkles', 'color' => 'teal', 'criteria_kind' => 'lessons_completed', 'criteria_config' => ['count' => 1], 'points' => 10],
            ['code' => 'product-master', 'title' => 'Product Master', 'description' => 'Completed every lesson in the Product Mastery course.', 'icon' => 'academic-cap', 'color' => 'indigo', 'criteria_kind' => 'course_completed', 'criteria_config' => ['course_slug' => 'product-mastery'], 'points' => 100],
            ['code' => 'objection-ace', 'title' => 'Objection Ace', 'description' => 'Completed every lesson in the Objection Handling Masterclass.', 'icon' => 'shield-check', 'color' => 'amber', 'criteria_kind' => 'course_completed', 'criteria_config' => ['course_slug' => 'objection-handling-masterclass'], 'points' => 100],
            ['code' => 'failure-expert', 'title' => 'Failure Expert', 'description' => 'Completed all 10 failure-recovery lessons (F1–F10).', 'icon' => 'wrench-screwdriver', 'color' => 'rose', 'criteria_kind' => 'course_completed', 'criteria_config' => ['course_slug' => 'failure-recovery-deep-dive'], 'points' => 150],
            ['code' => 'certified', 'title' => 'Certified', 'description' => 'Earned the Core Sales/CS Certification.', 'icon' => 'trophy', 'color' => 'emerald', 'criteria_kind' => 'certification_earned', 'criteria_config' => ['certification_slug' => 'core-sales-cs-certification'], 'points' => 500],
            ['code' => 'streak-7', 'title' => '7-Day Streak', 'description' => 'Completed the daily drill seven days in a row.', 'icon' => 'fire', 'color' => 'orange', 'criteria_kind' => 'streak_days', 'criteria_config' => ['days' => 7], 'points' => 70],
            ['code' => 'streak-30', 'title' => '30-Day Streak', 'description' => 'Completed the daily drill thirty days in a row. Legendary.', 'icon' => 'fire', 'color' => 'red', 'criteria_kind' => 'streak_days', 'criteria_config' => ['days' => 30], 'points' => 300],
        ];
    }

    // ============================================================
    // Daily Drills (~10)
    // ============================================================

    private function dailyDrills(): array
    {
        return [
            ['topic' => 'Discover', 'prompt' => 'Lead says: "I already list on Competitor X."', 'scenario' => 'You\'re on an outbound discovery call.', 'options' => ['Argue they\'re overpriced', 'Use the cross-listing reframe and lead with lead quality', 'End the call', 'Offer a discount'], 'correct' => 1, 'explanation' => 'Cross-listing reframe: "A lot of our most successful clients cross-list. We tend to attract higher-quality enquiries."'],
            ['topic' => 'Subscription', 'prompt' => 'Customer asks for a free trial.', 'options' => ['Grant it on the spot', 'Decline cold', 'Tell them you\'ll need supervisor PIN approval and ask why they need it', 'Offer a discount instead'], 'correct' => 2, 'explanation' => 'All free trials need supervisor PIN. Asking "why" helps the supervisor decide and qualifies the lead.'],
            ['topic' => 'Failures', 'prompt' => '5 customers in 30 minutes all say "I paid but profile not live".', 'options' => ['Fix each one individually for the next hour', 'Tell them all to try again', 'Recognise this as F6 webhook backlog — escalate to engineering immediately', 'Refund everyone'], 'correct' => 2, 'explanation' => 'Multiple simultaneous unmatched payments = F6 signature. Escalate first, triage second.'],
            ['topic' => 'Renewals', 'prompt' => 'Renewal call. Customer says "I\'m not getting enough clients."', 'options' => ['Offer 30% off immediately', 'Pull up their profile and diagnose completeness first', 'Tell them the platform is having a slow month', 'End the call'], 'correct' => 1, 'explanation' => 'Diagnose, don\'t discount. Check completeness, views, recency before offering anything.'],
            ['topic' => 'Failures', 'prompt' => 'Customer paid Ksh 2,500 for a Ksh 3,000 package. Auto-match low confidence.', 'options' => ['Silently activate Basic', 'Refund and end the conversation', 'Contact the customer: top-up Ksh 500 or move to cheaper tier', 'Ignore — it\'s within rounding'], 'correct' => 2, 'explanation' => '16% mismatch is outside ±5% rounding tolerance. Silent activation kills trust. Refund skips recovery chance.'],
            ['topic' => 'Product', 'prompt' => 'A prospect asks "if my subscription expires, do I lose my photos?"', 'options' => ['Yes, immediately', 'Yes after 7 days', 'No — profile and data are preserved indefinitely', 'Only if they ask for deletion'], 'correct' => 2, 'explanation' => 'Data preservation is a deliberate retention asset. Removes the silent fear behind "I\'m taking a break".'],
            ['topic' => 'Renewals', 'prompt' => 'Customer demands 40% off renewal. Market threshold is 15%.', 'options' => ['Promise 40%, plan to get approval later', 'Refuse coldly', 'Offer your max (15%) with specific value, frame as "now vs. wait for supervisor"', 'Stack discounts'], 'correct' => 2, 'explanation' => 'Offer authority + name the number + clean choice between two yeses.'],
            ['topic' => 'Discover', 'prompt' => 'Which question gets asked LAST in discovery?', 'options' => ['City/country', 'Listing on competitor?', 'Active vs. exploratory', 'Preferred payment method'], 'correct' => 2, 'explanation' => 'Urgency is the most loaded question — leave it for after trust is built by procedural questions.'],
            ['topic' => 'Failures', 'prompt' => 'Customer reports duplicate payment.', 'options' => ['Refund immediately', 'Keep it silently', 'Offer wallet credit / refund / apply to next renewal — customer choice', 'Tell them to take it up with their bank'], 'correct' => 2, 'explanation' => 'Three options respects customer preference and often retains revenue.'],
            ['topic' => 'Product', 'prompt' => 'A customer in Côte d\'Ivoire is being quoted "CFA 25,000". The risk is:', 'options' => ['No risk', 'CFA is ambiguous — XOF (Côte d\'Ivoire) and XAF are different currencies', 'CFA is too small a unit', 'Côte d\'Ivoire uses EUR'], 'correct' => 1, 'explanation' => 'XOF vs XAF are distinct currencies. Always quote with explicit currency code.'],
        ];
    }
}

