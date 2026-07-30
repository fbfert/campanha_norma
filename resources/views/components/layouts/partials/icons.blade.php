{{--
    Sprite de icones.

    SVG inline em vez de biblioteca externa. Três razões:

    - nenhuma dependência nova para versionar, atualizar e auditar;
    - funciona sem rede e sem CDN, o que importa num sistema que roda em
      servidor próprio e precisa abrir mesmo com a internet ruim;
    - `currentColor` faz o icone herdar a cor do texto ao redor, então ele
      acompanha estado ativo, foco e tema sem uma linha de CSS extra.

    Todos os desenhos usam a mesma gramática: traço de 2, sem preenchimento,
    pontas e junções arredondadas, dentro de uma caixa de 24. Icones de origens
    diferentes numa mesma tela são a forma mais rapida de um sistema parecer
    remendado.

    O sprite e renderizado uma vez por página e cada uso vira um `<use>`, que
    não repete o desenho no HTML.
--}}
<svg xmlns="http://www.w3.org/2000/svg" style="display:none" aria-hidden="true">
    <defs>
        <g id="i-home"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20h14V9.5"/><path d="M9.5 20v-6h5v6"/></g>
        <g id="i-inbox"><path d="M3 12h5l2 3h4l2-3h5"/><path d="M5.5 5h13l2.5 7v7H3v-7z"/></g>
        <g id="i-chat"><path d="M20 12a7.5 7.5 0 0 1-10.9 6.7L4 20l1.3-4.1A7.5 7.5 0 1 1 20 12z"/></g>
        <g id="i-reply"><path d="M9 8 4 12l5 4"/><path d="M4 12h9a6 6 0 0 1 6 6v2"/></g>
        <g id="i-poll"><path d="M5 3h14v18H5z"/><path d="M9 8h6"/><path d="M9 12h6"/><path d="M9 16h3"/></g>
        <g id="i-flow"><circle cx="6" cy="6" r="2.5"/><circle cx="18" cy="18" r="2.5"/><circle cx="6" cy="18" r="2.5"/><path d="M6 8.5v7"/><path d="M8.5 18h7"/><path d="M8 6h6a4 4 0 0 1 4 4v5"/></g>
        <g id="i-chart"><path d="M4 20V4"/><path d="M4 20h16"/><path d="M8 20v-6"/><path d="M13 20V8"/><path d="M18 20v-9"/></g>
        <g id="i-tag"><path d="M3 12V4h8l9 9-8 8z"/><circle cx="7.5" cy="7.5" r="1.3"/></g>
        <g id="i-map"><path d="M12 21s7-6.3 7-11a7 7 0 1 0-14 0c0 4.7 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></g>
        <g id="i-megaphone"><path d="M4 10v4h3l7 4V6l-7 4z"/><path d="M18 9a4 4 0 0 1 0 6"/><path d="M7 14v4h3"/></g>
        <g id="i-question"><circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.6 2.6 0 1 1 3.4 2.5c-.6.2-.9.8-.9 1.4v.6"/><path d="M12 17.2h.01"/></g>
        <g id="i-users"><circle cx="9" cy="8" r="3.2"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M16 5.5a3.2 3.2 0 0 1 0 5.6"/><path d="M17.5 20a5.5 5.5 0 0 0-2.2-4.4"/></g>
        <g id="i-upload"><path d="M12 15V4"/><path d="m8 8 4-4 4 4"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/></g>
        <g id="i-download"><path d="M12 4v11"/><path d="m8 11 4 4 4-4"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/></g>
        <g id="i-file"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6"/><path d="M9 17h4"/></g>
        <g id="i-layers"><path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/></g>
        <g id="i-play"><circle cx="12" cy="12" r="9"/><path d="m10 8.5 6 3.5-6 3.5z"/></g>
        <g id="i-clock"><circle cx="12" cy="12" r="9"/><path d="M12 7v5.2l3.2 2"/></g>
        <g id="i-report"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 17v-3"/><path d="M12 17v-5"/><path d="M15 17v-2"/></g>
        <g id="i-send"><path d="m21 3-9.5 9.5"/><path d="M21 3 14.5 21 11.5 13 3.5 10z"/></g>
        <g id="i-sparkles"><path d="m12 3 1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M18.5 16.5 19 18l1.5.5-1.5.5-.5 1.5-.5-1.5L16.5 18l1.5-.5z"/></g>
        <g id="i-tree"><path d="M4 6h5"/><path d="M4 6v12h5"/><path d="M4 12h5"/><path d="M11 4h9"/><path d="M11 10h9"/><path d="M11 16h9"/></g>
        <g id="i-book"><path d="M5 4.5A1.5 1.5 0 0 1 6.5 3H19v14H6.5A1.5 1.5 0 0 0 5 18.5z"/><path d="M5 18.5A1.5 1.5 0 0 1 6.5 17H19v4H6.5A1.5 1.5 0 0 1 5 19.5z"/></g>
        <g id="i-mind-map"><circle cx="5" cy="12" r="2.2"/><circle cx="18.5" cy="5.5" r="2.2"/><circle cx="18.5" cy="12" r="2.2"/><circle cx="18.5" cy="18.5" r="2.2"/><path d="M7.2 12h2.3v-6h6.8"/><path d="M7.2 12h9.1"/><path d="M7.2 12h2.3v6h6.8"/></g>
        <g id="i-search"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.4-4.4"/></g>
        <g id="i-refresh"><path d="M20 12a8 8 0 1 1-2.6-5.9"/><path d="M20 4v4.5h-4.5"/></g>
        <g id="i-gauge"><path d="M4 17a8 8 0 1 1 16 0"/><path d="m12 13 4-3.5"/><circle cx="12" cy="14" r="1.4"/></g>
        <g id="i-activity"><path d="M3 12h4l2.5-7 5 14 2.5-7h4"/></g>
        <g id="i-plug"><path d="M9 3v6"/><path d="M15 3v6"/><path d="M6 9h12v3a6 6 0 0 1-12 0z"/><path d="M12 18v3"/></g>
        <g id="i-settings"><circle cx="12" cy="12" r="3"/><path d="M12 2.5v3M12 18.5v3M21.5 12h-3M5.5 12h-3M18.7 5.3l-2.1 2.1M7.4 16.6l-2.1 2.1M18.7 18.7l-2.1-2.1M7.4 7.4 5.3 5.3"/></g>
        <g id="i-phone"><rect x="6" y="2.5" width="12" height="19" rx="2.5"/><path d="M10.5 18.5h3"/></g>
        <g id="i-shield"><path d="M12 3 5 6v5.5c0 4.3 3 8.1 7 9.5 4-1.4 7-5.2 7-9.5V6z"/><path d="m9.2 12 2 2 3.6-3.6"/></g>
        <g id="i-pulse"><path d="M20.5 12h-3l-2 5-4-11-2 6H4"/><path d="M12 20.5S4 15 4 9.8A4.3 4.3 0 0 1 12 7a4.3 4.3 0 0 1 8 2.8"/></g>
        <g id="i-wrench"><path d="M15.5 3.5a5 5 0 0 0-4.6 7L3.5 17.9a2 2 0 0 0 2.8 2.8l7.4-7.4a5 5 0 0 0 6.2-6.6l-2.9 2.9-2.6-.7-.7-2.6z"/></g>
        <g id="i-scroll"><path d="M6 4h11a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M5 6a2 2 0 0 1 4 0v2H5z"/><path d="M10 11h6"/><path d="M10 15h4"/></g>
        <g id="i-bell"><path d="M18 9a6 6 0 1 0-12 0c0 5-2 6-2 6h16s-2-1-2-6"/><path d="M13.7 19a2 2 0 0 1-3.4 0"/></g>
        <g id="i-check"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.2 2.4 2.4 4.6-4.8"/></g>
        <g id="i-alert"><path d="M12 4.5 21 19.5H3z"/><path d="M12 10v4"/><path d="M12 17h.01"/></g>
        <g id="i-info"><circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/></g>
        <g id="i-empty"><path d="M4 8h16v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/><path d="M4 8 6 4h12l2 4"/><path d="M10 12h4"/></g>
        <g id="i-plus"><path d="M12 5v14"/><path d="M5 12h14"/></g>
        <g id="i-logout"><path d="M14 5V4a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1"/><path d="M18 12H9"/><path d="m15.5 8.5 3.5 3.5-3.5 3.5"/></g>
        <g id="i-user"><circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/></g>
    </defs>
</svg>
