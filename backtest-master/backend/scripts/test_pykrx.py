from pykrx import stock
import FinanceDataReader as fdr


# 코스피 지수
kospi = fdr.DataReader("KS11", "2026-06-01", "2026-06-24")
print(kospi)
print(kospi.columns.tolist())

# 삼성전자 최근 10일 OHLCV
df = stock.get_market_ohlcv("20260601", "20260624", "005930")
print(df)