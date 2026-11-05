<x-filament-panels::page>
    <x-filament::section>
        <div style="display:grid; gap:.75rem; font-size:.875rem;">
            <p>
                <strong>Reset demo data</strong> empties the database and loads the demo again: stations, routes,
                trains and coaches, fares and refund rules, one account per role, and about a month of past
                bookings, cancellations, refunds and running times for the dashboard and reports.
            </p>
            <p>Use it when test data gets messy. Demo account passwords don't change.</p>
            <p style="opacity:.7;">From a terminal: <code>php artisan demo:reset</code></p>
        </div>
    </x-filament::section>

    <x-filament::section heading="Right now">
        <dl style="display:grid; grid-template-columns:repeat(auto-fit, minmax(10rem, 1fr)); gap:1rem; font-size:.875rem;">
            @foreach ($this->counts() as $label => $count)
                <div>
                    <dt style="opacity:.7;">{{ $label }}</dt>
                    <dd style="font-size:1.5rem; font-weight:600; font-variant-numeric:tabular-nums;">{{ number_format($count) }}</dd>
                </div>
            @endforeach
        </dl>
    </x-filament::section>
</x-filament-panels::page>
