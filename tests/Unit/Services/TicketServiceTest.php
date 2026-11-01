<?php

use App\Services\TicketService;

it('makes 6-character PNRs without the confusable characters 0, O, 1 and I', function () {
    $service = new TicketService;

    foreach (range(1, 500) as $i) {
        $pnr = $service->randomPnr();

        expect($pnr)->toHaveLength(6)
            ->and($pnr)->toMatch('/^['.TicketService::PNR_ALPHABET.']+$/')
            ->and($pnr)->not->toMatch('/[0O1I]/');
    }
});
