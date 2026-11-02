@if ($visits->isEmpty())
    <p style="font-size:.875rem;">No workshop visits recorded.</p>
@else
    <table style="width:100%; border-collapse:collapse; font-size:.875rem;">
        <thead>
            <tr style="text-align:left; font-size:.6875rem; text-transform:uppercase; color: rgb(113 113 122);">
                <th style="padding:.4rem .5rem;">Type</th>
                <th style="padding:.4rem .5rem;">Started</th>
                <th style="padding:.4rem .5rem;">Completed</th>
                <th style="padding:.4rem .5rem;">Notes</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($visits as $visit)
                <tr style="border-top:1px solid rgb(228 228 231 / .7);">
                    <td style="padding:.4rem .5rem;">{{ $visit->type->getLabel() }}</td>
                    <td style="padding:.4rem .5rem;">{{ $visit->started_on->format('d M Y') }}</td>
                    <td style="padding:.4rem .5rem;">
                        @if ($visit->isOpen())
                            <x-filament::badge color="warning">In progress</x-filament::badge>
                        @else
                            {{ $visit->completed_on->format('d M Y') }}
                        @endif
                    </td>
                    <td style="padding:.4rem .5rem;">{{ $visit->description ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
