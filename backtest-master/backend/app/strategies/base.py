from abc import ABC, abstractmethod
import pandas as pd


class BaseStrategy(ABC):
    """모든 전략의 공통 인터페이스.
    price_df는 ['open','high','low','close','volume'] 컬럼을 가진 DataFrame (날짜 인덱스)."""

    def __init__(self, params: dict | None = None):
        self.params = params or {}

    @abstractmethod
    def generate_signals(self, price_df: pd.DataFrame) -> pd.DataFrame:
        """price_df에 'signal' 컬럼을 추가해서 반환.
        signal: 1 = 매수, -1 = 매도, 0 = 유지"""
        raise NotImplementedError