@extends('marketing.layout', ['title' => 'Pricing'])

@section('content')
    <section>
        <div class="wrap">
            <h2>Simple pricing</h2>
            <p class="lead">Every plan starts with a free trial. Pay by card, bKash or bank transfer; cancel anytime.</p>

            @if ($plans->isEmpty())
                <div class="card">
                    <h3>Free while we're in early access</h3>
                    <p>Create an account and start sending. Paid plans come later; you'll hear from us first.</p>
                </div>
            @else
                <div class="grid">
                    @foreach ($plans as $plan)
                        <div class="card">
                            <h3>{{ $plan->name }}</h3>
                            @if (filled($plan->description))
                                <p>{{ $plan->description }}</p>
                            @endif
                            <div class="price">
                                @if ($plan->price_monthly > 0)
                                    {{ \App\Models\Plan::formatMoney($plan->price_monthly, $plan->currency) }} <small>/ month</small>
                                @else
                                    Free
                                @endif
                            </div>
                            @if ($plan->price_yearly > 0)
                                <p>or {{ \App\Models\Plan::formatMoney($plan->price_yearly, $plan->currency) }} / year</p>
                            @endif
                            <ul>
                                @foreach ($plan->relevantLimits() as $key => $label)
                                    <li>{{ $plan->limit($key) === null ? 'Unlimited' : number_format((int) $plan->limit($key)) }} {{ strtolower($label) }}</li>
                                @endforeach
                                @foreach ($plan->modules ?? [] as $module)
                                    @if (modules()->exists($module) && modules()->enabled($module))
                                        <li>{{ modules()->label($module) }}</li>
                                    @endif
                                @endforeach
                                @if ($plan->trial_days > 0)
                                    <li>{{ $plan->trial_days }}-day free trial</li>
                                @endif
                            </ul>
                            <a class="btn btn-primary" href="{{ route('filament.app.auth.register') }}">Start free trial</a>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </section>
@endsection
