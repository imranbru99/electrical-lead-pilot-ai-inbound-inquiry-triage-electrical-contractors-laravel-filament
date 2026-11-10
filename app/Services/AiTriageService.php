<?php

namespace App\Services;

use App\Models\AiClassification;
use App\Models\FollowUpDraft;
use App\Models\Inquiry;

/**
 * Local equivalent of imrandevbd/laravel-ai-hub classification for electrical contractors.
 * Rule + heuristic engine ships offline; swap provider via AI Hub module.
 */
class AiTriageService
{
    public function __construct(
        protected ModuleManager $modules,
        protected AuditLogger $audit,
    ) {}

    public function classify(Inquiry $inquiry): Inquiry
    {
        if (! $this->modules->enabled('ai_classification')) {
            $inquiry->update(['status' => 'pending_approval']);

            return $inquiry->fresh();
        }

        $text = strtolower(($inquiry->subject ?? '').' '.$inquiry->body.' '.$inquiry->service_interest);
        $category = $this->detectCategory($text);
        $priority = $this->detectPriority($text, $category);
        $score = $this->score($text, $category, $priority);
        $tags = $this->tags($text, $category);
        $summary = sprintf(
            'AI triage: %s inquiry scored %d/100 as %s priority for %s work.',
            $inquiry->source,
            $score,
            $priority,
            $category
        );

        $response = [
            'category' => $category,
            'priority' => $priority,
            'score' => $score,
            'tags' => $tags,
            'summary' => $summary,
            'qualified' => $score >= 55,
        ];

        AiClassification::query()->create([
            'inquiry_id' => $inquiry->id,
            'provider' => $this->modules->enabled('ai_hub') ? 'ai-hub-local' : 'local-rules',
            'model' => 'electrical-triage-v1',
            'prompt' => substr($inquiry->body, 0, 2000),
            'response' => $response,
            'confidence' => min(95, 50 + (int) floor($score / 3)),
        ]);

        $inquiry->update([
            'ai_category' => $category,
            'ai_priority' => $priority,
            'ai_score' => $score,
            'ai_summary' => $summary,
            'ai_tags' => $tags,
            'status' => $this->modules->enabled('human_approval_workflow') ? 'pending_approval' : 'classified',
            'classified_at' => now(),
        ]);

        $this->audit->log('inquiry.classified', Inquiry::class, $inquiry->id, null, $response);

        if ($this->modules->enabled('follow_up_drafts')) {
            $this->draftFollowUp($inquiry->fresh());
        }

        return $inquiry->fresh();
    }

    protected function detectCategory(string $text): string
    {
        $map = [
            'emergency_repair' => ['sparking', 'burning smell', 'no power', 'outage', 'emergency', 'smoke', 'breaker keeps'],
            'panel_upgrade' => ['panel upgrade', '200 amp', 'service upgrade', 'breaker box', 'main panel'],
            'ev_charger' => ['ev charger', 'tesla', 'level 2', 'electric vehicle', 'wall connector'],
            'lighting' => ['lighting', 'recessed', 'led', 'fixture', 'chandelier'],
            'rewire' => ['rewire', 'knob and tube', 'aluminum wiring', 'whole house'],
            'generator' => ['generator', 'transfer switch', 'backup power'],
            'inspection' => ['inspection', 'permit', 'code compliance', 'home inspection'],
            'new_construction' => ['new construction', 'rough-in', 'new build', 'addition'],
        ];

        foreach ($map as $category => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    return $category;
                }
            }
        }

        return 'general_service';
    }

    protected function detectPriority(string $text, string $category): string
    {
        if ($category === 'emergency_repair' || str_contains($text, 'asap') || str_contains($text, 'urgent')) {
            return 'urgent';
        }

        if (str_contains($text, 'this week') || str_contains($text, 'quote')) {
            return 'high';
        }

        if (in_array($category, ['panel_upgrade', 'ev_charger', 'generator', 'rewire'], true)) {
            return 'high';
        }

        return 'normal';
    }

    protected function score(string $text, string $category, string $priority): int
    {
        $score = 45;

        if (strlen($text) > 80) {
            $score += 10;
        }

        if (preg_match('/\d{3}[-.\s]?\d{3}[-.\s]?\d{4}/', $text)) {
            $score += 8;
        }

        $score += match ($priority) {
            'urgent' => 25,
            'high' => 15,
            default => 5,
        };

        $score += match ($category) {
            'emergency_repair', 'panel_upgrade', 'ev_charger' => 12,
            'generator', 'rewire', 'new_construction' => 8,
            default => 3,
        };

        return max(10, min(99, $score));
    }

    protected function tags(string $text, string $category): array
    {
        $tags = [$category];

        if (str_contains($text, 'commercial')) {
            $tags[] = 'commercial';
        }
        if (str_contains($text, 'residential') || str_contains($text, 'home')) {
            $tags[] = 'residential';
        }
        if (str_contains($text, 'insured') || str_contains($text, 'insurance')) {
            $tags[] = 'insurance';
        }

        return array_values(array_unique($tags));
    }

    protected function draftFollowUp(Inquiry $inquiry): void
    {
        $channel = $inquiry->consent_email ? 'email' : ($inquiry->consent_sms ? 'sms' : 'email');

        FollowUpDraft::query()->create([
            'inquiry_id' => $inquiry->id,
            'channel' => $channel,
            'subject' => 'Re: '.($inquiry->subject ?: 'Your electrical service request'),
            'body' => "Hi {$inquiry->contact_name},\n\nThanks for contacting us about {$inquiry->ai_category}. ".
                "Our team reviewed your {$inquiry->source} inquiry (ref {$inquiry->reference}) and classified it as {$inquiry->ai_priority} priority. ".
                "A licensed electrician will follow up shortly to confirm details and schedule.\n\n— ElectricalLeadPilot Desk",
            'status' => 'pending_approval',
        ]);
    }
}
