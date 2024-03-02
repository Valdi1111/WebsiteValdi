import { editShelf } from "@BooksBundle/api/shelves";
import React from "react";

export default function ShelfEditModal({ update }) {
    const [id, setId] = React.useState(null);
    const path = React.useRef();
    const name = React.useRef();
    const modal = React.useRef();

    React.useEffect(() => {
        modal.current.addEventListener("show.bs.modal", (e) => {
            setId(e.relatedTarget.getAttribute("data-bs-id"));
            path.current.value = e.relatedTarget.getAttribute("data-bs-path");
            name.current.value = e.relatedTarget.getAttribute("data-bs-name");
        });
        modal.current.addEventListener("shown.bs.modal", (e) => {
            name.current.focus();
        });
        modal.current.addEventListener("hidden.bs.modal", (e) => {
            setId(null);
            path.current.value = '';
            name.current.value = '';
        });
    }, []);

    function confirm() {
        if (!id) {
            return;
        }
        editShelf(id, name.current.value).then(
            res => update(res.data),
            err => console.error(err)
        );
    }

    return (
        <div className="modal fade" id="shelf-edit-modal" tabIndex={-1} aria-hidden={true}
             aria-labelledby="shelf-edit-modal-label" ref={modal}>
            <div className="modal-dialog">
                <div className="modal-content">
                    <div className="modal-header">
                        <h5 className="modal-title" id="shelf-edit-modal-label">Edit shelf</h5>
                        <button type="button" className="btn-close" data-bs-dismiss="modal" aria-label="Close"/>
                    </div>
                    <div className="modal-body">
                        <div className="mb-2">
                            <label htmlFor="edit-path" className="form-label">Path</label>
                            <input ref={path} type="text" className="form-control" id="edit-path"
                                   aria-describedby="edit-path-help" disabled={true}/>
                            <div className="text-secondary">Insert a folder without the / at the end.</div>
                        </div>
                        <div className="mb-2">
                            <label htmlFor="edit-name" className="form-label">Name</label>
                            <input ref={name} type="text" className="form-control" id="edit-name"/>
                        </div>
                    </div>
                    <div className="modal-footer">
                        <button type="button" className="btn btn-danger" data-bs-dismiss="modal">
                            Close
                        </button>
                        <button type="button" className="btn btn-primary" data-bs-dismiss="modal" onClick={confirm}>
                            Update
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
