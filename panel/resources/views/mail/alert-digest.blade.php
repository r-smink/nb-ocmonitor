Hoi {{ $user->name }},

{{ rtrim(\App\Ocm\Alerts\Message::title($items), '.') }}.

@foreach (collect($items)->groupBy('kind')->sortBy(fn ($g, $k) => array_search($k, array_keys(\App\Ocm\Alerts\Message::KINDS), true)) as $kind => $group)
{{ \App\Ocm\Alerts\Message::KINDS[$kind] }}
@foreach ($group as $item)
- {{ \App\Ocm\Alerts\Message::line($item) }}
  {{ $item['link'] }}
@endforeach

@endforeach
Je krijgt deze e-mail omdat meldingen aanstaan voor je account. Aanpassen: {{ route('account.show') }}#meldingen
