import axios from "axios";

const client = axios.create({
    baseURL: "http://127.0.0.1:8000",
});

export const getStocks = () => client.get("/stocks").then((res) => res.data);

export const getStrategies = () =>
    client.get("/strategies").then((res) => res.data);

export const runBacktest = (payload) =>
    client.post("/backtest/run", payload).then((res) => res.data);

export const getBacktestResult = (runId) =>
    client.get(`/backtest/${runId}`).then((res) => res.data);

export const getBacktestTrades = (runId) =>
    client.get(`/backtest/${runId}/trades`).then((res) => res.data);

export const compareStrategies = (payload) =>
    client.post("/backtest/compare", payload).then((res) => res.data);

export const runGridSearch = (payload) =>
    client.post("/backtest/grid-search", payload).then((res) => res.data);

export const getBacktestHistory = (skip = 0, limit = 20) =>
    client.get(`/backtest/history?skip=${skip}&limit=${limit}`).then((res) => res.data);

export const getCurrentPrice = (ticker) =>
    client.get(`/realtime/price/${ticker}`).then((res) => res.data)

export const searchStock = (ticker) =>
    client.get(`/stocks/search?ticker=${ticker}`).then((res) => res.data);

export const runStrategy = (ticker, strategyName = "ma20", quantity = 1) =>
    client
        .post(`/trading/run-strategy?ticker=${ticker}&strategy_name=${strategyName}&quantity=${quantity}`)
        .then((res) => res.data);

export const getOrderStatus = (orderNo, ticker, orderDate) => {
    const params = new URLSearchParams({ ticker });
    if (orderDate) params.append("order_date", orderDate);
    return client
        .get(`/trading/order/${orderNo}/status?${params.toString()}`)
        .then((res) => res.data);
};

export const getOrders = (skip = 0, limit = 50) =>
    client.get(`/trading/orders?skip=${skip}&limit=${limit}`).then((res) => res.data);

export const pauseTrading = () =>
    client.post("/trading/scheduler/pause").then((res) => res.data);

export const resumeTrading = () =>
    client.post("/trading/scheduler/resume").then((res) => res.data);

export const getSchedulerStatus = () =>
    client.get("/trading/scheduler/status").then((res) => res.data);