<?php

/**
 * Default settings. Staff can change them later in the admin dashboard.
 * Safe to run again: INSERT IGNORE never overwrites a value that already exists.
 */

$default_settings = [
    // Support contacts shown in "Msaada" and used for "Uliza Muuzaji" (fill in the real numbers)
    'support_phone'               => '+255700000000',
    'support_whatsapp'            => '+255700000000',
    'support_hours'               => 'Jumatatu - Jumamosi, 08:00 - 18:00',

    // One-time login codes
    'otp_lifetime_seconds'        => '300',   // a code works for 5 minutes
    'otp_max_attempts'            => '5',     // wrong guesses before the code is blocked
    'otp_resend_wait_seconds'     => '60',    // wait before "Tuma tena"
    'otp_max_requests_per_hour'   => '5',     // per phone number

    // Orders and payments
    'unpaid_order_expiry_minutes' => '1440',  // unpaid orders may be cancelled after this (24 hours: payments are checked by hand)
    'cod_max_order_total'         => '300000', // largest order allowed with "Lipa ukipokea" (TZS)

    // Payment methods and their "pay to" details live in the payment_methods table (admin → Payments)

    // Legal pages (plain text, paragraphs separated by an empty line). DRAFT ONLY — a lawyer must
    // review them before launch; staff replace them in the admin under Settings → Legal texts.
    'legal_terms'                 => "RASIMU — maandishi haya yatakaguliwa kabla ya kuzinduliwa.\n\n"
        . "CHIMBO ni soko la jumla kwa wenye maduka Tanzania. Kwa kutumia programu au tovuti ya CHIMBO unakubali vigezo hivi.\n\n"
        . "Bei: bei zote ni za Shilingi za Kitanzania. Bei ya oda ni ile iliyoonyeshwa ulipothibitisha oda.\n\n"
        . "Kiwango cha chini (MOQ): kila bidhaa ina idadi ya chini ya kuagiza iliyoonyeshwa kwenye ukurasa wake.\n\n"
        . "Malipo: unaweza kulipa kwa simu (M-Pesa, Airtel Money, Mixx), kwa benki au wakati wa kupokea mzigo. Malipo kwa simu na benki huthibitishwa na CHIMBO kabla oda haijaandaliwa. Oda ya kulipa ukipokea ina kiasi cha juu.\n\n"
        . "Kughairi: unaweza kughairi oda kabla haijafungwa kwa ajili ya kusafirishwa.\n\n"
        . "Akaunti: linda PIN yako. Usimpe mtu yeyote PIN au namba ya uthibitisho.\n\n"
        . "Msaada: wasiliana nasi kupitia namba ya msaada iliyo kwenye ukurasa wa Msaada.",
    'legal_privacy'               => "RASIMU — maandishi haya yatakaguliwa kabla ya kuzinduliwa.\n\n"
        . "Tunakusanya: namba yako ya simu, jina, taarifa za biashara yako, anwani za kufikishia mzigo na historia ya oda zako.\n\n"
        . "Tunazitumia: kukuwezesha kuingia, kushughulikia na kufikisha oda zako, na kukutumia taarifa za oda.\n\n"
        . "Hatuuzi taarifa zako. Tunawapa wasafirishaji tu kile wanachohitaji kukufikishia mzigo.\n\n"
        . "PIN yako huhifadhiwa kwa njia ambayo hata wafanyakazi wa CHIMBO hawawezi kuisoma.\n\n"
        . "Unaweza kufuta akaunti yako wakati wowote kwenye Wasifu.\n\n"
        . "Maswali: wasiliana nasi kupitia namba ya msaada.",
];

return function (Database $db) use ($default_settings): void {
    foreach ($default_settings as $setting_key => $setting_value) {
        $db->execute(
            'INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (:setting_key, :setting_value)',
            ['setting_key' => $setting_key, 'setting_value' => $setting_value]
        );
    }
};
