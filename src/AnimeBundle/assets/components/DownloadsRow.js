import React from 'react';

export default function DownloadsRow({data, config}) {

    function Cell(params) {
        if (params.config.hidden) {
            return <></>;
        }
        const text = typeof params.config.format === "function" ? params.config.format(params.data) : params.data[params.id];
        if (params.config.row_header) {
            return <th scope="row">{text}</th>;
        }
        return <td>{text}</td>;
    }

    return (
        <tr>
            {config.map(c => <Cell key={c.id} id={c.id} data={data} config={c}/>)}
        </tr>
    );

}