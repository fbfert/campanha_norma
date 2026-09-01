{{-- O aviso é permanente e nomeia quem está vendo.
     Documento nominal que circula sem dono vira captura de tela sem origem, e
     dizer o nome de quem abriu é o que faz alguém pensar duas vezes antes de
     encaminhar. --}}
<p class="alert alert-error">
    <strong>Documento nominal.</strong> Esta tela traz nome, cidade e o que cada pessoa escreveu.
    Não encaminhe e não publique. Aberta por {{ auth()->user()?->name }}.
</p>
