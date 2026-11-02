@if (session('status'))
  <div class="flash ok">{{ session('status') }}</div>
@endif
@if ($errors->any())
  <div class="flash err">
    <ul>
      @foreach ($errors->all() as $error)
        <li>{{ $error }}</li>
      @endforeach
    </ul>
  </div>
@endif
