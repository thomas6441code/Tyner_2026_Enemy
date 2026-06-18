from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    internal_api_secret: str = "change-me-in-production"
    laravel_base_url: str = "http://yner_main"
    poll_interval_seconds: int = 300

    class Config:
        env_file = ".env"


settings = Settings()
