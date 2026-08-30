"""
Semantic similarity engine — wraps the pretrained MiniLM-L6-v2 model.

Category-2 component: uses a pretrained sentence encoder as-is.
No training, no fine-tuning. This is honestly a wrapper around a third-party model.

The wrapper's job is to give our own code a stable, simple interface:
    - encode(list_of_strings) -> list of 384-dim vectors
    - similarity(vec_a, vec_b) -> cosine similarity float in [-1, 1]
    - similarity_matrix(anchor, candidates) -> list of similarities
"""

from typing import List
import sys


# Lazy-loaded module-level model. First call to any function that needs it
# triggers loading (~2 seconds from cache, ~30 seconds first-ever run).
_model = None


def _load_model():
    """Load the pretrained model into memory. Called lazily on first use."""
    global _model
    if _model is None:
        # Only imported inside the function so that Python scripts that don't
        # need semantic similarity (e.g. eligibility-only checks) don't pay the
        # ~2-second load cost.
        from sentence_transformers import SentenceTransformer
        print("[semantic_engine] Loading all-MiniLM-L6-v2...", file=sys.stderr)
        _model = SentenceTransformer('all-MiniLM-L6-v2')
        print("[semantic_engine] Model loaded.", file=sys.stderr)
    return _model


def encode(texts: List[str]):
    """
    Encode a list of texts into 384-dim vectors. Batched internally for
    speed — encoding 25 texts costs roughly the same as encoding 1.

    Returns numpy array of shape (N, 384) with L2-normalized rows.
    """
    if not texts:
        import numpy as np
        return np.zeros((0, 384))
    model = _load_model()
    # normalize_embeddings=True gives us unit-length vectors, so downstream
    # cosine similarity reduces to a dot product.
    return model.encode(texts, normalize_embeddings=True, show_progress_bar=False)


def similarity(vec_a, vec_b) -> float:
    """
    Cosine similarity between two vectors. Since inputs are already L2-normalized
    by encode(), this is just a dot product.
    """
    import numpy as np
    return float(np.dot(vec_a, vec_b))


def similarity_matrix(anchor_text: str, candidate_texts: List[str]) -> List[float]:
    """
    Encode one anchor text and N candidate texts, return N similarity scores.
    Used by the recommendation engine: anchor = student description,
    candidates = descriptions of eligible offers.
    """
    if not candidate_texts:
        return []
    all_texts = [anchor_text] + candidate_texts
    embeddings = encode(all_texts)
    anchor_vec = embeddings[0]
    candidate_vecs = embeddings[1:]
    # Because all vectors are L2-normalized, the dot product is the cosine sim.
    import numpy as np
    sims = np.dot(candidate_vecs, anchor_vec)
    return [float(s) for s in sims]


# CLI mode: for one-off testing.
# Usage: python semantic_engine.py "student text" "offer 1" "offer 2" ...
if __name__ == "__main__":
    if len(sys.argv) < 3:
        print("Usage: semantic_engine.py <anchor> <candidate1> [candidate2 ...]")
        sys.exit(1)
    anchor = sys.argv[1]
    candidates = sys.argv[2:]
    scores = similarity_matrix(anchor, candidates)
    for cand, score in zip(candidates, scores):
        print(f"  {score:.4f}  {cand[:60]}")