{{-- O Laravel traz uma view propria para este codigo, entao o fallback
     `4xx` nunca seria alcancado sem este arquivo. O texto continua num lugar
     so, em `4xx.blade.php`. --}}
@include('errors.4xx')
