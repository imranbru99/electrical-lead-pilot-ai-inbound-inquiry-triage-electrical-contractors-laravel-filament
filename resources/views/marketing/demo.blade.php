@extends('layouts.marketing')

@section('title', 'Live Demo Intake — ElectricalLeadPilot')

@section('content')
<section class="wrap section">
    <p class="eyebrow">Interactive demo</p>
    <h1>Submit a website inquiry</h1>
    <p class="lead">Watch AI triage classify your request. Then log into Filament to approve and convert it.</p>

    @if(session('success'))
        <div class="flash">{{ session('success') }}</div>
    @endif

    <div class="grid grid-2" style="margin-top:1rem">
        <form class="card" method="post" action="{{ route('demo.intake') }}">
            @csrf
            <label>Name</label>
            <input name="contact_name" value="{{ old('contact_name') }}" required>
            <label>Email</label>
            <input type="email" name="contact_email" value="{{ old('contact_email') }}">
            <label>Phone</label>
            <input name="contact_phone" value="{{ old('contact_phone') }}">
            <label>Subject</label>
            <input name="subject" value="{{ old('subject', 'Need EV charger install') }}">
            <label>Service interest</label>
            <input name="service_interest" value="{{ old('service_interest', 'EV charger') }}">
            <label>Message</label>
            <textarea name="body" rows="5" required>{{ old('body', 'Residential Level 2 EV charger install this week. Please call back.') }}</textarea>
            <label><input type="checkbox" name="consent_email" value="1" checked> Email consent</label>
            <label><input type="checkbox" name="consent_sms" value="1"> SMS consent</label>
            <div class="cta-row">
                <button class="btn btn-accent" type="submit">Classify with AI</button>
            </div>
        </form>
        <div class="card">
            <h3>Demo logins</h3>
            <p class="muted">Password for all users: <strong>password</strong></p>
            <ul class="muted">
                <li>admin@electricalleadpilot.test (admin)</li>
                <li>owner@electricalleadpilot.test (owner)</li>
                <li>staff@electricalleadpilot.test (staff)</li>
                <li>tech@electricalleadpilot.test (technician)</li>
                <li>customer@electricalleadpilot.test (customer — no admin)</li>
            </ul>
            <a class="btn btn-primary" href="{{ url('/admin/login') }}">Go to /admin</a>
        </div>
    </div>
</section>
@endsection
