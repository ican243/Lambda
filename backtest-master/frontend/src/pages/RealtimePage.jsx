import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { useRealtimePrice } from "../hooks/useRealtimePrice";
import { getStocks } from "../api/client";

const card = {
  background: "white",
  borderRadius: 12,
  padding: "20px 24px",
  boxShadow: "0 1px 4px rgba(0,0,0,0.08)",
  marginBottom: 20,
};

function PriceCard({ ticker }) {
  const { price, connected } = useRealtimePrice(ticker, 5);

  if (!price) return (
    <div style={{ ...card, textAlign: "center", color: "#718096", padding: 40 }}>
      {connected ? "데이터 수신 중..." : "연결 중..."}
    </div>
  );

  const isUp = price.change_rate >= 0;

  return (
    <div style={card}>
      {/* 헤더 */}
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 20 }}>
      <div>
  <h2 style={{ margin: 0, fontSize: 20, color: "#0f1f3d" }}>
    {price.name || ticker}  {/* ← 종목명 */}
  </h2>
  <span style={{ fontSize: 13, color: "#718096", marginTop: 2, display: "block" }}>
    {ticker}  {/* ← 종목코드는 아래에 작게 */}
  </span>
  <span style={{
    fontSize: 11,
    background: connected ? "#eef7ee" : "#f4f6f9",
    color: connected ? "#2e7d32" : "#718096",
    padding: "2px 8px",
    borderRadius: 4,
    fontWeight: 600,
    marginTop: 4,
    display: "inline-block",
  }}>
    {connected ? "● 실시간 연결됨" : "○ 연결 끊김"}
  </span>
</div>
        <div style={{ textAlign: "right" }}>
          <div style={{
            fontSize: 32,
            fontWeight: 700,
            fontFamily: "monospace",
            color: isUp ? "#c62828" : "#1565C0",
          }}>
            {price.current_price.toLocaleString()}원
          </div>
          <div style={{
            fontSize: 14,
            fontWeight: 600,
            color: isUp ? "#c62828" : "#1565C0",
          }}>
            {isUp ? "▲" : "▼"} {Math.abs(price.change_price).toLocaleString()}원
            ({isUp ? "+" : ""}{price.change_rate.toFixed(2)}%)
          </div>
        </div>
      </div>

      {/* 세부 지표 */}
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr 1fr", gap: 12 }}>
        {[
          { label: "시가", value: price.open.toLocaleString() + "원" },
          { label: "고가", value: price.high.toLocaleString() + "원", color: "#c62828" },
          { label: "저가", value: price.low.toLocaleString() + "원", color: "#1565C0" },
          { label: "거래량", value: price.volume.toLocaleString() },
        ].map((item) => (
          <div key={item.label} style={{
            background: "#f8fafc",
            borderRadius: 8,
            padding: "10px 14px",
            textAlign: "center",
          }}>
            <div style={{ fontSize: 11, color: "#718096", marginBottom: 4, fontWeight: 600 }}>
              {item.label}
            </div>
            <div style={{
              fontSize: 14,
              fontWeight: 700,
              fontFamily: "monospace",
              color: item.color || "#0f1f3d",
            }}>
              {item.value}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}


export default function RealtimePage() {
  const [selectedTicker, setSelectedTicker] = useState("");
  const [watchlist, setWatchlist] = useState([]);

  const { data: stocks } = useQuery({ queryKey: ["stocks"], queryFn: getStocks });

  const addToWatchlist = () => {
    if (!selectedTicker || watchlist.includes(selectedTicker)) return;
    setWatchlist((prev) => [...prev, selectedTicker]);
    setSelectedTicker("");
  };

  const removeFromWatchlist = (ticker) => {
    setWatchlist((prev) => prev.filter((t) => t !== ticker));
  };

  return (
    <div>
      <div style={{ marginBottom: 28 }}>
        <h1 style={{ fontSize: 24, fontWeight: 700, color: "#0f1f3d", margin: 0 }}>
          실시간 시세
        </h1>
        <p style={{ color: "#718096", marginTop: 6, fontSize: 14 }}>
          KIS API 연동 · 5초마다 자동 갱신 · 장 중(09:00~15:30)에만 실시간 데이터
        </p>
      </div>

      {/* 종목 추가 */}
      <div style={{ ...card, display: "flex", gap: 12, alignItems: "flex-end" }}>
        <div style={{ flex: 1 }}>
          <label style={{
            display: "block",
            fontSize: 11,
            fontWeight: 600,
            color: "#4a5568",
            marginBottom: 6,
            textTransform: "uppercase",
            letterSpacing: 0.5,
          }}>
            종목 추가
          </label>
          <select
            value={selectedTicker}
            onChange={(e) => setSelectedTicker(e.target.value)}
            style={{
              width: "100%",
              padding: "10px 12px",
              border: "1.5px solid #dde1e8",
              borderRadius: 8,
              fontSize: 14,
              background: "#f8fafc",
              cursor: "pointer",
            }}
          >
            <option value="">종목을 선택하세요</option>
            {stocks?.filter((s) => s.market !== "INDEX")
              .filter((s) => !watchlist.includes(s.ticker))
              .map((s) => (
                <option key={s.ticker} value={s.ticker}>
                  {s.name} ({s.ticker})
                </option>
              ))}
          </select>
        </div>
        <button
          onClick={addToWatchlist}
          disabled={!selectedTicker}
          style={{
            padding: "10px 24px",
            background: selectedTicker ? "#0f1f3d" : "#718096",
            color: "white",
            border: "none",
            borderRadius: 8,
            fontSize: 14,
            fontWeight: 600,
            cursor: selectedTicker ? "pointer" : "not-allowed",
            whiteSpace: "nowrap",
          }}
        >
          + 추가
        </button>
      </div>

      {/* 워치리스트 */}
      {watchlist.length === 0 ? (
        <div style={{ ...card, textAlign: "center", color: "#718096", padding: 48 }}>
          종목을 추가하면 실시간 시세를 볼 수 있습니다.
        </div>
      ) : (
        watchlist.map((ticker) => (
          <div key={ticker}>
            <div style={{ display: "flex", justifyContent: "flex-end", marginBottom: 6 }}>
              <button
                onClick={() => removeFromWatchlist(ticker)}
                style={{
                  background: "none",
                  border: "none",
                  color: "#718096",
                  fontSize: 12,
                  cursor: "pointer",
                }}
              >
                ✕ 제거
              </button>
            </div>
            <PriceCard ticker={ticker} />
          </div>
        ))
      )}
    </div>
  );
}