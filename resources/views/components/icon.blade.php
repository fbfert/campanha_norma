@props(['name', 'size' => 20])

{{--
    Um icone do sprite.

    `aria-hidden` por padrao porque o icone acompanha um rotulo em texto em
    praticamente todo uso: anunciar os dois faria o leitor de tela repetir a
    mesma informacao. Quando o icone for a unica coisa que o botao tem, passe um
    `aria-label` no elemento que o envolve.
--}}
<svg class="icon" width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24"
    fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"
    aria-hidden="true" focusable="false" {{ $attributes }}>
    <use href="#i-{{ $name }}"></use>
</svg>
