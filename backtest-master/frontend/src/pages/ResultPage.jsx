import { useQuery } from "@tanstack/react-query";
import {
  LineChart, Line, XAxis, YAxis, CartesianGrid,
  Tooltip, Legend, ResponsiveContainer, ReferenceLine,
} from "recharts";
import { getBacktestTrades } from "../api/client";

const card = {
  background: "white",
  borderRadius: 12,
  padding: "24px 28px",
  boxShadow: "0 1px 4px rgba(0,0,0,0.08)",
  marginBottom: 20,
};

function MetricCard({ label, value, unit = "", highlight = false, positive }) {
  const isPositive = positive ?? (typeof value === "number" ? value >= 0 : null);
  return (
    <div style={{
      background: highlight ? "#0f1f3d" : "white",
      borderRadius: 10,
      padding: "16px 20px",
      minWidth: 130,
      flex: 1,
      borderTop: `3px solid ${highlight ? "#c9a84c" : "#e2e8f0"}`,
      boxShadow: "0 1px 4px rgba(0,0,0,0.07)",
    }}>
      <div style={{ fontSize: 11, color: highlight ? "#94a3b8" : "#718096", marginBottom: 8, fontWeight: 600, textTransform: "uppercase", letterSpacing: 0.5 }}>
        {label}
      </div>
      <div style={{
        fontSize: 22,
        fontWeight: 700,
        fontFamily: "'Courier New', monospace",
        color: highlight ? "#c9a84c" : isPositive === null ? "#1a202c" : isPositive ? "#2e7d32" : "#c62828",
      }}>
        {typeof value === "number" ? value.toFixed(2) : value}{unit}
      </div>
    </div>
  );
}

