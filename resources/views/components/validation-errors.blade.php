@if ($errors->any())
    <div role="alert" {{ $attributes->merge(['class' => 'au-errors']) }}>
        <div>{{ __('That did not go through.') }}</div>

        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
