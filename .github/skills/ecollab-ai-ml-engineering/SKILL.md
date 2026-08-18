---
name: ecollab-ai-ml-engineering
description: Design, implement, evaluate, and integrate AI/ML features into E-Collab with measurable quality, privacy, cost, latency, and reproducibility.
---

# AI/ML Engineering

First classify the solution: deterministic logic, search, provider API, LLM, embeddings/RAG, classical ML, or another method. Choose the simplest approach that meets requirements.

For ML, track data source, preprocessing, split strategy, leakage, baseline, metrics, reproducibility, model version, and inference behavior.

For LLM/RAG, define retrieval scope, grounding sources, prompt-injection defenses, context limits, output schema, evaluation set, latency, cost, fallback, and privacy boundaries.

Integrate through server-side services. Never expose provider credentials. Treat model output as untrusted and validate before persistence or privileged actions.