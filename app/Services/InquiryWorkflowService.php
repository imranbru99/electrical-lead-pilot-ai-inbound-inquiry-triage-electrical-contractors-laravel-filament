<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Inquiry;
use App\Models\Opportunity;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class InquiryWorkflowService
{
    public function __construct(
        protected AiTriageService $triage,
        protected ModuleManager $modules,
        protected AuditLogger $audit,
    ) {}

    public function intake(array $data, ?User $actor = null): Inquiry
    {
        $inquiry = Inquiry::query()->create([
            'source' => $data['source'] ?? 'website',
            'contact_name' => $data['contact_name'],
            'contact_email' => $data['contact_email'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'subject' => $data['subject'] ?? null,
            'body' => $data['body'],
            'service_interest' => $data['service_interest'] ?? null,
            'consent_sms' => (bool) ($data['consent_sms'] ?? false),
            'consent_email' => (bool) ($data['consent_email'] ?? false),
            'raw_payload' => $data,
            'status' => 'new',
            'assigned_to' => $data['assigned_to'] ?? null,
        ]);

        $this->audit->log('inquiry.intake', Inquiry::class, $inquiry->id, null, $inquiry->toArray(), $actor?->id);

        return $this->triage->classify($inquiry);
    }

    public function approve(Inquiry $inquiry, User $actor): Opportunity
    {
        return DB::transaction(function () use ($inquiry, $actor) {
            $before = $inquiry->toArray();

            $customer = null;
            if ($inquiry->contact_email || $inquiry->contact_phone) {
                $customer = Customer::query()->firstOrCreate(
                    ['email' => $inquiry->contact_email ?: 'lead+'.$inquiry->id.'@example.local'],
                    [
                        'name' => $inquiry->contact_name,
                        'phone' => $inquiry->contact_phone,
                        'notes' => 'Auto-created from inquiry '.$inquiry->reference,
                    ]
                );
            }

            $inquiry->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => $actor->id,
                'customer_id' => $customer?->id,
            ]);

            $opportunity = Opportunity::query()->create([
                'inquiry_id' => $inquiry->id,
                'customer_id' => $customer?->id,
                'owner_id' => $actor->id,
                'title' => ($inquiry->ai_category ?: 'Electrical lead').' — '.$inquiry->contact_name,
                'stage' => 'qualified',
                'value_estimate' => $this->estimateValue($inquiry),
                'probability' => max(20, min(90, (int) ($inquiry->ai_score ?? 50))),
                'status' => 'open',
                'notes' => $inquiry->ai_summary,
            ]);

            if ($this->modules->enabled('service_requests')) {
                ServiceRequest::query()->create([
                    'opportunity_id' => $opportunity->id,
                    'customer_id' => $customer?->id,
                    'customer_name' => $inquiry->contact_name,
                    'service_type' => $inquiry->ai_category,
                    'priority' => $inquiry->ai_priority ?? 'normal',
                    'status' => 'intake',
                    'notes' => $inquiry->body,
                ]);
            }

            $inquiry->update(['status' => 'converted']);

            $this->audit->log(
                'inquiry.approved_converted',
                Inquiry::class,
                $inquiry->id,
                $before,
                ['opportunity_id' => $opportunity->id, 'status' => 'converted'],
                $actor->id
            );

            return $opportunity;
        });
    }

    public function reject(Inquiry $inquiry, User $actor, ?string $reason = null): Inquiry
    {
        $before = $inquiry->toArray();
        $inquiry->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ]);

        $this->audit->log('inquiry.rejected', Inquiry::class, $inquiry->id, $before, $inquiry->toArray(), $actor->id);

        return $inquiry->fresh();
    }

    protected function estimateValue(Inquiry $inquiry): float
    {
        return match ($inquiry->ai_category) {
            'panel_upgrade' => 3500,
            'ev_charger' => 1800,
            'generator' => 6500,
            'rewire' => 9000,
            'new_construction' => 12000,
            'emergency_repair' => 450,
            'lighting' => 900,
            'inspection' => 250,
            default => 750,
        };
    }
}
