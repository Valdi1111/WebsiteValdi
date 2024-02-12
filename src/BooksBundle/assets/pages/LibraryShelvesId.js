import {useLibraryUpdate, useShelves} from "../components/Contexts";
import {getBooksInShelf} from "../api/shelves";
import ShelfEditModal from "../components/library/shelves/modals/ShelfEditModal";
import ShelfDeleteModal from "../components/library/shelves/modals/ShelfDeleteModal";
import ShelvesContent from "../components/library/shelves/content/ShelvesContent";
import LoadingComponent from "../components/LoadingComponent";
import {useNavigate} from "react-router-dom";
import {Helmet} from "react-helmet";
import React from "react";

export default function LibraryShelvesId() {
    const [update, setUpdate] = useLibraryUpdate();
    const [shelves, setShelves, refreshShelves, shelf, setShelf] = useShelves();
    const [content, setContent] = React.useState([]);
    const [loading, setLoading] = React.useState(true);
    const navigate = useNavigate();
    /** @type {MutableRefObject<EventSource>}*/
    const ws = React.useRef(null);

    React.useEffect(() => {
        if (ws.current) {
            ws.current.close();
        }
        if (shelf) {
            refreshContent();
        }
    }, [shelf]);

    // Handle book add/recreate/delete
    React.useEffect(() => {
        if (update.type === 'add') {
            let flag = false;
            for (const item of update.items) {
                if (item.shelf_id === shelf.id) {
                    flag = true;
                }
            }
            if (flag) {
                refreshContent();
            }
        }
        if (update.type === 'recreate') {
            refreshContent();
        }
        if (update.type === 'delete') {
            refreshContent();
        }
    }, [update]);

    function refreshContent() {
        setLoading(true);
        getBooksInShelf(shelf.id).then(
            res => {
                // Update content
                const data = {};
                res.data.forEach(b => {
                    const path = b.url.replace(`${shelf.path}/`, '').split('/', 3);
                    const folder = path.length === 2 ? shelf.path : path[1];
                    if (!data[folder]) {
                        data[folder] = [];
                    }
                    data[folder].push(b);
                });
                setContent(data);
                // Update shelf book count
                let allShelves = shelves;
                let i = allShelves.findIndex(s => s.id === shelf.id);
                allShelves[i]._count = res.data.length;
                setShelves([...allShelves]);
                setLoading(false);
                // Websocket
                startWebsocket();
            },
            err => console.error(err)
        );
    }

    function startWebsocket() {
        // Append the topic(s) to subscribe as query parameter
        const hub = new URL(MERCURE_HUB_URL, window.origin);
        hub.searchParams.append('topic', `https://books.valdi.ovh/library/shelves/${shelf.id}`);
        // Subscribe to updates
        ws.current = new EventSource(hub, {withCredentials: true});
        ws.current.onmessage = event => {
            // Will be called every time an update is published by the server
            console.log(JSON.parse(event.data));
        }
    }

    function onShelfEdit(data) {
        let allShelves = shelves;
        let i = allShelves.findIndex(s => s.id === data.id);
        allShelves[i].name = data.name;
        setShelves([...allShelves]);
        setShelf(data);
    }

    function onShelfDelete(data) {
        navigate('/library/shelves');
        refreshShelves(null);
    }

    if (!shelf) {
        return <></>;
    }

    return (
        <>
            <Helmet>
                <title>{shelf.name}</title>
            </Helmet>
            <ShelfEditModal update={onShelfEdit}/>
            <ShelfDeleteModal update={onShelfDelete}/>
            {loading ? <LoadingComponent/> : <ShelvesContent content={content}/>}
        </>
    );
}