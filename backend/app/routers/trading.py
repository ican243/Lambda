from fastapi import APIRouter, Depends
from sqlalchemy.orm import Session
from app.database import get_db
from app.models.order import LiveOrder
from app.services import kis_client
from datetime import datetime, date
from app.services.executor import run_strategy_once
from app.strategies.ma20 import MA20Strategy
from app.services.scheduler import run_all_strategies   #수동트리거(테스트/디버깅용)
from app.services.scheduler import pause_trading, resume_trading, is_trading_paused
from app.services.scheduler import _STRATEGY_MAP
from app.models.stock_master import StockMaster
from app.services.kis_client import get_current_price

router = APIRouter(prefix="/trading", tags=["trading"])

@router.post("/scheduler/pause")
def pause_scheduler():
    """매매 일시정지 — 스케줄러는 계속 돌지만 실제 주문은 안 나감."""
    pause_trading()
    return {"status": "paused"}


@router.post("/scheduler/resume")
def resume_scheduler():
    """매매 재개."""
    resume_trading()
    return {"status": "resumed"}


@router.get("/scheduler/status")
def scheduler_status():
    """현재 매매 상태 조회."""
    return {"paused": is_trading_paused()}

@router.post("/order")
def create_order(
    ticker: str,
    order_type: str,
    quantity: int,
    price: int = 0,
    strategy_name: str = "manual_test",
    db: Session = Depends(get_db),
):
    """모의투자 주문 테스트용 엔드포인트."""
    result = kis_client.place_order(ticker, order_type, quantity, price)

    order = LiveOrder(
        ticker=ticker,
        order_type=order_type,
        quantity=quantity,
        price=price,
        strategy_name=strategy_name,
        kis_order_no=result.get("order_no"),
        status="pending" if result["success"] else "failed",
    )
    db.add(order)
    db.commit()
    db.refresh(order)

    return {"order": order.id, "kis_response": result}

@router.get("/order/{order_no}/status")
def check_order_status(
    order_no: str,
    ticker: str,
    order_date: str = None,
    db: Session = Depends(get_db),
):
    """주문 체결 상태 조회 (KIS API 조회 + DB 반영)."""
    query_date = order_date or date.today().strftime("%Y%m%d")

    result = kis_client.get_order_status(order_no, query_date, ticker)

    order = db.query(LiveOrder).filter(LiveOrder.kis_order_no == order_no).first()
    if order and result.get("status") != "not_found":
        order.status = result["status"]
        order.filled_qty = result.get("filled_qty", 0)
        order.filled_price = result.get("filled_price")
        order.updated_at = datetime.utcnow()
        db.commit()
        db.refresh(order)

    return {"kis_status": result, "db_order": order.id if order else None}

@router.post("/run-strategy")
def run_strategy_endpoint(
    ticker: str,
    strategy_name: str = "ma20",
    quantity: int | None = None,
    db: Session = Depends(get_db),
):
    strategy_cls = _STRATEGY_MAP.get(strategy_name)
    if strategy_cls is None:
        return {"action": "error", "reason": f"알 수 없는 전략: {strategy_name}"}

    strategy = strategy_cls()
    result = run_strategy_once(db, ticker, strategy, strategy_name, quantity)
    return result

@router.get("/orders")
def list_orders(skip: int = 0, limit: int = 50, db: Session = Depends(get_db)):
    """주문 이력 목록 조회 (최신순)."""
    orders = (
        db.query(LiveOrder)
        .order_by(LiveOrder.created_at.desc())
        .offset(skip)
        .limit(limit)
        .all()
    )
    return [
        {
            "id": o.id,
            "ticker": o.ticker,
            "order_type": o.order_type,
            "quantity": o.quantity,
            "price": o.price,
            "strategy_name": o.strategy_name,
            "kis_order_no": o.kis_order_no,
            "status": o.status,
            "filled_qty": o.filled_qty,
            "filled_price": o.filled_price,
            "reason": o.reason,
            "created_at": o.created_at.isoformat() if o.created_at else None,
        }
        for o in orders
    ]

@router.post("/scheduler/run-now")
def trigger_scheduler_now():
    """스케줄러 작업을 즉시 1회 수동 실행 (테스트/디버깅용)."""
    run_all_strategies()
    return {"status": "triggered"}

@router.get("/positions")
def get_positions(db: Session = Depends(get_db)):
    """체결된 주문을 바탕으로 순보유수량/평단가를 계산하고 실시간 현재가를 붙여서 반환."""
    filled_orders = (
        db.query(LiveOrder)
        .filter(LiveOrder.status == "filled")
        .order_by(LiveOrder.ticker, LiveOrder.created_at)
        .all()
    )

    holdings: dict[str, dict] = {}
    for o in filled_orders:
        h = holdings.setdefault(o.ticker, {"qty": 0, "cost": 0.0})
        qty = o.filled_qty or o.quantity
        price = o.filled_price or o.price

        # ⚠ order_type 실제 값("buy"/"sell"인지 "01"/"02"인지)에 맞춰 조건 수정 필요
        is_buy = str(o.order_type).lower() in ("buy", "b", "01")

        if is_buy:
            h["cost"] += qty * price
            h["qty"] += qty
        else:
            if h["qty"] > 0:
                avg = h["cost"] / h["qty"]
                h["cost"] -= avg * qty
            h["qty"] -= qty

    positions = []
    for ticker, h in holdings.items():
        if h["qty"] <= 0:
            continue

        avg_price = h["cost"] / h["qty"] if h["qty"] else 0
        stock = db.query(StockMaster).filter(StockMaster.stock_code == ticker).first()
        name = stock.stock_name if stock else ticker

        current_price = None
        try:
            live = get_current_price(ticker)
            # ⚠ get_current_price가 반환하는 실제 키 이름 확인 필요 (아래 둘 중 하나로 시도)
            current_price = live.get("current_price") or live.get("price")
        except Exception:
            pass

        pnl = (current_price - avg_price) * h["qty"] if current_price else None
        pnl_pct = ((current_price - avg_price) / avg_price * 100) if current_price and avg_price else None

        positions.append({
            "ticker": ticker,
            "name": name,
            "quantity": h["qty"],
            "avg_price": round(avg_price, 2),
            "current_price": current_price,
            "pnl": round(pnl, 2) if pnl is not None else None,
            "pnl_pct": round(pnl_pct, 2) if pnl_pct is not None else None,
        })

    return positions