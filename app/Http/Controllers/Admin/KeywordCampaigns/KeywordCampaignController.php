<?php

namespace App\Http\Controllers\Admin\KeywordCampaigns;

use App\Enums\ConversationFlowStatus;
use App\Enums\KeywordCampaignStatus;
use App\Enums\KeywordParticipationEligibility;
use App\Enums\KeywordParticipationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\KeywordCampaigns\KeywordCampaignRequest;
use App\Models\ConversationFlow;
use App\Models\KeywordCampaign;
use App\Services\AuditLogger;
use App\Services\KeywordCampaigns\ConfirmationThrottle;
use App\Services\KeywordCampaigns\KeywordListNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * As campanhas por palavra-chave.
 *
 * O que se cadastra aqui é: quais palavras pegam, por quanto tempo, com que
 * teto, e o que a pessoa recebe de volta em cada desfecho.
 */
class KeywordCampaignController extends Controller
{
    public function index(Request $request, ConfirmationThrottle $throttle): View
    {
        abort_unless($request->user()->can('keyword_campaigns.view'), 403);

        $campaigns = KeywordCampaign::query()
            ->withCount([
                'participations as enrolled_count' => fn ($query) => $query->whereIn('status', [
                    KeywordParticipationStatus::Valida,
                    KeywordParticipationStatus::SemNome,
                ]),
                'participations as pending_review_count' => fn ($query) => $query
                    ->whereIn('status', [KeywordParticipationStatus::Valida, KeywordParticipationStatus::SemNome])
                    ->where('eligibility', KeywordParticipationEligibility::NaoVerificada),
                'participations as ambiguous_count' => fn ($query) => $query
                    ->where('status', KeywordParticipationStatus::EmRevisao),
            ])
            ->orderByDesc('id')
            ->paginate(20);

        return view('admin.keyword-campaigns.index', [
            'campaigns' => $campaigns,
            'tetoPorMinuto' => $throttle->tetoPorMinuto(),
            'intervaloMinimo' => $throttle->intervaloMinimoSegundos(),
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->can('keyword_campaigns.manage'), 403);

        return view('admin.keyword-campaigns.create', $this->formData(new KeywordCampaign([
            'status' => KeywordCampaignStatus::Rascunho,
            'starts_at' => now()->startOfHour(),
            'ends_at' => now()->addWeek()->startOfHour(),
            'confirmation_text' => 'Inscrição confirmada! Boa sorte.',
            'already_enrolled_text' => 'Você já está inscrito nesta campanha.',
        ])));
    }

    public function store(KeywordCampaignRequest $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validated();
        $data['keywords'] = $request->palavrasNormalizadas();
        $data['created_by'] = $request->user()->id;
        $data['updated_by'] = $request->user()->id;

        $campaign = KeywordCampaign::create($data);

        $audit->log('keyword_campaign.created', 'Campanha por palavra-chave criada.', $campaign, null, [
            'name' => $campaign->name,
            'status' => $campaign->status->value,
            'keywords' => $campaign->keywords,
        ], $request->user());

        return redirect()
            ->route('admin.keyword-campaigns.index')
            ->with('success', 'Campanha criada.')
            ->with('avisos', app(KeywordListNormalizer::class)->avisos($campaign->keywordList()));
    }

    public function edit(Request $request, KeywordCampaign $campaign): View
    {
        abort_unless($request->user()->can('keyword_campaigns.manage'), 403);

        return view('admin.keyword-campaigns.edit', $this->formData($campaign));
    }

    public function update(KeywordCampaignRequest $request, KeywordCampaign $campaign, AuditLogger $audit): RedirectResponse
    {
        /*
         | Campanha congelada não se edita.
         |
         | Mudar a palavra, a vigência ou o texto depois de a lista ter sido
         | fechada muda o que a campanha era quando as pessoas se inscreveram —
         | e é justamente isso que o congelamento existe para impedir.
         */
        if ($campaign->estaCongelada()) {
            return redirect()
                ->route('admin.keyword-campaigns.index')
                ->withErrors(['status' => 'Esta campanha já teve a lista congelada e não pode mais ser alterada.']);
        }

        $antes = $campaign->only(['name', 'status', 'keywords', 'starts_at', 'ends_at', 'participant_limit']);

        $data = $request->validated();
        $data['keywords'] = $request->palavrasNormalizadas();
        $data['updated_by'] = $request->user()->id;

        $campaign->update($data);

        $audit->log('keyword_campaign.updated', 'Campanha por palavra-chave alterada.', $campaign, $antes, $campaign->only([
            'name', 'status', 'keywords', 'starts_at', 'ends_at', 'participant_limit',
        ]), $request->user());

        return redirect()
            ->route('admin.keyword-campaigns.index')
            ->with('success', 'Campanha atualizada.')
            ->with('avisos', app(KeywordListNormalizer::class)->avisos($campaign->keywordList()));
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(KeywordCampaign $campaign): array
    {
        return [
            'campaign' => $campaign,
            'statuses' => [
                KeywordCampaignStatus::Rascunho,
                KeywordCampaignStatus::Ativa,
                KeywordCampaignStatus::Encerrada,
            ],
            'keywordsTexto' => app(KeywordListNormalizer::class)->paraFormulario($campaign->keywords),

            /*
             | Só fluxo ativo pode ser vinculado, mesma regra do lote: fluxo em
             | rascunho não tem pergunta homologada, e vincular um seria
             | prometer uma pesquisa que não existe. O já vinculado continua na
             | lista mesmo se for pausado depois — sumir com a opção faria a
             | edição da campanha trocar o fluxo sozinha.
             */
            'flows' => ConversationFlow::query()
                ->where(function ($query) use ($campaign): void {
                    $query->where('status', ConversationFlowStatus::Active);

                    if ($campaign->conversation_flow_id) {
                        $query->orWhere('id', $campaign->conversation_flow_id);
                    }
                })
                ->withCount(['activeQuestions'])
                ->orderBy('name')
                ->get(),
            'avisos' => app(KeywordListNormalizer::class)->avisos($campaign->keywordList()),
        ];
    }
}
