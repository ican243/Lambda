import { useState } from "react";
import { useQuery, useMutation } from "@tanstack/react-query";
import { useQueryClient } from "@tanstack/react-query";
import { getStocks, getStrategies, runBacktest, searchStock } from "../api/client";

const STRATEGY_PARAM_FIELDS = {
  MA20: [{ key: "period", label: "이동평균 기간 (일)", default: 20 }],
  GOLDEN_CROSS: [
    { key: "short_period", label: "단기 이동평균 (일)", default: 5 },
    { key: "long_period", label: "장기 이동평균 (일)", default: 20 },
  ],
  RSI: [
    { key: "period", label: "RSI 기간 (일)", default: 14 },
    { key: "lower", label: "과매도 기준", default: 30 },
    { key: "upper", label: "과매수 기준", default: 70 },
  ],
};

const STRATEGY_DESC = {
  MA20: "종가가 이동평균선을 돌파하는 시점을 매매 신호로 사용합니다.",
  GOLDEN_CROSS: "단기 이동평균이 장기 이동평균을 교차하는 시점을 매매 신호로 사용합니다.",
  RSI: "RSI 과매도/과매수 구간 진입 및 이탈 시점을 매매 신호로 사용합니다.",
};

const card = {
  background: "white",
  borderRadius: 12,
  padding: "24px 28px",
  boxShadow: "0 1px 4px rgba(0,0,0,0.08)",
  marginBottom: 20,
};

const label = {
  display: "block",
  fontSize: 12,
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
  outline: "none",
};

