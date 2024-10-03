import missingCover from "@BooksBundle/images/books-missing-cover.png";
import {getMetadata} from "@BooksBundle/api/book";
import React from "react";

export default function BookInfoModal() {
    const [path, setPath] = React.useState('');
    const [cover, setCover] = React.useState(missingCover);
    const [metadata, setMetadata] = React.useState({});
    const modal = React.useRef();

    React.useEffect(() => {
        modal.current.addEventListener("show.bs.modal", (e) => {
            const id = e.relatedTarget.getAttribute("data-bs-id");
            getMetadata(id).then(
                res => {
                    const cache = res.data.book_cache;
                    const metadata = res.data.book_metadata;
                    setPath(res.data.url);
                    setCover(cache.cover ? cache.cover : missingCover);
                    setMetadata(metadata);
                },
                err => console.error(err)
            );
        });
        modal.current.addEventListener("hidden.bs.modal", (e) => {
            setPath('');
            setCover(missingCover);
            setMetadata({});
        });
    }, []);

    return (
        <div className="modal fade" id="book-info-modal" tabIndex={-1} aria-hidden={true}
             aria-labelledby="book-info-modal-label" ref={modal}>
            <div className="modal-dialog modal-dialog-centered">
                <div className="modal-content">
                    <div className="modal-header">
                        <h5 className="modal-title" id="book-info-modal-label">About this book</h5>
                        <button type="button" className="btn-close" data-bs-dismiss="modal" aria-label="Close"/>
                    </div>
                    <div className="modal-body">
                        <div className="row">
                            <div className="col-4 mb-2">
                                <img className="img-fluid w-100 h-auto" src={cover} alt="Book cover" loading="lazy"/>
                                {/*
                                <Image className="img-fluid w-100 h-auto" src={cover} alt="Book cover" loading="lazy"
                                       sizes="(max-width: 576px) 50vw, (max-width: 768px) 50vw, 25vw" width={0}
                                       height={0}/>
                                */}
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
