import { Button, Flex, Result } from "antd";
import { Link } from "react-router-dom";
import React from "react";

export default function NotFoundComponent({ redirectPath, redirectText }) {

    return <Flex vertical={true} align="center" justify="center" style={{ height: '100%', width: '100%' }}>
        <Result
            status="404"
            title="404"
            subTitle="Sorry, the page you visited does not exist."
            extra={<Link to={redirectPath}><Button type="primary">{redirectText}</Button></Link>}
        />
    </Flex>;

}
