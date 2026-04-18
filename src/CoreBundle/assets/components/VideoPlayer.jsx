import PlyrVideoComponent from "@CoreBundle/components/PlyrVideoComponent";
import { useSearchParams } from "react-router";
import { Flex } from "antd";
import React from "react";

/**
 * Video player
 * @param {function} apiUrl base api url
 * @returns {JSX.Element}
 * @constructor
 */
export default function VideoPlayer({ apiUrl }) {
    const [searchParams, setSearchParams] = useSearchParams();

    return <Flex style={{ width: '100vw', height: '100vh' }} vertical>
        <PlyrVideoComponent src={apiUrl(searchParams.get('id'))}/>
    </Flex>;

}