export default function MainPage({ onResult }) {
  const [ticker, setTicker] = useState("");
  const [strategyName, setStrategyName] = useState("MA20");
  const [startDate, setStartDate] = useState("2024-01-01");
  const [endDate, setEndDate] = useState("2026-06-24");
  const [params, setParams] = useState({ period: 20 });
  const queryClient = useQueryClient();
  const [searchTicker, setSearchTicker] = useState("");

  const { data: stocks } = useQuery({ queryKey: ["stocks"], queryFn: getStocks });

  const mutation = useMutation({
    mutationFn: runBacktest,
    onSuccess: (data) => onResult(data),
  });

  const handleSubmit = (e) => {
    e.preventDefault();
    mutation.mutate({ ticker, strategy_name: strategyName, start_date: startDate, end_date: endDate, params });
  };

  const handleStrategyChange = (name) => {
    setStrategyName(name);
    const defaults = {};
    (STRATEGY_PARAM_FIELDS[name] || []).forEach((f) => (defaults[f.key] = f.default));
    setParams(defaults);
  };

  const searchMutation = useMutation({
    mutationFn: searchStock,
    onSuccess: (data) => {
      // 종목 목록 새로고침
      queryClient.invalidateQueries({ queryKey: ["stocks"] });
      // 검색된 종목 자동 선택
      setTicker(data.ticker);
      setSearchTicker("");
      alert(data.is_new
        ? `${data.name} (${data.ticker}) 종목이 추가되었습니다!`
        : `${data.name} (${data.ticker}) 종목이 이미 등록되어 있습니다.`
      );
    },
    onError: () => {
      alert("종목을 찾을 수 없습니다. 종목 코드를 확인해주세요.");
    },
  });

  return (
    <div>
      <div style={{ marginBottom: 28 }}>
        <h1 style={{ fontSize: 24, fontWeight: 700, color: "#0f1f3d", margin: 0 }}>
          새 백테스트 실행
        </h1>
        <p style={{ color: "#718096", marginTop: 6, fontSize: 14 }}>
          종목과 전략을 선택하고 백테스트를 실행하세요.
        </p>
      </div>

      <form onSubmit={handleSubmit}>
        <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 20 }}>

          {/* 종목 선택 */}
          <div style={card}>
            <div style={{ display: "flex", alignItems: "center", gap: 8, marginBottom: 16 }}>
              <span style={{ width: 4, height: 20, background: "#c9a84c", borderRadius: 2, display: "inline-block" }} />
              <span style={{ fontWeight: 700, color: "#0f1f3d", fontSize: 15 }}>종목 선택</span>
            </div>
            <label style={label}>종목</label>
            <select
              value={ticker}
              onChange={(e) => setTicker(e.target.value)}
              required
              style={{ ...input, cursor: "pointer" }}
            >
              {/* 종목 직접 검색 */}
            <div style={{ marginTop: 12 }}>
              <div style={{ fontSize: 11, color: "#718096", marginBottom: 6, fontWeight: 600 }}>
                목록에 없는 종목 직접 추가
              </div>
              <div style={{ display: "flex", gap: 8 }}>
                <input
                  type="text"
                  placeholder="종목코드 입력 (예: 035720)"
                  value={searchTicker}
                  onChange={(e) => setSearchTicker(e.target.value.toUpperCase())}
                  onKeyDown={(e) => {
                    if (e.key === "Enter") {
                      e.preventDefault();
                      if (searchTicker) searchMutation.mutate(searchTicker);
                    }
                  }}
                  style={{ ...input, flex: 1 }}
                />
                <button
                  type="button"
                  onClick={() => { if (searchTicker) searchMutation.mutate(searchTicker); }}
                  disabled={!searchTicker || searchMutation.isPending}
                  style={{
                    padding: "10px 16px",
                    background: searchTicker ? "#1a3c5e" : "#718096",
                    color: "white",
                    border: "none",
                    borderRadius: 8,
                    fontSize: 13,
                    fontWeight: 600,
                    cursor: searchTicker ? "pointer" : "not-allowed",
                    whiteSpace: "nowrap",
                  }}
                >
                  {searchMutation.isPending ? "조회 중..." : "추가"}
                </button>
              </div>
            </div>
              <option value="">종목을 선택하세요</option>
              {stocks?.filter((s) => s.market !== "INDEX").map((s) => (
                <option key={s.ticker} value={s.ticker}>
                  {s.name} ({s.ticker})
                </option>
              ))}
            </select>

            <div style={{ marginTop: 20 }}>
              <label style={label}>백테스트 기간</label>
              <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 10 }}>
                <div>
                  <div style={{ fontSize: 11, color: "#718096", marginBottom: 4 }}>시작일</div>
                  <input type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} style={input} />
                </div>
                <div>
                  <div style={{ fontSize: 11, color: "#718096", marginBottom: 4 }}>종료일</div>
                  <input type="date" value={endDate} onChange={(e) => setEndDate(e.target.value)} style={input} />
                </div>
              </div>
            </div>
          </div>

          {/* 전략 선택 */}
          <div style={card}>
            <div style={{ display: "flex", alignItems: "center", gap: 8, marginBottom: 16 }}>
              <span style={{ width: 4, height: 20, background: "#c9a84c", borderRadius: 2, display: "inline-block" }} />
              <span style={{ fontWeight: 700, color: "#0f1f3d", fontSize: 15 }}>전략 선택</span>
            </div>

            {/* 전략 탭 */}
            <div style={{ display: "flex", gap: 8, marginBottom: 14 }}>
              {["MA20", "GOLDEN_CROSS", "RSI"].map((s) => (
                <button
                  key={s}
                  type="button"
                  onClick={() => handleStrategyChange(s)}
                  style={{
                    padding: "6px 14px",
                    borderRadius: 20,
                    border: "1.5px solid",
                    borderColor: strategyName === s ? "#1a3c5e" : "#dde1e8",
                    background: strategyName === s ? "#1a3c5e" : "white",
                    color: strategyName === s ? "white" : "#4a5568",
                    fontSize: 12,
                    fontWeight: 600,
                    cursor: "pointer",
                  }}
                >
                  {s === "GOLDEN_CROSS" ? "골든크로스" : s}
                </button>
              ))}
            </div>

            <p style={{ fontSize: 12, color: "#718096", marginBottom: 14 }}>
              {STRATEGY_DESC[strategyName]}
            </p>

            {/* 파라미터 */}
            <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 10 }}>
              {STRATEGY_PARAM_FIELDS[strategyName]?.map((field) => (
                <div key={field.key}>
                  <label style={label}>{field.label}</label>
                  <input
                    type="number"
                    value={params[field.key] ?? field.default}
                    onChange={(e) => setParams({ ...params, [field.key]: Number(e.target.value) })}
                    style={input}
                  />
                </div>
              ))}
            </div>
          </div>
        </div>

        {/* 실행 버튼 */}
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
            cursor: mutation.isPending ? "not-allowed" : "pointer",
            letterSpacing: 0.5,
            transition: "background 0.2s",
          }}
        >
          {mutation.isPending ? "⏳  백테스트 실행 중..." : "▶  백테스트 실행"}
        </button>

        {mutation.isError && (
          <div style={{
            marginTop: 12,
            padding: "12px 16px",
            background: "#fff5f5",
            border: "1px solid #fc8181",
            borderRadius: 8,
            color: "#c53030",
            fontSize: 13,
          }}>
            {mutation.error?.response?.data?.detail || "알 수 없는 오류가 발생했습니다."}
          </div>
        )}
      </form>
    </div>
  );
}