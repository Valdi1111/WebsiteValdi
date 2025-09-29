import { FONT, FONTS } from "@BooksBundle/components/books/BookConstants";
import { useBook } from "@BooksBundle/components/books/BookContext";
import React from "react";

export default function SettingsFont() {
    const { settings, setSetting } = useBook();

    function font(e) {
        setSetting(FONT, e.target.value);
    }

    return <div className="row mx-0 mb-2">
        <label className="col-4 col-form-label" htmlFor="input-font">Font</label>
        <div className="col-8">
            <select id="input-font" className="form-select" defaultValue={settings[FONT]} onChange={font}>
                {Object.entries(FONTS).map(([id, value]) =>
                    <option key={id} value={id}>{value}</option>
                )}
            </select>
        </div>
    </div>;
}