export default function ResultPage({ result, onBack }) {
  const { data: trades } = useQuery({
    queryKey: ["trades", result.run_id],
    queryFn: () => getBacktestTrades(result.run_id),
  });

  const metrics = [
    { label: "전략 수익률", value: result.total_return, unit: "%", highlight: true },
    { label: "매수후보유", value: result.buy_hold_return, unit: "%" },
    { label: "코스피 지수", value: result.benchmark_return, unit: "%" },
    { label: "초과수익", value: result.excess_return, unit: "%p" },
    { label: "승률", value: result.win_rate, unit: "%", positive: null },
    { label: "MDD", value: result.mdd, unit: "%", positive: false },
    { label: "Sharpe Ratio", value: result.sharpe_ratio, unit: "" },
  ];

  return (
    <div>
      {/* 헤더 */}
      <div style={{ display: "flex", alignItems: "center", gap: 12, marginBottom: 28 }}>
        <button
          onClick={onBack}
          style={{
            background: "white",
            border: "1.5px solid #dde1e8",
            borderRadius: 8,
            padding: "8px 14px",
            fontSize: 13,
            cursor: "pointer",
            color: "#4a5568",
          }}
        >
          ← 다시 실행
        </button>
        <div>
          <h1 style={{ fontSize: 22, fontWeight: 700, color: "#0f1f3d", margin: 0 }}>
            백테스트 결과
          </h1>
          <span style={{ fontSize: 12, color: "#718096" }}>Run #{result.run_id}</span>
        </div>
        <div style={{ marginLeft: "auto", display: "flex", gap: 10 }}>
          <button
            onClick={() => {
              const url = `${window.location.origin}/backtest/${result.run_id}`;
              navigator.clipboard.writeText(url).then(() => alert("링크가 복사되었습니다!"));
            }}
            style={{
              background: "white",
              border: "1.5px solid #dde1e8",
              color: "#1a3c5e",
              padding: "8px 16px",
              borderRadius: 8,
              cursor: "pointer",
              fontSize: 13,
              fontWeight: 600,
            }}
          >
            🔗 링크 복사
          </button>
          
            <a href={`http://127.0.0.1:8000/backtest/${result.run_id}/report`}
            target="_blank"
            rel="noreferrer"
            style={{
              background: "#0f1f3d",
              color: "white",
              padding: "8px 16px",
              borderRadius: 8,
              textDecoration: "none",
              fontSize: 13,
              fontWeight: 600,
              display: "flex",
              alignItems: "center",
            }}
          >
            📄 PDF 다운로드
          </a>
        </div>
      </div>

      {/* 지표 카드 */}
      <div style={{ display: "flex", gap: 10, flexWrap: "wrap", marginBottom: 20 }}>
        {metrics.map((m) => (
          <MetricCard key={m.label} {...m} />
        ))}
      </div>

      {/* 자산 곡선 */}
      <div style={card}>
        <div style={{ display: "flex", alignItems: "center", gap: 8, marginBottom: 20 }}>
          <span style={{ width: 4, height: 20, background: "#c9a84c", borderRadius: 2, display: "inline-block" }} />
          <span style={{ fontWeight: 700, color: "#0f1f3d", fontSize: 15 }}>자산 곡선</span>
          <span style={{ fontSize: 12, color: "#718096", marginLeft: 4 }}>초기 자본 1,000만원 기준</span>
        </div>
        {result.equity_curve && result.equity_curve.length > 0 ? (
          <ResponsiveContainer width="100%" height={300}>
            <LineChart data={result.equity_curve}>
              <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
              <XAxis
                dataKey="date"
                tickFormatter={(d) => d.slice(2, 10).replace(/-/g, "/")}
                interval={Math.floor(result.equity_curve.length / 7)}
                tick={{ fontSize: 11, fill: "#718096" }}
              />
              <YAxis
                tickFormatter={(v) => `${(v / 10000).toFixed(0)}만`}
                width={55}
                tick={{ fontSize: 11, fill: "#718096" }}
              />
              <Tooltip
                contentStyle={{ borderRadius: 8, border: "1px solid #e2e8f0", fontSize: 13 }}
                formatter={(v, name) => [
                  `${v.toLocaleString()}원`,
                  name === "strategy" ? "전략" : "매수후보유",
                ]}
                labelFormatter={(d) => d}
              />
              <Legend
                formatter={(v) => v === "strategy" ? "전략" : "매수후보유"}
                wrapperStyle={{ fontSize: 12 }}
              />
              <ReferenceLine y={10000000} stroke="#94a3b8" strokeDasharray="4 4" />
              <Line type="monotone" dataKey="strategy" stroke="#1a3c5e" strokeWidth={2} dot={false} name="strategy" />
              <Line type="monotone" dataKey="buy_hold" stroke="#c9a84c" strokeWidth={2} dot={false} strokeDasharray="5 5" name="buy_hold" />
            </LineChart>
          </ResponsiveContainer>
        ) : (
          <p style={{ color: "#718096" }}>자산 곡선 데이터가 없습니다.</p>
        )}
      </div>

      {/* 매매 내역 */}
      <div style={card}>
        <div style={{ display: "flex", alignItems: "center", gap: 8, marginBottom: 20 }}>
          <span style={{ width: 4, height: 20, background: "#c9a84c", borderRadius: 2, display: "inline-block" }} />
          <span style={{ fontWeight: 700, color: "#0f1f3d", fontSize: 15 }}>매매 내역</span>
          {trades && <span style={{ fontSize: 12, color: "#718096" }}>총 {trades.length}건</span>}
        </div>
        {trades && trades.length > 0 ? (
          <div style={{ overflowX: "auto" }}>
            <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 13 }}>
              <thead>
                <tr style={{ background: "#0f1f3d" }}>
                  {["매수일", "매수가", "매도일", "매도가", "손익", "거래비용"].map((h) => (
                    <th key={h} style={{
                      padding: "10px 14px",
                      color: "#94a3b8",
                      fontWeight: 600,
                      textAlign: "left",
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
                {trades.map((t, i) => (
                  <tr key={i} style={{ borderBottom: "1px solid #f0f4f8", background: i % 2 === 0 ? "#fafbfc" : "white" }}>
                    <td style={{ padding: "10px 14px", color: "#4a5568" }}>{t.entry_date}</td>
                    <td style={{ padding: "10px 14px", fontFamily: "monospace" }}>{t.entry_price?.toLocaleString()}원</td>
                    <td style={{ padding: "10px 14px", color: "#4a5568" }}>{t.exit_date ?? "-"}</td>
                    <td style={{ padding: "10px 14px", fontFamily: "monospace" }}>{t.exit_price?.toLocaleString()}원</td>
                    <td style={{
                      padding: "10px 14px",
                      fontFamily: "monospace",
                      fontWeight: 700,
                      color: t.profit >= 0 ? "#2e7d32" : "#c62828",
                    }}>
                      {t.profit >= 0 ? "+" : ""}{t.profit?.toLocaleString()}원
                    </td>
                    <td style={{ padding: "10px 14px", fontFamily: "monospace", color: "#718096" }}>
                      {t.cost?.toLocaleString()}원
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <p style={{ color: "#718096" }}>매매 내역이 없습니다.</p>
        )}
      </div>
    </div>
  );
}