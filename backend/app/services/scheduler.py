import os
from datetime import datetime, time as dtime
from apscheduler.schedulers.background import BackgroundScheduler
from app.database import SessionLocal
from app.services.executor import run_strategy_once
from app.strategies.ma20 import MA20Strategy
from app.strategies.golden_cross import GoldenCrossStrategy
from app.strategies.rsi import RSIStrategy
from app.strategies.combined import MA20RSIFilteredStrategy

_STRATEGY_MAP = {
    "ma20": MA20Strategy,
    "golden_cross": GoldenCrossStrategy,
    "rsi": RSIStrategy,
    "ma20_rsi_filtered": MA20RSIFilteredStrategy,
}

_scheduler = BackgroundScheduler(timezone="Asia/Seoul")

# 매매 일시정지 플래그 (메모리 기반, 서버 재시작 시 초기화됨)
_trading_paused = True


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

def _parse_watch_list() -> list[tuple[str, str]]:
    """'005930:ma20,035720:rsi' 형태를 [(ticker, strategy_name), ...]로 파싱."""
    raw = os.getenv("WATCH_LIST", "")
    pairs = []
    for item in raw.split(","):
        item = item.strip()
        if not item:
            continue
        ticker, strategy_name = item.split(":")
        pairs.append((ticker.strip(), strategy_name.strip()))
    return pairs


def _is_market_hours() -> bool:
    """한국 정규장 시간(09:00~15:30, 평일)인지 체크."""
    now = datetime.now()
    if now.weekday() >= 5:  # 토(5)/일(6)
        return False
    market_open = dtime(9, 0)
    market_close = dtime(15, 30)
    return market_open <= now.time() <= market_close


def run_all_strategies():
    """감시 목록의 모든 (종목, 전략) 조합을 1회씩 실행."""
    if _trading_paused:
        print(f"[스케줄러] 매매 일시정지 상태 — 스킵 ({datetime.now().strftime('%H:%M:%S')})")
        return
    
    if not _is_market_hours():
        print(f"[스케줄러] 장 시간 외 — 스킵 ({datetime.now().strftime('%H:%M:%S')})")
        return

    db = SessionLocal()
    try:
        for ticker, strategy_name in _parse_watch_list():
            strategy_cls = _STRATEGY_MAP.get(strategy_name)
            if strategy_cls is None:
                print(f"[스케줄러] 알 수 없는 전략: {strategy_name} — 스킵")
                continue

            strategy = strategy_cls()
            try:
                result = run_strategy_once(db, ticker, strategy, strategy_name, quantity=None)
                print(f"[스케줄러] {ticker}/{strategy_name} → {result}")
            except Exception as e:
                print(f"[스케줄러] {ticker}/{strategy_name} 실행 중 에러: {e}")
    finally:
        db.close()


def start_scheduler():
    interval = int(os.getenv("SCHEDULER_INTERVAL_MINUTES", "5"))
    _scheduler.add_job(
        run_all_strategies,
        "interval",
        minutes=interval,
        id="strategy_runner",
        replace_existing=True,
    )
    _scheduler.start()
    print(f"[스케줄러] 시작됨 ({interval}분 간격)")


def stop_scheduler():
    _scheduler.shutdown(wait=False)
    print("[스케줄러] 종료됨")