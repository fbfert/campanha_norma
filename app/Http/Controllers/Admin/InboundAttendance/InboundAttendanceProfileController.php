<?php

namespace App\Http\Controllers\Admin\InboundAttendance;

use App\Enums\ConversationFlowStatus;
use App\Enums\InboundAttendanceOutcome;
use App\Enums\InboundAttendanceProfileStatus;
use App\Enums\InboundOpeningMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\InboundAttendance\InboundAttendanceProfileRequest;
use App\Models\ConversationFlow;
use App\Models\InboundAttendanceProfile;
use App\Services\AuditLogger;
use App\Services\InboundAttendance\InboundAttendanceGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Perfis de atendimento: a configuração dessas conversas automáticas.
 *
 * É o formulário do lote sem a seção de contatos — aqui a seleção não é nossa,
 * é de quem escreve. O que sobra é o mesmo: qual mensagem, qual fluxo, em que
 * horário e com que teto.
 */
class InboundAttendanceProfileController extends Controller
{
    public function index(Request $request, InboundAttendanceGuard $guard): View
    {
        abort_unless($request->user()->can('inbound_attendance.view'), 403);

        $profiles = InboundAttendanceProfile::query()
            ->with('conversationFlow')
            ->withCount([
                'attempts as started_count' => fn ($query) => $query->where('outcome', InboundAttendanceOutcome::Started),
            ])
            ->orderBy('match_priority')
            ->orderBy('name')
            ->paginate(20);

        return view('admin.inbound-attendance.profiles.index', [
            'profiles' => $profiles,
            'enabled' => $guard->enabled(),
            'startedToday' => $guard->startedToday(),
            'exclusions' => implode("\n", app(\App\Services\InboundAttendance\InboundAttendanceRouter::class)->exclusionExpressions()),
            'internalPhones' => implode("\n", app(\App\Services\Conversations\InternalNumbers::class)->all()),
            'fallbackFaltando' => ! InboundAttendanceProfile::query()
                ->where('is_fallback', true)
                ->where('status', InboundAttendanceProfileStatus::Active)
                ->exists(),
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->can('inbound_attendance.manage_profiles'), 403);

        return view('admin.inbound-attendance.profiles.create', $this->formData(new InboundAttendanceProfile([
            'status' => InboundAttendanceProfileStatus::Draft,
            'opening_mode' => InboundOpeningMode::AiThenSurvey,
            'match_priority' => 100,
            'daily_start_limit' => 50,
            'homologation_threshold' => 5,
        ])));
    }

    public function store(InboundAttendanceProfileRequest $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validated();
        $data['is_fallback'] = $request->boolean('is_fallback');
        $data['created_by'] = $request->user()->id;
        $data['updated_by'] = $request->user()->id;

        $profile = InboundAttendanceProfile::create($data);

        $audit->log('inbound_attendance.profile_created', 'Perfil de atendimento criado.', $profile, null, [
            'name' => $profile->name,
            'status' => $profile->status->value,
        ], $request->user());

        return redirect()->route('admin.inbound-attendance.profiles.index')->with('success', 'Perfil criado com sucesso.');
    }

    public function edit(Request $request, InboundAttendanceProfile $profile): View
    {
        abort_unless($request->user()->can('inbound_attendance.manage_profiles'), 403);

        return view('admin.inbound-attendance.profiles.edit', $this->formData($profile));
    }

    public function update(InboundAttendanceProfileRequest $request, InboundAttendanceProfile $profile, AuditLogger $audit): RedirectResponse
    {
        $before = $profile->only(['name', 'status', 'is_fallback', 'match_expressions', 'opening_mode', 'presentation_text']);

        $data = $request->validated();
        $data['is_fallback'] = $request->boolean('is_fallback');
        $data['updated_by'] = $request->user()->id;

        $profile->update($data);

        $audit->log('inbound_attendance.profile_updated', 'Perfil de atendimento alterado.', $profile, $before, $profile->only([
            'name', 'status', 'is_fallback', 'match_expressions', 'opening_mode', 'presentation_text',
        ]), $request->user());

        return redirect()->route('admin.inbound-attendance.profiles.index')->with('success', 'Perfil atualizado com sucesso.');
    }

    public function destroy(Request $request, InboundAttendanceProfile $profile, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->can('inbound_attendance.manage_profiles'), 403);

        $profile->delete();

        $audit->log('inbound_attendance.profile_deleted', 'Perfil de atendimento excluído.', $profile, null, [
            'name' => $profile->name,
        ], $request->user());

        return redirect()->route('admin.inbound-attendance.profiles.index')->with('success', 'Perfil excluído.');
    }

    /**
     * Expressões que fazem a mensagem não ser atendida nem entrar na fila.
     *
     * Fica junto dos perfis, e não numa tela própria, porque é a mesma decisão
     * vista pelo avesso: o perfil diz quem atender, isto diz o que nem é
     * gente. Quem edita um vai querer editar o outro na mesma sessão.
     */
    public function updateExclusions(Request $request, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->can('inbound_attendance.manage_profiles'), 403);

