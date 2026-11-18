<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Retrieval;

/**
 * A retriever returns passages from the site's own published posts. The
 * plugin rechecks every passage against the post gate, whatever the
 * retriever returns.
 */
interface Retriever
{
    /**
     * @return list<Passage>
     */
    public function retrieve(RetrievalQuery $query): array;
}
