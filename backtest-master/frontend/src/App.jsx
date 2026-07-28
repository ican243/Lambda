import { useState } from "react";
import { getBacktestResult } from "./api/client";
import MainPage from "./pages/MainPage";
import ResultPage from "./pages/ResultPage";
import ComparePage from "./pages/ComparePage";
import GridSearchPage from "./pages/GridSearchPage";
import HistoryPage from "./pages/HistoryPage";
import RealtimePage from "./pages/RealtimePage";
import TradingPage from "./pages/TradingPage";


function App() {
  const [result, setResult] = useState(null);
  const [tab, setTab] = useState("backtest");
  const [historyRunId, setHistoryRunId] = useState(null);

  const handleHistorySelect = async (runId) => {
    const data = await getBacktestResult(runId);
    setResult(data);
    setTab("backtest");
  };

  const navBtn = (id, label) => (
    <button
      onClick={() => { setTab(id); setResult(null); }}
      style={{
        background: "none",
        border: "none",
        borderBottom: tab === id ? "2px solid #c9a84c" : "2px solid transparent",
        color: tab === id ? "#c9a84c" : "#94a3b8",
        padding: "0 4px 8px",
        fontSize: 14,
        fontWeight: 600,
        cursor: "pointer",
        letterSpacing: 0.3,
      }}
    >
      {label}
    </button>
  );

  return (
    <div style={{ minHeight: "100vh", background: "#f4f6f9", fontFamily: "'Segoe UI', system-ui, sans-serif" }}>
      <header style={{
        background: "#0f1f3d",
        borderBottom: "3px solid #c9a84c",
        padding: "0 32px",
        height: 60,
        display: "flex",
        alignItems: "center",
        gap: 12,
      }}>
        <span style={{ color: "#c9a84c", fontSize: 20, fontWeight: 700 }}>▲</span>
        <span style={{ color: "white", fontSize: 16, fontWeight: 600, letterSpacing: 0.5 }}>
          백테스팅 플랫폼
        </span>
        <span style={{ color: "#4a7aad", fontSize: 13, marginLeft: 8 }}>
          KOSPI · KOSDAQ Strategy Backtester
        </span>

        {/* 탭 네비게이션 — 오른쪽 정렬 */}
        <div style={{ marginLeft: "auto", display: "flex", gap: 24 }}>
          {navBtn("backtest", "백테스트")}
          {navBtn("compare", "전략 비교")}
          {navBtn("gridsearch", "파라미터 최적화")}
          {navBtn("history", "히스토리")}
          {navBtn("realtime", "실시간 시세")}
          {navBtn("trading", "자동매매")}
        </div>
      </header>

      <main style={{ maxWidth: 980, margin: "0 auto", padding: "32px 24px" }}>
        {tab === "backtest" && (
          !result
            ? <MainPage onResult={setResult} />
            : <ResultPage result={result} onBack={() => setResult(null)} />
        )}
        {tab === "compare" && <ComparePage />}
        {tab === "gridsearch" && <GridSearchPage />}
        {tab === "history" && <HistoryPage onSelectResult={handleHistorySelect} />}
        {tab === "realtime" && <RealtimePage />}
        {tab === "trading" && <TradingPage />}
        </main>
    </div>
  );
}

export default App;