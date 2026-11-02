<x-mail::message>
# Crew and loco warnings

@if ($licences->isNotEmpty())
## Driving licences

<x-mail::table>
| Staff | Licence expires |
| :-- | :-- |
@foreach ($licences as $staff)
| {{ $staff->label() }} | {{ $staff->licence_expires_on === null ? 'No licence on record' : ($staff->licence_expires_on->isPast() ? 'Expired '.$staff->licence_expires_on->format('d M Y') : $staff->licence_expires_on->format('d M Y')) }} |
@endforeach
</x-mail::table>
@endif

@if ($locos->isNotEmpty())
## Locomotive maintenance

<x-mail::table>
| Loco | Maintenance due |
| :-- | :-- |
@foreach ($locos as $loco)
| {{ $loco->label() }} | {{ $loco->next_maintenance_due->isPast() ? 'Overdue since '.$loco->next_maintenance_due->format('d M Y') : $loco->next_maintenance_due->format('d M Y') }} |
@endforeach
</x-mail::table>
@endif

Drivers with an expired licence and locos under repair can't be assigned to a train.

<x-mail::button :url="$url">
Open assignments
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
