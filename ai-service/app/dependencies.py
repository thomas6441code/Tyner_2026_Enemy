from fastapi import Header, HTTPException, status
from .config import settings


def verify_internal_secret(x_internal_secret: str = Header(...)):
    if x_internal_secret != settings.internal_api_secret:
        raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="Invalid internal secret")
