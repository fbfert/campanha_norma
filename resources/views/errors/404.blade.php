{{-- O Laravel traz uma view própria para este código, então o fallback
     `4xx` nunca seria alcançado sem este arquivo. O texto continua num lugar
     so, em `4xx.blade.php`. --}}
@include('errors.4xx')
