from fastapi import FastAPI
from app.routers import health, analysis

app = FastAPI(title="IFM AI Service", version="0.1.0")

app.include_router(health.router)
app.include_router(analysis.router)
