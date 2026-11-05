@php use App\Filament\Resources\AuditLogs\AuditLogResource as Log; @endphp

<div style="font-size:.875rem; display:grid; gap:.75rem;">
    <p>
        {{ $activity->created_at?->format('d M Y H:i:s') }} ·
        {{ $activity->causer?->name ?? 'System' }}
        @if ($activity->causer?->email) <span style="opacity:.65;">({{ $activity->causer->email }})</span>@endif
    </p>

    @if ($changes === [])
        <p>{{ $activity->description }}</p>
    @else
        <table style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="text-align:left; font-size:.6875rem; text-transform:uppercase; color: rgb(113 113 122);">
                    <th style="padding:.35rem .5rem;">Field</th>
                    @if ($activity->event !== 'created') <th style="padding:.35rem .5rem;">Before</th> @endif
                    @if ($activity->event !== 'deleted') <th style="padding:.35rem .5rem;">After</th> @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($changes as $field => [$old, $new])
                    <tr style="border-top:1px solid rgb(228 228 231 / .7);">
                        <td style="padding:.35rem .5rem; font-weight:500;">{{ \Illuminate\Support\Str::headline($field) }}</td>
                        @if ($activity->event !== 'created') <td style="padding:.35rem .5rem; word-break:break-word;">{{ Log::show($old) }}</td> @endif
                        @if ($activity->event !== 'deleted') <td style="padding:.35rem .5rem; word-break:break-word;">{{ Log::show($new) }}</td> @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
