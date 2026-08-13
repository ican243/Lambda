import os
from datetime import datetime, time as dtime
from apscheduler.schedulers.background import BackgroundScheduler
from app.database import SessionLocal
from app.services.executor import run_strategy_once, get_all_held_tickers
from app.services.screener import get_top_candidates_by_trade_value
from app.strategies.configurable_factor import ConfigurableFactorStrategy
from app.services.log_policy import cleanup_old_logs, record_decision_log

_scheduler = BackgroundScheduler(timezone="Asia/Seoul")

# 매매 일시정지 플래그 (메모리 기반, 서버 재시작 시 초기화됨)
# 기본값 True: 서버가 켜져도 자동으로 매매를 시작하지 않고, "시작" 버튼을 눌러야 매매가 시작됨
_trading_paused = True

STRATEGY_NAME = "configurable_factor"

# 기본 팩터 설정 - momentum/market_regime은 추가 컬럼 병합(momentum_rank_pct, market_bullish)이
# 실시간 경로에서도 momentum_rank_pct와 market_bullish를 함께 병합해 5개 지표를 사용한다.
# buy_threshold/sell_threshold는 각 지표가 0~1 연속값 * weight로 합산되는 점수 기준.
# (trend 35 + momentum 25 + volume 20 + volatility 10 + market_regime 10 = 최대 100점)
DEFAULT_STRATEGY_PARAMS = {
    "indicators": ["trend", "momentum", "volume", "volatility", "market_regime"],
    "weights": {"trend": 35, "momentum": 25, "volume": 20, "volatility": 10, "market_regime": 10},
    "buy_threshold": float(os.getenv("BUY_THRESHOLD", "40")),
    "sell_threshold": float(os.getenv("SELL_THRESHOLD", "15")),
    "stop_loss_pct": float(os.getenv("STOP_LOSS_PCT", "0.03")),
    "take_profit_pct": float(os.getenv("TAKE_PROFIT_PCT", "0.06")),
}

CANDIDATE_LIMIT = int(os.getenv("CANDIDATE_LIMIT", "100"))

_STRATEGY_MAP = {
    "configurable_factor": ConfigurableFactorStrategy,
}


def is_trading_paused() -> bool:
    return _trading_paused


def pause_trading():
    global _trading_paused
    _trading_paused = True
    print("[스케줄러] 매매 일시정지됨")


def resume_trading():
    global _trading_paused
    _trading_paused = False
    print("[스케줄러] 매매 재개됨")


def _is_market_hours() -> bool:
    """한국 정규장 시간(09:00~15:30, 평일)인지 체크."""
    now = datetime.now()
    if now.weekday() >= 5:  # 토(5)/일(6)
        return False
    market_open = dtime(9, 0)
    market_close = dtime(15, 30)
    return market_open <= now.time() <= market_close


def _get_candidate_tickers(db) -> list[str]:
    """이번 사이클에서 평가할 종목 목록:
    (1) stock_latest 기준 거래대금 상위 N개 (신규 매수 후보 발굴)
    (2) 이미 보유 중인 종목 전부 (스크리닝 순위와 무관하게 손절/익절 판단 유지)
    두 집합을 합쳐서 중복 제거."""
    screened = get_top_candidates_by_trade_value(db, limit=CANDIDATE_LIMIT)
    held = get_all_held_tickers(db, STRATEGY_NAME)
    return list(dict.fromkeys(screened + held))  # 순서 유지하며 중복 제거


def run_all_strategies(source: str = "scheduler"):
    """거래대금 상위 종목 + 보유 종목을 대상으로 팩터 스코어링 전략을 1회씩 실행."""
    if source not in {"scheduler", "scheduler_manual"}:
        raise ValueError(f"허용되지 않는 스케줄러 source: {source}")
    if _trading_paused:
        print(f"[스케줄러] 매매 일시정지 상태 - 스킵 ({datetime.now().strftime('%H:%M:%S')})")
        return

    if not _is_market_hours():
        print(f"[스케줄러] 장 시간 외 - 스킵 ({datetime.now().strftime('%H:%M:%S')})")
        return

    db = SessionLocal()
    try:
        candidates = _get_candidate_tickers(db)
        if not candidates:
            print("[스케줄러] 스크리닝 후보 없음 - 스킵")
            return

        strategy = ConfigurableFactorStrategy(params=DEFAULT_STRATEGY_PARAMS)

        for ticker in candidates:
            try:
                result = run_strategy_once(db, ticker, strategy, STRATEGY_NAME, quantity=None)
                if result.get("action") not in ("hold", "skip"):
                    # 실제 매매가 발생한 경우만 로그 출력 (매 틱마다 hold/skip 다 찍으면 로그가 너무 많아짐)
                    print(f"[스케줄러] {ticker} -> {result}")

                record_decision_log(
                    db,
                    ticker=ticker,
                    strategy_name=STRATEGY_NAME,
                    result=result,
                    source=source,
                    strategy_params=DEFAULT_STRATEGY_PARAMS,
                )
            except Exception as e:
                print(f"[스케줄러] {ticker} 실행 중 에러: {e}")
                try:
                    record_decision_log(
                        db,
                        ticker=ticker,
                        strategy_name=STRATEGY_NAME,
                        result={"action": "error", "reason": str(e)[:50]},
                        source=source,
                        strategy_params=DEFAULT_STRATEGY_PARAMS,
                    )
                except Exception as log_error:
                    print(f"[스케줄러] 에러 로그 저장 실패: {log_error}")

        db.commit()
    finally:
        db.close()


def cleanup_logs_job():
    db = SessionLocal()
    try:
        deleted = cleanup_old_logs(db)
        if deleted:
            print(f"[로그 정리] 3일 경과 로그 {deleted}건 삭제")
    except Exception as e:
        db.rollback()
        print(f"[로그 정리] 실패: {e}")
    finally:
        db.close()


def start_scheduler():
    # 실시간 반응을 위해 초 단위 간격 사용 (팀원의 stock_latest/stock_candles_1m 실시간 데이터 기반)
    interval = int(os.getenv("SCHEDULER_INTERVAL_SECONDS", "3"))
    _scheduler.add_job(
        run_all_strategies,
        "interval",
        seconds=interval,
        id="strategy_runner",
        replace_existing=True,
        max_instances=1,
        coalesce=True,
    )
    _scheduler.add_job(
        cleanup_logs_job,
        "interval",
        hours=1,
        id="signal_log_cleanup",
        replace_existing=True,
        max_instances=1,
        coalesce=True,
    )
    _scheduler.start()
    print(f"[스케줄러] 시작됨 ({interval}초 간격, 후보 상위 {CANDIDATE_LIMIT}개 + 보유종목, 로그 1시간 정리)")


def stop_scheduler():
    _scheduler.shutdown(wait=False)
    print("[스케줄러] 종료됨")