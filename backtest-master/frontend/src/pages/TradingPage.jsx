import { useState, useEffect } from "react";
import { getOrders, runStrategy, pauseTrading, resumeTrading, getSchedulerStatus } from "../api/client";

const statusColor = {
  filled: "#1a7a4c",
  pending: "#c9a84c",
  partial: "#4a7aad",
  failed: "#b3413a",
};

const statusLabel = {
  filled: "체결완료",
  pending: "대기중",
  partial: "부분체결",
  failed: "실패",
};

const reasonLabel = {
  strategy_signal: "전략 신호",
  stop_loss: "손절",
  take_profit: "익절",
};

const reasonColor = {
  strategy_signal: "#64748b",
  stop_loss: "#b3413a",
  take_profit: "#1a7a4c",
};

export default function TradingPage() {
  const [orders, setOrders] = useState([]);
  const [loading, setLoading] = useState(false);
  const [ticker, setTicker] = useState("005930");
  const [strategyName, setStrategyName] = useState("ma20");
  const [quantity, setQuantity] = useState(1);
  const [lastResult, setLastResult] = useState(null);
  const [isPaused, setIsPaused] = useState(false);
  const [toggling, setToggling] = useState(false);

  const loadOrders = async () => {
    const data = await getOrders();
    setOrders(data);
  };

  const loadSchedulerStatus = async () => {
    const data = await getSchedulerStatus();
    setIsPaused(data.paused);
  };

  useEffect(() => {
    loadOrders();
    loadSchedulerStatus();
  }, []);

  const handleRunStrategy = async () => {
    setLoading(true);
    setLastResult(null);
    try {
      const result = await runStrategy(ticker, strategyName, quantity);
      setLastResult(result);
      await loadOrders();
    } catch (err) {
      setLastResult({ action: "error", reason: err.message });
    } finally {
      setLoading(false);
    }
  };

  const handleTogglePause = async () => {
    setToggling(true);
    try {
      if (isPaused) {
        await resumeTrading();
      } else {
        await pauseTrading();
      }
      await loadSchedulerStatus();
    } finally {
      setToggling(false);
    }
  };

  return (
    <div>
      <div style={{
        display: "flex",
        justifyContent: "space-between",
        alignItems: "flex-start",
        marginBottom: 24,
      }}>
        <div>
          <h2 style={{ color: "#0f1f3d", fontSize: 20, fontWeight: 700, marginBottom: 4 }}>
            자동매매 (모의투자)
          </h2>
          <p style={{ color: "#94a3b8", fontSize: 13, margin: 0 }}>
            전략 신호를 실행하고 실제 주문 이력을 확인합니다.
          </p>
        </div>

        <button
          onClick={handleTogglePause}
          disabled={toggling}
          style={{
            display: "flex",
            alignItems: "center",
            gap: 8,
            background: isPaused ? "#fef2f2" : "#f0fdf4",
            border: `2px solid ${isPaused ? "#b3413a" : "#1a7a4c"}`,
            color: isPaused ? "#b3413a" : "#1a7a4c",
            borderRadius: 20,
            padding: "8px 16px",
            fontSize: 13,
            fontWeight: 700,
            cursor: toggling ? "default" : "pointer",
            opacity: toggling ? 0.6 : 1,
          }}
        >
          <span style={{
            width: 8,
            height: 8,
            borderRadius: "50%",
            background: isPaused ? "#b3413a" : "#1a7a4c",
            display: "inline-block",
          }} />
          {toggling ? "변경 중..." : isPaused ? "매매 일시정지됨 (클릭하여 재개)" : "자동매매 실행 중 (클릭하여 정지)"}
        </button>
      </div>

      {/* 전략 실행 패널 */}
      <div style={{
        background: "white",
        border: "1px solid #e2e8f0",
        borderRadius: 8,
        padding: 20,
        marginBottom: 24,
        display: "flex",
        gap: 12,
        alignItems: "flex-end",
        flexWrap: "wrap",
      }}>
        <div>
          <label style={{ display: "block", fontSize: 12, color: "#64748b", marginBottom: 4 }}>
            종목코드
          </label>
          <input
            value={ticker}
            onChange={(e) => setTicker(e.target.value)}
            style={{
              border: "1px solid #cbd5e1",
              borderRadius: 4,
              padding: "8px 10px",
              fontSize: 14,
              width: 120,
            }}
          />
        </div>

        <div>
          <label style={{ display: "block", fontSize: 12, color: "#64748b", marginBottom: 4 }}>
            전략
          </label>
          <select
            value={strategyName}
            onChange={(e) => setStrategyName(e.target.value)}
            style={{
              border: "1px solid #cbd5e1",
              borderRadius: 4,
              padding: "8px 10px",
              fontSize: 14,
            }}
          >
            <option value="ma20">MA20</option>
            <option value="golden_cross">골든크로스</option>
            <option value="rsi">RSI</option>
            <option value="ma20_rsi_filtered">MA20+RSI 필터링</option>
          </select>
        </div>

        <div>
          <label style={{ display: "block", fontSize: 12, color: "#64748b", marginBottom: 4 }}>
            수량
          </label>
          <input
            type="number"
            min={1}
            value={quantity}
            onChange={(e) => setQuantity(Number(e.target.value))}
            style={{
              border: "1px solid #cbd5e1",
              borderRadius: 4,
              padding: "8px 10px",
              fontSize: 14,
              width: 80,
            }}
          />
        </div>

        <button
          onClick={handleRunStrategy}
          disabled={loading}
          style={{
            background: "#0f1f3d",
            border: "2px solid #c9a84c",
            color: "#c9a84c",
            borderRadius: 4,
            padding: "9px 20px",
            fontSize: 14,
            fontWeight: 600,
            cursor: loading ? "default" : "pointer",
            opacity: loading ? 0.6 : 1,
          }}
        >
          {loading ? "실행 중..." : "전략 실행"}
        </button>
      </div>

      {/* 마지막 실행 결과 */}
      {lastResult && (
        <div style={{
          background: lastResult.action === "error" ? "#fef2f2" : "#f8fafc",
          border: `1px solid ${lastResult.action === "error" ? "#fca5a5" : "#e2e8f0"}`,
          borderRadius: 8,
          padding: "12px 16px",
          marginBottom: 24,
          fontSize: 13,
          color: "#334155",
        }}>
          <strong>실행 결과:</strong>{" "}
          {lastResult.action === "hold" && `홀드 — ${lastResult.reason}`}
          {lastResult.action === "skip" && `스킵 — ${lastResult.reason}`}
          {lastResult.action === "error" && `에러 — ${lastResult.reason}`}
          {(lastResult.action === "buy" || lastResult.action === "sell") && (
  <span>
    {lastResult.action === "buy" ? "매수" : "매도"} 주문 실행 (주문 ID: {lastResult.order_id})
    {lastResult.reason && lastResult.reason !== "strategy_signal" && (
      <span style={{
        marginLeft: 8,
        color: reasonColor[lastResult.reason],
        fontWeight: 700,
      }}>
        [{reasonLabel[lastResult.reason]}]
      </span>
    )}
  </span>
)}
        </div>
      )}

      {/* 주문 이력 테이블 */}
      <div style={{
        background: "white",
        border: "1px solid #e2e8f0",
        borderRadius: 8,
        overflow: "hidden",
      }}>
        <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 13 }}>
          <thead>
            <tr style={{ background: "#f8fafc", borderBottom: "1px solid #e2e8f0" }}>
              <th style={thStyle}>시각</th>
              <th style={thStyle}>종목</th>
              <th style={thStyle}>전략</th>
              <th style={thStyle}>구분</th>
              <th style={thStyle}>수량</th>
              <th style={thStyle}>체결가</th>
              <th style={thStyle}>상태</th>
              <th style={thStyle}>사유</th>
            </tr>
          </thead>
          <tbody>
            {orders.length === 0 && (
              <tr>
                <td colSpan={8} style={{ ...tdStyle, textAlign: "center", color: "#94a3b8" }}>
                  주문 이력이 없습니다.
                </td>
              </tr>
            )}
            {orders.map((o) => (
              <tr key={o.id} style={{ borderBottom: "1px solid #f1f5f9" }}>
                <td style={tdStyle}>{o.created_at?.slice(0, 16).replace("T", " ")}</td>
                <td style={tdStyle}>{o.ticker}</td>
                <td style={tdStyle}>{o.strategy_name}</td>
                <td style={{ ...tdStyle, color: o.order_type === "buy" ? "#b3413a" : "#1a4c8c", fontWeight: 600 }}>
                  {o.order_type === "buy" ? "매수" : "매도"}
                </td>
                <td style={tdStyle}>{o.quantity}</td>
                <td style={tdStyle}>{o.filled_price ? o.filled_price.toLocaleString() : "-"}</td>
                <td style={tdStyle}>
                  <span style={{ color: statusColor[o.status] || "#64748b", fontWeight: 600 }}>
                    {statusLabel[o.status] || o.status}
                  </span>
                </td>
                <td style={tdStyle}>
                  {o.reason ? (
                    <span style={{
                      color: reasonColor[o.reason] || "#64748b",
                      fontWeight: o.reason === "stop_loss" || o.reason === "take_profit" ? 700 : 400,
                      fontSize: 12,
                    }}>
                      {reasonLabel[o.reason] || o.reason}
                    </span>
                  ) : (
                    <span style={{ color: "#cbd5e1" }}>-</span>)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

const thStyle = {
  textAlign: "left",
  padding: "10px 16px",
  color: "#64748b",
  fontWeight: 600,
  fontSize: 12,
};

const tdStyle = {
  padding: "10px 16px",
  color: "#334155",
};