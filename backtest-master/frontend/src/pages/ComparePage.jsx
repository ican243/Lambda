import { useState } from "react";
import { useQuery, useMutation } from "@tanstack/react-query";
import {
  LineChart, Line, XAxis, YAxis, CartesianGrid,
  Tooltip, Legend, ResponsiveContainer,
} from "recharts";
import { getStocks, compareStrategies } from "../api/client";

const STRATEGY_COLORS = {
  MA20: "#0F4C81",
  GOLDEN_CROSS: "#C9A84C",
  RSI: "#2e7d32",
};

const STRATEGY_LABELS = {
  MA20: "MA20",
  GOLDEN_CROSS: "골든크로스",
  RSI: "RSI",
};

const card = {
  background: "white",
  borderRadius: 12,
  padding: "20px 24px",
  boxShadow: "0 1px 4px rgba(0,0,0,0.08)",
};

const label = {
  display: "block",
  fontSize: 11,
  fontWeight: 600,
  color: "#4a5568",
  marginBottom: 6,
  textTransform: "uppercase",
  letterSpacing: 0.5,
};

const input = {
  width: "100%",
  padding: "10px 12px",
  border: "1.5px solid #dde1e8",
  borderRadius: 8,
  fontSize: 14,
  color: "#1a202c",
  background: "#f8fafc",
  boxSizing: "border-box",
};

