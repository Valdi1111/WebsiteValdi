import React, { createContext, useContext, useState } from "react";
import { TRACKER_CATALOG } from "@AnimeBundle/components/TrackerConstants";

const TrackerContext = createContext({
    tracker: "myanimelist",
    trackerConfig: TRACKER_CATALOG.myanimelist,
    setTracker: () => {},
});

export function TrackerProvider({ children }) {
    const [tracker, setTrackerState] = useState(() => {
        const stored = localStorage.getItem("anime_active_tracker");
        return TRACKER_CATALOG[stored] ? stored : "myanimelist";
    });

    const setTracker = (newTracker) => {
        if (!TRACKER_CATALOG[newTracker]) return;
        localStorage.setItem("anime_active_tracker", newTracker);
        setTrackerState(newTracker);
    };

    const trackerConfig = TRACKER_CATALOG[tracker] ?? TRACKER_CATALOG.myanimelist;

    return (
        <TrackerContext.Provider value={{ tracker, trackerConfig, setTracker }}>
            {children}
        </TrackerContext.Provider>
    );
}

export function useTracker() {
    return useContext(TrackerContext);
}
