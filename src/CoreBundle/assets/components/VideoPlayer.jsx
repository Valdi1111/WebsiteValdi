import PlyrVideoComponent from "@CoreBundle/components/PlyrVideoComponent";
import { useSearchParams } from "react-router-dom";
import React from "react";

/**
 * Video player
 * @param {function} apiUrl base api url
 * @returns {JSX.Element}
 * @constructor
 */
export default function VideoPlayer({ apiUrl }) {
    const [searchParams, setSearchParams] = useSearchParams();

    return <main className="d-flex flex-column vh-100 vw-100">
        <PlyrVideoComponent src={apiUrl(searchParams.get('id'))}/>
    </main>;

}