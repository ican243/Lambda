import os
from datetime import date
import pandas as pd
from sqlalchemy.orm import Session
from sqlalchemy import func
from app.services import kis_client
from app.models.stock_candle_1d import StockCandle1d
from app.models.order import LiveOrder
from app.models.candle import Candle1m
from app.strategies.base import BaseStrategy

RISK_PER_TRADE_PCT = float(os.getenv("RISK_PER_TRADE_PCT", "0.02"))
STOP_LOSS_PCT = float(os.getenv("STOP_LOSS_PCT", "0.03"))
TAKE_PROFIT_PCT = float(os.getenv("TAKE_PROFIT_PCT", "0.06"))


def _load_historical_df(db: Session, ticker: str, lookback_days: int = 30) -> pd.DataFrame:
    """팀원의 stock_candles_1d(일봉)에서 최근 lookback_days만큼의 데이터 로드 (오늘 이전 데이터).
    price_history를 대체 - 팀원이 이미 수집한 데이터를 그대로 사용."""
    rows = (
        db.query(StockCandle1d)
        .filter(StockCandle1d.stock_code == ticker)
        .order_by(StockCandle1d.d.desc())
        .limit(lookback_days)
        .all()
    )
    rows = list(reversed(rows))

    if not rows:
        return pd.DataFrame(columns=["open", "high", "low", "close", "volume"])

    df = pd.DataFrame([{
        "date": r.d,
        "open": r.open_p,
        "high": r.high_p,
        "low": r.low_p,
        "close": r.close_p,
        "volume": r.volume,
    } for r in rows])
    df["date"] = pd.to_datetime(df["date"])
    return df.set_index("date")


def _get_today_ohlc_from_candles(db: Session, ticker: str) -> dict | None:
    """팀원의 stock_candles_1m(실시간 1분봉)을 오늘 날짜 기준으로 집계해 OHLCV 생성.
    데이터가 없으면 None 반환."""
    today = date.today()

    candles = (
        db.query(Candle1m)
        .filter(
            Candle1m.stock_code == ticker,
            func.date(Candle1m.ts) == today,
        )
        .order_by(Candle1m.ts.asc())
        .all()
    )

    if not candles:
        return None

    return {
        "open": candles[0].open_p,
        "high": max(c.high_p for c in candles),
        "low": min(c.low_p for c in candles),
        "close": candles[-1].close_p,
        "volume": sum(c.vol_delta or 0 for c in candles),
    }


def _append_today_price(db: Session, historical_df: pd.DataFrame, ticker: str) -> pd.DataFrame:
    """오늘자 실시간 OHLC(팀원의 stock_candles_1m 집계)를 마지막 행으로 추가/교체.
    당일 데이터가 아직 없으면(장 시작 전 등) historical_df를 그대로 반환."""
    today_ohlc = _get_today_ohlc_from_candles(db, ticker)
    if today_ohlc is None:
        return historical_df

    today = pd.Timestamp.now().normalize()
    today_row = pd.DataFrame([today_ohlc], index=[today])

    if today in historical_df.index:
        historical_df = historical_df.drop(index=today)
    return pd.concat([historical_df, today_row])


def get_all_held_tickers(db: Session, strategy_name: str) -> list[str]:
    """현재 보유 중인 모든 종목 티커 목록.
    스크리닝 상위 목록에서 밀려나더라도 보유 종목은 계속 손절/익절 판단이
    되어야 하므로, 스케줄러가 이 함수로 '반드시 감시해야 할 종목'을 챙긴다."""
    candidate_tickers = (
        db.query(LiveOrder.ticker)
        .filter(LiveOrder.strategy_name == strategy_name, LiveOrder.status == "filled")
        .distinct()
        .all()
    )
    held = []
    for (ticker,) in candidate_tickers:
        last = (
            db.query(LiveOrder)
            .filter(
                LiveOrder.ticker == ticker,
                LiveOrder.strategy_name == strategy_name,
                LiveOrder.status == "filled",
            )
            .order_by(LiveOrder.created_at.desc())
            .first()
        )
        if last and last.order_type == "buy":
            held.append(ticker)
    return held


def _get_current_position(db: Session, ticker: str, strategy_name: str) -> int:
    """DB 기준 현재 포지션 (0=미보유, 1=보유중). 가장 최근 체결 주문 종류로 판단."""
    last_filled = (
        db.query(LiveOrder)
        .filter(
            LiveOrder.ticker == ticker,
            LiveOrder.strategy_name == strategy_name,
            LiveOrder.status == "filled",
        )
        .order_by(LiveOrder.created_at.desc())
        .first()
    )
    if last_filled is None:
        return 0
    return 1 if last_filled.order_type == "buy" else 0

def _get_avg_buy_price(db: Session, ticker: str, strategy_name: str) -> int | None:
    """현재 보유 포지션의 매수 체결가 (가장 최근 buy 체결 기준)."""
    last_buy = (
        db.query(LiveOrder)
        .filter(
            LiveOrder.ticker == ticker,
            LiveOrder.strategy_name == strategy_name,
            LiveOrder.order_type == "buy",
            LiveOrder.status == "filled",
        )
        .order_by(LiveOrder.created_at.desc())
        .first()
    )
    return last_buy.filled_price if last_buy else None


