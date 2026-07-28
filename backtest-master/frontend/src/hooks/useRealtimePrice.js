import { useState, useEffect, useRef } from "react";

export function useRealtimePrice(ticker, interval = 5) {
    const [price, setPrice] = useState(null);
    const [connected, setConnected] = useState(false);
    const wsRef = useRef(null);

    useEffect(() => {
        if (!ticker) return;

        const ws = new WebSocket(
            `ws://127.0.0.1:8000/realtime/ws/${ticker}?interval=${interval}`
        );

        ws.onopen = () => setConnected(true);

        ws.onmessage = (e) => {
            const data = JSON.parse(e.data);
            if (!data.error) setPrice(data);
        };

        ws.onclose = () => setConnected(false);
        ws.onerror = () => setConnected(false);

        wsRef.current = ws;

        return () => {
            ws.close();
        };
    }, [ticker, interval]);

    return { price, connected };
}