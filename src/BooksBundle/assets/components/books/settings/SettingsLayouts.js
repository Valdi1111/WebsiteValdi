import SettingsLayout from "./SettingsLayout";
import {LAYOUTS} from "../BookConstants";
import React from "react";

export default function SettingsLayouts() {
    return (
        <div className="row mx-0 mb-2">
            <div className="col">
                {Object.entries(LAYOUTS).map(([id, value]) =>
                    <SettingsLayout key={id} id={id} name={value.name}/>
                )}
            </div>
        </div>
    );
}
