<x-layouts.app title="Importar temas" breadcrumbs="Inicio / Pesquisa conversacional / Temas / Importar">
    <x-import-panel
        :plan="$plan"
        :stored="$stored"
        preview-route="admin.insight-topics.import.preview"
        confirm-route="admin.insight-topics.import.confirm"
        export-route="admin.insight-topics.export"
        back-route="admin.insight-topics.index"
        label-singular="tema"
        label-plural="temas"
        :columns="['tema', 'identificador', 'tema_pai', 'descricao', 'sinonimos', 'cor', 'ordem', 'situacao']"
        :ignored="['insights', 'fallback']"
    />

    <section class="card">
        <h3>Duas notas sobre esta importação</h3>
        <p class="muted">
            <strong>O tema pai e indicado pelo identificador</strong>, não pelo nome — nome não e
            único. O vínculo e aplicado depois de todas as linhas, então o pai pode aparecer depois
            do filho no arquivo. Pai que não existe e ignorado, e não criado a partir da referência.
        </p>
        <p class="muted">
            <strong>A coluna <code>fallback</code> não e importada.</strong> Ela decide para onde vai
            o que o modelo não soube classificar, so pode haver um, e trocar isso por planilha
            alteraria o comportamento da classificação sem passar pela tela que explica o que isso
            significa.
        </p>
    </section>
</x-layouts.app>
