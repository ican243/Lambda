import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { getBacktestHistory, getBacktestResult } from "../api/client";

const card = {
  background: "white",
  borderRadius: 12,
  padding: "20px 24px",
  boxShadow: "0 1px 4px rgba(0,0,0,0.08)",
};

const STRATEGY_LABELS = {
  MA20: "MA20",
  GOLDEN_CROSS: "골든크로스",
  RSI: "RSI",
};

function ReturnBadge({ value }) {
  if (value === null || value === undefined) {
    return <span style={{ color: "#718096" }}>-</span>;
  }
  const color = value >= 0 ? "#2e7d32" : "#c62828";
  const bg = value >= 0 ? "#eef7ee" : "#fdecea";
  return (
    <span style={{
      background: bg,
      color,
      padding: "3px 8px",
      borderRadius: 4,
      fontSize: 12,
      fontWeight: 700,
      fontFamily: "monospace",
    }}>
      {value >= 0 ? "+" : ""}{value.toFixed(2)}%
    </span>
  );
}

export default function HistoryPage({ onSelectResult }) {
  const [page, setPage] = useState(0);
  const limit = 15;

  const { data, isLoading } = useQuery({
    queryKey: ["history", page],
    queryFn: () => getBacktestHistory(page * limit, limit),
  });

  const totalPages = data ? Math.ceil(data.total / limit) : 0;

  return (
    <div>
      <div style={{ marginBottom: 28 }}>
        <h1 style={{ fontSize: 24, fontWeight: 700, color: "#0f1f3d", margin: 0 }}>
          백테스트 히스토리
        </h1>
        <p style={{ color: "#718096", marginTop: 6, fontSize: 14 }}>
          지금까지 실행한 백테스트 기록입니다. 클릭하면 결과를 다시 볼 수 있습니다.
        </p>
      </div>

      <div style={card}>
        {isLoading ? (
          <p style={{ color: "#718096", textAlign: "center", padding: 40 }}>불러오는 중...</p>
        ) : !data || data.items.length === 0 ? (
          <p style={{ color: "#718096", textAlign: "center", padding: 40 }}>
            아직 실행한 백테스트가 없습니다.
          </p>
        ) : (
          <>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 16 }}>
              <span style={{ fontSize: 13, color: "#718096" }}>
                전체 {data.total}건
              </span>
            </div>

            <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 13 }}>
              <thead>
                <tr style={{ background: "#0f1f3d" }}>
                  {["Run #", "종목", "전략", "기간", "수익률", "승률", "MDD", "Sharpe", "실행일시", ""].map((h) => (
                    <th key={h} style={{
                      padding: "10px 14px",
                      color: "#94a3b8",
                      fontWeight: 600,
                      textAlign: "left",
                      fontSize: 11,
                      letterSpacing: 0.5,
                      textTransform: "uppercase",
                      whiteSpace: "nowrap",
                    }}>
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {data.items.map((item, i) => (
                  <tr key={item.run_id} style={{
                    borderBottom: "1px solid #f0f4f8",
                    background: i % 2 === 0 ? "#fafbfc" : "white",
                    cursor: "pointer",
                    transition: "background 0.15s",
                  }}
                    onMouseEnter={(e) => e.currentTarget.style.background = "#eef4ff"}
                    onMouseLeave={(e) => e.currentTarget.style.background = i % 2 === 0 ? "#fafbfc" : "white"}
                  >
                    <td style={{ padding: "10px 14px", color: "#718096", fontFamily: "monospace" }}>
                      #{item.run_id}
                    </td>
                    <td style={{ padding: "10px 14px", fontWeight: 600, color: "#0f1f3d" }}>
                      {item.ticker}
                    </td>
                    <td style={{ padding: "10px 14px" }}>
                      <span style={{
                        background: "#e8eef5",
                        color: "#1a3c5e",
                        padding: "3px 8px",
                        borderRadius: 4,
                        fontSize: 12,
                        fontWeight: 600,
                      }}>
                        {STRATEGY_LABELS[item.strategy_name] || item.strategy_name}
                      </span>
                    </td>
                    <td style={{ padding: "10px 14px", color: "#4a5568", fontSize: 12, whiteSpace: "nowrap" }}>
                      {item.start_date} ~ {item.end_date}
                    </td>
                    <td style={{ padding: "10px 14px" }}>
                      <ReturnBadge value={item.total_return} />
                    </td>
                    <td style={{ padding: "10px 14px", fontFamily: "monospace" }}>
                      {item.win_rate !== null ? `${item.win_rate.toFixed(1)}%` : "-"}
                    </td>
                    <td style={{
                      padding: "10px 14px",
                      fontFamily: "monospace",
                      color: item.mdd !== null && item.mdd < -20 ? "#c62828" : "#4a5568",
                    }}>
                      {item.mdd !== null ? `${item.mdd.toFixed(2)}%` : "-"}
                    </td>
                    <td style={{ padding: "10px 14px", fontFamily: "monospace" }}>
                      {item.sharpe_ratio !== null ? item.sharpe_ratio.toFixed(2) : "-"}
                    </td>
                    <td style={{ padding: "10px 14px", color: "#718096", fontSize: 12, whiteSpace: "nowrap" }}>
                      {item.created_at.slice(0, 16).replace("T", " ")}
                    </td>
                    <td style={{ padding: "10px 14px" }}>
                      <button
                        onClick={() => onSelectResult(item.run_id)}
                        style={{
                          background: "#0f1f3d",
                          color: "white",
                          border: "none",
                          borderRadius: 6,
                          padding: "5px 12px",
                          fontSize: 12,
                          cursor: "pointer",
                          whiteSpace: "nowrap",
                        }}
                      >
                        결과 보기
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>

            {/* 페이지네이션 */}
            {totalPages > 1 && (
              <div style={{ display: "flex", justifyContent: "center", gap: 8, marginTop: 20 }}>
                <button
                  onClick={() => setPage((p) => Math.max(0, p - 1))}
                  disabled={page === 0}
                  style={{
                    padding: "6px 14px",
                    borderRadius: 6,
                    border: "1.5px solid #dde1e8",
                    background: page === 0 ? "#f4f6f9" : "white",
                    cursor: page === 0 ? "not-allowed" : "pointer",
                    color: page === 0 ? "#718096" : "#0f1f3d",
                    fontSize: 13,
                  }}
                >
                  이전
                </button>
                <span style={{ padding: "6px 14px", fontSize: 13, color: "#4a5568" }}>
                  {page + 1} / {totalPages}
                </span>
                <button
                  onClick={() => setPage((p) => Math.min(totalPages - 1, p + 1))}
                  disabled={page >= totalPages - 1}
                  style={{
                    padding: "6px 14px",
                    borderRadius: 6,
                    border: "1.5px solid #dde1e8",
                    background: page >= totalPages - 1 ? "#f4f6f9" : "white",
                    cursor: page >= totalPages - 1 ? "not-allowed" : "pointer",
                    color: page >= totalPages - 1 ? "#718096" : "#0f1f3d",
                    fontSize: 13,
                  }}
                >
                  다음
                </button>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
}