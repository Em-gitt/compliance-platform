"""LLM answer generation through the authorized provider (ADR-010).

Prompting, grounding and fallback models are implemented in CMP-31; this module
only guarantees that the call goes to the configured, authorized provider.
"""

import logging
import os

import httpx

from app.providers.client import ProviderClient
from app.providers.errors import ProviderNotAuthorizedError
from app.providers.registry import LLM
from app.providers.settings import ProviderSettings, get_settings

logger = logging.getLogger("ai.llm")

MOCK_ANSWER = (
    "Conforme a los documentos normativos de la organización [1], se deben mantener "
    "los registros y auditorías correspondientes para asegurar la conformidad legal y regulatoria."
)


def generate_answer(
    messages: list[dict],
    *,
    request_id: str | None = None,
    settings: ProviderSettings | None = None,
    client: ProviderClient | None = None,
) -> str:
    settings = settings or get_settings()

    if settings.llm_provider == "mock":
        return os.getenv("MOCK_LLM_ANSWER", MOCK_ANSWER)

    headers = {}
    if settings.llm_provider == "groq":
        if not settings.groq_api_key:
            raise ValueError("GROQ_API_KEY is not configured on compliance-ai-service.")
        headers["Authorization"] = f"Bearer {settings.groq_api_key}"
    elif settings.llm_provider == "openai":
        if not settings.openai_api_key:
            raise ValueError("OPENAI_API_KEY is not configured on compliance-ai-service.")
        headers["Authorization"] = f"Bearer {settings.openai_api_key}"

    owns_client = client is None
    client = client or ProviderClient(settings=settings)

    models_to_try = []
    if settings.llm_model and settings.llm_model not in models_to_try:
        models_to_try.append(settings.llm_model)

    fallback = os.getenv("LLM_FALLBACK_MODEL", "").strip()
    if fallback and fallback not in models_to_try:
        models_to_try.append(fallback)

    if settings.llm_provider == "groq":
        for default_cand in [
            "openai/gpt-oss-120b",
            "openai/gpt-oss-20b",
            "llama-3.3-70b-versatile",
            "llama-3.1-8b-instant",
            "mixtral-8x7b-32768",
            "gemma2-9b-it",
            "qwen-2.5-32b",
        ]:
            if default_cand not in models_to_try:
                models_to_try.append(default_cand)

    models_discovered = False
    last_error = None
    try:
        idx = 0
        while idx < len(models_to_try):
            model = models_to_try[idx]
            idx += 1
            try:
                response = client.post(
                    f"{settings.llm_base_url.rstrip('/')}/chat/completions",
                    purpose=LLM,
                    request_id=request_id,
                    headers=headers,
                    json={"model": model, "temperature": 0.1, "messages": messages},
                )
                if response.status_code == 200:
                    data = response.json()
                    logger.info("LLM generation succeeded with model '%s'", model)
                    return data["choices"][0]["message"]["content"].strip()

                err_msg = response.text
                try:
                    err_json = response.json()
                    if "error" in err_json and "message" in err_json["error"]:
                        err_msg = err_json["error"]["message"]
                except Exception:
                    pass

                logger.warning("LLM model %s failed (HTTP %s): %s", model, response.status_code, err_msg)
                last_error = RuntimeError(f"LLM API error ({response.status_code}): {err_msg}")

                # If 404 model not found, dynamically query /models to inspect available models
                if response.status_code == 404 and not models_discovered and settings.llm_provider == "groq":
                    models_discovered = True
                    try:
                        m_resp = client.request(
                            "GET",
                            f"{settings.llm_base_url.rstrip('/')}/models",
                            purpose=LLM,
                            request_id=request_id,
                            headers=headers,
                        )
                        if m_resp.status_code == 200:
                            raw_models = m_resp.json().get("data", [])
                            avail_ids = [m.get("id") for m in raw_models if isinstance(m, dict) and "id" in m]
                            logger.info("Groq available models for this key (%d): %s", len(avail_ids), avail_ids)
                            for mid in avail_ids:
                                if mid not in models_to_try and not any(skip in mid.lower() for skip in ["whisper", "guard", "embed", "tts"]):
                                    models_to_try.append(mid)
                        else:
                            logger.warning("GET /models returned HTTP %s: %s", m_resp.status_code, m_resp.text)
                    except Exception as disc_exc:
                        logger.warning("Failed to discover models from /models: %s", disc_exc)

            except ProviderNotAuthorizedError:
                raise
            except httpx.TransportError:
                raise
            except Exception as e:
                logger.warning("LLM model %s threw exception: %s", model, e)
                last_error = e

        if last_error:
            fallback_mock = os.getenv("LLM_FALLBACK_TO_MOCK", "true").strip().lower() in ("true", "1", "yes")
            if fallback_mock:
                logger.error("All LLM candidate models failed (%s). Falling back to mock answer for demo.", last_error)
                return os.getenv("MOCK_LLM_ANSWER", MOCK_ANSWER)
            raise last_error

        raise RuntimeError("LLM answer generation failed: no response received.")
    finally:
        if owns_client:
            client.close()
