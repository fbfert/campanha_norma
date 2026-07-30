@can('ai_insights.view')
    @php
        $latestInsight = $conversation->insights()->with('topic', 'topicLinks.topic')->first();
        $latestClassification = $conversation->messageClassifications()->first();
    @endphp

    @if($latestInsight || $latestClassification)
        <section class="card">
            <h2>
                Interpretação
                <span class="badge" style="background:#4f46e5;color:#fff;">Gerado por IA</span>
            </h2>
            <p class="muted">Resultado derivado da mensagem original, que permanece inalterada.</p>

            @if($latestClassification)
                <p><strong>Classificação:</strong> {{ $latestClassification->classification->label() }}
                    <span class="muted">({{ $latestClassification->source->label() }})</span>
                </p>
                @if($latestClassification->confidence !== null)
                    <p><strong>Confiança:</strong> {{ number_format($latestClassification->confidence, 2) }}</p>
                @endif
            @endif

            @if($latestInsight)
                <p><strong>Resumo:</strong> {{ $latestInsight->summary ?? '-' }}</p>
                <p><strong>Temas:</strong>
                    @if($latestInsight->topic)
                        <span class="badge" style="background:{{ $latestInsight->topic->color ?? '#64748b' }};color:#fff;">{{ $latestInsight->topic->name }}</span>
                    @endif
                    @foreach($latestInsight->topicLinks->where('role', 'secondary') as $link)
                        <span class="badge" style="background:{{ $link->topic?->color ?? '#64748b' }};color:#fff;">{{ $link->topic?->name }}</span>
                    @endforeach
                </p>
                <p><strong>Urgência:</strong> {{ $latestInsight->urgency?->label() ?? '-' }}</p>
                @if($latestInsight->requires_human_review && ! $latestInsight->reviewed)
                    <p><strong>Revisão humana:</strong>
                        {{ \App\Enums\InsightReviewReason::tryFrom((string) $latestInsight->review_reason)?->label() ?? $latestInsight->review_reason }}
                    </p>
                @endif
                <a class="btn ghost" href="{{ route('admin.ai-insights.show', $latestInsight) }}">Abrir insight</a>
            @elseif($latestClassification?->requires_human_review)
                <p><strong>Revisão humana:</strong>
                    {{ \App\Enums\InsightReviewReason::tryFrom((string) $latestClassification->review_reason)?->label() ?? $latestClassification->review_reason }}
                </p>
            @endif
        </section>
    @endif
@endcan