        $validated = $request->validate([
            'exclusion_expressions' => ['nullable', 'string', 'max:8000'],
            'internal_phones' => ['nullable', 'string', 'max:4000'],
        ], [], [
            'exclusion_expressions' => 'expressões de exclusão',
            'internal_phones' => 'telefones da equipe',
        ]);

        // Só dígitos: o telefone chega normalizado num lugar e digitado à mão
        // no outro, e um `+55 49 9...` que não casa com `5549...` é uma trava
        // que existe no papel e não impede nada.
        $telefones = collect(preg_split('/[|,\r\n]+/', (string) ($validated['internal_phones'] ?? '')) ?: [])
            ->map(fn (string $item): string => preg_replace('/\D/', '', $item) ?? '')
            ->filter()
            ->unique()
            ->values();

        \App\Models\SystemSetting::query()->updateOrCreate(
            ['key' => 'conversations.internal_phones'],
            ['group' => 'conversations', 'value' => $telefones->implode('|'), 'type' => 'string', 'is_public' => false],
        );

        $anterior = (string) app(\App\Services\SystemSettingService::class)->get('inbound_attendance.exclusion_expressions', '');

        $lista = collect(preg_split('/[|\r\n]+/', (string) ($validated['exclusion_expressions'] ?? '')) ?: [])
            ->map(fn (string $item): string => trim($item))
            ->filter()
            ->unique()
            ->values();

        \App\Models\SystemSetting::query()->updateOrCreate(
            ['key' => 'inbound_attendance.exclusion_expressions'],
            ['group' => 'inbound_attendance', 'value' => $lista->implode('|'), 'type' => 'string', 'is_public' => false],
        );

        app(\App\Services\SystemSettingService::class)->forget();

        $audit->log('inbound_attendance.exclusions_updated', 'Expressões de exclusão alteradas.', null,
            ['exclusion_expressions' => $anterior],
            ['exclusion_expressions' => $lista->implode('|')],
            $request->user(),
        );

        // A lista nova vale para o que já está parado, senão a pessoa
        // acrescenta a frase, olha a fila e vê a mesma linha no mesmo lugar.
        $removidas = app(\App\Services\InboundAttendance\InboundAttendanceService::class)->applyExclusionsToPending();
        app(\App\Services\InboundAttendance\InboundAttendanceQueue::class)->forgetCount();

        $recado = $lista->count().' '.($lista->count() === 1 ? 'expressão de exclusão gravada.' : 'expressões de exclusão gravadas.');

        if ($removidas > 0) {
            $recado .= ' '.$removidas.' '.($removidas === 1 ? 'conversa saiu da fila.' : 'conversas saíram da fila.');
        }

        return redirect()->route('admin.inbound-attendance.profiles.index')->with('success', $recado);
    }

    /**
     * O botão de desligar tudo.
     *
     * Uma chave só, que suspende o atendimento a quem escreve primeiro sem
     * tocar nos lotes nem no resto da automação. Fica aqui, e não na tela de
     * configuração da automação conversacional, porque quem precisa dela
     * precisa dela agora — procurar a chave certa dentro de uma tela com trinta
     * campos é tempo que não se tem quando algo está saindo errado.
     */
    public function toggle(Request $request, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->can('inbound_attendance.manage_profiles'), 403);

        $ligar = $request->boolean('enabled');

        \App\Models\SystemSetting::query()->updateOrCreate(
            ['key' => 'inbound_attendance.enabled'],
            ['group' => 'inbound_attendance', 'value' => $ligar ? '1' : '0', 'type' => 'boolean', 'is_public' => false],
        );

        app(\App\Services\SystemSettingService::class)->forget();

        $audit->log(
            $ligar ? 'inbound_attendance.enabled' : 'inbound_attendance.disabled',
            $ligar ? 'Atendimento de entrada ligado.' : 'Atendimento de entrada desligado.',
            null, null, [], $request->user(),
        );

        return redirect()->route('admin.inbound-attendance.profiles.index')
            ->with('success', $ligar
                ? 'Atendimento de entrada ligado. Novas mensagens passam a ser atendidas automaticamente.'
                : 'Atendimento de entrada desligado. Nenhuma conversa nova será aberta sozinha.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(InboundAttendanceProfile $profile): array
    {
        return [
            'profile' => $profile,
            'statuses' => InboundAttendanceProfileStatus::cases(),
            'modes' => InboundOpeningMode::cases(),

            // Só fluxo ativo pode ser vinculado, mesma regra do lote: fluxo em
            // rascunho não tem pergunta homologada, e vincular um seria
            // prometer uma pesquisa que não existe.
            'flows' => ConversationFlow::query()
                ->where(function ($query) use ($profile): void {
                    $query->where('status', ConversationFlowStatus::Active);

                    // O fluxo já vinculado continua na lista mesmo se for
                    // pausado depois. Pausar interrompe a automação; sumir com
                    // a opção faria a edição do perfil trocar o fluxo sozinha.
                    if ($profile->conversation_flow_id) {
                        $query->orWhere('id', $profile->conversation_flow_id);
                    }
                })
                ->withCount(['activeQuestions'])
                ->orderBy('name')
                ->get(),
        ];
    }
}
