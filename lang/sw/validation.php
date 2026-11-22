<?php

/*
| Kiswahili validation messages for the most common rules. Any rule not
| listed here falls back to the English message (APP_FALLBACK_LOCALE).
*/

return [
    'alpha_dash' => 'Sehemu ya :attribute inaweza kuwa na herufi, namba, vistari na mistari ya chini tu.',
    'confirmed' => 'Uthibitisho wa :attribute haulingani.',
    'email' => 'Sehemu ya :attribute lazima iwe barua pepe halali.',
    'in' => ':attribute uliyochagua si halali.',
    'integer' => 'Sehemu ya :attribute lazima iwe namba kamili.',
    'max' => [
        'numeric' => 'Sehemu ya :attribute isizidi :max.',
        'string' => 'Sehemu ya :attribute isizidi herufi :max.',
    ],
    'min' => [
        'numeric' => 'Sehemu ya :attribute lazima iwe angalau :min.',
        'string' => 'Sehemu ya :attribute lazima iwe na angalau herufi :min.',
    ],
    'password' => [
        'letters' => 'Sehemu ya :attribute lazima iwe na angalau herufi moja.',
        'mixed' => 'Sehemu ya :attribute lazima iwe na angalau herufi kubwa moja na ndogo moja.',
        'numbers' => 'Sehemu ya :attribute lazima iwe na angalau namba moja.',
        'symbols' => 'Sehemu ya :attribute lazima iwe na angalau alama moja.',
        'uncompromised' => ':attribute uliyoweka imeonekana kwenye uvujaji wa taarifa. Tafadhali chagua :attribute nyingine.',
    ],
    'regex' => 'Muundo wa :attribute si sahihi.',
    'required' => 'Sehemu ya :attribute inahitajika.',
    'string' => 'Sehemu ya :attribute lazima iwe maandishi.',
    'unique' => ':attribute tayari imeshatumika.',
    'uuid' => 'Sehemu ya :attribute lazima iwe UUID halali.',

    'attributes' => [
        'email' => 'barua pepe',
        'password' => 'nenosiri',
        'name' => 'jina',
        'phone' => 'simu',
        'address' => 'anwani',
        'code' => 'msimbo',
        'branch' => 'tawi',
        'company_name' => 'jina la kampuni',
        'company_email' => 'barua pepe ya kampuni',
        'business_address' => 'anwani ya biashara',
        'branch_name' => 'jina la tawi',
        'owner_name' => 'jina la mmiliki',
    ],
];
