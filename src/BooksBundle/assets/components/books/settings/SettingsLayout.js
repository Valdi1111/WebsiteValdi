import {useBookSettings} from "../../Contexts";
import {LAYOUT} from "../BookConstants";
import React from "react";

export default function SettingsLayout({ id, name }) {
    const [settings, setSetting] = useBookSettings();

    function layout(e) {
        setSetting(LAYOUT, e.target.value);
    }

    return (
        <div className="form-check">
            <input className="form-check-input" name="input-layout" type="radio" onChange={layout}
                   defaultChecked={settings[LAYOUT] === id} value={id} id={"layout-" + id}/>
            <label className="form-check-label w-100" htmlFor={"layout-" + id}>{name}</label>
        </div>
    );
}
