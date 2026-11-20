import React from "react";

/**
 * Reusable layout row ensuring labels and input controls stay side-by-side
 * on both desktop and mobile screens without breaking onto multiple lines.
 */
export default function SettingRow({ label, description, children }) {
    return (
        <div
            style={{
                display: "flex",
                justifyContent: "space-between",
                alignItems: "center",
                marginBottom: 16,
                gap: 12,
            }}
        >
            <div style={{ flex: 1 }}>
                <div>{label}</div>
                {description && (
                    <div style={{ color: "#8c8c8c", fontSize: 12, lineHeight: 1.3, marginTop: 2 }}>
                        {description}
                    </div>
                )}
            </div>
            <div style={{ flexShrink: 0 }}>
                {children}
            </div>
        </div>
    );
}
