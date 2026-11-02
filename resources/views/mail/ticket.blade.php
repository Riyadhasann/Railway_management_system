<x-mail::message>
# Your ticket is confirmed

**PNR {{ $booking->pnr }}** · {{ $booking->trainRun->train->label() }} · {{ $booking->trainRun->journey_date->format('D d M Y') }}

Your e-ticket is attached as a PDF. Show the QR code to the ticket checker on the train.

<x-mail::button :url="$url">
View booking
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
