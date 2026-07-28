from contextlib import asynccontextmanager
from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from app.database import Base, engine
from app import models  # noqa: F401  (테이블 등록을 위해 import 필요)
from app.routers import backtest, stocks, realtime, trading
from app.services.scheduler import start_scheduler, stop_scheduler

# WebSocket 연결 끊김 에러 무시
import logging
logging.getLogger("uvicorn.error").setLevel(logging.CRITICAL)


@asynccontextmanager
async def lifespan(app: FastAPI):
    start_scheduler()
    yield
    stop_scheduler()


app = FastAPI(title="투자 전략 백테스팅 플랫폼", lifespan=lifespan)

# CORS 설정 — add_middleware는 라우터 등록보다 먼저 와야 합니다
app.add_middleware(
    CORSMiddleware,
    allow_origins=["http://localhost:5173"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

Base.metadata.create_all(bind=engine)

app.include_router(backtest.router)
app.include_router(stocks.router)
app.include_router(realtime.router)
app.include_router(trading.router)


@app.get("/")
def root():
    return {"status": "ok"}