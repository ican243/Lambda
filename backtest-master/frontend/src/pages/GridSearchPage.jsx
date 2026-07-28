import { useState } from "react";
import { useQuery, useMutation } from "@tanstack/react-query";
import { getStocks, runGridSearch } from "../api/client";

const STRATEGY_PARAM_DEFAULTS = {
  MA20: {
    period: { min: 5, max: 60, step: 5 },
  },
  GOLDEN_CROSS: {
    short_period: { min: 3, max: 15, step: 2 },
    long_period: { min: 10, max: 60, step: 5 },
  },
  RSI: {
    period: { min: 7, max: 21, step: 7 },
    lower: { min: 20, max: 35, step: 5 },
    upper: { min: 65, max: 80, step: 5 },
  },
};

const PARAM_LABELS = {
  period: "기간 (일)",
  short_period: "단기 이동평균",
  long_period: "장기 이동평균",
  lower: "과매도 기준",
  upper: "과매수 기준",
};

const card = {
  background: "white",
  borderRadius: 12,
  padding: "20px 24px",
  boxShadow: "0 1px 4px rgba(0,0,0,0.08)",
  marginBottom: 20,
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
  padding: "9px 12px",
  border: "1.5px solid #dde1e8",
  borderRadius: 8,
  fontSize: 14,
  background: "#f8fafc",
  boxSizing: "border-box",
};


function buildParamGrid(strategyName, ranges) {
  const grid = {};
  const defaults = STRATEGY_PARAM_DEFAULTS[strategyName] || {};

  Object.entries(defaults).forEach(([key]) => {
    const { min, max, step } = ranges[key] || STRATEGY_PARAM_DEFAULTS[strategyName][key];
    const values = [];
    for (let v = min; v <= max; v += step) {
      values.push(v);
    }
    grid[key] = values;
  });

  return grid;
}


function countCombinations(strategyName, ranges) {
  const defaults = STRATEGY_PARAM_DEFAULTS[strategyName] || {};
  return Object.entries(defaults).reduce((acc, [key]) => {
    const { min, max, step } = ranges[key] || defaults[key];
    const count = Math.floor((max - min) / step) + 1;
    return acc * count;
  }, 1);
}


