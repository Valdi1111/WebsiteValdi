import { ShelvesContext } from "@BooksBundle/components/Contexts";
import {getShelves} from "@BooksBundle/api/shelves";
import ShelfAddModal from "./modals/ShelfAddModal";
import ShelvesList from "./list/ShelvesList";
import {useNavigate, useParams} from "react-router-dom";
import {Helmet} from "react-helmet";
import React from "react";

export default function LibraryShelvesLayout({ children }) {
    const [shelves, setShelves] = React.useState([]);
    const [shelf, setShelf] = React.useState(null);
    const [loading, setLoading] = React.useState(true);
    const navigate = useNavigate();
    const { shelfId } = useParams();

    React.useEffect(() => {
        refreshShelves(shelfId);
    }, []);

    React.useEffect(() => {
        setCurrentShelf(shelves, shelfId);
    }, [shelfId]);

    function refreshShelves(currentId) {
        setLoading(true);
        getShelves().then(
            res => {
                setShelves(res.data);
                setCurrentShelf(res.data, currentId);
                setLoading(false);
            },
            err => console.error(err)
        );
    }

    function setCurrentShelf(shelves, shelfId) {
        if(!shelfId) {
            setShelf(null);
            return;
        }
        setShelf(shelves.find(s => s.id == shelfId));
    }

    function onShelfAdd(data) {
        navigate(`/library/shelves/${data.id}`);
        refreshShelves(data.id);
    }

    return (
        <ShelvesContext.Provider value={[shelves, setShelves, refreshShelves, shelf, setShelf]}>
            <Helmet>
                <title>Shelves</title>
            </Helmet>
            <div className="flex-grow-1 d-flex flex-row border-top">
                <ShelfAddModal update={onShelfAdd}/>
                <ShelvesList loading={loading} shelves={shelves} shelf={shelf}/>
                {children}
            </div>
        </ShelvesContext.Provider>
    )
}
