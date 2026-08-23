<?php

namespace App\Http\Controllers\Admin\Cleanup;

use App\Http\Controllers\Controller;
use App\Models\CleanupOperation;
use App\Models\Contact;
use App\Services\Cleanup\CleanupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Sistema › Limpeza.
 *
 * Três telas: procurar a pessoa, ver e escolher o que ela participou, e a
 * lixeira do que já foi limpo.
 *
 * A confirmação pede o telefone digitado e um motivo escrito. Não é cerimônia:
 * o telefone digitado é o que impede limpar a pessoa errada depois de uma busca
 * que trouxe dois homônimos, e o motivo é a única coisa que explica a remoção
 * para quem for ler a auditoria daqui a um ano.
 */
class CleanupController extends Controller
{
    public function index(Request $request, CleanupService $cleanup): View
    {
        abort_unless($request->user()->can('cleanup.view'), 403);

        $termo = trim((string) $request->query('busca', ''));
        $contatos = collect();

        if ($termo !== '') {
            $digitos = preg_replace('/\D/', '', $termo) ?? '';

            $contatos = Contact::query()
                ->where(function ($query) use ($termo, $digitos): void {
                    $query->where('name', 'like', "%{$termo}%");

                    if ($digitos !== '') {
                        $query->orWhere('phone_normalized', 'like', "%{$digitos}%")
                            ->orWhere('phone', 'like', "%{$digitos}%");
                    }
                })
                ->orderBy('name')
                ->limit(25)
                ->get();
        }

        return view('admin.cleanup.index', [
            'termo' => $termo,
            'contatos' => $contatos,
            'diasNaLixeira' => $cleanup->diasDeRetencao(),
            'naLixeira' => CleanupOperation::query()->naLixeira()->count(),
        ]);
    }

    public function show(Request $request, Contact $contact, CleanupService $cleanup): View
    {
        abort_unless($request->user()->can('cleanup.view'), 403);

        return view('admin.cleanup.show', [
            'contact' => $contact,
            'itens' => $cleanup->inventario($contact),
            'diasNaLixeira' => $cleanup->diasDeRetencao(),
            'limpezas' => CleanupOperation::query()
                ->where('contact_id', $contact->id)
                ->latest('executed_at')
                ->limit(10)
                ->get(),
        ]);
    }

    public function store(Request $request, Contact $contact, CleanupService $cleanup): RedirectResponse
    {
        abort_unless($request->user()->can('cleanup.execute'), 403);

        $dados = $request->validate([
            'modo' => ['required', 'in:selecionados,tudo'],
            'itens' => ['array'],
            'itens.*' => ['string'],
            'motivo' => ['required', 'string', 'min:10', 'max:500'],
            'telefone_confirmado' => ['required', 'string'],
            'confirmo_sorteio' => ['nullable'],
        ], [], [
            'itens' => 'participações',
            'motivo' => 'motivo',
            'telefone_confirmado' => 'telefone de confirmação',
        ]);

        $this->conferirTelefone($contact, $dados['telefone_confirmado']);

        $inventario = collect($cleanup->inventario($contact));

        /*
         | "Limpar tudo" resolve aqui, e não no navegador.
         |
         | A alternativa seria um script que marca as caixas antes de enviar, e
         | aí o botão passaria a depender de JavaScript ter carregado numa tela
         | que precisa abrir com a internet ruim. O servidor já tem a lista
         | inteira: usar a que ele tem é mais curto e não falha calado.
         */
        $chaves = $dados['modo'] === 'tudo'
            ? $inventario->pluck('chave')->all()
            : ($dados['itens'] ?? []);

        $this->exigirConfirmacaoDeSorteio($inventario, $chaves, $request->boolean('confirmo_sorteio'));

        $operacao = $cleanup->limpar($contact, $chaves, $dados['motivo'], $request->user());

        return redirect()
            ->route('admin.cleanup.show', $contact)
            ->with('success', "Limpeza concluída: {$operacao->items_count} "
                .($operacao->items_count === 1 ? 'participação removida' : 'participações removidas')
                .'. Dá para restaurar na lixeira até '.$operacao->expires_at->format('d/m/Y H:i').'.');
    }

    public function trash(Request $request, CleanupService $cleanup): View
    {
        abort_unless($request->user()->can('cleanup.view'), 403);

        return view('admin.cleanup.trash', [
            'limpezas' => CleanupOperation::query()
                ->with(['items', 'executor', 'restorer'])
                ->latest('executed_at')
                ->paginate(20),
            'diasNaLixeira' => $cleanup->diasDeRetencao(),
        ]);
    }

    public function restore(Request $request, CleanupOperation $operation, CleanupService $cleanup): RedirectResponse
    {
        abort_unless($request->user()->can('cleanup.restore'), 403);
        $request->validate(['confirm' => ['accepted']]);

        $cleanup->restaurar($operation, $request->user());

        return back()->with('success', 'Limpeza restaurada: tudo voltou para onde estava.');
    }

    /**
     * Inscrição já sorteada só sai com uma confirmação a mais.
     *
     * A escolha foi permitir, não bloquear — mas permitir em silêncio faria a
     * remoção de um ganhador custar exatamente o mesmo que a de um inscrito
     * qualquer, e não custa. A caixa extra é o que obriga a pessoa a ver o que
     * está fazendo antes de fazer.
     *
     * @param  Collection<int, array<string, mixed>>  $inventario
     * @param  list<string>  $chaves
     *
     * @throws ValidationException
     */
    private function exigirConfirmacaoDeSorteio($inventario, array $chaves, bool $confirmado): void
    {
        if ($confirmado) {
            return;
        }

        $sorteadas = $inventario
            ->whereIn('chave', $chaves)
            ->filter(fn (array $item): bool => $item['envolve_sorteio'] ?? false);

        if ($sorteadas->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'confirmo_sorteio' => 'Entre o que você marcou há inscrição que já foi sorteada. '
                .'Marque a confirmação do sorteio para seguir: remover reescreve um resultado que já foi apurado.',
        ]);
    }

    /**
     * O telefone digitado precisa bater com o do contato.
     *
     * Compara só os dígitos porque quem confere lê o número na tela e digita do
     * jeito que está acostumado — com parêntese, com traço, com ou sem o 55 na
     * frente. Recusar por causa de pontuação treinaria a pessoa a copiar e
     * colar, e colar de volta o que a tela mostrou não confere nada.
     *
     * @throws ValidationException
     */
    private function conferirTelefone(Contact $contact, string $digitado): void
    {
        $esperado = preg_replace('/\D/', '', (string) ($contact->phone_normalized ?? $contact->phone ?? ''));
        $informado = preg_replace('/\D/', '', $digitado);

        if ($esperado === '' || $informado === '' || ! str_ends_with($esperado, substr($informado, -8)) || strlen($informado) < 8) {
            throw ValidationException::withMessages([
                'telefone_confirmado' => 'O telefone digitado não confere com o do contato. Confira antes de limpar: a partir daqui a participação sai do ar para todo o sistema.',
            ]);
        }
    }
}
