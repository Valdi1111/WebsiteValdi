import React from 'react';

/*
id: 635
episode_url: "/play/seiken-gakuin-no-makentsukai.IBegC/h23Fcc"
download_url: "https://server18.cherrycloud.net/DDL/ANIME/SeikenGakuinNoMakentsukai/SeikenGakuinNoMakentsukai_Ep_05_SUB_ITA.mp4"
file: "SeikenGakuinNoMakentsukai_Ep_05_SUB_ITA.mp4"
folder: "/SeikenGakuinNoMakentsukai/Stagione 1"
episode: "5"
created: "2023-10-30T21:50:33+00:00"
started: "2023-10-30T21:51:01+00:00"
completed: "2023-10-30T21:53:40+00:00"
state: "completed"
mal_id: 50184
al_id: 140501
 */
export default function TableDownloads() {
    const divRef = React.useRef();
    React.useEffect(() => {
        webix.ready(function () {
            // use custom scrolls, optional
            webix.CustomScroll.init();

            const dateParser = webix.Date.strToDate("%c");
            const dateFormatted = webix.Date.dateToStr("%Y-%m-%d %H:%i");
            const app = webix.ui({
                container: "table-downloads",
                view: "datatable",
                url: "/api/downloads",
                dragColumn: true,
                visibleBatch: "normal",
                columns: [
                    {
                        id: 'id', header: "ID", adjust: true, sort: "int",
                    },
                    {
                        id: 'episode_url', header: "URL", fillspace: true,
                    },
                    {
                        id: 'download_url', header: "Download", batch: "all",
                    },
                    {
                        id: 'folder', header: "Folder", fillspace: true,
                    },
                    {
                        id: 'file', header: "File", batch: "all",
                    },
                    {
                        id: 'episode', header: "Episode", adjust: true,
                    },
                    {
                        id: 'created', header: "Created", batch: "all", adjust: true, sort: "date", format: dateFormatted,
                    },
                    {
                        id: 'started', header: "Started", adjust: true, sort: "date", format: dateFormatted,
                    },
                    {
                        id: 'completed', header: "Completed", adjust: true, sort: "date", format: dateFormatted,
                    },
                    {
                        id: 'state', header: "State", adjust: true,
                    },
                    {
                        id: 'mal_id', header: "MAL", adjust: true,
                    },
                    {
                        id: 'al_id', header: "AL", batch: "all", adjust: true,
                    },
                ],
                scheme: {
                    $init: function (item) {
                        if (item.created) {
                            item.created = dateParser(item.created);
                        }
                        if (item.started) {
                            item.started = dateParser(item.started);
                        }
                        if (item.completed) {
                            item.completed = dateParser(item.completed);
                        }
                    },
                },
            });
        });
    }, []);

    return <main ref={divRef} id="table-downloads" className="flex-grow-1"></main>;

}