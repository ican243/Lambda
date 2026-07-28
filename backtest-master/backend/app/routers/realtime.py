from fastapi import APIRouter, HTTPException, WebSocket, WebSocketDisconnect, Depends
from sqlalchemy.orm import Session
from app.database import get_db
from app.models.stock import Stock
from app.services.kis_client import get_current_price, get_minute_ohlcv
import asyncio
import json

router = APIRouter(prefix="/realtime", tags=["realtime"])


@router.get("/price/{ticker}")
def current_price(ticker: str, db: Session = Depends(get_db)):
    """실시간 현재가 조회 (종목명 포함)."""
    try:
        data = get_current_price(ticker)
        # DB에서 종목명 조회
        stock = db.query(Stock).filter(Stock.ticker == ticker).first()
        data["name"] = stock.name if stock else ticker
        return data
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))


@router.get("/chart/{ticker}")
def minute_chart(ticker: str, interval: str = "30"):
    try:
        return get_minute_ohlcv(ticker, interval)
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))


@router.websocket("/ws/{ticker}")
async def price_websocket(websocket: WebSocket, ticker: str, interval: int = 5):
    await websocket.accept()

    from app.database import SessionLocal
    from starlette.websockets import WebSocketState
    db = SessionLocal()

    try:
        stock = db.query(Stock).filter(Stock.ticker == ticker).first()
        stock_name = stock.name if stock else ticker

        while True:
            # 연결이 끊겼으면 루프 탈출
            if websocket.client_state != WebSocketState.CONNECTED:
                break

            try:
                data = get_current_price(ticker)
                data["name"] = stock_name
                await websocket.send_text(json.dumps(data))
            except WebSocketDisconnect:
                break
            except Exception as e:
                # 연결이 살아있을 때만 에러 전송 시도
                try:
                    if websocket.client_state == WebSocketState.CONNECTED:
                        await websocket.send_text(json.dumps({"error": str(e)}))
                except Exception:
                    break

            await asyncio.sleep(interval)

    except WebSocketDisconnect:
        pass  # 정상적인 연결 종료
    except Exception:
        pass  # 그 외 예외도 조용히 종료
    finally:
        db.close()