def _check_risk_exit(current_price: int, avg_buy_price: int) -> str | None:
    """손절/익절 조건 체크. 'stop_loss' | 'take_profit' | None 반환."""
    if avg_buy_price is None or avg_buy_price == 0:
        return None

    change_pct = (current_price - avg_buy_price) / avg_buy_price

    if change_pct <= -STOP_LOSS_PCT:
        return "stop_loss"
    if change_pct >= TAKE_PROFIT_PCT:
        return "take_profit"
    return None

def _calculate_position_size(current_price: int) -> int:
    """계좌 현금 잔고 기준으로 리스크 % 만큼 몇 주 살지 계산.
    리스크 예산으로 1주도 못 사면, 현금으로 1주는 살 수 있는 한 최소 1주 허용.
    계좌 잔고는 팀원 데이터로 대체 불가능한 영역이라 KIS API 그대로 사용."""
    balance = kis_client.get_account_balance()
    risk_amount = balance["cash"] * RISK_PER_TRADE_PCT
    quantity = int(risk_amount // current_price)

    if quantity < 1:
        if balance["cash"] >= current_price:
            return 1
        return 0

    return quantity

def _place_and_log(db, ticker, order_type, quantity, strategy_name, reason=None):
    """공통 주문 실행 + DB 기록 헬퍼. 주문 실행 자체는 KIS API 그대로 사용."""
    result = kis_client.place_order(ticker, order_type, quantity, price=0)
    order = LiveOrder(
        ticker=ticker,
        order_type=order_type,
        quantity=quantity,
        price=0,
        strategy_name=strategy_name,
        kis_order_no=result.get("order_no"),
        status="pending" if result["success"] else "failed",
        reason=reason,
    )
    db.add(order)
    db.commit()
    db.refresh(order)
    return {"action": order_type, "order_id": order.id, "kis_response": result, "reason": reason}


def run_strategy_once(
    db: Session,
    ticker: str,
    strategy: BaseStrategy,
    strategy_name: str,
    quantity: int | None = None,  # None이면 포지션 사이징 자동 계산
) -> dict:
    historical_df = _load_historical_df(db, ticker, lookback_days=30)

    if len(historical_df) < 20:
        return {"action": "skip", "reason": f"데이터 부족 (보유: {len(historical_df)}일, 최소 20일 필요)"}

    price_df = _append_today_price(db, historical_df, ticker)

    if len(price_df) == len(historical_df):
        # 오늘자 실시간 데이터가 아직 없음 (장 시작 전 등)
        return {"action": "skip", "reason": "오늘자 실시간 데이터 없음 (stock_candles_1m 비어있음)"}

    current_price = int(price_df["close"].iloc[-1])
    current_position = _get_current_position(db, ticker, strategy_name)

    # ── 1순위: 보유 중이면 손절/익절부터 체크 ──
    if current_position == 1:
        avg_buy_price = _get_avg_buy_price(db, ticker, strategy_name)
        risk_exit = _check_risk_exit(current_price, avg_buy_price)
        if risk_exit:
            last_buy = (
                db.query(LiveOrder)
                .filter(LiveOrder.ticker == ticker, LiveOrder.strategy_name == strategy_name,
                        LiveOrder.order_type == "buy", LiveOrder.status == "filled")
                .order_by(LiveOrder.created_at.desc()).first()
            )
            sell_qty = last_buy.quantity if last_buy else 1
            return _place_and_log(db, ticker, "sell", sell_qty, strategy_name, reason=risk_exit)

    # ── 2순위: 전략 신호 체크 ──
    signal_df = strategy.generate_signals(price_df)
    latest_signal = int(signal_df["signal"].iloc[-1])

    if latest_signal == 1 and current_position == 1:
        return {"action": "hold", "reason": "이미 보유 중"}
    if latest_signal == -1 and current_position == 0:
        return {"action": "hold", "reason": "보유 물량 없음"}
    if latest_signal == 0:
        return {"action": "hold", "reason": "신호 없음"}

    order_type = "buy" if latest_signal == 1 else "sell"

    if order_type == "buy":
        qty = quantity if quantity is not None else _calculate_position_size(current_price)
        if qty < 1:
            return {"action": "skip", "reason": "리스크 기준 매수 가능 수량 부족 (잔고 부족)"}
    else:
        last_buy = (
            db.query(LiveOrder)
            .filter(LiveOrder.ticker == ticker, LiveOrder.strategy_name == strategy_name,
                    LiveOrder.order_type == "buy", LiveOrder.status == "filled")
            .order_by(LiveOrder.created_at.desc()).first()
        )
        qty = last_buy.quantity if last_buy else (quantity or 1)

    return _place_and_log(db, ticker, order_type, qty, strategy_name, reason="strategy_signal")