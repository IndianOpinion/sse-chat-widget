<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Retrieval;

/**
 * Picks the retriever, applies the fallbacks of cases 37 and 80, and gates,
 * deduplicates and numbers the passages (cases 34 and 38).
 */
final class RetrievalRunner
{
    /** @var list<string> */
    private array $notes = [];

    private string $used = 'keyword';

    public function __construct(
        private readonly PostGate $gate,
        private readonly Retriever $keyword,
        private readonly ?Retriever $custom = null,
    ) {
    }

    /**
     * @return list<Passage> Numbered 1 to n.
     */
    public function run(RetrievalQuery $query): array
    {
        $this->notes = [];
        $passages = null;
        if ($this->custom !== null) {
            try {
                $passages = $this->custom->retrieve($query);
                $this->used = 'custom';
            } catch (\Throwable $e) {
                $this->notes[] = 'retriever_fallback';
                $passages = null;
            }
        }
        if ($passages === null) {
            try {
                $passages = $this->keyword->retrieve($query);
                $this->used = 'keyword';
            } catch (\Throwable $e) {
                $this->notes[] = 'retriever_failed';
                $this->used = 'none';
                return [];
            }
        }

        $out = [];
        $seen = [];
        foreach ($passages as $p) {
            if (!$p instanceof Passage || isset($seen[$p->postId])) {
                continue;
            }
            if (!$this->gate->allows($p->postId)) {
                continue;
            }
            $seen[$p->postId] = true;
            $out[] = $p->withMarker(count($out) + 1);
            if (count($out) >= $query->limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    public function used(): string
    {
        return $this->used;
    }
}
