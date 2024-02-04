import Breadcrumb from "@CoreBundle/components/Breadcrumb";
import MainLayout from "./MainLayout";
import {getFiles} from "../api";
import LoadingComponent from "@CoreBundle/components/LoadingComponent";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faFolder, faFolderOpen} from "@fortawesome/free-regular-svg-icons";
import {faFilm} from "@fortawesome/free-solid-svg-icons";
import {Link, useSearchParams} from "react-router-dom";
import React from "react";

export default function Files() {
    const [crumbs, setCrumbs] = React.useState([]);
    const [loading, setLoading] = React.useState(true);
    const [folders, setFolders] = React.useState([]);
    const [files, setFiles] = React.useState([]);
    const [searchParams, setSearchParams] = useSearchParams();
    const divRef = React.useRef();

    React.useEffect(() => {
        setLoading(true);
        const path = searchParams.get('path') || "";
        const items = [];
        for (const split of path.split('/')) {
            if (!split) {
                continue;
            }
            items.push({path: '/files?path=' + encodeURIComponent(split), name: split});
        }
        setCrumbs(items);
        getFiles(path).then(
            res => {
                setFolders(res.filter(f => f.type === 'folder'));
                setFiles(res.filter(f => f.type === 'file'));
                divRef.current.scrollTo({top: 0});
                setLoading(false);
            },
            err => console.error(err)
        )
    }, [searchParams]);

    return (
        <MainLayout>
            <Breadcrumb home="/" items={crumbs}/>
            <div className="flex-grow-1 overflow-y-scroll" ref={divRef}>
                {loading ? <LoadingComponent/> :
                    <div className="list-group list-group-flush">
                        {folders.map(f =>
                            <Link key={f.name} to={'/files?path=' + f.path}
                                  className="list-group-item list-group-item-action">
                                <FontAwesomeIcon icon={faFolderOpen}/>
                                <span className="ms-2">{f.name}</span>
                            </Link>
                        )}
                        {files.map(f =>
                            <Link key={f.name} to={'/videos?path=' + encodeURIComponent(f.path)} className="list-group-item list-group-item-action">
                                <FontAwesomeIcon icon={faFilm}/>
                                <span className="ms-2">{f.name}</span>
                            </Link>
                        )}
                    </div>
                }
            </div>
        </MainLayout>
    );

}