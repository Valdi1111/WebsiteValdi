import { useCallback, useEffect, useRef, useState } from "react";

/**
 * Tracks live pull operation progress for a game profile via Mercure EventSource,
 * falling back automatically to HTTP polling when Mercure is unavailable, and
 * triggering onOperationCompleted once per finished (completed or failed) operation ID:
 * a failed operation may have written part of its pulls (backup batches, earlier banners).
 */
export default function usePullOperationProgress({
    mercureTopic,
    fetchOperationStatus,
    onOperationCompleted,
}) {
    const eventSourceRef = useRef(null);
    const completedRefreshedIdsRef = useRef(new Set());
    const [activeOperation, setActiveOperation] = useState(null);
    const [mercureErrored, setMercureErrored] = useState(false);

    const applyOperationUpdate = useCallback((nextOp) => {
        if (!nextOp || !nextOp.id) {
            return;
        }
        setActiveOperation(nextOp);
    }, []);

    const mergeInitialOperation = useCallback((initialOp) => {
        if (!initialOp || !initialOp.id) {
            return;
        }
        setActiveOperation((prev) =>
            prev && prev.id === initialOp.id ? { ...prev, ...initialOp } : initialOp
        );
    }, []);

    const dismissOperation = useCallback(() => {
        setActiveOperation(null);
    }, []);

    // Trigger refresh callback once whenever a tracked operation finishes, also when it failed
    useEffect(() => {
        if (
            activeOperation &&
            (activeOperation.status === "completed" || activeOperation.status === "failed") &&
            !completedRefreshedIdsRef.current.has(activeOperation.id)
        ) {
            completedRefreshedIdsRef.current.add(activeOperation.id);
            onOperationCompleted?.(activeOperation);
        }
    }, [activeOperation, onOperationCompleted]);

    // Subscribe to Mercure real-time progress updates
    useEffect(() => {
        if (!mercureTopic || typeof MERCURE_HUB_URL === "undefined" || !MERCURE_HUB_URL) {
            setMercureErrored(true);
            return;
        }

        let source = null;
        try {
            const hub = new URL(MERCURE_HUB_URL, window.origin);
            hub.searchParams.append("topic", mercureTopic);
            source = new EventSource(hub, { withCredentials: true });
            eventSourceRef.current = source;

            source.onopen = () => {
                setMercureErrored(false);
            };

            source.onmessage = (event) => {
                try {
                    const payload = JSON.parse(event.data);
                    if (payload?.action === "pulls:operation_progress" && payload?.operation) {
                        applyOperationUpdate(payload.operation);
                    }
                } catch {
                    // Ignore malformed events
                }
            };

            source.onerror = () => {
                setMercureErrored(true);
            };
        } catch {
            setMercureErrored(true);
        }

        return () => {
            if (source) {
                source.close();
            }
            if (eventSourceRef.current === source) {
                eventSourceRef.current = null;
            }
        };
    }, [mercureTopic, applyOperationUpdate]);

    // Fallback HTTP polling if Mercure is unavailable while an operation is active
    const activeOperationId = activeOperation?.id;
    const activeOperationStatus = activeOperation?.status;
    const isOperationRunning =
        activeOperationStatus === "queued" || activeOperationStatus === "running";

    useEffect(() => {
        if (!mercureErrored || !isOperationRunning || !activeOperationId || !fetchOperationStatus) {
            return;
        }

        const intervalId = setInterval(() => {
            Promise.resolve(fetchOperationStatus(activeOperationId))
                .then((res) => {
                    if (res?.data?.operation) {
                        applyOperationUpdate(res.data.operation);
                    }
                })
                .catch(() => {
                    // Keep polling silently on transient failures
                });
        }, 1000);

        return () => clearInterval(intervalId);
    }, [mercureErrored, isOperationRunning, activeOperationId, fetchOperationStatus, applyOperationUpdate]);

    return {
        activeOperation,
        applyOperationUpdate,
        mergeInitialOperation,
        dismissOperation,
    };
}
