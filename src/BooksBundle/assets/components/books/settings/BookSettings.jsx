import SettingsFont from "@BooksBundle/components/books/settings/SettingsFont";
import SettingsFontSize from "@BooksBundle/components/books/settings/SettingsFontSize";
import SettingsForceFont from "@BooksBundle/components/books/settings/SettingsForceFont";
import SettingsForceFontSize from "@BooksBundle/components/books/settings/SettingsForceFontSize";
import SettingsFullJustification from "@BooksBundle/components/books/settings/SettingsFullJustification";
import SettingsLayouts from "@BooksBundle/components/books/settings/SettingsLayouts";
import SettingsMargins from "@BooksBundle/components/books/settings/SettingsMargins";
import SettingsSpacing from "@BooksBundle/components/books/settings/SettingsSpacing";
import SettingsWidth from "@BooksBundle/components/books/settings/SettingsWidth";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faGear } from "@fortawesome/free-solid-svg-icons";
import React from "react";

export default function BookSettings() {
    return <div className="dropdown">
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
    </div>;
}
