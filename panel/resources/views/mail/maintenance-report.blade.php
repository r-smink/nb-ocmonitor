Beste {{ $customer->contact_name ?: $customer->name }},

In de bijlage vind je het onderhoudsrapport van je website{{ $customer->sites()->count() === 1 ? '' : 's' }} over de periode {{ $periodFrom->format('d-m-Y') }} tot en met {{ $periodTo->format('d-m-Y') }}.

Heb je vragen over het rapport? Beantwoord dan gewoon deze e-mail.

Met vriendelijke groet,

{{ $design['company'] }}
