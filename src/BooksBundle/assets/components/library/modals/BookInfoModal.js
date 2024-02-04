import {getCoverUrl, MISSING_COVER_URL} from "../../../api/library";
import {getMetadata} from "../../../api/book";
import React from "react";

export default function BookInfoModal() {
    const [path, setPath] = React.useState('');
    const [cover, setCover] = React.useState(MISSING_COVER_URL);
    const [title, setTitle] = React.useState('');
    const [creator, setCreator] = React.useState('');
    const [publisher, setPublisher] = React.useState('');
    const [publication, setPublication] = React.useState('');
    const [modified, setModified] = React.useState('');
    const [language, setLanguage] = React.useState('');
    const [identifier, setIdentifier] = React.useState('');
    const [copyright, setCopyright] = React.useState('');
    const modal = React.useRef();

    React.useEffect(() => {
        modal.current.addEventListener("show.bs.modal", (e) => {
            const id = e.relatedTarget.getAttribute("data-bs-id");
            setPath(e.relatedTarget.getAttribute("data-bs-url"));
            getMetadata(id).then(
                res => {
                    setCover(res.data.cover ? res.data.cover : MISSING_COVER_URL);
                    setTitle(res.data.title);
                    setCreator(res.data.creator);
                    setPublisher(res.data.publisher);
                    setPublication(res.data.pubdate);
                    setModified(res.data.modified_date);
                    setLanguage(res.data.language);
                    setIdentifier(res.data.identifier);
                    setCopyright(res.data.rights);
                },
                err => console.error(err)
            );
        });
        modal.current.addEventListener("hidden.bs.modal", (e) => {
            setPath('');
            setCover(MISSING_COVER_URL);
            setTitle('');
            setCreator('');
            setPublisher('');
            setPublication('');
            setModified('');
            setLanguage('');
            setIdentifier('');
            setCopyright('');
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
                                <h6 className="mb-1">{title}</h6>
                                <p className="small">{creator}</p>
                            </div>
                            <div className="col-12">
                                <h6 className="small mb-0">Path</h6>
                                <p>{path}</p>
                            </div>
                            <div className="col-12 col-sm-6">
                                <h6 className="small mb-0">Publisher</h6>
                                <p>{publisher}</p>
                            </div>
                            <div className="col-12 col-sm-6">
                                <h6 className="small mb-0">Publication Date</h6>
                                <p>{publication}</p>
                            </div>
                            <div className="col-12 col-sm-6">
                                <h6 className="small mb-0">Modified Date</h6>
                                <p>{modified}</p>
                            </div>
                            <div className="col-12 col-sm-6">
                                <h6 className="small mb-0">Language</h6>
                                <p>{language}</p>
                            </div>
                            <div className="col-12">
                                <h6 className="small mb-0">Identifier</h6>
                                <p>{identifier}</p>
                            </div>
                            <div className="col-12">
                                <h6 className="small mb-0">Copyright</h6>
                                <p>{copyright}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
