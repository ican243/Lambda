"""
지표 계산 함수들과 메타데이터를 한곳에 모아둔 레지스트리.
사용자가 프론트엔드에서 지표를 선택하면, 여기 등록된 함수들만 조합해서 점수를 계산한다.
"""
import pandas as pd


def compute_trend_score(df: pd.DataFrame, params: dict) -> pd.Series:
    ma_short = params.get("ma_short", 20)
    ma_long = params.get("ma_long", 60)
    s = df["close"].rolling(ma_short).mean()
    l = df["close"].rolling(ma_long).mean()
    return (s > l).astype(int)


def compute_momentum_score(df: pd.DataFrame, params: dict) -> pd.Series:
    threshold = params.get("momentum_rank_threshold", 0.6)
    if "momentum_rank_pct" not in df.columns:
        raise ValueError("momentum_rank_pct 컬럼이 없습니다. 사전 병합이 필요합니다.")
    rank = df["momentum_rank_pct"]
    return (rank >= threshold).where(rank.notna(), 0).astype(int)


def compute_volume_score(df: pd.DataFrame, params: dict) -> pd.Series:
    period = params.get("volume_ma_period", 20)
    multiplier = params.get("volume_multiplier", 1.5)
    volume_ma = df["volume"].rolling(period).mean()
    return (df["volume"] >= volume_ma * multiplier).astype(int)


def compute_volatility_score(df: pd.DataFrame, params: dict) -> pd.Series:
    period = params.get("atr_period", 14)
    low = params.get("atr_low_pct", 0.01)
    high = params.get("atr_high_pct", 0.06)

    high_low = df["high"] - df["low"]
    high_close = (df["high"] - df["close"].shift()).abs()
    low_close = (df["low"] - df["close"].shift()).abs()
    tr = pd.concat([high_low, high_close, low_close], axis=1).max(axis=1)
    atr_pct = tr.rolling(period).mean() / df["close"]
    return atr_pct.between(low, high).astype(int)


def compute_market_regime_score(df: pd.DataFrame, params: dict) -> pd.Series:
    if "market_bullish" not in df.columns:
        raise ValueError("market_bullish 컬럼이 없습니다. 사전 병합이 필요합니다.")
    return df["market_bullish"].fillna(False).astype(int)


FACTOR_REGISTRY = {
    "trend": {
        "label": "추세 (MA20 > MA60)",
        "description": "단기 이동평균이 장기 이동평균 위에 있는 상승 추세 구간",
        "compute": compute_trend_score,
        "default_weight": 35,
        "requires": [],
    },
    "momentum": {
        "label": "모멘텀 (상대 강도)",
        "description": "관심 종목군 내에서 최근 상승률이 상위권인지",
        "compute": compute_momentum_score,
        "default_weight": 25,
        "requires": ["momentum_rank_pct"],
    },
    "volume": {
        "label": "거래량 급증",
        "description": "평균 대비 거래량이 유의미하게 늘어난 구간",
        "compute": compute_volume_score,
        "default_weight": 20,
        "requires": [],
    },
    "volatility": {
        "label": "변동성 적정 구간",
        "description": "너무 잠잠하지도, 너무 요동치지도 않는 변동성 구간",
        "compute": compute_volatility_score,
        "default_weight": 10,
        "requires": [],
    },
    "market_regime": {
        "label": "시장 상황 (코스피 MA200)",
        "description": "코스피 지수 자체가 장기 상승 국면인지",
        "compute": compute_market_regime_score,
        "default_weight": 10,
        "requires": ["market_bullish"],
    },
}