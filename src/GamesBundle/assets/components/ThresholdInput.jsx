import { Button, InputNumber } from "antd";
import React from "react";

/**
 * Number input with explicit visual indicator when threshold is disabled (-1).
 * Displays "Off" directly in the input when value is <= -1, with a "Turn off" button stacked underneath.
 */
export default function ThresholdInput({ value, onChange, min = -1, max, step = 1, disabled, width = 110 }) {
    const isOff = value === undefined || value === null || value <= -1;

    return (
        <div style={{ display: "flex", flexDirection: "column", alignItems: "flex-end" }}>
            <InputNumber
                min={min}
                max={max}
                step={step}
                value={value}
                onChange={onChange}
                disabled={disabled}
                style={{ width }}
                formatter={(val) => {
                    if (val === "" || val === undefined || val === null) return "";
                    return Number(val) <= -1 ? "Off" : String(val);
                }}
                parser={(displayValue) => {
                    if (!displayValue || displayValue.trim().toLowerCase() === "off") {
                        return -1;
                    }
                    const parsed = parseInt(displayValue.replace(/[^\d-]/g, ""), 10);
                    return isNaN(parsed) ? -1 : parsed;
                }}
            />
            {!disabled && !isOff && (
                <Button
                    size="small"
                    type="link"
                    danger
                    onClick={() => onChange?.(-1)}
                    style={{ fontSize: 11, padding: 0, height: 18, marginTop: 2 }}
                >
                    Turn off
                </Button>
            )}
        </div>
    );
}
