<?php

namespace App\Services\MessageBatches;

use App\Enums\ContactSource;
use App\Models\Contact;
use App\Services\Contacts\ContactQueryService;
use App\Services\SystemSettingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class ContactSelectionService
{
    public function __construct(
        private readonly ContactQueryService $queryService,
        private readonly RandomSelectionService $random,
        private readonly SystemSettingService $settings,
    ) {}

    public function select(array $data): Collection
    {
        $type = $data['selection_type'] ?? 'manual';
        $max = (int) $this->settings->get('messages.maximum_batch_size', 1000);
        $incluirGatilho = (bool) ($data['include_trigger_contacts'] ?? false);

        if ($type === 'manual') {
            $ids = collect($data['contact_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
            if ($ids->isEmpty()) {
                throw ValidationException::withMessages(['contact_ids' => 'Selecione pelo menos um contato.']);
            }

            $this->recusarGatilhoNaSelecaoManual($ids, $incluirGatilho);

            return Contact::query()->with('tags')->whereIn('id', $ids)->limit($max)->get();
        }

        $query = $this->queryService->query($data['filters'] ?? []);
        $this->aplicarBarreiraDeFinalidade($query, $incluirGatilho);
        $contacts = $query->limit($max)->get();

        if ($type === 'random_sample') {
            $quantity = max(1, (int) ($data['random_quantity'] ?? 0));
            if ($contacts->count() < $quantity) {
                throw ValidationException::withMessages(['random_quantity' => "Foram encontrados somente {$contacts->count()} contatos nos filtros."]);
            }
            $ids = $this->random->sample($contacts->pluck('id')->all(), $quantity, $data['random_seed'] ?? null);

            return Contact::query()->with('tags')->whereIn('id', $ids)->get();
        }

        return $contacts;
    }

    /**
     * Barreira de finalidade.
     *
     * Quem se inscreveu numa campanha por palavra-chave consentiu em participar
     * dela, e não em receber disparo. As duas coisas são consentimento, e não
     * são o mesmo consentimento — tratar uma pela outra é usar um opt-in
     * específico como se fosse geral, que é exatamente o que a LGPD chama de
     * desvio de finalidade.
     *
     * A barreira mora aqui, e não na tela, porque barreira que depende de a
     * tela lembrar de aplicar é barreira que um dia falha: basta uma tela nova.
     */
    private function aplicarBarreiraDeFinalidade(Builder $query, bool $incluirGatilho): void
    {
        if ($incluirGatilho) {
            return;
        }

        // `contacts.source` é obrigatória no schema, então não há caso de
        // origem nula para tratar aqui.
        $query->where('source', '!=', ContactSource::Gatilho->value);
    }

    /**
     * Na seleção manual a barreira recusa em vez de filtrar.
     *
     * Tirar em silêncio um contato que o operador clicou produz um lote menor
     * do que ele montou, sem dizer por quê — e o que ele conclui é que o
     * sistema perdeu gente. Recusar com a contagem ensina a regra na primeira
     * vez que ela aparece.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $ids
     */
    private function recusarGatilhoNaSelecaoManual(\Illuminate\Support\Collection $ids, bool $incluirGatilho): void
    {
        if ($incluirGatilho) {
            return;
        }

        $bloqueados = Contact::query()
            ->whereIn('id', $ids)
            ->where('source', ContactSource::Gatilho->value)
            ->count();

        if ($bloqueados === 0) {
            return;
        }

        $pessoas = $bloqueados === 1 ? '1 contato veio' : "{$bloqueados} contatos vieram";

        throw ValidationException::withMessages([
            'contact_ids' => "{$pessoas} de campanha por palavra-chave. Essas pessoas consentiram em participar da campanha, não em receber disparo. Para incluí-las mesmo assim, marque a inclusão explícita.",
        ]);
    }
}