export default function ComparePage() {
  const [ticker, setTicker] = useState("");
  const [startDate, setStartDate] = useState("2024-01-01");
  const [endDate, setEndDate] = useState("2026-06-24");
  const [selectedStrategies, setSelectedStrategies] = useState([
    "MA20", "GOLDEN_CROSS", "RSI",
  ]);

  const { data: stocks } = useQuery({
    queryKey: ["stocks"],
    queryFn: getStocks,
  });

  const mutation = useMutation({ mutationFn: compareStrategies });

  const handleSubmit = (e) => {
    e.preventDefault();
    mutation.mutate({
      ticker,
      start_date: startDate,
      end_date: endDate,
      strategies: selectedStrategies,
    });
  };

  const toggleStrategy = (name) => {
    setSelectedStrategies((prev) =>
      prev.includes(name)
        ? prev.filter((s) => s !== name)
        : [...prev, name]
    );
  };

  // 자산 곡선 데이터 병합 (날짜 기준으로 모든 전략 합치기)
  const mergedCurve = (() => {
    if (!mutation.data) return [];
    const { results } = mutation.data;
    if (!results || results.length === 0) return [];

    const dateMap = {};
    results.forEach(({ strategy_name, equity_curve }) => {
      if (!equity_curve) return;
      equity_curve.forEach(({ date, strategy }) => {
        if (!dateMap[date]) dateMap[date] = { date };
        dateMap[date][strategy_name] = strategy;
      });
    });

    return Object.values(dateMap).sort((a, b) =>
      a.date.localeCompare(b.date)
    );
  })();

  return (
    <div>
      <div style={{ marginBottom: 28 }}>
        <h1 style={{ fontSize: 24, fontWeight: 700, color: "#0f1f3d", margin: 0 }}>
          전략 비교
        </h1>
        <p style={{ color: "#718096", marginTop: 6, fontSize: 14 }}>
          같은 종목과 기간에 여러 전략을 동시에 실행해서 비교합니다.
        </p>
      </div>

      {/* 설정 폼 */}
      <form onSubmit={handleSubmit}>
        <div style={{ ...card, marginBottom: 20 }}>
          <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 16 }}>
            <div>
              <label style={label}>종목</label>
              <select
                value={ticker}
                onChange={(e) => setTicker(e.target.value)}
                required
                style={{ ...input, cursor: "pointer" }}
              >
                <option value="">선택하세요</option>
                {stocks?.filter((s) => s.market !== "INDEX").map((s) => (
                  <option key={s.ticker} value={s.ticker}>
                    {s.name} ({s.ticker})
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label style={label}>시작일</label>
              <input
                type="date"
                value={startDate}
                onChange={(e) => setStartDate(e.target.value)}
                style={input}
              />
            </div>
            <div>
              <label style={label}>종료일</label>
              <input
                type="date"
                value={endDate}
                onChange={(e) => setEndDate(e.target.value)}
                style={input}
              />
            </div>
          </div>

          {/* 전략 선택 */}
          <div style={{ marginTop: 16 }}>
            <label style={label}>비교할 전략</label>
            <div style={{ display: "flex", gap: 10 }}>
              {["MA20", "GOLDEN_CROSS", "RSI"].map((s) => (
                <button
                  key={s}
                  type="button"
                  onClick={() => toggleStrategy(s)}
                  style={{
                    padding: "7px 16px",
                    borderRadius: 20,
                    border: `2px solid ${STRATEGY_COLORS[s]}`,
                    background: selectedStrategies.includes(s)
                      ? STRATEGY_COLORS[s]
                      : "white",
                    color: selectedStrategies.includes(s) ? "white" : STRATEGY_COLORS[s],
                    fontSize: 13,
                    fontWeight: 600,
                    cursor: "pointer",
                  }}
                >
                  {STRATEGY_LABELS[s]}
                </button>
              ))}
            </div>
          </div>
        </div>

        <button
          type="submit"
          disabled={mutation.isPending || !ticker || selectedStrategies.length === 0}
          style={{
            width: "100%",
            padding: "14px",
            background: mutation.isPending ? "#718096" : "#0f1f3d",
            color: "white",
            border: "none",
            borderRadius: 10,
            fontSize: 15,
            fontWeight: 700,
            cursor: "pointer",
            marginBottom: 28,
          }}
        >
          {mutation.isPending ? "⏳  비교 실행 중..." : "▶  전략 비교 실행"}
        </button>
      </form>

      {/* 결과 */}
      {mutation.data && (
        <div>
          {/* 지표 비교 테이블 */}
          <div style={{ ...card, marginBottom: 20 }}>
            <div style={{ display: "flex", alignItems: "center", gap: 8, marginBottom: 16 }}>
              <span style={{ width: 4, height: 20, background: "#c9a84c", borderRadius: 2, display: "inline-block" }} />
              <span style={{ fontWeight: 700, color: "#0f1f3d", fontSize: 15 }}>지표 비교</span>
            </div>
            <div style={{ overflowX: "auto" }}>
              <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 13 }}>
                <thead>
                  <tr style={{ background: "#0f1f3d" }}>
                    {["전략", "수익률", "승률", "MDD", "Sharpe", "초과수익"].map((h) => (
                      <th key={h} style={{
                        padding: "10px 14px",
                        color: "#94a3b8",
                        fontWeight: 600,
                        textAlign: h === "전략" ? "left" : "right",
                        fontSize: 11,
                        letterSpacing: 0.5,
                        textTransform: "uppercase",
                      }}>
                        {h}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {mutation.data.results.map((r, i) => (
                    <tr key={r.strategy_name}
                      style={{ borderBottom: "1px solid #f0f4f8", background: i % 2 === 0 ? "#fafbfc" : "white" }}>
                      <td style={{ padding: "10px 14px" }}>
                        <span style={{
                          display: "inline-block",
                          width: 10, height: 10,
                          borderRadius: "50%",
                          background: STRATEGY_COLORS[r.strategy_name],
                          marginRight: 8,
                        }} />
                        <strong>{STRATEGY_LABELS[r.strategy_name]}</strong>
                      </td>
                      {[
                        r.total_return,
                        r.win_rate,
                        r.mdd,
                        r.sharpe_ratio,
                        r.excess_return,
                      ].map((v, j) => (
                        <td key={j} style={{
                          padding: "10px 14px",
                          textAlign: "right",
                          fontFamily: "monospace",
                          fontWeight: 600,
                          color: v === null ? "#718096"
                            : j === 2 ? (v < 0 ? "#c62828" : "#2e7d32")  // MDD
                            : v >= 0 ? "#2e7d32" : "#c62828",
                        }}>
                          {v !== null ? `${v >= 0 && j !== 2 ? "+" : ""}${v.toFixed(2)}%` : "-"}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          {/* 자산 곡선 비교 차트 */}
          <div style={card}>
            <div style={{ display: "flex", alignItems: "center", gap: 8, marginBottom: 16 }}>
              <span style={{ width: 4, height: 20, background: "#c9a84c", borderRadius: 2, display: "inline-block" }} />
              <span style={{ fontWeight: 700, color: "#0f1f3d", fontSize: 15 }}>자산 곡선 비교</span>
              <span style={{ fontSize: 12, color: "#718096" }}>초기 자본 1,000만원 기준</span>
            </div>
            <ResponsiveContainer width="100%" height={320}>
              <LineChart data={mergedCurve}>
                <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                <XAxis
                  dataKey="date"
                  tickFormatter={(d) => d.slice(2, 10).replace(/-/g, "/")}
                  interval={Math.floor(mergedCurve.length / 7)}
                  tick={{ fontSize: 11, fill: "#718096" }}
                />
                <YAxis
                  tickFormatter={(v) => `${(v / 10000).toFixed(0)}만`}
                  width={55}
                  tick={{ fontSize: 11, fill: "#718096" }}
                />
                <Tooltip
                  contentStyle={{ borderRadius: 8, border: "1px solid #e2e8f0", fontSize: 12 }}
                  formatter={(v, name) => [
                    `${v.toLocaleString()}원`,
                    STRATEGY_LABELS[name] || name,
                  ]}
                  labelFormatter={(d) => d}
                />
                <Legend
                  formatter={(v) => STRATEGY_LABELS[v] || v}
                  wrapperStyle={{ fontSize: 12 }}
                />
                {mutation.data.results.map(({ strategy_name }) => (
                  <Line
                    key={strategy_name}
                    type="monotone"
                    dataKey={strategy_name}
                    stroke={STRATEGY_COLORS[strategy_name]}
                    strokeWidth={2}
                    dot={false}
                    name={strategy_name}
                  />
                ))}
              </LineChart>
            </ResponsiveContainer>
          </div>
        </div>
      )}
    </div>
  );
}