export default function GridSearchPage() {
  const [ticker, setTicker] = useState("");
  const [strategyName, setStrategyName] = useState("MA20");
  const [startDate, setStartDate] = useState("2024-01-01");
  const [endDate, setEndDate] = useState("2026-06-24");
  const [ranges, setRanges] = useState(STRATEGY_PARAM_DEFAULTS["MA20"]);

  const { data: stocks } = useQuery({ queryKey: ["stocks"], queryFn: getStocks });
  const mutation = useMutation({ mutationFn: runGridSearch });

  const handleStrategyChange = (name) => {
    setStrategyName(name);
    setRanges(STRATEGY_PARAM_DEFAULTS[name]);
  };

  const handleRangeChange = (key, field, value) => {
    setRanges((prev) => ({
      ...prev,
      [key]: { ...prev[key], [field]: Number(value) },
    }));
  };

  const handleSubmit = (e) => {
    e.preventDefault();
    const param_grid = buildParamGrid(strategyName, ranges);
    mutation.mutate({
      ticker,
      strategy_name: strategyName,
      start_date: startDate,
      end_date: endDate,
      param_grid,
    });
  };

  const comboCount = countCombinations(strategyName, ranges);

  return (
    <div>
      <div style={{ marginBottom: 28 }}>
        <h1 style={{ fontSize: 24, fontWeight: 700, color: "#0f1f3d", margin: 0 }}>
          파라미터 최적화
        </h1>
        <p style={{ color: "#718096", marginTop: 6, fontSize: 14 }}>
          파라미터 범위를 설정하면 모든 조합을 자동으로 실행해서 최적값을 찾아줍니다.
        </p>
      </div>

      <form onSubmit={handleSubmit}>
        <div style={card}>
          {/* 기본 설정 */}
          <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 16, marginBottom: 20 }}>
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
              <input type="date" value={startDate}
                onChange={(e) => setStartDate(e.target.value)} style={input} />
            </div>
            <div>
              <label style={label}>종료일</label>
              <input type="date" value={endDate}
                onChange={(e) => setEndDate(e.target.value)} style={input} />
            </div>
          </div>

          {/* 전략 선택 */}
          <div style={{ marginBottom: 20 }}>
            <label style={label}>전략</label>
            <div style={{ display: "flex", gap: 10 }}>
              {["MA20", "GOLDEN_CROSS", "RSI"].map((s) => (
                <button
                  key={s}
                  type="button"
                  onClick={() => handleStrategyChange(s)}
                  style={{
                    padding: "7px 16px",
                    borderRadius: 20,
                    border: "1.5px solid",
                    borderColor: strategyName === s ? "#1a3c5e" : "#dde1e8",
                    background: strategyName === s ? "#1a3c5e" : "white",
                    color: strategyName === s ? "white" : "#4a5568",
                    fontSize: 13,
                    fontWeight: 600,
                    cursor: "pointer",
                  }}
                >
                  {s === "GOLDEN_CROSS" ? "골든크로스" : s}
                </button>
              ))}
            </div>
          </div>

          {/* 파라미터 범위 설정 */}
          <label style={label}>파라미터 범위</label>
          <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(280px, 1fr))", gap: 16 }}>
            {Object.entries(STRATEGY_PARAM_DEFAULTS[strategyName] || {}).map(([key]) => (
              <div key={key} style={{
                background: "#f8fafc",
                borderRadius: 8,
                padding: "14px 16px",
                border: "1px solid #e2e8f0",
              }}>
                <div style={{ fontWeight: 600, color: "#1a3c5e", marginBottom: 12, fontSize: 13 }}>
                  {PARAM_LABELS[key] || key}
                </div>
                <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 8 }}>
                  {["min", "max", "step"].map((field) => (
                    <div key={field}>
                      <div style={{ fontSize: 10, color: "#718096", marginBottom: 4, textTransform: "uppercase" }}>
                        {field === "min" ? "최솟값" : field === "max" ? "최댓값" : "간격"}
                      </div>
                      <input
                        type="number"
                        value={ranges[key]?.[field] ?? STRATEGY_PARAM_DEFAULTS[strategyName][key][field]}
                        onChange={(e) => handleRangeChange(key, field, e.target.value)}
                        style={{ ...input, padding: "7px 10px" }}
                      />
                    </div>
                  ))}
                </div>
              </div>
            ))}
          </div>

          {/* 조합 수 미리보기 */}
          <div style={{
            marginTop: 16,
            padding: "10px 14px",
            background: comboCount > 100 ? "#fff5f5" : "#eef7ee",
            borderRadius: 8,
            fontSize: 13,
            color: comboCount > 100 ? "#c53030" : "#2e7d32",
          }}>
            {comboCount > 100
              ? `⚠️  총 ${comboCount}개 조합 — 시간이 많이 걸릴 수 있습니다. 범위를 줄이는 걸 권장합니다.`
              : `✅  총 ${comboCount}개 조합을 탐색합니다.`}
          </div>
        </div>

        <button
          type="submit"
          disabled={mutation.isPending || !ticker}
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
          {mutation.isPending
            ? `⏳  ${comboCount}개 조합 탐색 중...`
            : `🔍  최적 파라미터 탐색 시작`}
        </button>
      </form>

      {/* 결과 */}
      {mutation.data && (
        <div style={card}>
          <div style={{ display: "flex", alignItems: "center", gap: 8, marginBottom: 16 }}>
            <span style={{ width: 4, height: 20, background: "#c9a84c", borderRadius: 2, display: "inline-block" }} />
            <span style={{ fontWeight: 700, color: "#0f1f3d", fontSize: 15 }}>최적화 결과</span>
            <span style={{ fontSize: 12, color: "#718096" }}>
              총 {mutation.data.total_combinations}개 조합 · Sharpe Ratio 높은 순 정렬
            </span>
          </div>
          <div style={{ overflowX: "auto" }}>
            <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 13 }}>
              <thead>
                <tr style={{ background: "#0f1f3d" }}>
                  {["순위", "파라미터", "수익률", "승률", "MDD", "Sharpe", "초과수익"].map((h) => (
                    <th key={h} style={{
                      padding: "10px 14px",
                      color: "#94a3b8",
                      fontWeight: 600,
                      textAlign: h === "파라미터" || h === "순위" ? "left" : "right",
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
                  <tr key={i} style={{
                    borderBottom: "1px solid #f0f4f8",
                    background: r.rank === 1
                      ? "#fffbeb"
                      : i % 2 === 0 ? "#fafbfc" : "white",
                  }}>
                    <td style={{ padding: "10px 14px" }}>
                      {r.rank === 1
                        ? <span style={{ color: "#c9a84c", fontWeight: 700 }}>🥇 1위</span>
                        : r.rank === 2
                        ? <span style={{ color: "#718096", fontWeight: 700 }}>🥈 2위</span>
                        : r.rank === 3
                        ? <span style={{ color: "#8b7355", fontWeight: 700 }}>🥉 3위</span>
                        : <span style={{ color: "#718096" }}>{r.rank}위</span>}
                    </td>
                    <td style={{ padding: "10px 14px" }}>
                      {Object.entries(r.params).map(([k, v]) => (
                        <span key={k} style={{
                          display: "inline-block",
                          background: "#e8eef5",
                          borderRadius: 4,
                          padding: "2px 8px",
                          fontSize: 12,
                          marginRight: 4,
                          color: "#1a3c5e",
                          fontWeight: 600,
                        }}>
                          {PARAM_LABELS[k] || k}: {v}
                        </span>
                      ))}
                    </td>
                    {[
                      { v: r.total_return, unit: "%" },
                      { v: r.win_rate, unit: "%" },
                      { v: r.mdd, unit: "%" },
                      { v: r.sharpe_ratio, unit: "" },
                      { v: r.excess_return, unit: "%p" },
                    ].map(({ v, unit }, j) => (
                      <td key={j} style={{
                        padding: "10px 14px",
                        textAlign: "right",
                        fontFamily: "monospace",
                        fontWeight: 600,
                        color: v === null ? "#718096"
                          : j === 2 ? (v < 0 ? "#c62828" : "#2e7d32")
                          : v >= 0 ? "#2e7d32" : "#c62828",
                      }}>
                        {v !== null
                          ? `${v > 0 && j !== 2 ? "+" : ""}${v.toFixed(2)}${unit}`
                          : "-"}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}