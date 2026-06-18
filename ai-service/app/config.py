from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    internal_api_secret: str = "change-me-in-production"
    laravel_base_url: str = "http://yner_main"
    anthropic_api_key: str = ""
    claude_model: str = "claude-sonnet-4-6"

    class Config:
        env_file = ".env"


settings = Settings()
