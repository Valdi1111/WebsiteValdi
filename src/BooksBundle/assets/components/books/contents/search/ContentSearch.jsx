import SearchItem from "@BooksBundle/components/books/contents/search/SearchItem";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faArrowRight } from "@fortawesome/free-solid-svg-icons";
import React from "react";

export default function ContentSearch({ close, navigateTo, search }) {
    const [searchResults, setSearchResults] = React.useState([]);
    const searchAll = React.useRef();
    const input = React.useRef();

    React.useEffect(() => {
        input.current.onkeydown = (e) => {
            let code = e.keyCode || e.which;
            if (code === 13) {
                goSearch();
            }
        };
    }, []);

    function goSearch() {
        if (!input.current.value) {
            setSearchResults([]);
            return;
        }
        search(input.current.value, searchAll.current.checked)
            .then(res => setSearchResults(res));
    }

    return <>
        <div className="dropdown-header py-0 px-2">
            <div className="input-group mb-1">
                <input className="form-control" id="search" type="text" placeholder="Search" ref={input}/>
                <button className="btn btn-outline-success btn-icon" onClick={goSearch}>
                    <FontAwesomeIcon icon={faArrowRight}/>
                </button>
            </div>
            <div className="form-check form-check-inline">
                <input id="search-select-all" className="form-check-input" name="search-type" type="radio"
                       value="all" defaultChecked={true} ref={searchAll}/>
                <label className="form-check-label" htmlFor="search-select-all">All chapters</label>
            </div>
            <div className="form-check form-check-inline">
                <input id="search-select-chapter" className="form-check-input" name="search-type" type="radio"
                       value="chapter"/>
                <label className="form-check-label" htmlFor="search-select-chapter">Current chapter</label>
            </div>
        </div>
        <ul className="list-unstyled overflow-auto mb-1" style={{ maxHeight: "400px" }}>
            {searchResults.map(i =>
                <SearchItem key={i.cfi} close={close} item={i} navigateTo={navigateTo}/>
            )}
        </ul>
        <span className="dropdown-header text-secondary py-0 px-2">{searchResults.length} results</span>
    </>;

}
