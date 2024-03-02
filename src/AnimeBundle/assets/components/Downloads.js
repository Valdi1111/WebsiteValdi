import * as api from "@AnimeBundle/api";
import React from 'react';
import {DateTime} from "luxon";
import DownloadsRow from "./DownloadsRow";

export default function Downloads() {
    const [rows, setRows] = React.useState([]);

    React.useEffect(() => {
        api.getEpisodeDownloads().then(res => {
            setRows(res);
        });
    }, []);

    function Column(params) {
        if(params.config.hidden) {
            return <></>;
        }
        return <th scope="col">{params.config.column}</th>;
    }

    return (
        <main className="flex-grow-1 overflow-auto">
            <table className="table table-striped">
                <thead>
                <tr>
                    {config.map(c => <Column key={c.id} id={c.id} config={c}/>)}
                </tr>
                </thead>
                <tbody>
                {rows.map(r => <DownloadsRow key={r.id} data={r} config={config}/>)}
                </tbody>
            </table>
        </main>
    );

}

const config = [
    {
        id: 'id',
        column: 'ID',
        row_header: true,
    },
    {
        id: 'episode_url',
        column: 'Episode Url',
    },
    {
        id: 'episode',
        column: 'Episode',
    },
    {
        id: 'download_url',
        column: 'Download Url',
        hidden: true,
    },
    {
        id: 'state',
        column: 'State',
    },
    {
        id: 'folder',
        column: 'Folder',
    },
    {
        id: 'file',
        column: 'File',
    },
    {
        id: 'created',
        column: 'Created',
        format: data => DateTime.fromISO(data.created).toLocaleString(DateTime.DATETIME_SHORT_WITH_SECONDS),
    },
    {
        id: 'started',
        column: 'Started',
        format: data => DateTime.fromISO(data.started).toLocaleString(DateTime.DATETIME_SHORT_WITH_SECONDS),
        hidden: true,
    },
    {
        id: 'completed',
        column: 'Completed',
        format: data => DateTime.fromISO(data.completed).toLocaleString(DateTime.DATETIME_SHORT_WITH_SECONDS),
        hidden: true,
    },
    {
        id: 'mal_id',
        column: 'Mal',
    },
    {
        id: 'al_id',
        column: 'Al',
    },
];