import { MARGINS } from "@BooksBundle/components/books/BookConstants";
import { useBook } from "@BooksBundle/components/books/BookContext";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faMinus, faPlus } from "@fortawesome/free-solid-svg-icons";
import React from "react";

export default function SettingsMargins() {
    const { settings, setSetting } = useBook();

    function margins(e, up) {
        const input = document.getElementById('input-margins');
        if (up) {
            input.stepUp(1);
        } else {
            input.stepDown(1);
        }
        setSetting(MARGINS, input.value);
    }

    return <div className="row mx-0 mb-2">
        <label className="col-4 col-form-label" htmlFor="input-margins">Margins</label>
        <div className="col-8">
            <div className="input-group">
                <input id="input-margins" className="form-control" type="number" disabled={true}
                       defaultValue={settings[MARGINS]} step={20} min={0}/>
                <button className="btn btn-outline-danger btn-icon" onClick={e => margins(e, false)}>
                    <FontAwesomeIcon icon={faMinus}/>
                </button>
                <button className="btn btn-outline-success btn-icon" onClick={e => margins(e, true)}>
                    <FontAwesomeIcon icon={faPlus}/>
                </button>
            </div>
        </div>
    </div>;
}
