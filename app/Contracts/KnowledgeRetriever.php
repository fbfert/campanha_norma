<?php

namespace App\Contracts;

use App\Data\Knowledge\RetrievalQuery;
use App\Data\Knowledge\RetrievalResult;

/**
 * Recuperação de trechos oficiais.
 *
 * Implementações consultam exclusivamente o armazenamento de trechos. Não tem e
 * não devem ter acesso a conversas, contatos ou ao banco de opiniões da 9B: a
 * fronteira e verificada por teste que le o código-fonte.
 */
interface KnowledgeRetriever
{
    public function retrieve(RetrievalQuery $query): RetrievalResult;
}
