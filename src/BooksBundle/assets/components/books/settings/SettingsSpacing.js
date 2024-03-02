import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faMinus, faPlus } from "@fortawesome/free-solid-svg-icons";
import {useBookSettings} from "@BooksBundle/components/Contexts";
import {SPACING} from "../BookConstants";
import React from "react";

export default function SettingsSpacing() {
    const [settings, setSetting] = useBookSettings();

    function spacing(e, up) {
        const input = document.getElementById('input-spacing');
        if (up) {
            input.stepUp(1);
        } else {
            input.stepDown(1);
        }
        setSetting(SPACING, input.value);
    }

    return (
        <div className="row mx-0 mb-2">
            <label className="col-4 col-form-label" htmlFor="input-spacing">Spacing</label>
            <div className="col-8">
                <div className="input-group">
                    <input id="input-spacing" className="form-control" type="number" disabled={true}
                           defaultValue={settings[SPACING]} step={0.05} min={1.00}/>
                    <button className="btn btn-outline-danger btn-icon" onClick={e => spacing(e, false)}>
                        <FontAwesomeIcon icon={faMinus}/>
                    </button>
                    <button className="btn btn-outline-success btn-icon" onClick={e => spacing(e, true)}>
                        <FontAwesomeIcon icon={faPlus}/>
                    </button>
                </div>
            </div>
        </div>
    );
}
