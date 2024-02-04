import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faGear } from "@fortawesome/free-solid-svg-icons";
import SettingsFont from "./SettingsFont";
import SettingsFontSize from "./SettingsFontSize";
import SettingsSpacing from "./SettingsSpacing";
import SettingsMargins from "./SettingsMargins";
import SettingsWidth from "./SettingsWidth";
import SettingsForceFont from "./SettingsForceFont";
import SettingsForceFontSize from "./SettingsForceFontSize";
import SettingsFullJustification from "./SettingsFullJustification";
import SettingsLayouts from "./SettingsLayouts";
import React from "react";

export default function BookSettings() {
    return (
        <div className="dropdown">
            <button className="btn btn-icon btn-outline-secondary" type="button" id="settings-dropdown"
                    data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded={false}>
                <FontAwesomeIcon icon={faGear} width={16} height={16}/>
            </button>
            <div className="dropdown-menu" aria-labelledby="settings-dropdown" style={{ width: "300px" }}>
                <SettingsFont/>
                <SettingsFontSize/>
                <SettingsSpacing/>
                <SettingsMargins/>
                <SettingsWidth/>
                <SettingsForceFont/>
                <SettingsForceFontSize/>
                <SettingsFullJustification/>
                <SettingsLayouts/>
            </div>
        </div>
    );
}
