<?php

namespace App\Services\KeywordCampaigns;

use App\Enums\KeywordParticipationEligibility;
use App\Enums\KeywordParticipationStatus;
use App\Models\KeywordCampaign;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Contacts\PhoneNormalizerService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Marca quem é aluno, a partir da lista exportada do portal.
 *
 * A palavra é **marca**. A importação não filtra inscrição, não recusa ninguém
 * e não cria contato: ela só carimba o que já está lá. Quem não casar continua
 * em `nao_verificada` e vai para a fila de conferência humana.
 *
 * A alternativa — verificar na entrada e recusar quem não é aluno — cria atrito
 * no único momento em que a pessoa está engajada, e recusa por engano quem
 * trocou de número.
 */
class StudentEligibilityImporter
{
    /**
     * O cabeçalho é identificador, lido pelo código: sem acento.
     *
     * Aceita as três formas que aparecem numa exportação de portal, para o
     * operador não precisar renomear coluna antes de subir o arquivo.
     *
     * @var list<string>
     */
    private const CABECALHOS_DE_TELEFONE = ['telefone', 'phone', 'celular', 'whatsapp'];

    public function __construct(
        private readonly PhoneNormalizerService $phones,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{marked: int, already_marked: int, unmatched: int, invalid_phones: int}
     */
    public function importar(KeywordCampaign $campaign, UploadedFile $arquivo, ?User $usuario = null): array
    {
        $telefones = $this->lerTelefones($arquivo);

        return $this->marcar($campaign, $telefones, $usuario);
    }

    /**
     * Marca as participações cujo telefone está na lista.
     *
     * Idempotente: rodar duas vezes produz o mesmo estado, e rodar de novo com
     * um arquivo maior só acrescenta marcações. Quem já foi conferido por um
     * humano não é sobrescrito — a decisão da pessoa vence a do arquivo.
     *
     * @param  list<string>  $telefonesBrutos
     * @return array{marked: int, already_marked: int, unmatched: int, invalid_phones: int}
     */
    public function marcar(KeywordCampaign $campaign, array $telefonesBrutos, ?User $usuario = null): array
    {
        $invalidos = 0;
        $normalizados = [];

        foreach ($telefonesBrutos as $bruto) {
            $resultado = $this->phones->normalize($bruto);

            if (! $resultado->valid()) {
                $invalidos++;

                continue;
            }

            $normalizados[$resultado->normalized] = true;

            /*
             | O nono dígito.
             |
             | O portal pode ter o número com nove dígitos e o WhatsApp entregar
             | com oito, ou o contrário. Guardar as duas formas faz o casamento
             | acontecer sem depender de qual lado foi cadastrado primeiro.
             */
            $variante = $this->phones->alternateBrazilianMobileDigits($resultado->normalized);

            if ($variante !== null) {
                $normalizados[$variante] = true;
            }
        }

        /*
         | De volta para string.
         |
         | Telefone é dígito, e o PHP converte chave de array numérica em
         | inteiro sozinho: `$a['5549999990001']` vira `$a[5549999990001]`. A
         | comparação estrita contra a coluna, que é string, falhava sempre — e
         | falhava em silêncio, marcando zero inscrições.
         */
        $chaves = array_map('strval', array_keys($normalizados));
        $marcadas = 0;
        $jaMarcadas = 0;

        $campaign->participations()
            ->with('contact')
            ->whereIn('status', [KeywordParticipationStatus::Valida, KeywordParticipationStatus::SemNome])
            ->chunkById(500, function ($participacoes) use ($chaves, &$marcadas, &$jaMarcadas, $usuario): void {
                foreach ($participacoes as $participacao) {
                    $telefone = (string) $participacao->contact?->phone_normalized;

                    if ($telefone === '' || ! in_array($telefone, $chaves, true)) {
                        continue;
                    }

                    if ($participacao->eligibility === KeywordParticipationEligibility::AlunoConfirmado) {
                        $jaMarcadas++;

                        continue;
                    }

                    /*
                     | Quem um humano marcou como não aluno continua não aluno.
                     |
                     | O arquivo é um retrato do portal num instante; a decisão
                     | humana veio de olhar o caso. Deixar o arquivo sobrescrever
                     | faria a conferência se desfazer a cada importação, e
                     | ninguém entenderia por quê.
                     */
                    if ($participacao->eligibility === KeywordParticipationEligibility::NaoAluno
                        && $participacao->reviewed_by !== null) {
                        continue;
                    }

                    $participacao->update([
                        'eligibility' => KeywordParticipationEligibility::AlunoConfirmado,
                        'reviewed_by' => $usuario?->id,
                        'reviewed_at' => now(),
                    ]);

                    $marcadas++;
                }
            });

        $semCorrespondencia = $campaign->pendentesDeConferencia()->count();

        $this->audit->log(
            'keyword_campaign.eligibility_imported',
            "Lista de alunos importada na campanha \"{$campaign->name}\".",
            $campaign,
            null,
            [
                'phones_in_file' => count($telefonesBrutos),
                'marked' => $marcadas,
                'already_marked' => $jaMarcadas,
                'unmatched' => $semCorrespondencia,
                'invalid_phones' => $invalidos,
            ],
            $usuario,
        );

        return [
            'marked' => $marcadas,
            'already_marked' => $jaMarcadas,
            'unmatched' => $semCorrespondencia,
            'invalid_phones' => $invalidos,
        ];
    }

    /**
     * Lê a coluna de telefone do arquivo.
     *
     * Arquivo de uma coluna só, sem cabeçalho reconhecível, é tratado como uma
     * lista de telefones. É o formato que sai de um "copiar e colar" do portal,
     * e recusá-lo obrigaria o operador a montar um CSV à mão.
     *
     * @return list<string>
     */
    public function lerTelefones(UploadedFile $arquivo): array
    {
        $caminho = $arquivo->getRealPath();
        $reader = str_ends_with(Str::lower((string) $arquivo->getClientOriginalName()), '.xlsx')
            ? new XlsxReader
            : new CsvReader;

        $reader->open((string) $caminho);

        $telefones = [];
        $coluna = null;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $indice => $linha) {
                $valores = array_map(fn ($valor): string => trim((string) $valor), $linha->toArray());

                if ($indice === 1) {
                    $coluna = $this->colunaDoTelefone($valores);

                    // Cabeçalho reconhecido é cabeçalho: não vira dado.
                    if ($coluna !== null) {
                        continue;
                    }

                    $coluna = 0;
                }

                $valor = $valores[$coluna] ?? '';

                if ($valor !== '') {
                    $telefones[] = $valor;
                }
            }

            break;
        }

        $reader->close();

        return $telefones;
    }

    /**
     * @param  list<string>  $cabecalho
     */
    private function colunaDoTelefone(array $cabecalho): ?int
    {
        foreach ($cabecalho as $indice => $valor) {
            if (in_array(Str::slug($valor, '_'), self::CABECALHOS_DE_TELEFONE, true)) {
                return $indice;
            }
        }

        return null;
    }
}
