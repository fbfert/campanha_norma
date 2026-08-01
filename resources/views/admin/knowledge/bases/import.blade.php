<x-layouts.app title="Importar bases" breadcrumbs="Inicio / Base de conhecimento / Importar">
    <x-import-panel
        :plan="$plan"
        :stored="$stored"
        preview-route="admin.knowledge.bases.import.preview"
        confirm-route="admin.knowledge.bases.import.confirm"
        export-route="admin.knowledge.bases.export"
        back-route="admin.knowledge.bases.index"
        label-singular="base"
        label-plural="bases"
        :columns="['base', 'identificador', 'descricao', 'proposito', 'politica_de_uso']"
        :ignored="['situacao', 'versao', 'provedor', 'documentos', 'documentos_aprovados', 'fluxos', 'aprovada_por', 'aprovada_em']"
    />

    <section class="card">
        <h3>O que esta importação não faz</h3>
        <p class="muted">
            <strong>Não traz documento nenhum.</strong> Documento oficial entra pela tela da base,
            passa por antivírus, extração e indexação, e so vale depois de alguém aprovar. Nada disso
            pode ser pulado por uma planilha.
        </p>
        <p class="muted">
            <strong>Não ativa base nem grava aprovação.</strong> Base nova nasce em rascunho e base
            existente mantem a situação que já tem, mesmo que a planilha diga outra coisa. Ativar e o
            ato que torna a base alcançável pela busca — tem tela própria e fica na auditoria com o
            nome de quem ativou.
        </p>
    </section>
</x-layouts.app>
