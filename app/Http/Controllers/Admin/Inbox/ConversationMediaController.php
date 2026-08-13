<?php

namespace App\Http\Controllers\Admin\Inbox;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Conversations\ConversationMediaService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entrega o arquivo de uma mídia recebida.
 *
 * O arquivo mora no disco privado, fora do alcance do servidor web: chegar até
 * ele passa por aqui, e aqui passa pela sessão e pela permissão. Servir de
 * `storage/app/public` seria publicar foto de eleitor numa URL que qualquer um
 * adivinha pelo identificador da mensagem.
 */
class ConversationMediaController extends Controller
{
    public function show(
        Request $request,
        Conversation $conversation,
        ConversationMessage $message,
        ConversationMediaService $media,
    ): Response {
        abort_unless($request->user()->can('inbox.view_message_content'), 403);

        // A mensagem tem de ser desta conversa. Sem esta linha, trocar o número
        // no endereço leria a mídia de qualquer conversa do sistema.
        abort_unless($message->conversation_id === $conversation->id, 404);

        $medium = $media->ensure($message);

        abort_unless($medium && $medium->isAvailable(), 404);

        return response()->file(
            \Illuminate\Support\Facades\Storage::disk($medium->disk ?: 'local')->path($medium->path),
            [
                'Content-Type' => $medium->mimetype ?: 'application/octet-stream',

                // Sempre `inline`: a imagem é para aparecer na conversa, e o
                // áudio para tocar no player. Baixar é escolha de quem clica.
                'Content-Disposition' => 'inline; filename="'.$this->safeName($medium->filename, $message->id).'"',

                // Cache privado: é conteúdo de uma pessoa, e proxy nenhum no
                // caminho tem por que guardar uma cópia.
                'Cache-Control' => 'private, max-age=3600',
            ],
        );
    }

    /**
     * O nome vem de fora e volta para um cabeçalho.
     *
     * Nome de arquivo do remetente com aspas ou quebra de linha dentro escreve
     * o que quiser no cabeçalho da resposta. Só o que é seguro atravessa.
     */
    private function safeName(?string $filename, int $messageId): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $filename) ?: '';

        return $clean !== '' ? mb_substr($clean, 0, 80) : 'midia-'.$messageId;
    }
}
