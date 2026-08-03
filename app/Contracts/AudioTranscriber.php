<?php

namespace App\Contracts;

use App\Data\Ai\TranscriptionResult;

/**
 * Transcreve áudio recebido.
 *
 * Contrato separado do provedor de texto porque o protocolo e outro: o
 * `/audio/transcriptions` recebe o arquivo em multipart, e não JSON, e devolve
 * texto puro em vez de objeto validado por schema.
 */
interface AudioTranscriber
{
    /**
     * @param  string  $audio  Conteúdo binário do áudio.
     * @param  string  $filename  Nome com extensão, que o provedor usa para inferir o formato.
     */
    public function transcribe(string $audio, string $filename): TranscriptionResult;
}
