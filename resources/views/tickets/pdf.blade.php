@php
    // dompdf's bundled fonts have no taka sign, so the PDF writes "Tk".
    $tk = fn (int $poisha) => 'Tk '.number_format($poisha / 100, $poisha % 100 === 0 ? 0 : 2);
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>E-ticket {{ $booking->pnr }}</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #18181b; }
        h1 { font-size: 20px; margin: 0; }
        .muted { color: #71717a; }
        .band { background: #065f46; color: #fff; padding: 12px 16px; }
        .band td { color: #fff; }
        .pnr { font-size: 26px; font-weight: bold; letter-spacing: 3px; }
        table { width: 100%; border-collapse: collapse; }
        .box { border: 1px solid #d4d4d8; margin-top: 14px; }
        .box td, .box th { padding: 7px 10px; text-align: left; vertical-align: top; }
        .grid th { background: #f4f4f5; font-size: 10px; text-transform: uppercase; color: #52525b; border-bottom: 1px solid #d4d4d8; }
        .grid td { border-bottom: 1px solid #e4e4e7; }
        .label { font-size: 9px; text-transform: uppercase; color: #71717a; }
        .big { font-size: 15px; font-weight: bold; }
        .right { text-align: right; }
        .status { display: inline-block; padding: 2px 8px; border-radius: 3px; font-weight: bold; font-size: 10px; }
        .ok { background: #d1fae5; color: #065f46; }
        .bad { background: #fee2e2; color: #991b1b; }
        .notes { margin-top: 16px; font-size: 9.5px; color: #52525b; }
    </style>
</head>
<body>
    <table class="band">
        <tr>
            <td>
                <h1>{{ config('app.name') }} · E-ticket</h1>
                <div>{{ $run->train->label() }}</div>
            </td>
            <td class="right">
                <div class="label" style="color:#d1fae5">PNR</div>
                <div class="pnr">{{ $booking->pnr }}</div>
            </td>
        </tr>
    </table>

    <table class="box">
        <tr>
            <td style="width:62%">
                <table>
                    <tr>
                        <td style="padding:0 0 10px">
                            <div class="label">From</div>
                            <div class="big">{{ $from?->routeStop->station->name }}</div>
                            <div>{{ $departure?->format('D d M Y, H:i') }}</div>
                        </td>
                        <td style="padding:0 0 10px">
                            <div class="label">To</div>
                            <div class="big">{{ $to?->routeStop->station->name }}</div>
                            <div>{{ $arrival?->format('D d M Y, H:i') }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0">
                            <div class="label">Journey date</div>
                            <div>{{ $run->journey_date->format('D d M Y') }}</div>
                        </td>
                        <td style="padding:0">
                            <div class="label">Status</div>
                            <span class="status {{ $booking->hasTicket() ? 'ok' : 'bad' }}">{{ strtoupper($booking->status->getLabel()) }}</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:10px 0 0">
                            <div class="label">Booked</div>
                            <div>{{ $booking->channel->getLabel() }} · {{ $booking->confirmed_at?->format('d M Y, H:i') }}</div>
                        </td>
                        <td style="padding:10px 0 0">
                            <div class="label">Paid</div>
                            <div>{{ $tk($booking->total_poisha) }}@if ($payment) · {{ $payment->method->getLabel() }}@endif</div>
                        </td>
                    </tr>
                </table>
            </td>
            <td class="right" style="width:38%">
                <img src="{{ $qr }}" alt="QR code" style="width:150px;height:150px">
                <div class="muted" style="font-size:9px">Show this code to the ticket checker</div>
            </td>
        </tr>
    </table>

    <table class="box grid">
        <thead>
            <tr>
                <th>#</th>
                <th>Passenger</th>
                <th>Age</th>
                <th>Gender</th>
                <th>Coach / seat</th>
                <th>Concession</th>
                <th class="right">Fare</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($booking->passengers->sortBy('id')->values() as $i => $p)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $p->name }}</td>
                    <td>{{ $p->age }}</td>
                    <td>{{ $p->gender->getLabel() }}</td>
                    <td>{{ $p->seatBooking->coach_no }}-{{ $p->seatBooking->seat->seat_no }}</td>
                    <td>{{ $p->concession?->name ?? '—' }}</td>
                    <td class="right">{{ $tk($p->fare_poisha) }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="6" class="right"><strong>Total</strong></td>
                <td class="right"><strong>{{ $tk($booking->total_poisha) }}</strong></td>
            </tr>
        </tbody>
    </table>

    <div class="notes">
        Carry the NID (or other photo ID) of each adult passenger. Be at the boarding station at least 20 minutes before departure.
        Cancellations are refunded according to the refund rules in force when you cancel. If the railway cancels the train,
        the full fare is refunded automatically. This ticket is valid only for the train, date and seats shown.
    </div>
</body>
</html>
