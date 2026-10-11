import React, { useMemo } from "react";
import { Segmented, Grid } from "antd";
import { useTracker } from "@AnimeBundle/components/TrackerContext";
import {TRACKER_CATALOG} from "@AnimeBundle/components/TrackerConstants";

const { useBreakpoint } = Grid;

export default function TrackerSelector({ size = "middle", ...restProps }) {
    const { tracker, setTracker } = useTracker();
    const screens = useBreakpoint();

    // Screen widths below md (768px) are treated as mobile devices
    const isMobile = !screens.md;

    const options = useMemo(() => {
        return Object.values(TRACKER_CATALOG).map((t) => ({
            label: isMobile ? t.shortLabel : t.label,
            value: t.key,
        }));
    }, [isMobile]);

    return (
        <Segmented
            options={options}
            value={tracker}
            onChange={setTracker}
            size={size}
            {...restProps}
        />
    );
}
