{{-- Laatste sectie: de klant van deze site, direct te bekijken en aan te passen. --}}
@php($customer = $site->customer)
<section class="sd-section" id="sd-klant" data-spy="klant">
  <div class="panel">
    <div class="panel-head">
      <span class="sd-ic">@include('partials.icon', ['name' => 'briefcase', 'size' => 18])</span>
      <h3>Klant</h3>
      @if ($customer)
        <span class="hint">{{ $customer->contractLabel() }} · <a href="{{ route('customers.show', $customer) }}">Klantpagina →</a></span>
      @endif
    </div>

    @if ($customer)
      @can('manage-sites')
        <form class="form" method="POST" action="{{ route('sites.customer.update', $site) }}">
          @csrf
          @method('PUT')
          <div class="stack">
            <label>Naam klant of bedrijf<input name="name" value="{{ old('name', $customer->name) }}" required maxlength="120"></label>
            <div class="form-row">
              <label>Contactpersoon<input name="contact_name" value="{{ old('contact_name', $customer->contact_name) }}" maxlength="120"></label>
              <label>Telefoon<input name="phone" value="{{ old('phone', $customer->phone) }}" maxlength="40"></label>
            </div>
            <div class="form-row">
              <label>E-mail (ontvangt het rapport)<input type="email" name="email" value="{{ old('email', $customer->email) }}" required maxlength="190"></label>
              <label>Ook in cc<input name="report_cc" value="{{ old('report_cc', $customer->report_cc) }}" maxlength="255" placeholder="marketing@klant.nl, eigenaar@klant.nl"></label>
            </div>
            <label>Notities (intern)<textarea name="notes" rows="3" maxlength="5000">{{ old('notes', $customer->notes) }}</textarea></label>
          </div>
          @php($siteCount = $customer->sites()->count())
          <div class="actions">
            <button class="btn" type="submit">Opslaan</button>
            @if ($siteCount > 1)<span class="note">Geldt voor alle {{ $siteCount }} sites van deze klant.</span>@endif
          </div>
        </form>
      @else
        <table class="data">
          <tr><td>Naam</td><td>{{ $customer->name }}</td></tr>
          <tr><td>Contactpersoon</td><td>{{ $customer->contact_name ?: '–' }}</td></tr>
          <tr><td>Telefoon</td><td>@if ($customer->phone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $customer->phone) }}">{{ $customer->phone }}</a>@else – @endif</td></tr>
          <tr><td>E-mail</td><td>@if ($customer->email)<a href="mailto:{{ $customer->email }}">{{ $customer->email }}</a>@else – @endif</td></tr>
          @if ($customer->report_cc)<tr><td>Cc</td><td>{{ $customer->report_cc }}</td></tr>@endif
          @if ($customer->notes)<tr><td>Notities</td><td>{!! nl2br(e($customer->notes)) !!}</td></tr>@endif
        </table>
      @endcan
    @else
      <p class="muted">Deze site hoort nog niet bij een klant.@if ($site->client_name) Bekende naam: {{ $site->client_name }}.@endif</p>
    @endif

    @can('manage-sites')
      @php($customers = \App\Models\Customer::query()->orderBy('name')->get(['id', 'name']))
      <form method="POST" action="{{ route('sites.customer.assign', $site) }}" class="row-actions mt">
        @csrf
        <select name="customer_id" aria-label="Klant">
          <option value="">{{ $customer ? '— losmaken van de klant —' : '— kies een klant —' }}</option>
          @foreach ($customers as $c)
            <option value="{{ $c->id }}" @selected($customer?->id === $c->id)>{{ $c->name }}</option>
          @endforeach
        </select>
        <button class="btn ghost small" type="submit">{{ $customer ? 'Andere klant' : 'Koppelen aan klant' }}</button>
        <a class="btn ghost small" href="{{ route('customers.create') }}">Nieuwe klant</a>
      </form>
    @endcan
  </div>
</section>
