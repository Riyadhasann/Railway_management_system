@php
    use App\Services\ReportExporter;
    $table = $this->table();
    $rangeError = $this->rangeError();
    $field = 'display:flex; flex-direction:column; gap:.25rem; font-size:.875rem;';
@endphp

<x-filament-panels::page>
    <style>
        .rp-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .rp-table th, .rp-table td { padding: .45rem .6rem; border-bottom: 1px solid rgb(228 228 231 / .7); text-align: left; }
        .rp-table th { font-size: .6875rem; text-transform: uppercase; letter-spacing: .04em; color: rgb(113 113 122); }
        .rp-table td.rp-num, .rp-table th.rp-num { text-align: right; font-variant-numeric: tabular-nums; }
        .rp-wrap { overflow-x: auto; }
    </style>

    <x-filament::section>
        <div style="display:flex; flex-wrap:wrap; gap:1rem; align-items:flex-end;">
            <label style="{{ $field }}">
                From
                <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper>
            </label>
            <label style="{{ $field }}">
                To
                <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper>
            </label>
            <label style="{{ $field }} min-width:14rem;">
                Report
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="report">
                        @foreach (ReportExporter::REPORTS as $key => $title)
                            <option value="{{ $key }}">{{ $title }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
            <div style="display:flex; gap:.5rem; flex-wrap:wrap; margin-left:auto;">
                <x-filament::button color="gray" icon="heroicon-o-document-text" wire:click="downloadCsv">Download this report (CSV)</x-filament::button>
                <x-filament::button icon="heroicon-o-table-cells" wire:click="downloadExcel">Download all reports (Excel)</x-filament::button>
            </div>
        </div>
        @if ($rangeError)
            <p style="margin-top:.5rem; color: rgb(220 38 38); font-size:.8125rem;">{{ $rangeError }}</p>
        @endif
    </x-filament::section>

    @if ($table)
        @php [$header, $rows] = $table; @endphp
        <x-filament::section>
            <x-slot name="heading">{{ ReportExporter::REPORTS[$report] }}</x-slot>
            <x-slot name="description">Money in taka. Revenue counts payments on the day they were received and refunds on the day they were sent.</x-slot>

            @if ($rows === [])
                <p style="font-size:.875rem;">Nothing to show for these dates.</p>
            @else
                <div class="rp-wrap">
                    <table class="rp-table" data-test="report">
                        <thead>
                            <tr>
                                @foreach ($header as $i => $h)
                                    <th @class(['rp-num' => $i > 0 && is_numeric($rows[0][$i] ?? null)])>{{ $h }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    @foreach ($row as $i => $cell)
                                        <td @class(['rp-num' => is_int($cell) || is_float($cell)])>{{ is_float($cell) ? number_format($cell, 2) : $cell }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
