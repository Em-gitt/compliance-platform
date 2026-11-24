"""LLM answer generation through the authorized provider (ADR-010).

Prompting, grounding and fallback models are implemented in CMP-31; this module
only guarantees that the call goes to the configured, authorized provider.
"""

import logging
import os

from app.providers.client import ProviderClient
from app.providers.registry import LLM
from app.providers.settings import ProviderSettings, get_settings

logger = logging.getLogger("ai.llm")

MOCK_ANSWER = (
    "According to section 4.2 of the Compliance Manual [1], compliance records and audit logs "
    "must be maintained for at least 7 years."
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

    models_to_try = [settings.llm_model]
    fallback = os.getenv("LLM_FALLBACK_MODEL", "").strip()
    if fallback and fallback not in models_to_try:
        models_to_try.append(fallback)
    elif settings.llm_provider == "groq" and "llama-3.1-8b-instant" not in models_to_try:
        models_to_try.append("llama-3.1-8b-instant")

    last_error = None
    try:
        for model in models_to_try:
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
            except Exception as e:
                logger.warning("LLM model %s threw exception: %s", model, e)
                last_error = e

        if last_error:
            raise last_error
        raise RuntimeError("LLM answer generation failed: no response received.")
    finally:
        if owns_client:
            client.close()
