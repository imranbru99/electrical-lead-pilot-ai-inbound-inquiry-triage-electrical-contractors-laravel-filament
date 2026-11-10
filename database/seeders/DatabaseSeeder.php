<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Inquiry;
use App\Models\JobTicket;
use App\Models\Opportunity;
use App\Models\ServiceRequest;
use App\Models\Technician;
use App\Models\User;
use App\Services\InquiryWorkflowService;
use App\Services\ModuleManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        app(ModuleManager::class)->syncFromConfig();

        $users = [
            ['name' => 'Admin Demo', 'email' => 'admin@electricalleadpilot.test', 'role' => 'admin', 'phone' => '555-0100'],
            ['name' => 'Owner Demo', 'email' => 'owner@electricalleadpilot.test', 'role' => 'owner', 'phone' => '555-0101'],
            ['name' => 'Staff Demo', 'email' => 'staff@electricalleadpilot.test', 'role' => 'staff', 'phone' => '555-0102'],
            ['name' => 'Tech Demo', 'email' => 'tech@electricalleadpilot.test', 'role' => 'technician', 'phone' => '555-0103'],
            ['name' => 'Customer Demo', 'email' => 'customer@electricalleadpilot.test', 'role' => 'customer', 'phone' => '555-0104'],
        ];

        $created = [];
        foreach ($users as $user) {
            $created[$user['role']] = User::query()->updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'role' => $user['role'],
                    'phone' => $user['phone'],
                    'password' => Hash::make('password'),
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );
        }

        $techUser = $created['technician'];
        $technician = Technician::query()->updateOrCreate(
            ['user_id' => $techUser->id],
            [
                'name' => 'Jordan Sparks',
                'license_number' => 'EL-TX-44821',
                'specialties' => ['panel_upgrade', 'ev_charger', 'emergency_repair'],
                'availability_status' => 'available',
                'service_area' => 'Dallas–Fort Worth',
                'phone' => '555-0103',
                'is_active' => true,
            ]
        );

        Technician::query()->updateOrCreate(
            ['license_number' => 'EL-TX-55210'],
            [
                'name' => 'Ava Circuit',
                'specialties' => ['lighting', 'generator', 'inspection'],
                'availability_status' => 'on_job',
                'service_area' => 'Austin Metro',
                'phone' => '555-0118',
                'is_active' => true,
            ]
        );

        Customer::query()->updateOrCreate(
            ['email' => 'homeowner@example.com'],
            [
                'name' => 'Riley Homeowner',
                'phone' => '555-2001',
                'address' => '1200 Oak Ridge Dr',
                'city' => 'Plano',
                'state' => 'TX',
                'zip' => '75024',
                'notes' => 'Prefers morning appointments',
            ]
        );

        if (Inquiry::query()->count() === 0) {
            $workflow = app(InquiryWorkflowService::class);
            $staff = $created['staff'];

            $samples = [
                [
                    'source' => 'website',
                    'contact_name' => 'Chris Martinez',
                    'contact_email' => 'chris.m@example.com',
                    'contact_phone' => '555-3001',
                    'subject' => 'EV charger install for Tesla',
                    'service_interest' => 'EV charger',
                    'body' => 'Looking for a Level 2 EV charger wall connector install at my residential home in Plano. Prefer this week if possible.',
                    'consent_email' => true,
                    'consent_sms' => true,
                ],
                [
                    'source' => 'phone',
                    'contact_name' => 'Dana Lee',
                    'contact_email' => 'dana.lee@example.com',
                    'contact_phone' => '555-3002',
                    'subject' => 'Burning smell from breaker box',
                    'service_interest' => 'Emergency',
                    'body' => 'Urgent: burning smell near breaker box and sparks when lights turn on. Need emergency repair ASAP.',
                    'consent_email' => true,
                    'consent_sms' => false,
                ],
                [
                    'source' => 'email',
                    'contact_name' => 'Sam Patel',
                    'contact_email' => 'sam.patel@example.com',
                    'contact_phone' => '555-3003',
                    'subject' => '200 amp panel upgrade quote',
                    'service_interest' => 'Panel upgrade',
                    'body' => 'Need a quote for a 200 amp main panel upgrade for our commercial warehouse addition.',
                    'consent_email' => true,
                    'consent_sms' => false,
                ],
            ];

            foreach ($samples as $i => $sample) {
                $inquiry = $workflow->intake($sample, $staff);
                if ($i < 2) {
                    $workflow->approve($inquiry, $created['owner']);
                }
            }
        }

        $sr = ServiceRequest::query()->first();
        if ($sr) {
            JobTicket::query()->updateOrCreate(
                ['ticket_number' => 'JT-DEMO0001'],
                [
                    'service_request_id' => $sr->id,
                    'technician_id' => $technician->id,
                    'status' => 'dispatched',
                    'scheduled_start' => now()->addDay()->setTime(9, 0),
                    'scheduled_end' => now()->addDay()->setTime(12, 0),
                    'safety_checklist' => ['PPE on site', 'Lockout/tagout verified', 'GFCI tested'],
                ]
            );
        }

        if (Opportunity::query()->count() === 0) {
            Opportunity::query()->create([
                'title' => 'Demo pipeline opportunity',
                'stage' => 'qualified',
                'value_estimate' => 1500,
                'probability' => 60,
                'status' => 'open',
                'owner_id' => $created['owner']->id,
            ]);
        }
    }
}

