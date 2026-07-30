{{-- Taxa sempre acompanhada do par que a formou. Sem denominador, um traco. --}}
@if($rate['value'] === null)
    <strong title="Sem denominador: nenhum caso elegivel no periodo.">—</strong>
    <span class="muted">(0 de 0)</span>
@else
    <strong>{{ number_format($rate['value'], 1, ',', '.') }}%</strong>
    <span class="muted">({{ $rate['numerator'] }} de {{ $rate['denominator'] }})</span>
@endif
