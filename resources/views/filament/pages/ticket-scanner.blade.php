@php
    $run = $this->run();
    $counts = $this->counts();
    $field = 'display:flex; flex-direction:column; gap:.25rem; font-size:.875rem;';
    $tones = [
        'valid' => ['bg' => '#dcfce7', 'fg' => '#14532d', 'edge' => '#16a34a', 'icon' => '✓'],
        'already_used' => ['bg' => '#fef3c7', 'fg' => '#78350f', 'edge' => '#d97706', 'icon' => '!'],
    ];
    $bad = ['bg' => '#fee2e2', 'fg' => '#7f1d1d', 'edge' => '#dc2626', 'icon' => '✕'];
@endphp

<x-filament-panels::page>
    @assets
        <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    @endassets

    <style>
        .ts-reader { width: 100%; max-width: 420px; aspect-ratio: 1; border-radius: .75rem; overflow: hidden; background: #18181b; }
        .ts-reader video { object-fit: cover; }
        .ts-result { border-radius: .75rem; padding: 1rem 1.25rem; border-left: 6px solid; }
        .ts-result h2 { margin: 0; font-size: 1.5rem; font-weight: 700; display: flex; align-items: center; gap: .5rem; }
        .ts-list { font-size: .8125rem; display: grid; gap: .25rem; }
        .ts-row { display: flex; justify-content: space-between; gap: .75rem; padding: .375rem 0; border-bottom: 1px solid rgb(228 228 231 / .6); }
    </style>

    <x-filament::section>
        <div style="display:flex; flex-wrap:wrap; gap:1rem; align-items:flex-end; justify-content:space-between;">
            <label style="{{ $field }} min-width:16rem; flex:1;">
                Train you are checking
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="runId">
                        <option value="">Choose a train</option>
                        @foreach ($this->runs as $r)
                            <option value="{{ $r->id }}">{{ $r->train->label() }} · {{ $r->journey_date->format('D d M') }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
            @if ($run)
                <div style="font-size:.875rem;" data-test="counts"><strong>{{ $counts['valid'] }}</strong> of {{ $counts['total'] }} bookings checked</div>
            @endif
        </div>
        @if ($this->runs->isEmpty())
            <p style="margin-top:.75rem; font-size:.875rem;">No trains are running today.</p>
        @endif
    </x-filament::section>

    @if ($run)
        <div style="display:grid; gap:1.5rem; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); align-items:start;">
            <x-filament::section>
                <x-slot name="heading">Camera</x-slot>
                <div wire:ignore
                     x-data="{
                        scanner: null, running: false, error: null, lastText: null, lastAt: 0,
                        async start() {
                            this.error = null;
                            if (! window.Html5Qrcode) { this.error = 'The scanner could not load. Check the internet connection.'; return; }
                            this.scanner ??= new Html5Qrcode('ts-reader');
                            try {
                                await this.scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 240, height: 240 } }, (text) => this.found(text));
                                this.running = true;
                            } catch (e) {
                                this.error = 'Camera not available: ' + e + '. The camera only works over HTTPS (or on localhost) and needs permission.';
                            }
                        },
                        async stop() { if (this.scanner && this.running) { await this.scanner.stop(); } this.running = false; },
                        found(text) {
                            // The camera reads the same code many times a second: send it once per 3 seconds.
                            const now = Date.now();
                            if (text === this.lastText && now - this.lastAt < 3000) return;
                            this.lastText = text; this.lastAt = now;
                            if (navigator.vibrate) navigator.vibrate(80);
                            $wire.scan(text);
                        },
                     }"
                     x-on:livewire:navigating.window="stop()">
                    <div id="ts-reader" class="ts-reader"></div>
                    <div style="margin-top:.75rem; display:flex; gap:.5rem;">
                        <x-filament::button x-show="! running" x-on:click="start()" icon="heroicon-o-camera">Start camera</x-filament::button>
                        <x-filament::button x-show="running" x-on:click="stop()" color="gray" icon="heroicon-o-stop">Stop</x-filament::button>
                    </div>
                    <p x-show="error" x-text="error" style="margin-top:.5rem; color: rgb(220 38 38); font-size:.8125rem;"></p>
                </div>

                <form wire:submit="scan" style="margin-top:1rem; display:flex; gap:.5rem; align-items:flex-end;">
                    <label style="{{ $field }} flex:1;">
                        Or paste the ticket code
                        <x-filament::input.wrapper>
                            <x-filament::input type="text" wire:model="code" autocomplete="off" />
                        </x-filament::input.wrapper>
                    </label>
                    <x-filament::button type="submit" color="gray">Check</x-filament::button>
                </form>
            </x-filament::section>

            <div style="display:grid; gap:1.5rem;">
                @if ($last)
                    @php $tone = $tones[$last['result']] ?? $bad; @endphp
                    <div class="ts-result" role="status" aria-live="assertive" data-test="result" data-result="{{ $last['result'] }}"
                         style="background: {{ $tone['bg'] }}; color: {{ $tone['fg'] }}; border-color: {{ $tone['edge'] }};">
                        <h2><span aria-hidden="true">{{ $tone['icon'] }}</span> {{ $last['label'] }}</h2>
                        <p style="margin:.25rem 0 0;">{{ $last['message'] }}</p>
                        @if ($last['pnr'])
                            <p style="margin:.75rem 0 0; font-weight:600; letter-spacing:.08em;">PNR {{ $last['pnr'] }} @if ($last['route']) <span style="font-weight:400; letter-spacing:0;">· {{ $last['route'] }}</span>@endif</p>
                        @endif
                        @if ($last['passengers'])
                            <ul style="margin:.5rem 0 0; padding-left:1.1rem; font-size:.875rem;">
                                @foreach ($last['passengers'] as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        @endif
                        <p style="margin:.75rem 0 0; font-size:.75rem; opacity:.8;">Scanned {{ $last['scanned_at'] }}</p>
                    </div>
                @else
                    <x-filament::section>
                        <p style="font-size:.875rem;">Point the camera at the QR code on the passenger's e-ticket. The result shows here.</p>
                    </x-filament::section>
                @endif

                <x-filament::section>
                    <x-slot name="heading">Recent scans on this train</x-slot>
                    <div class="ts-list">
                        @forelse ($this->recentScans() as $scan)
                            <div class="ts-row">
                                <span><strong>{{ $scan->pnr ?? '—' }}</strong> · {{ $scan->result->getLabel() }}</span>
                                <span style="opacity:.7;">{{ $scan->scanned_at->format('H:i') }} · {{ $scan->tte->name }}</span>
                            </div>
                        @empty
                            <p>No scans yet.</p>
                        @endforelse
                    </div>
                </x-filament::section>
            </div>
        </div>
    @endif
</x-filament-panels::page>
