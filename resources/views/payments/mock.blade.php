@php
    use App\Enums\PaymentMethod;
    use App\Support\Money;

    $brand = match ($payment->method) {
        PaymentMethod::Bkash => ['name' => 'bKash', 'color' => '#e2136e', 'soft' => '#fde7f1'],
        PaymentMethod::Nagad => ['name' => 'Nagad', 'color' => '#ec1c24', 'soft' => '#fdeaea'],
        default => ['name' => 'Card payment', 'color' => '#1d4ed8', 'soft' => '#e8efff'],
    };
    $final = $payment->status->isFinal();
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $brand['name'] }} · Test checkout</title>
    <style>
        :root { color-scheme: light; --brand: {{ $brand['color'] }}; --soft: {{ $brand['soft'] }}; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f4f4f5; color: #18181b; display: flex; align-items: center; justify-content: center; padding: 16px; }
        .card { width: 100%; max-width: 400px; background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgb(0 0 0 / .08); }
        .head { background: var(--brand); color: #fff; padding: 20px 24px; }
        .head h1 { margin: 0; font-size: 22px; }
        .head p { margin: 4px 0 0; opacity: .85; font-size: 13px; }
        .body { padding: 24px; }
        .amount { font-size: 34px; font-weight: 700; margin: 0; }
        .muted { color: #71717a; font-size: 13px; }
        dl { display: grid; grid-template-columns: auto 1fr; gap: 6px 16px; margin: 18px 0; font-size: 14px; }
        dt { color: #71717a; } dd { margin: 0; text-align: right; font-weight: 500; }
        .test { background: var(--soft); border-radius: 10px; padding: 10px 12px; font-size: 13px; margin-bottom: 18px; }
        button { width: 100%; border: 0; border-radius: 10px; padding: 13px; font-size: 15px; font-weight: 600; cursor: pointer; margin-top: 8px; }
        .pay { background: var(--brand); color: #fff; }
        .fail { background: #fee2e2; color: #991b1b; }
        .cancel { background: transparent; color: #52525b; text-decoration: underline; }
        button:focus-visible { outline: 3px solid #18181b; outline-offset: 2px; }
    </style>
</head>
<body>
    <main class="card">
        <div class="head">
            <h1>{{ $brand['name'] }}</h1>
            <p>Test gateway · no real money moves</p>
        </div>
        <div class="body">
            <div class="muted">Amount to pay</div>
            <p class="amount" data-test="amount">{{ Money::format($payment->amount_poisha) }}</p>

            <dl>
                <dt>Merchant</dt><dd>{{ config('app.name') }}</dd>
                <dt>PNR</dt><dd>{{ $booking->pnr }}</dd>
                <dt>Train</dt><dd>{{ $booking->trainRun->train->label() }}</dd>
                <dt>Reference</dt><dd style="font-size:12px">{{ $payment->reference }}</dd>
            </dl>

            @if ($final)
                <div class="test">This payment has already been processed ({{ $payment->status->getLabel() }}).</div>
                <a href="{{ route('booking.show', $booking) }}">Back to your booking</a>
            @else
                <div class="test">Prototype: choose how this test payment should end.</div>

                @foreach (['success' => ['pay', 'Pay '.Money::format($payment->amount_poisha)], 'failed' => ['fail', 'Simulate a declined payment'], 'cancelled' => ['cancel', 'Cancel and go back']] as $outcome => [$class, $label])
                    <form method="post" action="{{ route('payments.callback') }}">
                        @foreach ($buttons[$outcome] as $name => $value)
                            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                        @endforeach
                        <button type="submit" class="{{ $class }}" data-test="{{ $outcome }}">{{ $label }}</button>
                    </form>
                @endforeach
            @endif
        </div>
    </main>
</body>
</html>
