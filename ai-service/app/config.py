from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    internal_api_secret: str = "change-me-in-production"
    laravel_base_url: str = "http://yner_main"

    # Fallback LLM config, used only when a request doesn't carry its own provider/model/key
    # overrides (e.g. standalone dev/testing). In production, yner_main's AI Settings page owns
    # these values and sends them with every /api/analysis/summary request.
    llm_provider: str = "openrouter"
    llm_base_url: str = "https://openrouter.ai/api/v1"
    llm_api_key: str = ""
    llm_model: str = "anthropic/claude-sonnet-4.5"
    llm_timeout_seconds: int = 30

    # Optional OpenRouter attribution headers (https://openrouter.ai/docs -> app attribution).
    openrouter_referer: str = ""
    openrouter_title: str = "EAPMS"

    class Config:
        env_file = ".env"


settings = Settings()
