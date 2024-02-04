import { CHECKBOX_NAME, PREFIX } from "./ThemeChangeModal";
import React from "react";

export default function ModalTheme({theme, id, name}) {
    const item = `${PREFIX}-theme-${id}`;

    return (
        <div className="form-check">
            <input className="form-check-input" type="radio" id={item} name={CHECKBOX_NAME} value={id}
                   defaultChecked={theme === id}/>
            <label className="w-100 form-check-label" htmlFor={item}>{name}</label>
        </div>
    );
}
