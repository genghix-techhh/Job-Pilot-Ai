@auth
    <x-layouts.app title="Privacy & data" max="max-w-4xl">
        @include('privacy._content')
    </x-layouts.app>
@else
    <x-layouts.guest title="Privacy & data" wide plain>
        @include('privacy._content')
    </x-layouts.guest>
@endauth
