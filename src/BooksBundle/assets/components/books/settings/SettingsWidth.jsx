import { WIDTH } from "@BooksBundle/components/books/BookConstants";
import { useBook } from "@BooksBundle/components/books/BookContext";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faMinus, faPlus } from "@fortawesome/free-solid-svg-icons";
import React from "react";

export default function SettingsWidth() {
    const { settings, setSetting } = useBook();

    function width(e, up) {
        const input = document.getElementById('input-width');
        if (up) {
            input.stepUp(1);
        } else {
            input.stepDown(1);
        }
        setSetting(WIDTH, input.value);
    }

    return <div className="row mx-0 mb-2">
        <label className="col-4 col-form-label" htmlFor="input-width">Width</label>
        <div className="col-8">
            <div className="input-group">
                <input id="input-width" className="form-control" type="number" disabled={true}
                       defaultValue={settings[WIDTH]} step={100} min={0}/>
                <button className="btn btn-outline-danger btn-icon" onClick={e => width(e, false)}>
                    <FontAwesomeIcon icon={faMinus}/>
                </button>
                <button className="btn btn-outline-success btn-icon" onClick={e => width(e, true)}>
                    <FontAwesomeIcon icon={faPlus}/>
                </button>
            </div>
        </div>
    </div>;
}
