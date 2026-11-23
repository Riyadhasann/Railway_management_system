<?php

return [

    /*
    | Minutes a seat stays held while the passenger pays.
    */
    'hold_minutes' => (int) env('SEAT_HOLD_MINUTES', 10),

    /*
    | How many days ahead daily train runs are generated.
    */
    'runs_ahead_days' => (int) env('RUNS_AHEAD_DAYS', 10),

    /*
    | Maximum passengers per booking.
    */
    'max_passengers' => 4,

    /*
    | Password for all demo accounts. Leave empty to generate random
    | passwords; they are printed by the seeder and saved to
    | storage/app/private/demo-credentials.txt (never committed).
    */
    'demo_password' => env('DEMO_PASSWORD'),

    /*
    | Email domain used for the seeded demo accounts.
    */
    'demo_email_domain' => 'railway-erp.test',

    /*
    | Proxies whose X-Forwarded-* headers are trusted, comma-separated. Set it to
    | "127.0.0.1,::1" while sharing the demo through a tunnel running on this PC
    | (Cloudflare Tunnel, ngrok), so links and assets use the tunnel's https://
    | address. Empty: no proxy is trusted. Never use "*" on a public server.
    */
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),

];
