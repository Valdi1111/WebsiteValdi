import React from "react";
import RemoteTable from "@CoreBundle/components/standard-table/RemoteTable";

/**
 * StandardTable backward-compatible proxy.
 * Re-exports RemoteTable to ensure existing code continues to function seamlessly.
 */
export default function StandardTable(props) {
    return <RemoteTable {...props} />;
}
