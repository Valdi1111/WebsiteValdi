import missingCoverUrl from "@BooksBundle/images/books-missing-cover.png";
import { getCoverUrl, getEpubUrlById, getMetadata } from "@BooksBundle/api/book";
import { faDownload, faExternalLink } from "@fortawesome/free-solid-svg-icons";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { Link } from "react-router-dom";
import React from "react";

export default function BookInfoModal() {
    const [id, setId] = React.useState(null);
    const [path, setPath] = React.useState('');
    const [metadata, setMetadata] = React.useState({});
    const [coverUrl, setCoverUrl] = React.useState(null);
    const modal = React.useRef();

    React.useEffect(() => {
        modal.current.addEventListener("show.bs.modal", (e) => {
            const id = e.relatedTarget.getAttribute("data-bs-id");
            getMetadata(id).then(
                res => {
                    setId(id);
                    setPath(res.data.url);
                    setMetadata(res.data.book_metadata);
                    if (res.data.book_cache.cover) {
                        setCoverUrl(res.data.book_cache.cover_url);
                    }
                },
                err => console.error(err)
            );
        });
        modal.current.addEventListener("hidden.bs.modal", (e) => {
            setId(null);
            setPath('');
            setMetadata({});
            setCoverUrl(null);
        });
    }, []);

    return (
        <div className="modal fade" id="book-info-modal" tabIndex={-1} aria-hidden={true}
             aria-labelledby="book-info-modal-label" ref={modal}>
            <div className="modal-dialog modal-dialog-centered">
                <div className="modal-content">
                    <div className="modal-header">
                        <Link to={getEpubUrlById(id)} target="_blank" className="me-2">
                            <FontAwesomeIcon icon={faDownload}/>
                        </Link>
                        {coverUrl &&
                            <Link to={getCoverUrl(id)} target="_blank" className="me-2">
                                <FontAwesomeIcon icon={faExternalLink}/>
                            </Link>
                        }
                        <h5 className="modal-title" id="book-info-modal-label">About this book</h5>
                        <button type="button" className="btn-close" data-bs-dismiss="modal" aria-label="Close"/>
                    </div>
                    <div className="modal-body">
                        <div className="row">
                            <div className="col-4 mb-2">
                                <img className="img-fluid w-100 h-auto" src={coverUrl ?? missingCoverUrl}
                                     alt="Book cover" loading="lazy"/>
                            </div>
                            <div className="col-8">
                                <h6 className="mb-1">{metadata.title}</h6>
                                <p className="small">{metadata.creator}</p>
                            </div>
                            <div className="col-12">
                                <h6 className="small mb-0">Path</h6>
                                <p>{path}</p>
                            </div>
                            <div className="col-12 col-sm-6">
                                <h6 className="small mb-0">Publisher</h6>
                                <p>{metadata.publisher}</p>
                            </div>
                            <div className="col-12 col-sm-6">
                                <h6 className="small mb-0">Publication Date</h6>
                                <p>{metadata.publication}</p>
                            </div>
                            <div className="col-12 col-sm-6">
                                <h6 className="small mb-0">Modified Date</h6>
                                <p>{metadata.modified}</p>
                            </div>
                            <div className="col-12 col-sm-6">
                                <h6 className="small mb-0">Language</h6>
                                <p>{metadata.language}</p>
                            </div>
                            <div className="col-12">
                                <h6 className="small mb-0">Identifier</h6>
                                <p>{metadata.identifier}</p>
                            </div>
                            <div className="col-12">
                                <h6 className="small mb-0">Copyright</h6>
                                <p>{metadata.rights}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
