<?php

namespace App\Contracts;

use App\Data\Knowledge\RetrievalQuery;
use App\Data\Knowledge\RetrievalResult;

/**
 * Recuperacao de trechos oficiais.
 *
 * Implementacoes consultam exclusivamente o armazenamento de trechos. Nao tem e
 * nao devem ter acesso a conversas, contatos ou ao banco de opinioes da 9B: a
 * fronteira e verificada por teste que le o codigo-fonte.
 */
interface KnowledgeRetriever
{
    public function retrieve(RetrievalQuery $query): RetrievalResult;
}
